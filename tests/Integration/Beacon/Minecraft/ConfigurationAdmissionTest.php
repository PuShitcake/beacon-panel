<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\VanillaConfigurationService;
use Pterodactyl\Beacon\Minecraft\MinecraftConfigurationAdapter;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeContentRuntimeResolver;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationReadRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconConfigurationController;

/** Admission and controller wiring on isolated SQLite; no shared database or Wings. */
class ConfigurationAdmissionTest extends TestCase
{
    private $events;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'configuration_admission');
        config()->set('database.connections.configuration_admission', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        config()->set('beacon.modpacks.nest.curseforge_egg', 'CurseForge Generic');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        foreach (['nests' => ['name'], 'eggs' => ['name', 'nest_id'], 'servers' => ['egg_id'],
            'beacon_modpack_installations' => ['server_id', 'loader'], 'beacon_server_metadata' => ['server_id', 'profile_id']] as $table => $columns) {
            Schema::create($table, function (Blueprint $schema) use ($columns) {
                $schema->increments('id');
                foreach ($columns as $column) {
                    $schema->text($column)->nullable();
                }
            });
        }
        $native = \Mockery::mock(NativeCurseForgeContentRuntimeResolver::class);
        $native->allows('resolve')->andReturnNull();
        $this->app->instance(NativeCurseForgeContentRuntimeResolver::class, $native);
        // Unsupported calls must fail before looking at power, files or provider state.
        foreach ([DaemonFileRepository::class, DaemonServerRepository::class] as $class) {
            $repository = \Mockery::mock($class);
            $repository->shouldNotReceive('setServer');
            $this->app->instance($class, $repository);
        }
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('configuration_admission');
        parent::tearDown();
    }

    #[DataProvider('eggs')]
    public function testNavigationAdmissionAgreesWithTheExactConfigurationAdapter(string $egg, string $nest, bool $supported): void
    {
        $server = $this->server($egg, $nest);
        $this->assertSame($supported, MinecraftConfigurationAdapter::serviceClass($server) !== null);
        $this->assertSame($supported, app(ServerSoftwareCapabilityService::class)->resolve($server)['configuration']);
    }

    #[DataProvider('unsupportedEggs')]
    public function testUnsupportedReadFailsClosedBeforeAnyWingsAccess(string $egg, string $nest): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('only available for supported Beacon Minecraft servers');
        app(BeaconConfigurationController::class)->show(new BeaconConfigurationReadRequest(), $this->server($egg, $nest));
    }

    #[DataProvider('unsupportedEggs')]
    public function testUnsupportedWriteCannotReachTheLegacyWriter(string $egg, string $nest): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('only available for supported Beacon Minecraft servers');
        app(BeaconConfigurationController::class)->update(new BeaconConfigurationWriteRequest(), $this->server($egg, $nest));
    }

    #[DataProvider('supportedEggs')]
    public function testSupportedReadUsesItsDedicatedAdapter(string $egg): void
    {
        $server = $this->server($egg, 'Beacon');
        $class = MinecraftConfigurationAdapter::serviceClass($server);
        $adapter = \Mockery::mock($class);
        $adapter->expects('read')->with($server)->andReturn(['state' => 'waiting', 'editable' => false]);
        $this->app->instance($class, $adapter);
        $response = app(BeaconConfigurationController::class)->show(new BeaconConfigurationReadRequest(), $server);
        $this->assertSame(['state' => 'waiting', 'editable' => false], $response->getData(true)['data']);
        $this->assertInstanceOf(VanillaConfigurationService::class, $adapter);
    }

    #[DataProvider('supportedEggs')]
    public function testSupportedWritesReachTheSameDedicatedAdapterWithBothHashes(string $egg): void
    {
        $server = $this->server($egg, 'Beacon');
        $class = MinecraftConfigurationAdapter::serviceClass($server);
        $adapter = \Mockery::mock($class);
        // The adapter owns the transaction, power checks and backup lifecycle. Stop before
        // auditing so this wiring test cannot fabricate a successful configuration write.
        $adapter->expects('update')->with($server, str_repeat('a', 64), ['max-players' => 12], str_repeat('b', 64), null)
            ->andThrow(new ConflictHttpException('Dedicated adapter reached.'));
        $this->app->instance($class, $adapter);
        $request = new BeaconConfigurationWriteRequest();
        $request->merge(['hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64), 'properties' => ['max-players' => 12]]);
        $request->setValidator(app('validator')->make($request->all(), $request->rules()));
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Dedicated adapter reached.');
        app(BeaconConfigurationController::class)->update($request, $server);
    }

    public function testUnsupportedConfigurationDoesNotDisableUnrelatedPluginOrModCapabilities(): void
    {
        $caps = app(ServerSoftwareCapabilityService::class)->resolve($this->server('Purpur', 'Beacon'));
        $this->assertTrue($caps['plugins']);
        $this->assertFalse($caps['configuration']);
        $caps = app(ServerSoftwareCapabilityService::class)->resolve($this->server('Forge Minecraft', 'Beacon'));
        $this->assertTrue($caps['mods']);
        $this->assertFalse($caps['configuration']);
    }

    public function testNativeCurseForgeLoaderDoesNotChangeConfigurationAdmission(): void
    {
        $server = $this->server('CurseForge Generic', 'Beacon');
        $native = \Mockery::mock(NativeCurseForgeContentRuntimeResolver::class);
        $native->expects('resolve')->with($server)->andReturn(['runtime' => ['loader' => 'forge']]);
        $caps = (new ServerSoftwareCapabilityService($native))->resolve($server);
        $this->assertTrue($caps['mods']);
        $this->assertTrue($caps['configuration']);
    }

    public function testStaleManagedLoaderCannotEnableConfigurationForAnotherEgg(): void
    {
        $server = $this->server('Unreviewed Minecraft', 'Beacon');
        DB::table('beacon_modpack_installations')->insert(['server_id' => $server->id, 'loader' => 'forge']);
        $caps = app(ServerSoftwareCapabilityService::class)->resolve($server);
        $this->assertSame('forge', $caps['software']);
        $this->assertFalse($caps['configuration']);
    }

    public function testRuntimeHashIsRequiredEvenWhenTheEggHasNoAdapter(): void
    {
        $request = new BeaconConfigurationWriteRequest();
        $request->setRouteResolver(function () {
            $route = (new \Illuminate\Routing\Route('PUT', '/', fn () => null))->bind(\Illuminate\Http\Request::create('/'));
            $route->setParameter('server', $this->server('Purpur', 'Beacon'));

            return $route;
        });
        $rules = $request->rules();
        $this->assertContains('required', $rules['runtime_hash']);
        $this->assertFalse(app('validator')->make(['hash' => str_repeat('a', 64), 'properties' => ['motd' => 'test']], $rules)->passes());
    }

    public static function supportedEggs(): array
    {
        return array_map(fn ($name) => [$name], ['Vanilla Minecraft', 'Paper', 'Paper + Geyser + Floodgate', 'Fabric', 'CurseForge Generic', 'Vanilla Bedrock']);
    }

    public static function unsupportedEggs(): array
    {
        return [['paper', 'Beacon'], [' Paper ', 'Beacon'], ['Purpur', 'Beacon'], ['Spigot', 'Beacon'], ['Bukkit', 'Beacon'],
            ['Forge Minecraft', 'Beacon'], ['NeoForge', 'Beacon'], ['Quilt', 'Beacon'], ['Vanilla Bedrock Custom', 'Beacon'],
            ['Unreviewed Minecraft', 'Beacon'], ['Paper', 'Minecraft'], ['Vanilla Bedrock', 'Minecraft']];
    }

    public static function eggs(): array
    {
        return [...array_map(fn ($row) => [$row[0], 'Beacon', true], self::supportedEggs()),
            ...array_map(fn ($row) => [...$row, false], self::unsupportedEggs())];
    }

    private function server(string $egg, string $nest): Server
    {
        $nestId = DB::table('nests')->insertGetId(['name' => $nest]);
        $eggId = DB::table('eggs')->insertGetId(['name' => $egg, 'nest_id' => $nestId]);
        $id = DB::table('servers')->insertGetId(['egg_id' => $eggId]);

        return Server::withoutEagerLoads()->findOrFail($id);
    }
}
