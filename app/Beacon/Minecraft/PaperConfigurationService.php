<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/** Same lossless, offline, backed-up writer as Vanilla; Paper has its own runtime verifier. */
class PaperConfigurationService extends VanillaConfigurationService
{
    protected const RUNTIME = 'Paper';

    public function __construct(DaemonFileRepository $files, DaemonServerRepository $power, VanillaFileReader $reader, PaperJarVersionReader $versions, PaperPropertyPolicy $policy)
    {
        parent::__construct($files, $power, $reader, $versions, $policy);
    }

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon') && $server->egg?->name === 'Paper';
    }

    protected function identifyRuntime(Server $server, string $path, int $size): ?array
    {
        return $this->versions->inspect($server, $path, $size);
    }

    protected function worldSafety(): VanillaWorldSafety
    {
        return app(PaperWorldSafety::class);
    }

    protected function notes(string $version): array
    {
        return array_merge(parent::notes($version), [
            'Paper or plugin configuration can override these settings. This page edits server.properties only, not paper*.yml, bukkit.yml or spigot.yml.',
            'Paper dimension storage varies by release. Keep the matching Nether and End data with uploaded maps; Beacon never moves, converts or deletes world folders.',
        ]);
    }

    protected function unrecognizedReason(): string
    {
        return 'This JAR is not a verified supported official Paper build (Minecraft 1.16.5 or newer). Custom JARs and snapshots remain unavailable; Beacon never guesses from Startup or latest.';
    }
}
