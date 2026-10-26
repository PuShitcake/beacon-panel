<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;

/** Exact Egg adapters only; do not promote other Paper-compatible or modpack Eggs. */
class JavaConfigurationAdapter
{
    public static function serviceClass(Server $server): ?string
    {
        foreach ([VanillaConfigurationService::class, PaperConfigurationService::class, PaperGeyserConfigurationService::class, FabricConfigurationService::class, CurseForgeConfigurationService::class] as $service) {
            if ($service::supports($server)) {
                return $service;
            }
        }

        return null;
    }
}
