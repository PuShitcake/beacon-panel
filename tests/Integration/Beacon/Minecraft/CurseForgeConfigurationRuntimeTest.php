<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\CurseForgePropertyPolicy;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\ModpackMinecraftVersionPolicy;
use Pterodactyl\Beacon\Minecraft\CurseForgeConfigurationService;
use Pterodactyl\Beacon\Modpacks\CurseForgeInstalledRuntimeService;
use Pterodactyl\Beacon\Minecraft\CurseForgeConfigurationRuntimeReader;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeContentRuntimeResolver;

/** Real native provenance/manifest validation, isolated SQLite only; no application DB/Wings. */
class CurseForgeConfigurationRuntimeTest extends TestCase
{
    private $events;
    private CurseForgeConfigurationRuntimeReader $runtime;
    private VanillaFileReader $reader;
    private string $manifest;
    private int $reads = 0;
    private ?\Closure $duringRead = null;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'curseforge_configuration_test');
        config()->set('database.connections.curseforge_configuration_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        config()->set('beacon.modpacks.nest.curseforge_egg', 'CurseForge Generic');
        config()->set('beacon.modpacks.java_images', [8 => 'approved:java_8', 17 => 'approved:java_17', 21 => 'approved:java_21', 25 => 'approved:java_25']);
        foreach ([
            'nests' => ['name'], 'eggs' => ['name', 'nest_id', 'startup'],
            'egg_variables' => ['egg_id', 'env_variable'], 'server_variables' => ['server_id', 'variable_id', 'variable_value'],
            'server_transfers' => ['server_id', 'successful'],
            'allocations' => ['server_id'],
            'beacon_server_metadata' => ['server_id', 'profile_id'],
            'servers' => ['uuid', 'egg_id', 'skip_scripts', 'status', 'installed_at', 'startup', 'image'],
            'beacon_operations' => ['server_id', 'type', 'status', 'finished_at', 'payload', 'result'],
            'beacon_modpack_installations' => ['server_id', 'operation_id', 'status', 'provider', 'project_id', 'version_id', 'installed_at', 'minecraft_version', 'loader', 'loader_version', 'manifest'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $schema) use ($columns) {
                $schema->increments('id');
                foreach ($columns as $column) {
                    $schema->text($column)->nullable();
                }
                $schema->timestamps();
            });
        }
        DB::table('nests')->insert(['id' => 1, 'name' => 'Beacon']);
        DB::table('eggs')->insert(['id' => 1, 'nest_id' => 1, 'name' => 'CurseForge Generic', 'startup' => 'java original-egg-command']);
        DB::table('servers')->insert(['id' => 1, 'egg_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111',
            'skip_scripts' => false, 'installed_at' => now(), 'image' => 'approved:java_17', 'startup' => 'java original-egg-command']);
        DB::table('egg_variables')->insert([['id' => 1, 'egg_id' => 1, 'env_variable' => 'PROJECT_ID'], ['id' => 2, 'egg_id' => 1, 'env_variable' => 'VERSION']]);
        DB::table('server_variables')->insert([['server_id' => 1, 'variable_id' => 1, 'variable_value' => '123'], ['server_id' => 1, 'variable_id' => 2, 'variable_value' => 'latest']]);
        $this->seedRuntime();
        $this->reader = \Mockery::mock(VanillaFileReader::class);
        $this->reader->allows('read')->andReturnUsing(function ($server, $path, $limit) {
            ++$this->reads;
            $this->assertSame('/client.manifest.json', $path);
            $this->assertSame(1048576, $limit);
            if ($this->duringRead) {
                ($this->duringRead)();
            }

            return $this->manifest;
        });
        $this->runtime = new CurseForgeConfigurationRuntimeReader($this->reader, new NativeCurseForgeContentRuntimeResolver(), new CurseForgeInstalledRuntimeService(new ModpackMinecraftVersionPolicy()));
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('curseforge_configuration_test');
        \Mockery::close();
        parent::tearDown();
    }

    private function server(): Server
    {
        return Server::query()->findOrFail(1);
    }

    private function seedRuntime(string $game = '1.20.1', string $loader = 'forge', string $loaderVersion = '47.0.0'): void
    {
        $this->manifest = json_encode(['minecraft' => ['version' => $game, 'modLoaders' => [['id' => $loader . '-' . $loaderVersion, 'primary' => true]]], 'private' => 'PRIVATE_MANIFEST_FIXTURE']);
        DB::table('beacon_operations')->delete();
        DB::table('beacon_operations')->insert(['id' => 1, 'server_id' => 1, 'type' => 'native_modpack.runtime', 'status' => 'succeeded',
            'finished_at' => now(), 'payload' => json_encode(['phase' => 'verify', 'egg_id' => 1,
                'selection_hash' => hash('sha256', json_encode(['PROJECT_ID' => '123', 'VERSION' => 'latest']))]),
            'result' => json_encode(['minecraft_version' => $game, 'loader' => $loader, 'loader_version' => $loaderVersion,
                'java_version' => (new ModpackMinecraftVersionPolicy())->javaVersion($game), 'runtime_source' => 'curseforge_client_manifest'])]);
    }

    private function inspect(): ?array
    {
        return $this->runtime->inspect($this->server(), '/client.manifest.json', strlen($this->manifest));
    }

    public static function runtimes(): array
    {
        return [['1.16.5', 'forge', '36.2.22', 8], ['1.20.1', 'forge', '47.0.0', 17], ['1.21.1', 'neoforge', '21.1.1', 21],
            ['1.20.1', 'fabric', '0.16.0', 17], ['1.20.1', 'quilt', '0.26.0', 17], ['26.3', 'fabric', '0.20.1', 25]];
    }

    #[DataProvider('runtimes')]
    public function testActualManifestAndNativeProvenanceAgreeWithoutLegacyMetadata(string $game, string $loader, string $loaderVersion, int $java): void
    {
        $this->seedRuntime($game, $loader, $loaderVersion);
        DB::table('servers')->update(['image' => 'approved:java_' . $java]);
        $this->assertNull($this->runtime->unavailableReason($this->server()));
        $resolved = $this->inspect();
        $this->assertSame($game, $resolved['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $resolved['identity']);
        $this->assertStringNotContainsString('PRIVATE_MANIFEST_FIXTURE', json_encode($resolved));
        $this->assertSame(0, DB::table('beacon_modpack_installations')->count());
    }

    public static function unavailableStates(): array
    {
        return [
            ['servers', ['skip_scripts' => true]], ['servers', ['installed_at' => null]], ['servers', ['status' => 'installing']],
            ['servers', ['status' => 'install_failed']], ['servers', ['startup' => 'java custom --world other']], ['servers', ['image' => 'unverified:java_21']],
            ['nests', ['name' => 'Minecraft']], ['eggs', ['name' => 'CurseForge Generic Custom']],
            ['beacon_operations', ['status' => 'pending']], ['beacon_operations', ['status' => 'running']], ['beacon_operations', ['status' => 'failed']],
            ['beacon_operations', ['finished_at' => null]], ['beacon_operations', ['payload' => '{"phase":"wait_install","egg_id":1}']],
            ['beacon_operations', ['result' => '{"minecraft_version":"1.20.1","loader":"forge","loader_version":"47.0.0","java_version":17,"runtime_source":"guess"}']],
        ];
    }

    #[DataProvider('unavailableStates')]
    public function testUnverifiedStatesNeverReadWingsOrGuessRuntime(string $table, array $change): void
    {
        DB::table($table)->update($change);
        $this->assertNotNull($this->runtime->unavailableReason($this->server()));
        $this->assertNull($this->inspect());
        $this->assertSame(0, $this->reads);
    }

    public function testStartupSelectionDriftCannotUseOldSuccess(): void
    {
        DB::table('server_variables')->where('variable_id', 2)->update(['variable_value' => '999']);
        $this->assertNull($this->inspect());
        $this->assertSame(0, $this->reads);
    }

    public function testActiveTransferAndMissingImageMappingCannotReadRuntime(): void
    {
        DB::table('server_transfers')->insert(['server_id' => 1]);
        $this->assertNull($this->inspect());
        DB::table('server_transfers')->delete();
        config()->set('beacon.modpacks.java_images.17', null);
        $this->assertNull($this->inspect());
        $this->assertSame(0, $this->reads);
    }

    public function testTwoMissingImageValuesAreNotProofOfAVerifiedJavaRuntime(): void
    {
        config()->set('beacon.modpacks.java_images.17', null);
        DB::table('servers')->update(['image' => null]);
        $this->assertNull($this->inspect());
        $this->assertSame(0, $this->reads);
    }

    public function testNewFailedNativeHistoryCannotFallBackToSuccessfulLegacyRecord(): void
    {
        $this->legacy();
        DB::table('beacon_operations')->insert(['id' => 2, 'server_id' => 1, 'type' => 'native_modpack.runtime', 'status' => 'failed']);
        $this->assertNull($this->inspect());
        $this->assertSame(0, $this->reads);
    }

    public function testRuntimeCannotChangeDuringManifestRead(): void
    {
        $this->duringRead = fn () => DB::table('beacon_operations')->update(['status' => 'failed']);
        $this->assertNull($this->inspect());
    }

    public function testSelectionCannotChangeDuringManifestRead(): void
    {
        $this->duringRead = fn () => DB::table('server_variables')->where('variable_id', 1)->update(['variable_value' => '456']);
        $this->assertNull($this->inspect());
    }

    public function testDifferentInstallOfSameGameAndChangedManifestBothInvalidateIdentity(): void
    {
        $first = $this->inspect();
        DB::table('beacon_operations')->update(['id' => 2]);
        $second = $this->inspect();
        $this->assertNotSame($first['identity'], $second['identity']);
        $this->manifest .= ' ';
        $third = $this->inspect();
        $this->assertNotSame($second['identity'], $third['identity']);
    }

    public static function badManifests(): array
    {
        return [['invalid-json'], ['{}'], ['{"minecraft":{"version":"1.21.1","modLoaders":[{"id":"forge-47.0.0","primary":true}]}}'],
            ['{"minecraft":{"version":"1.20.1","modLoaders":[{"id":"fabric-0.16.0","primary":true}]}}'],
            ['{"minecraft":{"version":"1.20.1","modLoaders":[{"id":"forge-47.1.0","primary":true}]}}'],
            ['{"minecraft":{"version":"1.20.1","modLoaders":[{"id":"forge-47.0.0","primary":true},{"id":"fabric-0.16.0","primary":true}]}}']];
    }

    #[DataProvider('badManifests')]
    public function testInvalidOrMismatchedManifestCannotSelectRules(string $manifest): void
    {
        $this->manifest = $manifest;
        $this->assertNull($this->inspect());
    }

    public function testPathSizeAndTransportFailuresAreNotSilentlyReplacedByGuesses(): void
    {
        $this->assertNull($this->runtime->inspect($this->server(), '/wrong.json', 10));
        $this->assertNull($this->runtime->inspect($this->server(), '/client.manifest.json', 1048577));
        $this->assertSame(0, $this->reads);
        $this->assertNull($this->runtime->inspect($this->server(), '/client.manifest.json', 10));
    }

    private function legacy(): void
    {
        $result = json_decode(DB::table('beacon_operations')->where('id', 1)->value('result'), true);
        DB::table('beacon_modpack_installations')->insert(['id' => 1, 'server_id' => 1, 'operation_id' => 1, 'provider' => 'curseforge', 'status' => 'installed',
            'installed_at' => now(), 'project_id' => '123', 'version_id' => 'latest', 'minecraft_version' => '1.20.1',
            'loader' => 'forge', 'loader_version' => '47.0.0', 'manifest' => json_encode($result)]);
    }

    public function testVerifiedLegacyRecordRetainsItsIndependentPathOnlyWithoutNativeHistory(): void
    {
        $this->legacy();
        DB::table('beacon_operations')->delete();
        $this->assertSame('1.20.1', $this->inspect()['version']);
        DB::table('beacon_modpack_installations')->update(['loader_version' => '47.1.0']);
        $this->assertNull($this->inspect());
    }

    public function testMalformedLegacyManifestAndChangedLegacySelectionAreNotAdopted(): void
    {
        $this->legacy();
        DB::table('beacon_operations')->delete();
        DB::table('server_variables')->where('variable_id', 1)->update(['variable_value' => '456']);
        $this->assertNull($this->inspect());
        DB::table('server_variables')->where('variable_id', 1)->update(['variable_value' => '123']);
        DB::table('beacon_modpack_installations')->update(['manifest' => '"invalid"']);
        $this->assertNull($this->inspect());
        $this->assertSame(0, $this->reads);
    }

    public function testNoInstalledPackRendersOrdinaryWaitingWithoutAnyWingsIo(): void
    {
        DB::table('beacon_operations')->delete();
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->shouldNotReceive('setServer');
        $service = new CurseForgeConfigurationService($files, \Mockery::mock(DaemonServerRepository::class), $this->reader, $this->runtime, new CurseForgePropertyPolicy());
        $read = $service->read($this->server());
        $this->assertSame('waiting', $read['state']);
        $this->assertFalse($read['editable']);
        $this->assertSame([], (array) $read['properties']);
        $this->assertStringContainsString('Install a modpack from Startup', $read['unavailable_reason']);
        $this->assertSame(0, $this->reads);
    }

    public function testControllerRoutesNativeInstallsAndMissingPacksToDedicatedAdapterNotLegacyWriter(): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->shouldNotReceive('setServer');
        $adapter = \Mockery::mock(CurseForgeConfigurationService::class);
        $adapter->expects('read')->twice()->andReturn(['state' => 'waiting', 'editable' => false]);
        $this->app->instance(CurseForgeConfigurationService::class, $adapter);
        $controller = app(\Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconConfigurationController::class);
        foreach ([false, true] as $withoutPack) {
            if ($withoutPack) {
                DB::table('beacon_operations')->delete();
            }
            $response = $controller->show(new \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationReadRequest(), $this->server());
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('waiting', $response->getData(true)['data']['state']);
        }
    }
}
