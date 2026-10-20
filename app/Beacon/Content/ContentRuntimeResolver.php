<?php

namespace Pterodactyl\Beacon\Content;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Models\BeaconModpackInstallation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeContentRuntimeResolver;

class ContentRuntimeResolver
{
    private const MOD_LOADERS = ['fabric', 'forge', 'neoforge', 'quilt'];

    public function __construct(private NativeCurseForgeContentRuntimeResolver $native)
    {
    }

    public function resolve(Server $server, bool $required = true): ?array
    {
        $native = $this->native->resolve($server);
        if ($native !== null) {
            return $native['runtime'] ?? $this->unavailable($required, $native['reason']);
        }

        $modpack = BeaconModpackInstallation::query()
            ->where('server_id', $server->id)
            ->whereIn('status', ['installed', 'updating', 'reinstalling'])
            ->first();

        if ($modpack instanceof BeaconModpackInstallation) {
            $loader = strtolower($modpack->loader);
            if (!in_array($loader, self::MOD_LOADERS, true)) {
                return $this->unavailable($required, 'The installed modpack does not use a supported mod loader.');
            }

            return [
                'source' => 'modpack',
                'project_type' => 'mod',
                'loader' => $loader,
                'loader_version' => $modpack->loader_version ?? '',
                'game_version' => $modpack->minecraft_version,
                'destination' => 'mods',
                'profile' => [
                    'code' => $loader,
                    'name' => ucfirst($loader),
                    'loader' => $loader,
                    'content_directory' => 'mods',
                ],
            ];
        }

        $metadata = BeaconServerMetadata::query()
            ->with(['profile', 'version'])
            ->where('server_id', $server->id)
            ->first();
        if (!$metadata instanceof BeaconServerMetadata || !$metadata->profile->content_directory) {
            return $this->unavailable($required, 'Managed content is not enabled for this server.');
        }

        $destination = $metadata->profile->content_directory;
        if (!in_array($destination, ['plugins', 'mods'], true)) {
            return $this->unavailable($required, 'The managed content destination is not supported.');
        }

        return [
            'source' => 'catalog',
            'project_type' => $destination === 'plugins' ? 'plugin' : 'mod',
            'loader' => strtolower($metadata->profile->loader),
            'loader_version' => $metadata->version->loader_version,
            'game_version' => $metadata->version->version,
            'destination' => $destination,
            'profile' => $metadata->profile->only(['code', 'name', 'loader', 'content_directory']),
        ];
    }

    private function unavailable(bool $required, string $message): ?array
    {
        if ($required) {
            throw new ConflictHttpException($message);
        }

        return null;
    }

    public function assertModOperationRuntime(Server $server, array $expected): void
    {
        $server->loadMissing('egg.nest');
        $runtime = $this->resolve($server, false);
        if ($server->egg?->nest?->name !== config('beacon.modpacks.nest.name', 'Beacon')
            || $server->status !== null || $server->transfer()->exists()
            || !$runtime || $runtime['loader'] !== 'forge' || $runtime['destination'] !== 'mods'
            || $runtime['project_type'] !== 'mod' || $runtime !== $expected) {
            throw new ConflictHttpException('The Minecraft runtime changed or is unavailable. No mod files were changed. Reload the Mod Installer and try again.');
        }
    }
}
