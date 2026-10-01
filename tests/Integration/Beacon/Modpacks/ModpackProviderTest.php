<?php

namespace Pterodactyl\Tests\Integration\Beacon\Modpacks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Beacon\Modpacks\Providers\FtbModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\TechnicModpackProvider;
use Pterodactyl\Beacon\Modpacks\Providers\ModrinthModpackProvider;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;
use Pterodactyl\Beacon\Modpacks\Providers\AtLauncherModpackProvider;

class ModpackProviderTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function testAtLauncherUnwrapsPublicApiResponses(): void
    {
        Http::fake([
            'https://api.atlauncher.com/v1/pack/TestPack' => Http::response(['data' => [
                'name' => 'Test Pack',
                'versions' => [[
                    'version' => '1.0.0',
                    'minecraft' => '1.20.1',
                    'published' => 1700000000,
                ]],
            ]]),
            'https://api.atlauncher.com/v1/pack/TestPack/1.0.0' => Http::response(['data' => [
                'version' => '1.0.0',
                'minecraftVersion' => '1.20.1',
                'serverZipURL' => 'https://cdn.atlauncher.com/test-server.zip',
            ]]),
        ]);

        $provider = app(AtLauncherModpackProvider::class);
        $this->assertSame('1.20.1', $provider->versions('TestPack')[0]['minecraft_version']);
        $release = $provider->release('TestPack', '1.0.0');
        $this->assertSame('https://cdn.atlauncher.com/test-server.zip', $release['archive']['url']);
        $this->assertNull($release['loader']);
    }

    public function testFtbReadsGameAndLoaderTargets(): void
    {
        Http::fake([
            'https://api.feed-the-beast.com/v1/modpacks/public/modpack/130' => Http::response([
                'versions' => [[
                    'id' => 100001,
                    'name' => '1.0.0',
                    'released' => 1700000000,
                    'targets' => [
                        ['name' => 'minecraft', 'type' => 'game', 'version' => '1.21.1'],
                        ['name' => 'neoforge', 'type' => 'modloader', 'version' => '21.1.1'],
                    ],
                ]],
            ]),
        ]);

        $version = app(FtbModpackProvider::class)->versions('130')[0];
        $this->assertSame('1.21.1', $version['minecraft_version']);
        $this->assertSame('neoforge', $version['loader']);
        $this->assertSame('21.1.1', $version['loader_version']);
        $this->assertTrue($version['installable']);
    }

    public function testModrinthReleaseUsesVerifiedProjectMetadata(): void
    {
        Http::fake([
            'https://api.modrinth.com/v2/version/version-one' => Http::response([
                'project_id' => 'project-one',
                'name' => 'Release One',
                'version_number' => '1.0.0',
                'game_versions' => ['1.20.1'],
                'loaders' => ['forge'],
                'dependencies' => [],
                'files' => [[
                    'primary' => true,
                    'filename' => 'pack.mrpack',
                    'url' => 'https://cdn.modrinth.com/data/project-one/versions/version-one/pack.mrpack',
                    'size' => 1024,
                    'hashes' => ['sha512' => str_repeat('a', 128)],
                ]],
            ]),
            'https://api.modrinth.com/v2/project/project-one' => Http::response([
                'project_type' => 'modpack',
                'title' => 'Project One',
                'slug' => 'project-one',
                'icon_url' => 'https://cdn.modrinth.com/data/project-one/icon.png',
            ]),
        ]);

        $release = app(ModrinthModpackProvider::class)->release('project-one', 'version-one');
        $this->assertSame('Project One', $release['name']);
        $this->assertSame('project-one', $release['project_slug']);
        $this->assertSame(str_repeat('a', 128), $release['archive']['sha512']);
    }

    public function testModrinthOnlyReturnsImagesFromApprovedCdn(): void
    {
        Http::fake([
            'https://api.modrinth.com/v2/search*' => Http::response([
                'total_hits' => 2,
                'hits' => [
                    [
                        'project_id' => 'trusted-project',
                        'title' => 'Trusted Project',
                        'icon_url' => 'https://cdn.modrinth.com/data/trusted-project/icon.png',
                    ],
                    [
                        'project_id' => 'untrusted-project',
                        'title' => 'Untrusted Project',
                        'icon_url' => 'https://tracking.example/icon.png',
                    ],
                ],
            ]),
        ]);

        $projects = app(ModrinthModpackProvider::class)->search('', 1, 10)['projects'];
        $this->assertSame('https://cdn.modrinth.com/data/trusted-project/icon.png', $projects[0]['icon_url']);
        $this->assertNull($projects[1]['icon_url']);
    }

    public function testAtLauncherRejectsServerArchivesOutsideApprovedCdn(): void
    {
        Http::fake([
            'https://api.atlauncher.com/v1/pack/TestPack' => Http::response(['data' => [
                'name' => 'Test Pack',
                'versions' => [],
            ]]),
            'https://api.atlauncher.com/v1/pack/TestPack/1.0.0' => Http::response(['data' => [
                'version' => '1.0.0',
                'minecraftVersion' => '1.20.1',
                'serverZipURL' => 'https://untrusted.example/test-server.zip',
            ]]),
        ]);

        $this->expectException(ModpackProviderException::class);
        $this->expectExceptionMessage('outside the approved CDN');
        app(AtLauncherModpackProvider::class)->release('TestPack', '1.0.0');
    }

    public function testTechnicUsesPublicSearchAndServerPackUrl(): void
    {
        config()->set('beacon.modpacks.providers.technic.build', 'multimc');
        Http::fake([
            'https://api.technicpack.net/search*' => Http::response(['modpacks' => [[
                'slug' => 'test-pack',
                'name' => 'Test Pack',
                'iconUrl' => 'https://cdn.technicpack.net/icon.png',
                'url' => 'https://www.technicpack.net/modpack/test-pack.1',
            ]]]),
            'https://api.technicpack.net/modpack/test-pack*' => Http::response([
                'displayName' => 'Test Pack',
                'version' => '1.0.0',
                'minecraft' => '1.20.1',
                'description' => 'Forge server pack',
                'serverPackUrl' => 'https://servers.technicpack.net/test-pack.zip',
                'icon' => ['url' => 'https://cdn.technicpack.net/icon.png'],
            ]),
        ]);

        $provider = app(TechnicModpackProvider::class);
        $this->assertSame('test-pack', $provider->search('test', 1, 20)['projects'][0]['id']);
        $release = $provider->release('test-pack', '1.0.0');
        $this->assertSame('https://servers.technicpack.net/test-pack.zip', $release['archive']['url']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'build=multimc'));
    }

    public function testTechnicRejectsClientOnlyArchives(): void
    {
        Http::fake([
            'https://api.technicpack.net/modpack/client-pack*' => Http::response([
                'displayName' => 'Client Pack',
                'version' => '1.0.0',
                'minecraft' => '1.20.1',
                'url' => 'https://example.com/client.zip',
            ]),
        ]);

        $this->expectException(ModpackProviderException::class);
        $this->expectExceptionMessage('server archive');
        app(TechnicModpackProvider::class)->release('client-pack', '1.0.0');
    }
}
