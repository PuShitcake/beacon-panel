<?php

namespace Pterodactyl\Beacon\Content;

use Illuminate\Support\ServiceProvider;
use Pterodactyl\Beacon\Content\Providers\ContentProvider;
use Pterodactyl\Beacon\Content\Providers\ModrinthProvider;
use Pterodactyl\Beacon\Modpacks\Providers\FtbModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\TechnicModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\ModpackProviderRegistry;
use Pterodactyl\Beacon\Modpacks\Providers\ModrinthModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\AtLauncherModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\CurseForgeModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\VoidsWrathModpackProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentProvider::class, ModrinthProvider::class);
        $this->app->singleton(ModpackProviderRegistry::class, fn ($app) => new ModpackProviderRegistry([
            $app->make(ModrinthModpackProvider::class),
            $app->make(CurseForgeModpackProvider::class),
            $app->make(FtbModpackProvider::class),
            $app->make(AtLauncherModpackProvider::class),
            $app->make(TechnicModpackProvider::class),
            $app->make(VoidsWrathModpackProvider::class),
        ]));
    }
}
