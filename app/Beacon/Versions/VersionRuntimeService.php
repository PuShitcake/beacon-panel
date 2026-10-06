<?php

namespace Pterodactyl\Beacon\Versions;

use Pterodactyl\Models\Server;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;

class VersionRuntimeService
{
    public function __construct(
        private ModpackRuntimeService $runtime,
        private ServerSoftwareCapabilityService $capabilities,
    ) {
    }

    public function current(Server $server): array
    {
        $capabilities = $this->capabilities->resolve($server);
        $variables = $server->variables()->get()->mapWithKeys(fn ($variable) => [
            strtoupper($variable->env_variable) => (string) ($variable->server_value ?? $variable->default_value ?? ''),
        ]);
        $software = $capabilities['software'];
        $version = match ($software) {
            'vanilla' => $variables->get('VANILLA_VERSION'),
            'paper' => $variables->get('MINECRAFT_VERSION'),
            'forge' => $variables->get('MC_VERSION'),
            default => null,
        };
        $build = match ($software) {
            'paper' => $variables->get('BUILD_NUMBER'),
            'forge' => $variables->get('FORGE_VERSION') ?: $variables->get('BUILD_TYPE'),
            default => null,
        };

        return [
            'software' => $software,
            'software_name' => $capabilities['name'],
            'minecraft_version' => is_string($version) && $version !== '' ? $version : null,
            'build' => is_string($build) && $build !== '' ? $build : null,
            'image' => $server->image,
        ];
    }

    public function snapshot(Server $server): array
    {
        return $this->runtime->snapshot($server);
    }

    public function switch(Server $server, array $target): Server
    {
        $java = (int) ($target['java_version'] ?? 0);
        if (!is_string(config("beacon.modpacks.java_images.{$java}"))) {
            throw new \InvalidArgumentException("No trusted Java {$java} image is configured for this Minecraft version.");
        }
        $loaderVersion = $target['loader_version'] ?? null;
        if (($target['software'] ?? null) === 'forge'
            && (!is_string($loaderVersion) || trim($loaderVersion) === '')) {
            throw new \InvalidArgumentException('The selected Forge build has no trusted Forge version.');
        }

        return $this->runtime->switchToRuntime($server, [
            'loader' => $target['software'],
            'minecraft_version' => $target['minecraft_version'],
            'loader_version' => $target['loader_version'],
            'java_version' => $java,
            'build_number' => $target['build_number'],
        ]);
    }

    public function restore(Server $server, array $snapshot): Server
    {
        return $this->runtime->restore($server, $snapshot);
    }
}
