<?php

namespace Pterodactyl\Beacon\Content;

use Illuminate\Support\ServiceProvider;
use Pterodactyl\Beacon\Content\Providers\ContentProvider;
use Pterodactyl\Beacon\Content\Providers\ModrinthProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentProvider::class, ModrinthProvider::class);
    }
}
