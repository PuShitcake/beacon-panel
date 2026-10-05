<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Content\ContentRuntimeResolver;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ContentRuntimeResolverTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testManagedModpackRuntimeTakesPrecedenceForModInstallation(): void
    {
        $server = $this->createServerModel();
        BeaconModpackInstallation::query()->create([
            'server_id' => $server->id,
            'operation_id' => null,
            'provider' => 'modrinth',
            'project_id' => 'pack-one',
            'project_slug' => 'pack-one',
            'name' => 'Pack One',
            'icon_url' => null,
            'version_id' => 'pack-version',
            'version_name' => '1.0.0',
            'minecraft_version' => '1.20.1',
            'loader' => 'forge',
            'loader_version' => '47.3.0',
            'status' => 'installed',
            'safety_backup_uuid' => '278f58c7-a1f8-4b03-a296-d4468f8fd451',
            'original_runtime' => [],
            'manifest' => [],
            'installed_at' => now(),
        ]);

        $runtime = app(ContentRuntimeResolver::class)->resolve($server);

        $this->assertSame('modpack', $runtime['source']);
        $this->assertSame('mod', $runtime['project_type']);
        $this->assertSame('mods', $runtime['destination']);
        $this->assertSame('forge', $runtime['loader']);
        $this->assertSame('1.20.1', $runtime['game_version']);
    }

    public function testUnsupportedModpackLoaderDoesNotEnableTheModInstaller(): void
    {
        $server = $this->createServerModel();
        BeaconModpackInstallation::query()->create([
            'server_id' => $server->id,
            'operation_id' => null,
            'provider' => 'modrinth',
            'project_id' => 'vanilla-pack',
            'project_slug' => null,
            'name' => 'Vanilla Pack',
            'icon_url' => null,
            'version_id' => 'pack-version',
            'version_name' => '1.0.0',
            'minecraft_version' => '1.21.1',
            'loader' => 'vanilla',
            'loader_version' => null,
            'status' => 'installed',
            'safety_backup_uuid' => '01ef092c-afbf-4cb1-989b-d41d5bdb4570',
            'original_runtime' => [],
            'manifest' => [],
            'installed_at' => now(),
        ]);

        $this->assertNull(app(ContentRuntimeResolver::class)->resolve($server, false));
    }
}
