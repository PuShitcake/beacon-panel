<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;

interface MinecraftConfigurationService
{
    public function read(Server $server): array;

    public function update(Server $server, string $hash, array $changes, string $runtimeHash, ?string $worldBackupUuid = null): array;
}
