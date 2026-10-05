<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Models\BeaconModpackInstallation;

class ServerSoftwareCapabilityService
{
    private const MOD_LOADERS = ['forge', 'fabric', 'neoforge', 'quilt'];

    /**
     * Resolve the customer-facing Minecraft software and the installers it is allowed to use.
     *
     * @return array{
     *     software: string,
     *     name: string,
     *     plugins: bool,
     *     mods: bool,
     *     modpacks: bool,
     *     modpack_loaders: string[],
     *     modpack_providers: string[],
     *     plugin_unavailable_reason: string|null,
     *     mod_unavailable_reason: string|null,
     *     modpack_unavailable_reason: string|null
     * }
     */
    public function resolve(Server $server): array
    {
        $installation = BeaconModpackInstallation::query()
            ->where('server_id', $server->id)
            ->first();
        if ($installation instanceof BeaconModpackInstallation) {
            $loader = strtolower($installation->loader);
            if (in_array($loader, self::MOD_LOADERS, true)) {
                return $this->capabilities($loader);
            }
        }

        $metadata = BeaconServerMetadata::query()
            ->with('profile')
            ->where('server_id', $server->id)
            ->first();
        if ($metadata instanceof BeaconServerMetadata && $metadata->profile) {
            return $this->capabilities(strtolower($metadata->profile->loader));
        }

        $server->loadMissing('egg.nest');
        if ($server->egg?->nest?->name !== config('beacon.modpacks.nest.name', 'Beacon')) {
            return $this->capabilities('unknown');
        }

        return $this->capabilities($this->softwareFromEgg($server->egg->name));
    }

    private function softwareFromEgg(string $name): string
    {
        return match (strtolower(trim($name))) {
            'vanilla minecraft' => 'vanilla',
            'paper', 'paper + geyser + floodgate' => 'paper',
            'forge minecraft' => 'forge',
            'fabric' => 'fabric',
            'neoforge', 'neoforge minecraft' => 'neoforge',
            'quilt', 'quilt minecraft' => 'quilt',
            default => 'unknown',
        };
    }

    private function capabilities(string $software): array
    {
        $name = match ($software) {
            'vanilla' => 'Minecraft Vanilla',
            'paper' => 'Paper',
            'forge' => 'Forge',
            'fabric' => 'Fabric',
            'neoforge' => 'NeoForge',
            'quilt' => 'Quilt',
            'curseforge' => 'Minecraft CurseForge',
            'modrinth' => 'Minecraft Modrinth',
            default => 'Unsupported software',
        };
        $plugins = $software === 'paper';
        $mods = in_array($software, self::MOD_LOADERS, true);
        $modpacks = $mods || in_array($software, ['curseforge', 'modrinth'], true);
        $modpackLoaders = $mods ? [$software] : ($modpacks ? self::MOD_LOADERS : []);
        $modpackProviders = match ($software) {
            'curseforge' => ['curseforge'],
            'modrinth' => ['modrinth'],
            default => [],
        };

        return [
            'software' => $software,
            'name' => $name,
            'plugins' => $plugins,
            'mods' => $mods,
            'modpacks' => $modpacks,
            'modpack_loaders' => $modpackLoaders,
            'modpack_providers' => $modpackProviders,
            'plugin_unavailable_reason' => $plugins
                ? null
                : "This server is currently using {$name}. Plugins are only available for Paper servers.",
            'mod_unavailable_reason' => $mods
                ? null
                : "This server is currently using {$name}. Switch to Forge, Fabric, NeoForge, or Quilt before installing mods.",
            'modpack_unavailable_reason' => $modpacks
                ? null
                : "This server is currently using {$name}. Switch to Forge, Fabric, NeoForge, or Quilt before installing modpacks.",
        ];
    }
}
