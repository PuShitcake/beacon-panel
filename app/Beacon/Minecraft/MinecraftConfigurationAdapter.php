<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;

/** Each file format has an explicit, reviewed adapter; no name-based fallback. */
class MinecraftConfigurationAdapter
{
    public static function serviceClass(Server $server): ?string
    {
        if (VelocityConfigurationService::supports($server)) {
            return VelocityConfigurationService::class;
        }

        return BedrockConfigurationService::supports($server)
            ? BedrockConfigurationService::class : JavaConfigurationAdapter::serviceClass($server);
    }
}
