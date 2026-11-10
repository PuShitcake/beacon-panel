<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceConfiguration;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconMinecraftServiceRequest;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconMinecraftServiceController;

/** Isolated SQLite context reads only. Never runs migrations or contacts Wings. */
class BedrockConsoleContextTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'bedrock_console_test');
        config()->set('database.connections.bedrock_console_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('beacon_minecraft_services', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('server_id');
        });
        Schema::create('beacon_operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('server_id');
            $table->string('type');
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('bedrock_console_test');
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedServers')]
    public function testContextGuidesOnlyBeaconBedrockWithoutInspectingFiles(string $eggName, string $nestName, bool $consoleOnly): void
    {
        $egg = (new Egg())->forceFill(['name' => $eggName])->setRelation('nest', (new Nest())->forceFill(['name' => $nestName]));
        $server = (new Server())->forceFill(['id' => 1])->setRelation('egg', $egg);
        $configuration = \Mockery::mock(MinecraftServiceConfiguration::class);
        $configuration->shouldNotReceive('inspect');
        $operations = \Mockery::mock(StartContentOperationService::class);
        $operations->shouldNotReceive('start');
        $controller = new BeaconMinecraftServiceController($configuration, $operations);
        $data = $controller->context(new BeaconMinecraftServiceRequest(), $server)->getData(true)['data'];
        $this->assertSame($consoleOnly, $data['console_only']);
        $this->assertFalse($data['supported']);
        $this->assertFalse($data['rcon']['available']);
        $this->assertFalse($data['query']['available']);
        $this->assertNull($data['active_operation']);
        $this->assertSame(0, DB::table('beacon_minecraft_services')->count());
        $this->assertSame(0, DB::table('beacon_operations')->count());
    }

    public static function unsupportedServers(): array
    {
        return [['Vanilla Bedrock', 'Beacon', true], ['Vanilla Bedrock', 'Minecraft', false], ['Unknown', 'Beacon', false]];
    }

    public function testVelocitySetupCoordinatesComeFromTheServerAdapter(): void
    {
        $egg = (new Egg())->forceFill(['name' => 'Velocity'])->setRelation('nest', (new Nest())->forceFill(['name' => 'Beacon']));
        $server = (new Server())->forceFill(['id' => 1])->setRelation('egg', $egg);
        $configuration = \Mockery::mock(MinecraftServiceConfiguration::class);
        $configuration->expects('inspect')->with($server)->andReturn([]);
        $controller = new BeaconMinecraftServiceController($configuration, \Mockery::mock(StartContentOperationService::class));
        $data = $controller->context(new BeaconMinecraftServiceRequest(), $server)->getData(true)['data'];
        $this->assertTrue($data['supported']);
        $this->assertFalse($data['console_only']);
        $this->assertSame([
            'name' => 'Velocircon',
            'version' => \Pterodactyl\Beacon\Minecraft\VelocityRconConfiguration::VERSION,
            'provider' => 'modrinth',
            'project_id' => \Pterodactyl\Beacon\Minecraft\VelocityRconConfiguration::PROJECT,
        ], $data['required_plugin']);
    }
}
