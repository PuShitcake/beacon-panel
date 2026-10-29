<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeContentRuntimeResolver;

class ServerSoftwareCapabilityService
{
    private const MOD_LOADERS = ['forge', 'fabric', 'neoforge', 'quilt'];

    public function __construct(private NativeCurseForgeContentRuntimeResolver $native)
    {
    }

    /**
     * Resolve the customer-facing Minecraft software and the installers it is allowed to use.
     *
     * @return array{
     *     software: string,
     *     name: string,
     *     plugins: bool,
     *     mods: bool,
     *     modpacks: bool,
     *     configuration: bool,
     *     modpack_loaders: string[],
     *     modpack_providers: string[],
     *     plugin_unavailable_reason: string|null,
     *     mod_unavailable_reason: string|null,
     *     modpack_unavailable_reason: string|null
     * }
     */
    public function resolve(Server $server): array
    {
        $server->loadMissing('egg.nest');
        if ($server->egg?->nest?->name !== config('beacon.modpacks.nest.name', 'Beacon')) {
            return $this->capabilities('unknown');
        }
        // Navigation and direct API admission must use the same reviewed adapter list.
        // A familiar software name or stale modpack metadata is not a configuration adapter.
        $configuration = MinecraftConfigurationAdapter::serviceClass($server) !== null;
        // Native Bedrock must never adopt stale Java/modpack metadata or gain Java installers.
        if (BedrockConfigurationService::supports($server)) {
            return $this->capabilities('bedrock', false, $configuration);
        }

        $native = $this->native->resolve($server);
        if ($native !== null) {
            if ($native['runtime'] !== null) {
                return $this->capabilities($native['runtime']['loader'], true, $configuration);
            }

            return array_replace($this->capabilities('curseforge', false, $configuration), ['mod_unavailable_reason' => $native['reason']]);
        }

        $installation = BeaconModpackInstallation::query()
            ->where('server_id', $server->id)
            ->first();
        if ($installation instanceof BeaconModpackInstallation) {
            $loader = strtolower($installation->loader);
            if (in_array($loader, self::MOD_LOADERS, true)) {
                return $this->capabilities($loader, true, $configuration);
            }
        }

        $metadata = BeaconServerMetadata::query()
            ->with('profile')
            ->where('server_id', $server->id)
            ->first();
        if ($metadata instanceof BeaconServerMetadata
            && $metadata->profile
            && $metadata->profile->egg_id === $server->egg_id) {
            return $this->capabilities(strtolower($metadata->profile->loader), false, $configuration);
        }

        return $this->capabilities($this->softwareFromEgg($server->egg->name), false, $configuration);
    }

    private function softwareFromEgg(string $name): string
    {
        return self::pluginPlatformFromEggName($name) ?? match (strtolower(trim($name))) {
            'vanilla minecraft' => 'vanilla',
            'paper', 'paper + geyser + floodgate' => 'paper',
            'forge minecraft' => 'forge',
            'fabric' => 'fabric',
            'neoforge', 'neoforge minecraft' => 'neoforge',
            'quilt', 'quilt minecraft' => 'quilt',
            'curseforge generic' => 'curseforge',
            default => 'unknown',
        };
    }

    public static function pluginPlatformFromEggName(string $name): ?string
    {
        return match (strtolower(trim($name))) {
            'paper', 'paper + geyser + floodgate', 'purpur' => 'paper',
            'spigot' => 'spigot',
            'bukkit', 'craftbukkit' => 'bukkit',
            default => null,
        };
    }

    private function capabilities(string $software, bool $managedModpack = false, bool $configuration = false): array
    {
        $name = match ($software) {
            'bedrock' => 'Vanilla Bedrock',
            'vanilla' => 'Minecraft Vanilla',
            'paper' => 'Paper',
            'spigot' => 'Spigot',
            'bukkit' => 'Bukkit',
            'forge' => 'Forge',
            'fabric' => 'Fabric',
            'neoforge' => 'NeoForge',
            'quilt' => 'Quilt',
            'curseforge' => 'Minecraft CurseForge',
            'modrinth' => 'Minecraft Modrinth',
            default => 'Unsupported software',
        };
        $plugins = in_array($software, ['paper', 'spigot', 'bukkit'], true);
        $mods = $software === 'forge';
        $modpacks = $managedModpack || $software === 'curseforge';
        $modpackLoaders = $modpacks ? self::MOD_LOADERS : [];
        $modpackProviders = $modpacks ? ['curseforge'] : [];

        $modUnavailableReason = match ($software) {
            'curseforge' => "This server is currently using {$name}. Install a Forge modpack before installing mods.",
            default => "This server is currently using {$name}. Switch to Forge before installing mods.",
        };

        return [
            'software' => $software,
            'name' => $name,
            'plugins' => $plugins,
            'mods' => $mods,
            'modpacks' => $modpacks,
            'configuration' => $configuration,
            'modpack_loaders' => $modpackLoaders,
            'modpack_providers' => $modpackProviders,
            'plugin_unavailable_reason' => $plugins
                ? null
                : "This server is currently using {$name}. Plugins require a Bukkit/Paper-compatible server.",
            'mod_unavailable_reason' => $mods ? null : $modUnavailableReason,
            'modpack_unavailable_reason' => $modpacks
                ? null
                : "This server is currently using {$name}. Modpacks require the CurseForge Generic Egg.",
        ];
    }
}
