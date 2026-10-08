<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;

class ServerSoftwareCapabilityServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testVanillaKeepsModAndModpackInstallersUnavailable(): void
    {
        $server = $this->serverForEgg('Vanilla Minecraft');

        $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($server);

        $this->assertSame('vanilla', $capabilities['software']);
        $this->assertFalse($capabilities['plugins']);
        $this->assertFalse($capabilities['mods']);
        $this->assertFalse($capabilities['modpacks']);
        $this->assertSame(
            'This server is currently using Minecraft Vanilla. Switch to Forge before installing mods.',
            $capabilities['mod_unavailable_reason']
        );
    }

    public function testPaperOnlyEnablesThePluginInstaller(): void
    {
        $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($this->serverForEgg('Paper'));

        $this->assertSame('paper', $capabilities['software']);
        $this->assertTrue($capabilities['plugins']);
        $this->assertFalse($capabilities['mods']);
        $this->assertFalse($capabilities['modpacks']);
    }

    public function testOnlyForgeEnablesIndividualModsAndModpacksRequireCurseForgeGeneric(): void
    {
        foreach ([
            'Forge Minecraft' => 'forge',
            'Fabric' => 'fabric',
            'NeoForge' => 'neoforge',
            'Quilt' => 'quilt',
        ] as $egg => $loader) {
            $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($this->serverForEgg($egg));

            $this->assertSame($loader, $capabilities['software']);
            $this->assertFalse($capabilities['plugins']);
            $this->assertSame($loader === 'forge', $capabilities['mods']);
            $this->assertFalse($capabilities['modpacks']);
            $this->assertSame([], $capabilities['modpack_loaders']);
        }
    }

    public function testCurseForgeGenericEnablesTheModpackPicker(): void
    {
        $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($this->serverForEgg('CurseForge Generic'));

        $this->assertSame('curseforge', $capabilities['software']);
        $this->assertFalse($capabilities['plugins']);
        $this->assertFalse($capabilities['mods']);
        $this->assertTrue($capabilities['modpacks']);
        $this->assertSame(['forge', 'fabric', 'neoforge', 'quilt'], $capabilities['modpack_loaders']);
        $this->assertSame(['curseforge'], $capabilities['modpack_providers']);
        $this->assertSame(
            'This server is currently using Minecraft CurseForge. Install a Forge modpack before installing mods.',
            $capabilities['mod_unavailable_reason']
        );
    }

    public function testManagedModpackLoaderOverridesTheOriginalSoftware(): void
    {
        $server = $this->serverForEgg('Vanilla Minecraft');
        BeaconModpackInstallation::query()->create([
            'server_id' => $server->id,
            'operation_id' => null,
            'provider' => 'modrinth',
            'project_id' => 'fabric-pack',
            'project_slug' => 'fabric-pack',
            'name' => 'Fabric Pack',
            'icon_url' => null,
            'version_id' => 'version-one',
            'version_name' => '1.0.0',
            'minecraft_version' => '1.21.1',
            'loader' => 'fabric',
            'loader_version' => '0.16.0',
            'status' => 'installed',
            'safety_backup_uuid' => 'a66e4ca6-93e3-4661-85d1-162203f46c70',
            'original_runtime' => [],
            'manifest' => [],
            'installed_at' => now(),
        ]);

        $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($server);

        $this->assertSame('fabric', $capabilities['software']);
        $this->assertFalse($capabilities['plugins']);
        $this->assertFalse($capabilities['mods']);
        $this->assertTrue($capabilities['modpacks']);
        $this->assertSame(['curseforge'], $capabilities['modpack_providers']);
    }

    private function serverForEgg(string $name): \Pterodactyl\Models\Server
    {
        $nest = Nest::query()->where('name', 'Beacon')->first() ?? Nest::factory()->create(['name' => 'Beacon']);
        $egg = Egg::factory()->create(['nest_id' => $nest->id, 'name' => $name]);

        return $this->createServerModel(['egg_id' => $egg->id]);
    }
}
