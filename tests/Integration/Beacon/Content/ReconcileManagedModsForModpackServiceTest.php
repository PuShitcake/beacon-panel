<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\ReconcileManagedModsForModpackService;

class ReconcileManagedModsForModpackServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testItPreservesBeaconManagedModsBeforeANonDestructiveModpackInstall(): void
    {
        $server = $this->createServerModel();
        $installation = BeaconContentInstallation::query()->create([
            'server_id' => $server->id,
            'operation_id' => null,
            'provider' => 'modrinth',
            'project_id' => 'mod-one',
            'project_name' => 'Mod One',
            'version_id' => 'version-one',
            'version_number' => '1.0.0',
            'icon_url' => null,
            'project_type' => 'mod',
            'loader' => 'forge',
            'game_version' => '1.20.1',
            'destination' => 'mods',
            'filename' => 'mod-one.jar',
            'sha512' => str_repeat('a', 128),
            'size' => 1024,
            'dependencies' => [],
            'is_dependency' => false,
            'disabled_path' => null,
            'status' => 'installed',
        ]);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('getDirectory')->with('/.beacon')->andReturn([]);
        $files->expects('getDirectory')->with('/mods')->andReturn([['name' => 'mod-one.jar']]);
        $files->expects('createDirectory')->with('mod-addons-operation-one', '/.beacon');
        $files->expects('renameFiles')->with('/', [[
            'from' => 'mods/mod-one.jar',
            'to' => '.beacon/mod-addons-operation-one/mod-one.jar',
        ]]);

        $preserved = app(ReconcileManagedModsForModpackService::class)
            ->preserve($server, $files, 'operation-one', 'install');

        $this->assertSame($installation->id, $preserved[0]['id']);
        $this->assertSame('forge', $preserved[0]['loader']);
    }

    public function testItDisablesAnAddonWhenTheUpdatedModpackChangesRuntime(): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('getDirectory')->with('/')->andReturn([['name' => 'mods']]);
        $files->expects('getDirectory')->with('/mods')->andReturn([]);
        $files->expects('renameFiles')->with('/', \Mockery::on(function (array $moves) {
            return str_starts_with($moves[0]['to'], 'mods/.beacon-disabled-10-operation-two-mod-one.jar.disabled');
        }));
        $files->expects('deleteFiles')->with('/.beacon', ['mod-addons-operation-two']);

        $changes = app(ReconcileManagedModsForModpackService::class)->restore(
            $files,
            'operation-two',
            ['minecraft_version' => '1.21.1', 'loader' => 'neoforge'],
            [['id' => 10, 'filename' => 'mod-one.jar', 'game_version' => '1.20.1', 'loader' => 'forge']],
        );

        $this->assertSame('disabled', $changes[0]['status']);
        $this->assertStringEndsWith('.disabled', $changes[0]['disabled_path']);
    }
}
