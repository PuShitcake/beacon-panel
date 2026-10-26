<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/** Fabric's installed Minecraft JAR supplies the game version, never its loader version. */
class FabricConfigurationService extends VanillaConfigurationService
{
    protected const RUNTIME = 'Fabric';

    public function __construct(DaemonFileRepository $files, DaemonServerRepository $power, VanillaFileReader $reader, FabricJarVersionReader $versions, FabricPropertyPolicy $policy)
    {
        parent::__construct($files, $power, $reader, $versions, $policy);
    }

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon') && $server->egg?->name === 'Fabric';
    }

    protected function identifyRuntime(Server $server, string $path, int $size): ?array
    {
        return $this->versions->inspect($server, $path, $size);
    }

    protected function runtimeCacheEnabled(): bool
    {
        // The launch properties and game JAR can change without touching the launcher JAR.
        return false;
    }

    protected function notes(string $version): array
    {
        return array_merge(parent::notes($version), [
            'Minecraft version is verified from the installed game JAR referenced by the Fabric launcher, not from Fabric Loader or Startup/latest.',
            'Mods and data packs can change game behavior. This page edits server.properties only; it does not edit mods or their configuration, install dependencies or guarantee mod compatibility.',
            'Keep the matching mods and custom dimension data with uploaded worlds. World selection never converts, moves or deletes map data, and a new seed does not regenerate an existing map.',
        ]);
    }

    protected function unrecognizedReason(): string
    {
        return 'Configuration requires a supported installer-generated Fabric launcher, a safe explicit root serverJar in fabric-server-launcher.properties and a verified official Minecraft game JAR. Custom, shaded, automatic-download or overridden launchers and snapshots remain unavailable; Beacon never guesses from Startup/latest.';
    }
}
