<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CurseForgeConfigurationService extends VanillaConfigurationService
{
    protected const RUNTIME = 'CurseForge modpack';

    public function __construct(DaemonFileRepository $files, DaemonServerRepository $power, VanillaFileReader $reader, CurseForgeConfigurationRuntimeReader $versions, CurseForgePropertyPolicy $policy)
    {
        parent::__construct($files, $power, $reader, $versions, $policy);
    }

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon')
            && $server->egg?->name === config('beacon.modpacks.nest.curseforge_egg', 'CurseForge Generic');
    }

    protected function runtimeWaitingReason(Server $server): ?string
    {
        return $this->versions->unavailableReason($server);
    }

    protected function runtimeFile(Server $server): ?string
    {
        return CurseForgeConfigurationRuntimeReader::FILE;
    }

    protected function runtimeFileLimit(): int
    {
        return CurseForgeConfigurationRuntimeReader::LIMIT;
    }

    protected function identifyRuntime(Server $server, string $path, int $size): ?array
    {
        return $this->versions->inspect($server, $path, $size);
    }

    protected function runtimeCacheEnabled(): bool
    {
        return false;
    }

    protected function versionSource(): string
    {
        return 'verified_curseforge_manifest';
    }

    protected function assertRuntimeCurrent(Server $server, array $response): void
    {
        $current = $this->read($server);
        if (!$current['editable'] || !hash_equals($response['runtime_hash'], $current['runtime_hash'])
            || !hash_equals($response['hash'], $current['hash'])) {
            throw new ConflictHttpException('The installed modpack or server.properties changed before saving. Reload Configuration.');
        }
    }

    protected function notes(string $version): array
    {
        return array_merge(parent::notes($version), [
            'Minecraft and loader identity come from the verified installed CurseForge manifest and matching installation record, not Project ID, File ID, latest or the Docker Java version.',
            'This page edits existing common server.properties fields only. It does not edit mod configuration or certify every mod/loader binary; pack settings and mods can override game behavior.',
            'Keep matching mods and custom dimensions with uploaded worlds. World selection never converts, moves or deletes map data; seed and generator changes require a new unused folder and a full safety backup.',
            'After manually replacing runtime files, reinstall through Startup and wait for verification before using Configuration. Custom launch commands and unverified or changed selections remain unavailable.',
        ]);
    }

    protected function unrecognizedReason(): string
    {
        return 'The installed CurseForge manifest does not match the verified modpack runtime. Review the installation and reinstall from Startup; Configuration will not guess from IDs or latest.';
    }
}
