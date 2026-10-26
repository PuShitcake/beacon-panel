<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;

/** Bedrock has its own parser and policy; Java routing remains unchanged. */
class MinecraftConfigurationAdapter
{
    public static function serviceClass(Server $server): ?string
    {
        return BedrockConfigurationService::supports($server)
            ? BedrockConfigurationService::class : JavaConfigurationAdapter::serviceClass($server);
    }
}
