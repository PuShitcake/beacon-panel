<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class ModpackRuntimeService
{
    public function __construct(private DaemonServerRepository $servers)
    {
    }

    public function snapshot(Server $server): array
    {
        return [
            'nest_id' => $server->nest_id,
            'egg_id' => $server->egg_id,
            'startup' => $server->startup,
            'image' => $server->image,
            'skip_scripts' => $server->skip_scripts,
            'variables' => ServerVariable::query()
                ->where('server_id', $server->id)
                ->get(['variable_id', 'variable_value'])
                ->map(fn (ServerVariable $variable) => [
                    'variable_id' => $variable->variable_id,
                    'variable_value' => $variable->variable_value,
                ])->values()->all(),
        ];
    }

    public function validateTargets(array $release): void
    {
        $this->installerEgg();
        $loader = data_get($release, 'loader');
        if (!is_string($loader) || $loader === '') {
            return;
        }
        $this->runtimeEgg($loader);
        $java = (int) data_get($release, 'java_version');
        if (!is_string(config("beacon.modpacks.java_images.{$java}"))) {
            throw new ModpackProviderException("No trusted Java {$java} image is configured for this modpack.");
        }
    }

    public function validateCurseForgeTarget(array $release): void
    {
        if (($release['provider'] ?? null) !== 'curseforge') {
            throw new ModpackProviderException('The CurseForge Generic Egg only accepts CurseForge modpacks.');
        }
        foreach (['project_id', 'version_id'] as $key) {
            if (!is_string($release[$key] ?? null) || !ctype_digit($release[$key])) {
                throw new ModpackProviderException("The selected CurseForge {$key} is invalid.");
            }
        }

        $egg = $this->curseForgeEgg();
        $variables = $egg->variables()->pluck('env_variable')->all();
        foreach (['PROJECT_ID', 'VERSION'] as $required) {
            if (!in_array($required, $variables, true)) {
                throw new ModpackProviderException("The CurseForge Generic Egg is missing the {$required} variable.");
            }
        }
        $this->curseForgeImage($egg, (int) ($release['java_version'] ?? 0));
    }

    public function isCurseForgeServer(Server $server): bool
    {
        $server->loadMissing('egg.nest');
        $egg = $server->egg;

        return $egg instanceof Egg
            && $egg->nest?->name === config('beacon.modpacks.nest.name')
            && $egg->name === config('beacon.modpacks.nest.curseforge_egg');
    }

    public function switchToCurseForge(Server $server, array $release): Server
    {
        $this->validateCurseForgeTarget($release);
        $egg = $this->curseForgeEgg();

        return $this->switch(
            $server,
            $egg,
            $this->curseForgeImage($egg, (int) $release['java_version']),
            $egg->startup,
            [
                'PROJECT_ID' => $release['project_id'],
                'VERSION' => $release['version_id'],
            ],
        );
    }

    public function switchToInstaller(Server $server): Server
    {
        $egg = $this->installerEgg();

        return $this->switch($server, $egg, (string) array_values($egg->docker_images)[0], $egg->startup, [
            'BEACON_MANIFEST_PATH' => '.beacon/modpack-install.json',
        ]);
    }

    public function switchToRuntime(Server $server, array $release): Server
    {
        $egg = $this->runtimeEgg((string) $release['loader']);
        $java = (int) $release['java_version'];
        $image = (string) config("beacon.modpacks.java_images.{$java}");

        return $this->switch($server, $egg, $image, $egg->startup, $this->runtimeEnvironment($egg, $release));
    }

    public function restore(Server $server, array $snapshot): Server
    {
        foreach (['nest_id', 'egg_id', 'startup', 'image', 'skip_scripts', 'variables'] as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new \InvalidArgumentException("The original runtime snapshot is missing {$key}.");
            }
        }
        if (!is_array($snapshot['variables'])) {
            throw new \InvalidArgumentException('The original runtime variable snapshot is invalid.');
        }

        DB::transaction(function () use ($server, $snapshot) {
            $server->forceFill([
                'nest_id' => (int) $snapshot['nest_id'],
                'egg_id' => (int) $snapshot['egg_id'],
                'startup' => (string) $snapshot['startup'],
                'image' => (string) $snapshot['image'],
                'skip_scripts' => (bool) $snapshot['skip_scripts'],
            ])->save();
            ServerVariable::query()->where('server_id', $server->id)->delete();
            foreach ($snapshot['variables'] as $variable) {
                if (!is_array($variable) || !isset($variable['variable_id'])) {
                    throw new \InvalidArgumentException('The original runtime variable snapshot is invalid.');
                }
                ServerVariable::query()->create([
                    'server_id' => $server->id,
                    'variable_id' => (int) $variable['variable_id'],
                    'variable_value' => (string) ($variable['variable_value'] ?? ''),
                ]);
            }
        });
        $server = $server->fresh();
        $this->servers->setServer($server)->sync();

        return $server;
    }

    private function installerEgg(): Egg
    {
        $nest = $this->beaconNest();
        $egg = Egg::query()
            ->where('nest_id', $nest->id)
            ->where('author', config('beacon.modpacks.nest.author'))
            ->where('name', config('beacon.modpacks.nest.installer_egg'))
            ->first();
        if (!$egg instanceof Egg) {
            throw new ModpackProviderException('The Beacon Modpack Installer Egg is missing. Run the database seeders.');
        }

        return $egg;
    }

    private function curseForgeEgg(): Egg
    {
        $nest = $this->beaconNest();
        $name = config('beacon.modpacks.nest.curseforge_egg');
        $egg = Egg::query()->where('nest_id', $nest->id)->where('name', $name)->first();
        if (!$egg instanceof Egg) {
            throw new ModpackProviderException("The configured Beacon Egg '{$name}' is not installed.");
        }

        return $egg;
    }

    private function curseForgeImage(Egg $egg, int $java): string
    {
        if ($java < 1) {
            throw new ModpackProviderException('The selected modpack has no trusted Java runtime.');
        }
        $suffixes = [":java_{$java}j9", ":java_{$java}"];
        foreach (array_values($egg->docker_images ?? []) as $image) {
            if (!is_string($image)) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($image, $suffix)) {
                    return $image;
                }
            }
        }

        throw new ModpackProviderException("The CurseForge Generic Egg has no trusted Java {$java} image.");
    }

    private function runtimeEgg(string $loader): Egg
    {
        $name = config("beacon.modpacks.runtime_eggs.{$loader}");
        if (!is_string($name) || $name === '') {
            throw new ModpackProviderException("No runtime Egg is configured for the {$loader} loader.");
        }
        $nest = $this->beaconNest();
        $egg = Egg::query()->where('nest_id', $nest->id)->where('name', $name)->first();
        if (!$egg instanceof Egg) {
            throw new ModpackProviderException("The configured Beacon runtime Egg '{$name}' is not installed.");
        }

        return $egg;
    }

    private function beaconNest(): Nest
    {
        $nest = Nest::query()->where('name', config('beacon.modpacks.nest.name'))->first();
        if (!$nest instanceof Nest) {
            throw new ModpackProviderException('The Beacon nest is missing. Run the database seeders.');
        }

        return $nest;
    }

    private function switch(Server $server, Egg $egg, string $image, ?string $startup, array $environment): Server
    {
        DB::transaction(function () use ($server, $egg, $image, $startup, $environment) {
            $server->forceFill([
                'nest_id' => $egg->nest_id,
                'egg_id' => $egg->id,
                'startup' => $startup ?? '',
                'image' => $image,
                'skip_scripts' => false,
            ])->save();

            $variables = $egg->variables()->get();
            $variableIds = $variables->pluck('id')->map(fn ($id) => (int) $id)->all();
            $obsolete = ServerVariable::query()->where('server_id', $server->id);
            if ($variableIds === []) {
                $obsolete->delete();
            } else {
                $obsolete->whereNotIn('variable_id', $variableIds)->delete();
            }

            foreach ($variables as $variable) {
                $value = array_key_exists($variable->env_variable, $environment)
                    ? $environment[$variable->env_variable]
                    : $variable->default_value;
                ServerVariable::query()->updateOrCreate([
                    'server_id' => $server->id,
                    'variable_id' => $variable->id,
                ], ['variable_value' => (string) $value]);
            }
        });

        $server = $server->fresh();
        $this->servers->setServer($server)->sync();

        return $server;
    }

    private function runtimeEnvironment(Egg $egg, array $release): array
    {
        $values = [];
        foreach ($egg->variables()->get() as $variable) {
            $name = strtoupper($variable->env_variable);
            $values[$variable->env_variable] = match ($name) {
                'MC_VERSION', 'MINECRAFT_VERSION', 'MINECRAFT_VERSION_ID', 'VANILLA_VERSION' => $release['minecraft_version'],
                'FORGE_VERSION' => $this->forgeVersion($release, $variable->default_value),
                'BUILD_NUMBER' => isset($release['build_number']) ? (string) $release['build_number'] : $variable->default_value,
                'FABRIC_LOADER_VERSION', 'NEOFORGE_VERSION', 'QUILT_LOADER_VERSION' => $release['loader_version'] ?: $variable->default_value,
                'SERVER_JARFILE' => $this->serverJarFile($release, $variable->default_value),
                default => $variable->default_value,
            };
        }

        return $values;
    }

    private function forgeVersion(array $release, string $default): string
    {
        $version = $release['loader_version'] ?? null;
        if (!is_string($version) || $version === '') {
            return $default;
        }
        $minecraft = (string) ($release['minecraft_version'] ?? '');

        return str_starts_with($version, $minecraft . '-') ? $version : $minecraft . '-' . $version;
    }

    private function serverJarFile(array $release, string $default): string
    {
        return match ($release['loader'] ?? null) {
            'fabric' => 'fabric-server-launch.jar',
            'quilt' => 'quilt-server-launch.jar',
            default => $default,
        };
    }
}
