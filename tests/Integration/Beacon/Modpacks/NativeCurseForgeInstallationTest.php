<?php

namespace Pterodactyl\Tests\Integration\Beacon\Modpacks;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Events\Dispatcher;
use Pterodactyl\Exceptions\DisplayException;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Content\ContentPowerService;
use Pterodactyl\Jobs\Beacon\ProcessModOperationJob;
use Pterodactyl\Beacon\Content\ContentRuntimeResolver;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Beacon\Content\Providers\ContentProvider;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Servers\VariableValidatorService;
use Pterodactyl\Services\Deployment\FindViableNodesService;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Pterodactyl\Beacon\Modpacks\ModpackMinecraftVersionPolicy;
use Pterodactyl\Repositories\Eloquent\ServerVariableRepository;
use Pterodactyl\Services\Deployment\AllocationSelectionService;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Pterodactyl\Http\Requests\Api\Remote\InstallationDataRequest;
use Pterodactyl\Beacon\Modpacks\CurseForgeInstalledRuntimeService;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModRequest;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeInstallationService;
use Pterodactyl\Jobs\Beacon\FinalizeNativeCurseForgeInstallationJob;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconModController;
use Pterodactyl\Http\Controllers\Api\Remote\Servers\ServerInstallController;

/** Real persistence in an isolated in-memory database, never the configured Panel database. */
class NativeCurseForgeInstallationTest extends TestCase
{
    private $modelEvents;
    private NativeCurseForgeInstallationService $lifecycle;
    private DaemonServerRepository $wings;
    private DaemonFileRepository $files;
    private DaemonPowerRepository $power;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'native_runtime_test');
        config()->set('database.connections.native_runtime_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->modelEvents = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        config()->set('beacon.modpacks.providers.curseforge.api_key', null);
        config()->set('beacon.modpacks.nest.curseforge_egg', 'CurseForge Generic');
        config()->set('beacon.modpacks.java_images', [8 => 'ghcr.io/pterodactyl/yolks:java_8', 17 => 'ghcr.io/pterodactyl/yolks:java_17', 21 => 'ghcr.io/pterodactyl/yolks:java_21']);
        config()->set('pterodactyl.email.send_install_notification', false);
        config()->set('pterodactyl.email.send_reinstall_notification', false);
        Bus::fake();
        $this->tables();
        $this->wings = \Mockery::mock(DaemonServerRepository::class);
        $this->files = \Mockery::mock(DaemonFileRepository::class);
        $this->power = \Mockery::mock(DaemonPowerRepository::class);
        $this->lifecycle = new NativeCurseForgeInstallationService(new ModpackRuntimeService($this->wings));
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->modelEvents);
        DB::purge('native_runtime_test');
        \Mockery::close();
        parent::tearDown();
    }

    private function tables(): void
    {
        foreach ([
            'users' => ['root_admin'],
            'nests' => ['name'], 'eggs' => ['name', 'nest_id', 'startup', 'docker_images'],
            'allocations' => ['server_id'], 'server_transfers' => ['server_id', 'successful'],
            'egg_variables' => ['egg_id', 'env_variable'],
            'beacon_modpack_installations' => ['server_id', 'status', 'loader', 'loader_version', 'minecraft_version'],
            'beacon_server_metadata' => ['server_id', 'profile_id', 'version_id'],
            'beacon_content_installations' => ['server_id', 'operation_id', 'provider', 'project_id', 'project_name', 'version_id', 'version_number', 'icon_url', 'project_type', 'loader', 'game_version', 'destination', 'filename', 'sha512', 'size', 'dependencies', 'is_dependency', 'disabled_path', 'status'],
            'server_variables' => ['server_id', 'variable_id', 'variable_value'],
            'servers' => ['uuid', 'owner_id', 'egg_id', 'nest_id', 'node_id', 'allocation_id', 'skip_scripts', 'status', 'image', 'startup', 'memory', 'installed_at'],
            'beacon_operations' => ['uuid', 'server_id', 'api_key_id', 'user_id', 'actor_key', 'type', 'status', 'correlation_id', 'idempotency_key', 'request_hash', 'payload', 'result', 'started_at', 'finished_at', 'error_code', 'error_message'],
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
        DB::table('eggs')->insert(['id' => 1, 'nest_id' => 1, 'name' => 'CurseForge Generic', 'startup' => 'egg-startup', 'docker_images' => '{}']);
        DB::table('egg_variables')->insert([
            ['id' => 1, 'egg_id' => 1, 'env_variable' => 'PROJECT_ID'],
            ['id' => 2, 'egg_id' => 1, 'env_variable' => 'VERSION'],
        ]);
        DB::table('servers')->insert([
            'id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'owner_id' => 1,
            'egg_id' => 1, 'nest_id' => 1, 'node_id' => 2, 'skip_scripts' => false, 'status' => null,
            'image' => 'ghcr.io/pterodactyl/yolks:java_17j9', 'startup' => 'unchanged-startup', 'memory' => 6144,
        ]);
        DB::table('server_variables')->insert([
            ['server_id' => 1, 'variable_id' => 1, 'variable_value' => '123'],
            ['server_id' => 1, 'variable_id' => 2, 'variable_value' => 'latest'],
        ]);
    }

    private function server(): Server
    {
        return Server::query()->findOrFail(1);
    }

    private function process(BeaconOperation $operation): bool
    {
        return $this->lifecycle->process($operation->refresh(), new CurseForgeInstalledRuntimeService(new ModpackMinecraftVersionPolicy()), $this->files, $this->wings, $this->power);
    }

    private function installedManifest(string $minecraft = '1.20.1'): void
    {
        $manifest = json_encode(['minecraft' => ['version' => $minecraft, 'modLoaders' => [['id' => 'forge-47.0.0', 'primary' => true]]]]);
        $this->wings->allows('setServer')->andReturnSelf();
        $this->wings->allows('getDetails')->andReturn(['state' => 'offline']);
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->expects('getDirectory')->with('/')->andReturn([['name' => 'client.manifest.json', 'file' => true, 'symlink' => false, 'size' => strlen($manifest)]]);
        $this->files->expects('getContent')->with('/client.manifest.json', 1048576)->andReturn($manifest);
    }

    public function testNativeCallbackRecordsVerificationWithoutWingsOrQueueIoOrReleasingTheServer(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false, true);
        $this->assertTrue($this->lifecycle->installed($this->server(), true, false));
        $this->assertSame('verify', $operation->refresh()->payload['phase']);
        $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
        $this->assertNull($this->server()->installed_at);
        Bus::assertNothingDispatched();
        $this->assertFalse($this->server()->isInstalled());
    }

    public function testSuccessfulNativeInstallChangesOnlyTheImageThenStartsAfterSync(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false, true);
        $before = $this->server()->only(['egg_id', 'nest_id', 'startup', 'memory', 'skip_scripts']);
        $variables = DB::table('server_variables')->get()->toJson();
        $this->lifecycle->installed($this->server(), true, false);
        $this->installedManifest();
        $this->wings->expects('sync')->andReturnUsing(function () {
            $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
            $this->assertSame('ghcr.io/pterodactyl/yolks:java_17', $this->server()->image);
        });
        $this->power->expects('setServer')->andReturnSelf();
        $this->power->expects('send')->with('start')->andReturnUsing(function () {
            $this->assertNull($this->server()->status);
        });
        $this->assertTrue($this->process($operation));
        $this->assertSame($before, $this->server()->only(array_keys($before)));
        $this->assertSame($variables, DB::table('server_variables')->get()->toJson());
        $this->assertSame(BeaconOperation::STATUS_SUCCEEDED, $operation->refresh()->status);
        $this->assertNotNull($this->server()->installed_at);
        $this->assertTrue($this->process($operation)); // Replayed job does not sync or start twice.
        $this->lifecycle->installed($this->server(), true, false); // Callback replay is also harmless.
        $this->assertNull($this->server()->status);
    }

    public function testNativeReinstallQueuesPreparationInsteadOfBlockingTheHttpRequest(): void
    {
        $service = new ReinstallServerService(DB::connection(), $this->wings, $this->lifecycle);
        $server = $service->handle($this->server());
        $this->assertSame(Server::STATUS_INSTALLING, $server->status);
        $this->assertSame('prepare', BeaconOperation::query()->firstOrFail()->payload['phase']);
        Bus::assertDispatched(FinalizeNativeCurseForgeInstallationJob::class);
    }

    public function testAdminCreateDefersWingsAutomaticStartUntilTheRuntimeWorkerFinishes(): void
    {
        $server = $this->server();
        $repository = \Mockery::mock(ServerRepository::class);
        $repository->expects('isUniqueUuidCombo')->andReturn(true);
        $repository->expects('create')->andReturn($server);
        $variables = \Mockery::mock(VariableValidatorService::class);
        $variables->expects('setUserLevel')->andReturnSelf();
        $variables->expects('handle')->with(1, ['PROJECT_ID' => '123', 'VERSION' => 'latest'])->andReturn(collect());
        $this->wings->expects('setServer')->with($server)->andReturnSelf();
        $this->wings->expects('create')->with(false)->andReturnUsing(function () {
            $operation = BeaconOperation::query()->firstOrFail();
            $this->assertTrue($operation->payload['start_requested']);
            $this->assertSame('wait_install', $operation->payload['phase']);
            $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
        });
        $service = new ServerCreationService(
            \Mockery::mock(AllocationSelectionService::class),
            DB::connection(),
            $this->wings,
            \Mockery::mock(FindViableNodesService::class),
            $repository,
            \Mockery::mock(ServerDeletionService::class),
            \Mockery::mock(ServerVariableRepository::class),
            $variables,
            $this->lifecycle,
        );
        $service->handle([
            'egg_id' => 1, 'nest_id' => 1, 'node_id' => 2, 'allocation_id' => 1,
            'environment' => ['PROJECT_ID' => '123', 'VERSION' => 'latest'], 'start_on_completion' => true,
        ]);
        Bus::assertDispatched(FinalizeNativeCurseForgeInstallationJob::class);
    }

    private function creationService(): ServerCreationService
    {
        return new ServerCreationService(
            \Mockery::mock(AllocationSelectionService::class),
            DB::connection(),
            $this->wings,
            \Mockery::mock(FindViableNodesService::class),
            \Mockery::mock(ServerRepository::class),
            \Mockery::mock(ServerDeletionService::class),
            \Mockery::mock(ServerVariableRepository::class),
            \Mockery::mock(VariableValidatorService::class),
            $this->lifecycle,
        );
    }

    private function prepareCreation(array $data): array
    {
        return (new \ReflectionMethod(ServerCreationService::class, 'prepareCurseForgeSelection'))
            ->invoke($this->creationService(), $data);
    }

    public function testDefaultOpenJ9SelectionIsReplacedBeforePersistingOrCallingWings(): void
    {
        $egg = DB::table('eggs')->first();
        $data = [
            'egg_id' => 1, 'image' => 'ghcr.io/pterodactyl/yolks:java_21j9',
            'startup' => 'unchanged-startup', 'memory' => 6144,
            'environment' => ['PROJECT_ID' => '123', 'VERSION' => 'latest'],
            'start_on_completion' => true,
        ];
        $this->assertSame(array_replace($data, ['image' => 'ghcr.io/pterodactyl/yolks:java_21']), $this->prepareCreation($data));
        $this->assertEquals($egg, DB::table('eggs')->first());
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17j9', $this->server()->image);
        $this->assertSame(0, BeaconOperation::query()->count());
        Bus::assertNothingDispatched();
    }

    public function testCreationUsesTheConfiguredTrustedBootstrapImageRatherThanRequestOrEggValues(): void
    {
        config()->set('beacon.modpacks.java_images.21', 'registry.example/java:java_21');
        $data = ['egg_id' => 1, 'image' => 'request.example/custom:java_17', 'environment' => ['PROJECT_ID' => 'zip', 'VERSION' => 'latest']];
        $this->assertSame('registry.example/java:java_21', $this->prepareCreation($data)['image']);
    }

    public static function invalidBootstrapImages(): array
    {
        return [[null], [''], [false], [[]], ['ghcr.io/pterodactyl/yolks:java_21j9'], ['ghcr.io/pterodactyl/yolks:java_17']];
    }

    #[DataProvider('invalidBootstrapImages')]
    public function testInvalidBootstrapPolicyFailsBeforeCreatingAnything(mixed $image): void
    {
        config()->set('beacon.modpacks.java_images.21', $image);
        try {
            $this->prepareCreation(['egg_id' => 1, 'image' => 'selected:java_21j9', 'environment' => ['PROJECT_ID' => '123']]);
            $this->fail('Missing or invalid trusted image must not fall back to the submitted OpenJ9 image.');
        } catch (DisplayException $exception) {
            $this->assertSame('Configure a trusted Java 21 HotSpot image before creating a native CurseForge server.', $exception->getMessage());
        }
        $this->assertSame(1, Server::query()->count());
        $this->assertSame(0, BeaconOperation::query()->count());
        Bus::assertNothingDispatched();
    }

    public static function nonNativeCreationCases(): array
    {
        return [['Minecraft', 'CurseForge Generic', false], ['Beacon', 'Paper', false], ['Beacon', 'Vanilla Minecraft', false], ['Beacon', 'CurseForge Generic', true]];
    }

    #[DataProvider('nonNativeCreationCases')]
    public function testOtherEggsNestsAndSkippedInstallationsKeepTheirSelectedImage(string $nest, string $egg, bool $skip): void
    {
        DB::table('nests')->update(['name' => $nest]);
        DB::table('eggs')->update(['name' => $egg]);
        config()->set('beacon.modpacks.java_images.21', null);
        $data = ['egg_id' => 1, 'image' => 'selected.example/image:original', 'skip_scripts' => $skip, 'environment' => []];
        $this->assertSame($data, $this->prepareCreation($data));
    }

    public static function verifiedGameRuntimes(): array
    {
        return [['1.16.5', 8], ['1.20.1', 17], ['1.21.1', 21]];
    }

    #[DataProvider('verifiedGameRuntimes')]
    public function testAdminCreatePersistsBootstrapImageBeforeWingsAndFinalizesToVerifiedJava(string $minecraft, int $java): void
    {
        $repository = \Mockery::mock(ServerRepository::class);
        $repository->expects('isUniqueUuidCombo')->andReturn(true);
        $repository->expects('create')->with(\Mockery::on(fn ($row) => $row['image'] === 'ghcr.io/pterodactyl/yolks:java_21'))
            ->andReturnUsing(function ($row) {
                DB::table('servers')->where('id', 1)->update(['image' => $row['image']]);

                return $this->server();
            });
        $variables = \Mockery::mock(VariableValidatorService::class);
        $variables->expects('setUserLevel')->andReturnSelf();
        $variables->expects('handle')->with(1, ['PROJECT_ID' => '123', 'VERSION' => 'latest'])->andReturn(collect());
        $this->wings->expects('setServer')->andReturnSelf();
        $this->wings->expects('create')->with(false)->andReturnUsing(function () {
            $this->assertSame('ghcr.io/pterodactyl/yolks:java_21', $this->server()->image);
            $this->assertTrue(BeaconOperation::query()->firstOrFail()->payload['start_requested']);
        });
        $service = new ServerCreationService(
            \Mockery::mock(AllocationSelectionService::class),
            DB::connection(),
            $this->wings,
            \Mockery::mock(FindViableNodesService::class),
            $repository,
            \Mockery::mock(ServerDeletionService::class),
            \Mockery::mock(ServerVariableRepository::class),
            $variables,
            $this->lifecycle,
        );
        $service->handle([
            'egg_id' => 1, 'nest_id' => 1, 'node_id' => 2, 'allocation_id' => 1,
            'image' => 'ghcr.io/pterodactyl/yolks:java_21j9',
            'environment' => ['PROJECT_ID' => '123', 'VERSION' => 'latest'], 'start_on_completion' => true,
        ]);
        Bus::assertDispatched(FinalizeNativeCurseForgeInstallationJob::class);
        $operation = BeaconOperation::query()->firstOrFail();
        $this->assertTrue($this->lifecycle->installed($this->server(), true, false));
        $this->installedManifest($minecraft);
        $this->wings->allows('setServer')->andReturnSelf();
        $this->wings->expects('sync')->andReturnUsing(function () use ($java) {
            $this->assertSame('ghcr.io/pterodactyl/yolks:java_' . $java, $this->server()->image);
            $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
        });
        $this->power->expects('setServer')->andReturnSelf();
        $this->power->expects('send')->with('start')->andReturnUsing(fn () => $this->assertNull($this->server()->status));
        $this->assertTrue($this->process($operation));
        $this->assertSame($java, $operation->refresh()->result['java_version']);
        $this->assertSame('unchanged-startup', $this->server()->startup);
        $this->assertSame(6144, $this->server()->memory);
    }

    public function testNativeReinstallCanChangeJava17ToJava21WithoutChangingTheEgg(): void
    {
        $operation = $this->lifecycle->begin($this->server(), true);
        $operation->forceFill(['payload' => array_replace($operation->payload, ['phase' => 'wait_install'])])->save();
        $this->lifecycle->installed($this->server(), true, true);
        $this->installedManifest('1.21.1');
        $this->wings->expects('sync');
        $this->assertTrue($this->process($operation));
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_21', $this->server()->image);
        $this->assertSame(1, $this->server()->egg_id);
        $this->assertSame('latest', DB::table('server_variables')->where('variable_id', 2)->value('variable_value'));
    }

    public function testSuspensionDuringImageSyncIsNeverClearedOrAutomaticallyStarted(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false, true);
        $this->lifecycle->installed($this->server(), true, false);
        $this->installedManifest();
        $this->wings->expects('sync')->andReturnUsing(fn () => DB::table('servers')->update(['status' => Server::STATUS_SUSPENDED]));
        try {
            $this->process($operation);
            $this->fail('Concurrent suspension must prevent completion.');
        } catch (DisplayException $exception) {
            $this->lifecycle->fail($operation, $exception, 'test');
        }
        $this->assertSame(Server::STATUS_SUSPENDED, $this->server()->status);
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->refresh()->status);
    }

    public function testMissingFreshManifestFailsClosedInTheActualQueueJob(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false, true);
        $this->lifecycle->installed($this->server(), true, false);
        $this->wings->allows('setServer')->andReturnSelf();
        $this->wings->allows('getDetails')->andReturn(['state' => 'offline']);
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->expects('getDirectory')->with('/')->andReturn([]);
        (new FinalizeNativeCurseForgeInstallationJob($operation->id))->handle(
            $this->lifecycle,
            new CurseForgeInstalledRuntimeService(new ModpackMinecraftVersionPolicy()),
            $this->files,
            $this->wings,
            $this->power,
        );
        $this->assertSame(Server::STATUS_INSTALL_FAILED, $this->server()->status);
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->refresh()->status);
        $this->assertNull($this->server()->installed_at);
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17j9', $this->server()->image);
    }

    public function testLegacyQueuedOperationsKeepTheirExistingCallbackOwner(): void
    {
        $native = $this->lifecycle->begin($this->server(), false);
        $native->forceFill(['status' => BeaconOperation::STATUS_SUCCEEDED])->save();
        $legacy = $native->replicate();
        $legacy->forceFill(['type' => 'modpack.reinstall', 'status' => BeaconOperation::STATUS_RUNNING])->save();
        $this->assertFalse($this->lifecycle->installed($this->server(), true, true));
        Bus::assertNothingDispatched();
    }

    public function testStalledNativeInstallEventuallyFailsRatherThanPollingForever(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false);
        $operation->forceFill(['created_at' => now()->subHours(3)])->save();
        (new FinalizeNativeCurseForgeInstallationJob($operation->id))->handle(
            $this->lifecycle,
            new CurseForgeInstalledRuntimeService(new ModpackMinecraftVersionPolicy()),
            $this->files,
            $this->wings,
            $this->power,
        );
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->refresh()->status);
        $this->assertSame(Server::STATUS_INSTALL_FAILED, $this->server()->status);
    }

    public function testReinstallRemovesTheOldManifestOnlyWhenOfflineBeforeCallingWings(): void
    {
        $operation = $this->lifecycle->begin($this->server(), true);
        $this->wings->allows('setServer')->andReturnSelf();
        $this->wings->expects('getDetails')->andReturn(['state' => 'offline']);
        $this->files->expects('setServer')->andReturnSelf();
        $this->files->expects('getDirectory')->with('/')->andReturn([['name' => 'client.manifest.json', 'file' => true, 'size' => 40]]);
        $this->files->expects('deleteFiles')->with('/', ['client.manifest.json']);
        $this->wings->expects('reinstall')->andReturnUsing(function () use ($operation) {
            $this->assertSame('wait_install', $operation->refresh()->payload['phase']);
        });
        $this->assertFalse($this->process($operation));
        $this->assertTrue($this->lifecycle->installed($this->server(), true, true));
        $this->assertSame('verify', $operation->refresh()->payload['phase']);
    }

    public function testRunningServerIsStoppedGracefullyBeforeAnyManifestAccess(): void
    {
        $operation = $this->lifecycle->begin($this->server(), true);
        $this->wings->allows('setServer')->andReturnSelf();
        $this->wings->expects('getDetails')->andReturn(['state' => 'running']);
        $this->power->expects('setServer')->andReturnSelf();
        $this->power->expects('send')->with('stop');
        $this->assertFalse($this->process($operation));
        $this->assertSame('prepare', $operation->refresh()->payload['phase']);
    }

    public function testCallbackCanArriveBeforeWingsReportsOffline(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false);
        $this->lifecycle->installed($this->server(), true, false);
        $this->wings->expects('setServer')->andReturnSelf();
        $this->wings->expects('getDetails')->andReturn(['state' => 'stopping']);
        $this->assertFalse($this->process($operation));
        $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
    }

    public function testFailedEggCallbackNeverStartsOrVerifiesAndDuplicateSuccessCannotClearFailure(): void
    {
        $operation = $this->lifecycle->begin($this->server(), true);
        $operation->forceFill(['payload' => array_replace($operation->payload, ['phase' => 'wait_install'])])->save();
        $this->assertTrue($this->lifecycle->installed($this->server(), false, true));
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->refresh()->status);
        $this->assertSame(Server::STATUS_INSTALL_FAILED, $this->server()->status);
        $this->assertTrue($this->lifecycle->installed($this->server(), true, true));
        $this->assertSame(Server::STATUS_INSTALL_FAILED, $this->server()->status);
        Bus::assertNothingDispatched();
    }

    public function testSelectionChangedDuringInstallCannotApplyAnImage(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false);
        DB::table('server_variables')->where('variable_id', 1)->update(['variable_value' => '456']);
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('selection changed');
        $this->process($operation);
    }

    public function testOnlyOneNativeLifecycleCanOwnTheServer(): void
    {
        $this->lifecycle->begin($this->server(), false);
        $this->expectException(DisplayException::class);
        $this->lifecycle->begin($this->server(), true);
    }

    public function testLegacyManagedMetadataCannotBecomeStaleThroughANativePackChange(): void
    {
        DB::table('beacon_modpack_installations')->insert(['server_id' => 1]);
        try {
            $this->lifecycle->begin($this->server(), true);
            $this->fail('Managed metadata needs its separately approved migration first.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('migrate that metadata', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('beacon_modpack_installations')->count());
        $this->assertSame(0, BeaconOperation::query()->count());
        $this->assertNull($this->server()->status);
        Bus::assertNothingDispatched();
    }

    public function testQueueFailureIsPersistedWithoutRawExceptionDisclosure(): void
    {
        $operation = $this->lifecycle->begin($this->server(), false);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('PRIVATE redis credential'));
        try {
            $this->lifecycle->enqueue($operation);
            $this->fail('Dispatch should fail closed.');
        } catch (DisplayException $exception) {
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->refresh()->status);
        $this->assertSame(NativeCurseForgeInstallationService::FAILURE, $operation->error_message);
        $this->assertSame(Server::STATUS_INSTALL_FAILED, $this->server()->status);
    }

    public function testSynchronousQueuesAreRejectedBeforeStartingTheInstall(): void
    {
        config()->set('queue.default', 'sync');
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('asynchronous');
        $this->lifecycle->begin($this->server(), false);
    }

    public function testOtherNestsAndSkippedScriptsDoNotAdoptTheNativeHook(): void
    {
        DB::table('nests')->update(['name' => 'Minecraft']);
        $this->assertFalse($this->lifecycle->manages($this->server()));
        $this->assertFalse($this->lifecycle->installed($this->server(), true, false));
        DB::table('nests')->update(['name' => 'Beacon']);
        DB::table('servers')->update(['skip_scripts' => true]);
        $this->assertFalse($this->lifecycle->manages($this->server()));
    }

    public function testAnotherWingsNodeCannotTriggerRuntimeFinalization(): void
    {
        $server = $this->server()->setRelation('node', (new Node())->forceFill(['id' => 2]));
        $repository = \Mockery::mock(ServerRepository::class);
        $repository->expects('getByUuid')->with($server->uuid)->andReturn($server);
        $events = \Mockery::mock(Dispatcher::class);
        $controller = new ServerInstallController($repository, $events);
        $request = InstallationDataRequest::create('/', 'POST', ['successful' => true]);
        $request->attributes->set('node', (new Node())->forceFill(['id' => 3]));
        $this->expectException(\Pterodactyl\Exceptions\Http\HttpForbiddenException::class);
        $controller->store($request, $server->uuid, $this->lifecycle);
    }

    public function testAuthorizedCallbackDoesNotRunTheNativeCompletionOrNotificationEarly(): void
    {
        $this->lifecycle->begin($this->server(), false);
        $server = $this->server()->setRelation('node', (new Node())->forceFill(['id' => 2]));
        $repository = \Mockery::mock(ServerRepository::class);
        $repository->expects('getByUuid')->with($server->uuid)->andReturn($server);
        $controller = new ServerInstallController($repository, \Mockery::mock(Dispatcher::class));
        $request = InstallationDataRequest::create('/', 'POST', ['successful' => true, 'reinstall' => false]);
        $request->attributes->set('node', $server->node);
        $this->assertSame(204, $controller->store($request, $server->uuid, $this->lifecycle)->getStatusCode());
        $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
        $this->assertNull($this->server()->installed_at);
    }

    public function testUnrelatedEggKeepsTheOriginalNativeCallback(): void
    {
        DB::table('eggs')->update(['name' => 'Paper']);
        $server = $this->server()->setRelation('node', (new Node())->forceFill(['id' => 2]));
        $repository = \Mockery::mock(ServerRepository::class);
        $repository->expects('getByUuid')->with($server->uuid)->andReturn($server);
        $repository->expects('update')->with(1, \Mockery::on(fn ($data) => $data['status'] === null && isset($data['installed_at'])), true, true)->andReturn($server);
        $controller = new ServerInstallController($repository, \Mockery::mock(Dispatcher::class));
        $request = InstallationDataRequest::create('/', 'POST', ['successful' => true]);
        $request->attributes->set('node', $server->node);
        $this->assertSame(204, $controller->store($request, $server->uuid, $this->lifecycle)->getStatusCode());
        Bus::assertNothingDispatched();
    }

    public function testWingsRestartCannotReleasePendingRuntimeVerification(): void
    {
        $this->lifecycle->begin($this->server(), false);
        DB::table('servers')->insert(['id' => 2, 'node_id' => 2, 'status' => Server::STATUS_INSTALLING]);
        $controller = new \Pterodactyl\Http\Controllers\Api\Remote\Servers\ServerDetailsController(
            DB::connection(),
            \Mockery::mock(ServerRepository::class),
            \Mockery::mock(\Pterodactyl\Services\Servers\ServerConfigurationStructureService::class),
            \Mockery::mock(\Pterodactyl\Services\Eggs\EggConfigurationService::class),
        );
        $request = \Illuminate\Http\Request::create('/', 'POST');
        $request->attributes->set('node', (new Node())->forceFill(['id' => 2]));
        $this->assertSame(204, $controller->resetState($request)->getStatusCode());
        $this->assertSame(Server::STATUS_INSTALLING, $this->server()->status);
        $this->assertNull(DB::table('servers')->where('id', 2)->value('status'));
    }

    public function testRetiredPickerRoutesCannotCreateNewOperations(): void
    {
        $routes = app('router')->getRoutes();
        foreach ($routes as $route) {
            if (str_contains($route->uri(), '/beacon/modpacks')) {
                $this->assertEmpty(array_diff($route->methods(), ['GET', 'HEAD']));
            }
        }
        $this->assertNotNull($routes->getByName('api:beacon.modpacks.history'));
    }

    public static function invalidSelections(): array
    {
        return [[[]], [['PROJECT_ID' => '']], [['PROJECT_ID' => 'pending-selection']], [['PROJECT_ID' => '../123']], [['PROJECT_ID' => '123', 'VERSION' => 'bad']], [['PROJECT_ID' => ['123']]]];
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidNativeSelectionsCannotCreateAPlaceholder(array $selection): void
    {
        $this->expectException(DisplayException::class);
        $this->lifecycle->validateSelection($selection);
    }

    public function testNativeIdAndZipContractsArePreserved(): void
    {
        $this->assertSame(['PROJECT_ID' => '123', 'VERSION' => '456'], $this->lifecycle->validateSelection(['PROJECT_ID' => '123', 'VERSION' => '456']));
        $this->assertSame(['PROJECT_ID' => 'zip', 'VERSION' => 'latest'], $this->lifecycle->validateSelection(['PROJECT_ID' => 'zip']));
    }

    private function verifiedNativeRuntime(string $loader = 'forge'): BeaconOperation
    {
        $operation = $this->lifecycle->begin($this->server(), false);
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_SUCCEEDED,
            'payload' => array_replace($operation->payload, ['phase' => 'verify']),
            'result' => ['minecraft_version' => '1.20.1', 'loader' => $loader, 'loader_version' => '47.0.0', 'java_version' => 17, 'runtime_source' => 'curseforge_client_manifest'],
            'finished_at' => now(),
        ])->save();
        $this->server()->forceFill(['status' => null, 'installed_at' => now()])->save();

        return $operation;
    }

    public function testNativeVerifiedForgeEnablesModInstallerWithoutLegacyMetadata(): void
    {
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server(), false);
        $this->assertIsArray($runtime);
        $this->assertSame('native_modpack', $runtime['source']);
        $this->assertSame('forge', $runtime['loader']);
        $this->assertSame('1.20.1', $runtime['game_version']);
        $this->assertSame('mods', $runtime['destination']);
        $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($this->server());
        $this->assertTrue($capabilities['mods']);
        $this->assertFalse($capabilities['plugins']);
        $this->assertSame('forge', $capabilities['software']);
        $this->assertSame(0, DB::table('beacon_modpack_installations')->count());
        $this->assertSame(0, DB::table('beacon_server_metadata')->count());
    }

    public static function staleNativeRuntimeCases(): array
    {
        return array_map(fn ($case) => [$case], ['pending', 'failed', 'newer_pending', 'newer_failed', 'project_changed', 'file_changed', 'egg_changed', 'outside_beacon', 'installing', 'install_failed', 'suspended', 'skip_scripts', 'not_installed', 'transfer', 'unverified_source', 'bad_game', 'bad_loader', 'bad_java', 'bad_loader_version', 'no_finished_at', 'bad_phase']);
    }

    #[DataProvider('staleNativeRuntimeCases')]
    public function testModInstallerRejectsStaleOrUnverifiedNativeRuntime(string $case): void
    {
        $operation = $this->verifiedNativeRuntime();
        switch ($case) {
            case 'pending': case 'failed':
                $operation->forceFill(['status' => $case])->save();
                break;
            case 'newer_pending': case 'newer_failed':
                $operation->replicate(['uuid', 'idempotency_key'])->forceFill(['uuid' => 'newer-native', 'idempotency_key' => 'newer-native', 'status' => substr($case, 6)])->save();
                break;
            case 'project_changed': case 'file_changed':
                DB::table('server_variables')->where('variable_id', $case === 'project_changed' ? 1 : 2)->update(['variable_value' => '456']);
                break;
            case 'egg_changed':
                $operation->forceFill(['payload' => array_replace($operation->payload, ['egg_id' => 999])])->save();
                break;
            case 'outside_beacon':
                DB::table('nests')->where('id', 1)->update(['name' => 'Minecraft']);
                break;
            case 'installing': case 'install_failed': case 'suspended':
                $this->server()->forceFill(['status' => $case])->save();
                break;
            case 'skip_scripts':
                $this->server()->forceFill(['skip_scripts' => true])->save();
                break;
            case 'not_installed':
                $this->server()->forceFill(['installed_at' => null])->save();
                break;
            case 'transfer':
                DB::table('server_transfers')->insert(['server_id' => 1, 'successful' => null]);
                break;
            case 'no_finished_at':
                $operation->forceFill(['finished_at' => null])->save();
                break;
            case 'bad_phase':
                $operation->forceFill(['payload' => array_replace($operation->payload, ['phase' => 'wait_install'])])->save();
                break;
            default:
                [$key, $value] = match ($case) {
                    'unverified_source' => ['runtime_source', 'provider_guess'],
                    'bad_game' => ['minecraft_version', 'unknown'],
                    'bad_loader' => ['loader', 'paper'],
                    'bad_java' => ['java_version', 21],
                    'bad_loader_version' => ['loader_version', '../unsafe'],
                };
                $operation->forceFill(['result' => array_replace($operation->result, [$key => $value])])->save();
        }
        $this->assertFalse(app(ServerSoftwareCapabilityService::class)->resolve($this->server())['mods']);
        $response = app(BeaconModController::class)->context(new BeaconModRequest(), $this->server())->getData(true)['data'];
        $this->assertFalse($response['enabled']);
        $this->assertNotEmpty($response['unavailable_reason']);
        $this->assertNull($response['game_version']);
    }

    public function testNativeFailureCannotFallBackToStaleLegacyMetadata(): void
    {
        $operation = $this->verifiedNativeRuntime();
        $operation->forceFill(['status' => 'failed'])->save();
        DB::table('beacon_modpack_installations')->insert(['server_id' => 1, 'status' => 'installed', 'loader' => 'forge', 'minecraft_version' => '1.20.1']);
        $this->assertNull(app(ContentRuntimeResolver::class)->resolve($this->server(), false));
        $this->assertFalse(app(ServerSoftwareCapabilityService::class)->resolve($this->server())['mods']);
    }

    public function testLegacyForgeRuntimeStillWorksWhenThereIsNoNativeInstallHistory(): void
    {
        DB::table('beacon_modpack_installations')->insert(['server_id' => 1, 'status' => 'installed', 'loader' => 'forge', 'minecraft_version' => '1.20.1']);
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server(), false);
        $this->assertSame('modpack', $runtime['source']);
        $this->assertTrue(app(ServerSoftwareCapabilityService::class)->resolve($this->server())['mods']);
    }

    public static function nonForgeLoaders(): array
    {
        return [['fabric'], ['quilt'], ['neoforge']];
    }

    #[DataProvider('nonForgeLoaders')]
    public function testVerifiedNonForgePackDoesNotEnableForgeModInstaller(string $loader): void
    {
        $this->verifiedNativeRuntime($loader);
        $controller = app(BeaconModController::class);
        $response = $controller->context(new BeaconModRequest(), $this->server())->getData(true)['data'];
        $this->assertFalse($response['enabled']);
        $this->assertSame($loader, $response['software']);
        $this->assertStringContainsString('Switch to Forge', $response['unavailable_reason']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $controller->search(new BeaconModRequest(), $this->server());
    }

    public function testNativeForgeApiContextAndProviderReceiveTheVerifiedVersionAndCategory(): void
    {
        $this->verifiedNativeRuntime();
        config()->set('beacon.mods.enabled', true);
        $provider = \Mockery::mock(ContentProvider::class);
        $provider->expects('search')->with('', '1.20.1', 'forge', 'mod', 0, 'technology')->andReturn(['hits' => [], 'total_hits' => 0]);
        $provider->expects('versions')->with('example-mod', '1.20.1', 'forge')->andReturn([]);
        app()->instance(ContentProvider::class, $provider);
        $controller = app(BeaconModController::class);
        $request = new BeaconModRequest(['provider' => 'modrinth', 'query' => '', 'category' => 'technology']);
        $context = $controller->context($request, $this->server())->getData(true)['data'];
        $this->assertTrue($context['enabled']);
        $this->assertNull($context['unavailable_reason']);
        $this->assertSame('native_modpack', $context['source']);
        $this->assertArrayNotHasKey('selection_hash', $context);
        $this->assertSame(200, $controller->search($request, $this->server())->getStatusCode());
        $this->assertSame([], $controller->versions($request, $this->server(), 'example-mod')->getData(true)['data']);
    }

    public function testNewNativeInstallIdentityInvalidatesQueuedModEvenWithIdenticalGameAndLoader(): void
    {
        $this->verifiedNativeRuntime();
        $resolver = app(ContentRuntimeResolver::class);
        $runtime = $resolver->resolve($this->server());
        // The new pack has identical version/loader and IDs, but is a different installation.
        $this->verifiedNativeRuntime();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $resolver->assertModOperationRuntime($this->server(), $runtime);
    }

    public function testStaleQueuedModFailsBeforeAnyWingsOrFileMutation(): void
    {
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server());
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'uuid' => 'queued-mod', 'type' => 'mod.install', 'status' => 'pending', 'payload' => ['phase' => 'prepare', 'runtime' => $runtime]]);
        DB::table('server_variables')->where('variable_id', 1)->update(['variable_value' => '456']);
        (new ProcessModOperationJob($operation->id))->handle(
            \Mockery::mock(ContentPowerService::class),
            \Mockery::mock(PrepareContentMutationService::class),
            $this->files,
            app(ContentRuntimeResolver::class),
        );
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringContainsString('No mod files were changed', $operation->error_message);
    }

    public function testNativeReinstallRefusesToOverwriteManagedAddOnMods(): void
    {
        $this->verifiedNativeRuntime();
        DB::table('beacon_content_installations')->insert(['server_id' => 1, 'project_type' => 'mod', 'destination' => 'mods']);
        try {
            $this->lifecycle->begin($this->server(), true);
            $this->fail('Native reinstall must not overwrite managed mod files.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('Uninstall Beacon-managed add-on mods', $exception->getMessage());
        }
        $this->assertNull($this->server()->status);
        $this->assertSame(1, BeaconOperation::query()->count());
    }

    private function modRequest(string $method, array $input = [], string $key = 'mod-test-key'): BeaconModRequest
    {
        $request = BeaconModRequest::create('/mods', $method, $input + ['provider' => 'modrinth']);
        $request->setContainer(app());
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => 1, 'root_admin' => false]));
        $request->attributes->set('beacon_idempotency_key', $key);

        return $request;
    }

    private function modVersion(): array
    {
        return [
            'id' => 'version-1', 'project_id' => 'example-mod', 'project_name' => 'Example mod', 'name' => 'Version 1',
            'version_number' => '1.0', 'icon_url' => null, 'project_type' => 'mod', 'server_side' => 'required',
            'game_versions' => ['1.20.1'], 'loaders' => ['forge'], 'dependencies' => [],
            'file' => ['url' => 'https://cdn.modrinth.com/data/example/fixture.jar', 'filename' => 'fixture.jar', 'size' => 20, 'sha512' => str_repeat('a', 128)],
        ];
    }

    public function testNativeForgePlanAndInstallAreScopedIdempotentAndDoNotExposeDownloadUrls(): void
    {
        $this->verifiedNativeRuntime();
        config()->set('beacon.mods.enabled', true);
        $provider = \Mockery::mock(ContentProvider::class);
        $provider->expects('version')->with('version-1')->times(3)->andReturn($this->modVersion());
        app()->instance(ContentProvider::class, $provider);
        $controller = app(BeaconModController::class);
        $request = $this->modRequest('POST', ['version_id' => 'version-1']);
        $plan = $controller->plan($request, $this->server())->getData(true)['data'];
        $this->assertSame('forge', $plan['loader']);
        $this->assertSame('1.20.1', $plan['game_version']);
        $this->assertArrayNotHasKey('url', $plan['files'][0]);
        $first = $controller->install($request, $this->server())->getData(true)['data'];
        $replayed = $controller->install($request, $this->server())->getData(true)['data'];
        $this->assertSame($first['uuid'], $replayed['uuid']);
        $this->assertArrayNotHasKey('payload', $first);
        $operation = BeaconOperation::query()->where('type', 'mod.install')->firstOrFail();
        $this->assertSame('native_modpack', $operation->payload['runtime']['source']);
        $this->assertSame('forge', $operation->payload['runtime']['loader']);
        Bus::assertDispatchedTimes(ProcessModOperationJob::class, 1);
    }

    public static function modProviders(): array
    {
        return [['modrinth'], ['curseforge']];
    }

    #[DataProvider('modProviders')]
    public function testNativeForgeModInstallAndUninstallWorkersPreserveRuntimeAndReleaseReinstallGuard(string $provider): void
    {
        $diagnostics = [];
        \Illuminate\Support\Facades\Log::shouldReceive('error')->andReturnUsing(function ($message, $context) use (&$diagnostics) { $diagnostics[] = $context; });
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server());
        $projectId = $provider === 'curseforge' ? '123' : 'example-mod';
        $versionId = $provider === 'curseforge' ? '123:456' : 'version-1';
        $url = $provider === 'curseforge' ? 'https://edge.forgecdn.net/files/1/2/fixture.jar' : $this->modVersion()['file']['url'];
        $directUrl = $provider === 'curseforge' ? 'https://mediafilez.forgecdn.net/files/1/2/fixture.jar' : $url;
        Http::preventStrayRequests();
        if ($provider === 'curseforge') {
            config()->set('beacon.modpacks.providers.curseforge.enabled', true);
            config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
            Http::fake([$url => Http::response('', 302, ['Location' => $directUrl]), $directUrl => Http::response('', 200)]);
        }
        $original = $this->server()->only(['egg_id', 'image', 'startup', 'memory']);
        $operation = BeaconOperation::query()->create([
            'server_id' => 1, 'uuid' => 'mod-install-fixture', 'type' => 'mod.install', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'provider' => $provider, 'action' => 'install', 'runtime' => $runtime, 'plan' => [
                'files' => [['project_id' => $projectId, 'project_name' => 'Example mod', 'version_id' => $versionId, 'version_number' => '1.0', 'name' => 'Version 1', 'url' => $url, 'filename' => 'fixture.jar', 'size' => 20, 'sha512' => $provider === 'curseforge' ? null : str_repeat('a', 128), 'sha1' => str_repeat('a', 40), 'dependency' => false]],
            ]],
        ]);
        $power = \Mockery::mock(ContentPowerService::class);
        $power->expects('prepare')->twice()->andReturn(true);
        $power->expects('beginRestart')->twice()->andReturn(false);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->twice()->andReturn(true);
        $activity = \Mockery::mock(\Pterodactyl\Services\Activity\ActivityLogService::class);
        $activity->allows('subject')->andReturnSelf();
        $activity->allows('property')->andReturnSelf();
        $activity->expects('log')->twice()->andReturn(new \Pterodactyl\Models\ActivityLog());
        \Pterodactyl\Facades\Activity::shouldReceive('event')->twice()->andReturn($activity);
        $present = false;
        $entry = ['name' => 'fixture.jar', 'size' => 20, 'file' => true, 'symlink' => false];
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->allows('getDirectory')->andReturnUsing(function (string $directory) use (&$present, $entry) {
            return match ($directory) {
                '/' => [['name' => '.beacon'], ['name' => 'mods']],
                '/.beacon' => [],
                '/mods' => $present ? [$entry] : [],
                default => [$entry],
            };
        });
        $this->files->allows('createDirectory')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('pull')->with($directUrl, '/.beacon/mod-stage-mod-install-fixture', ['filename' => 'fixture.jar', 'foreground' => true])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('renameFiles')->with('/', [['from' => '.beacon/mod-stage-mod-install-fixture/fixture.jar', 'to' => 'mods/fixture.jar']])->andReturnUsing(function () use (&$present) {
            $present = true;

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->files->allows('deleteFiles')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('getFileHashes')->with('/.beacon/mod-stage-mod-install-fixture/fixture.jar', 20)->andReturn(['size' => 20, 'sha1' => str_repeat('a', 40), 'sha512' => str_repeat('a', 128)]);
        (new ProcessModOperationJob($operation->id))->handle($power, $prepare, $this->files, app(ContentRuntimeResolver::class));
        $this->assertSame('succeeded', $operation->refresh()->status, json_encode($diagnostics));
        $this->assertTrue($present);
        $installation = \Pterodactyl\Models\BeaconContentInstallation::query()->firstOrFail();
        $this->assertSame('forge', $installation->loader);
        $this->assertSame($provider, $installation->provider);
        $this->assertSame(str_repeat('a', 128), $installation->sha512);
        $this->assertSame('1.20.1', $installation->game_version);
        $this->assertSame($original, $this->server()->only(array_keys($original)));

        config()->set('beacon.mods.enabled', true);
        $response = app(BeaconModController::class)->uninstall($this->modRequest('DELETE', [], 'uninstall-key'), $this->server(), $installation->id);
        $remove = BeaconOperation::query()->where('uuid', $response->getData(true)['data']['uuid'])->firstOrFail();
        $this->assertSame($runtime, $remove->payload['runtime']);
        $this->files->expects('renameFiles')->with('/', [['from' => 'mods/fixture.jar', 'to' => '.beacon/mod-remove-' . $remove->uuid . '/fixture.jar']])->andReturnUsing(function () use (&$present) {
            $present = false;

            return new \GuzzleHttp\Psr7\Response(204);
        });
        (new ProcessModOperationJob($remove->id))->handle($power, $prepare, $this->files, app(ContentRuntimeResolver::class));
        $this->assertSame('succeeded', $remove->refresh()->status);
        $this->assertFalse($present);
        $this->assertSame(0, DB::table('beacon_content_installations')->count());
        $this->assertSame('pending', $this->lifecycle->begin($this->server(), true)->status);
    }

    public function testNativeRuntimeIsRecheckedAfterBackupBeforeDownloadingAnyMod(): void
    {
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server());
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'uuid' => 'backup-race', 'type' => 'mod.install', 'status' => 'pending', 'payload' => ['phase' => 'prepare', 'runtime' => $runtime]]);
        $power = \Mockery::mock(ContentPowerService::class);
        $power->expects('prepare')->andReturn(true);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->andReturnUsing(function () {
            DB::table('server_variables')->where('variable_id', 2)->update(['variable_value' => '456']);

            return true;
        });
        (new ProcessModOperationJob($operation->id))->handle($power, $prepare, $this->files, app(ContentRuntimeResolver::class));
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringContainsString('No mod files were changed', $operation->error_message);
    }

    public static function managedModActions(): array
    {
        return [['update', 'modrinth'], ['reinstall', 'modrinth'], ['update', 'curseforge'], ['reinstall', 'curseforge']];
    }

    #[DataProvider('managedModActions')]
    public function testNativeForgeModUpdateAndReinstallKeepTheVerifiedRuntime(string $action, string $providerKey): void
    {
        $this->verifiedNativeRuntime();
        config()->set('beacon.mods.enabled', true);
        $id = DB::table('beacon_content_installations')->insertGetId([
            'server_id' => 1, 'provider' => $providerKey, 'project_type' => 'mod', 'destination' => 'mods', 'project_id' => 'example-mod',
            'version_id' => 'version-1', 'filename' => 'fixture.jar', 'dependencies' => '[]', 'is_dependency' => false,
        ]);
        $providerClass = $providerKey === 'curseforge' ? \Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider::class : ContentProvider::class;
        $provider = \Mockery::mock($providerClass);
        $provider->expects('version')->with('version-1')->andReturn($this->modVersion());
        app()->instance($providerClass, $provider);
        $request = $this->modRequest('POST', ['version_id' => 'version-1']);
        $response = app(BeaconModController::class)->{$action}($request, $this->server(), $id);
        $this->assertSame(202, $response->getStatusCode());
        $operation = BeaconOperation::query()->where('type', 'mod.' . $action)->firstOrFail();
        $this->assertSame([$id], $operation->payload['replace_installation_ids']);
        $this->assertSame('native_modpack', $operation->payload['runtime']['source']);
        $this->assertSame('forge', $operation->payload['plan']['loader']);
        $this->assertSame($providerKey, $operation->payload['provider']);
        Bus::assertDispatchedTimes(ProcessModOperationJob::class, 1);
    }

    public function testDefaultProviderIsCurseForgeAndMissingCredentialsLeaveModrinthSelectable(): void
    {
        $this->verifiedNativeRuntime();
        $context = app(BeaconModController::class)->context(new BeaconModRequest(), $this->server())->getData(true)['data'];
        $this->assertSame('curseforge', $context['provider']);
        $this->assertTrue($context['enabled']);
        $this->assertFalse($context['provider_available']);
        $this->assertStringContainsString('configure its API key', $context['provider_unavailable_reason']);
        $this->assertTrue($context['providers'][1]['available']);
        $this->assertArrayNotHasKey('api_key', $context);
    }

    public function testCurseForgePlanAndInstallPersistTheProviderWithoutLeakingDownloadUrls(): void
    {
        $this->verifiedNativeRuntime();
        config()->set('beacon.mods.enabled', true);
        $provider = \Mockery::mock(\Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider::class);
        $version = array_replace($this->modVersion(), ['id' => '123:456', 'project_id' => '123']);
        $version['file'] = array_replace($version['file'], ['sha512' => null, 'sha1' => str_repeat('a', 40)]);
        $provider->expects('version')->with('123:456')->twice()->andReturn($version);
        app()->instance(\Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider::class, $provider);
        $controller = app(BeaconModController::class);
        $request = $this->modRequest('POST', ['provider' => 'curseforge', 'version_id' => '123:456']);
        $plan = $controller->plan($request, $this->server())->getData(true)['data'];
        $this->assertSame('curseforge', $plan['provider']);
        $this->assertNull($plan['files'][0]['sha512']);
        $this->assertArrayNotHasKey('url', $plan['files'][0]);
        $controller->install($request, $this->server());
        $operation = BeaconOperation::query()->where('type', 'mod.install')->firstOrFail();
        $this->assertSame('curseforge', $operation->payload['provider']);
        $this->assertSame('curseforge', $operation->payload['intent']['provider']);
        Bus::assertDispatchedTimes(ProcessModOperationJob::class, 1);
    }

    public function testUninstallNeverConfusesDependencyIdsBetweenProvidersAndNeedsNoApiKey(): void
    {
        $this->verifiedNativeRuntime();
        config()->set('beacon.mods.enabled', true);
        $ids = [];
        foreach (['curseforge', 'modrinth'] as $provider) {
            foreach (['123', '999'] as $project) {
                $ids[$provider][$project] = DB::table('beacon_content_installations')->insertGetId([
                    'server_id' => 1, 'provider' => $provider, 'project_type' => 'mod', 'destination' => 'mods',
                    'project_id' => $project, 'version_id' => 'version-1', 'filename' => $provider . $project . '.jar',
                    'dependencies' => $project === '123' ? '["999"]' : '[]', 'is_dependency' => $project === '999',
                ]);
            }
        }
        $response = app(BeaconModController::class)->uninstall($this->modRequest('DELETE'), $this->server(), $ids['curseforge']['123']);
        $operation = BeaconOperation::query()->where('uuid', $response->getData(true)['data']['uuid'])->firstOrFail();
        $this->assertSame('curseforge', $operation->payload['provider']);
        $this->assertEqualsCanonicalizing(array_values($ids['curseforge']), $operation->payload['remove_installation_ids']);
        $this->assertSame(4, DB::table('beacon_content_installations')->count()); // Dispatch alone never deletes files/records.
    }

    public function testUninstallDoesNotRemoveAnIndependentlyManagedModAsAnOrphanDependency(): void
    {
        $this->verifiedNativeRuntime();
        config()->set('beacon.mods.enabled', true);
        $root = DB::table('beacon_content_installations')->insertGetId(['server_id' => 1, 'provider' => 'curseforge', 'project_type' => 'mod', 'destination' => 'mods', 'project_id' => '123', 'dependencies' => '["999"]', 'is_dependency' => false]);
        DB::table('beacon_content_installations')->insert(['server_id' => 1, 'provider' => 'curseforge', 'project_type' => 'mod', 'destination' => 'mods', 'project_id' => '999', 'dependencies' => '[]', 'is_dependency' => false]);
        $response = app(BeaconModController::class)->uninstall($this->modRequest('DELETE'), $this->server(), $root);
        $operation = BeaconOperation::query()->where('uuid', $response->getData(true)['data']['uuid'])->firstOrFail();
        $this->assertSame([$root], $operation->payload['remove_installation_ids']);
    }

    public function testFailedCurseForgeReplacementRestoresOldFilesAndLeavesOldRecordsIntact(): void
    {
        config()->set('beacon.modpacks.providers.curseforge.enabled', true);
        config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
        Http::preventStrayRequests();
        Http::fake(['https://edge.forgecdn.net/new.jar' => Http::response('', 200)]);
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server());
        $oldId = DB::table('beacon_content_installations')->insertGetId(['server_id' => 1, 'provider' => 'curseforge', 'project_type' => 'mod', 'destination' => 'mods', 'project_id' => '123', 'filename' => 'old.jar', 'status' => 'installed']);
        $before = DB::table('beacon_content_installations')->get()->toJson();
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'uuid' => 'rollback-fixture', 'type' => 'mod.update', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'provider' => 'curseforge', 'action' => 'update', 'runtime' => $runtime,
                'replace_installation_ids' => [$oldId], 'plan' => ['files' => [[
                    'filename' => 'new.jar', 'size' => 20, 'url' => 'https://edge.forgecdn.net/new.jar', 'sha1' => str_repeat('a', 40), 'sha512' => null,
                ]]]]]);
        $power = \Mockery::mock(ContentPowerService::class);
        $power->expects('prepare')->andReturn(true);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->andReturn(true);
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->allows('getDirectory')->with('/')->andReturn([]);
        $this->files->allows('getDirectory')->with('/.beacon')->andReturn([]);
        $this->files->expects('getDirectory')->with('/mods')->twice()->andReturn([['name' => 'old.jar']], []);
        $this->files->expects('getDirectory')->with('/.beacon/mod-stage-rollback-fixture')->andReturn([['name' => 'new.jar', 'file' => true, 'symlink' => false, 'size' => 20]]);
        $this->files->allows('createDirectory')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('pull')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('getFileHashes')->andReturn(['size' => 20, 'sha1' => str_repeat('a', 40), 'sha512' => str_repeat('b', 128)]);
        $this->files->expects('renameFiles')->with('/', [['from' => 'mods/old.jar', 'to' => '.beacon/mod-rollback-rollback-fixture/old.jar']])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('renameFiles')->with('/', [['from' => '.beacon/mod-stage-rollback-fixture/new.jar', 'to' => 'mods/new.jar']])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('deleteFiles')->with('/mods', ['new.jar'])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('renameFiles')->with('/', [['from' => '.beacon/mod-rollback-rollback-fixture/old.jar', 'to' => 'mods/old.jar']])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('deleteFiles')->with('/.beacon', ['mod-stage-rollback-fixture', 'mod-rollback-rollback-fixture'])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        (new ProcessModOperationJob($operation->id))->handle($power, $prepare, $this->files, app(ContentRuntimeResolver::class));
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame($before, DB::table('beacon_content_installations')->get()->toJson());
    }

    public function testUnsafeCurseForgeDependencyRedirectStopsEveryDownloadAndPreservesOldFilesAndRecords(): void
    {
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server());
        config()->set('beacon.modpacks.providers.curseforge.enabled', true);
        config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
        Http::preventStrayRequests();
        Http::fake([
            'https://edge.forgecdn.net/new.jar' => Http::response('', 302, ['Location' => 'https://mediafilez.forgecdn.net/new.jar']),
            'https://mediafilez.forgecdn.net/new.jar' => Http::response('', 200),
            'https://edge.forgecdn.net/dependency.jar' => Http::response('', 302, ['Location' => 'https://evil.invalid/PRIVATE_MARKER']),
        ]);
        $oldId = DB::table('beacon_content_installations')->insertGetId(['server_id' => 1, 'provider' => 'curseforge', 'project_type' => 'mod', 'destination' => 'mods', 'project_id' => '123', 'filename' => 'old.jar', 'status' => 'installed']);
        $before = DB::table('beacon_content_installations')->get()->toJson();
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'uuid' => 'unsafe-download', 'type' => 'mod.update', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'provider' => 'curseforge', 'action' => 'update', 'runtime' => $runtime,
                'replace_installation_ids' => [$oldId], 'plan' => ['files' => [
                    ['filename' => 'new.jar', 'size' => 20, 'url' => 'https://edge.forgecdn.net/new.jar'],
                    ['filename' => 'dependency.jar', 'size' => 20, 'url' => 'https://edge.forgecdn.net/dependency.jar'],
                ]]]]);
        $power = \Mockery::mock(ContentPowerService::class);
        $power->expects('prepare')->andReturn(true);
        $power->shouldNotReceive('beginRestart');
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->andReturn(true);
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->allows('getDirectory')->andReturnUsing(fn ($path) => $path === '/mods' ? [['name' => 'old.jar']] : []);
        $this->files->allows('createDirectory')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->shouldNotReceive('pull');
        $this->files->shouldNotReceive('getFileHashes');
        $this->files->shouldNotReceive('renameFiles');
        $this->files->expects('deleteFiles')->with('/.beacon', ['mod-stage-unsafe-download', 'mod-rollback-unsafe-download'])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        (new ProcessModOperationJob($operation->id))->handle($power, $prepare, $this->files, app(ContentRuntimeResolver::class));
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringContainsString('No mod files were changed', $operation->error_message);
        $this->assertStringNotContainsString('PRIVATE_MARKER', $operation->error_message);
        $this->assertSame($before, DB::table('beacon_content_installations')->get()->toJson());
        Http::assertSentCount(3);
    }

    #[DataProvider('modProviders')]
    public function testCorruptedModDownloadFailsBeforeMovingOrRecordingFiles(string $provider): void
    {
        Http::preventStrayRequests();
        if ($provider === 'curseforge') {
            config()->set('beacon.modpacks.providers.curseforge.enabled', true);
            config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
            Http::fake(['https://edge.forgecdn.net/fixture.jar' => Http::response('', 200)]);
        }
        $this->verifiedNativeRuntime();
        $runtime = app(ContentRuntimeResolver::class)->resolve($this->server());
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'uuid' => 'checksum-fixture', 'type' => 'mod.install', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'provider' => $provider, 'action' => 'install', 'runtime' => $runtime, 'plan' => ['files' => [[
                'filename' => 'fixture.jar', 'size' => 20, 'url' => 'https://edge.forgecdn.net/fixture.jar',
                'sha1' => str_repeat('a', 40), 'sha512' => str_repeat('a', 128),
            ]]]]]);
        $power = \Mockery::mock(ContentPowerService::class);
        $power->expects('prepare')->andReturn(true);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->andReturn(true);
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->allows('getDirectory')->andReturnUsing(fn ($path) => str_contains($path, 'mod-stage')
            ? [['name' => 'fixture.jar', 'size' => 20, 'file' => true, 'symlink' => false]] : []);
        $this->files->allows('createDirectory')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('pull')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->expects('getFileHashes')->andReturn(['size' => 20, 'sha1' => str_repeat('b', 40), 'sha512' => str_repeat('b', 128)]);
        $this->files->expects('deleteFiles')->with('/.beacon', ['mod-stage-checksum-fixture'])->andReturn(new \GuzzleHttp\Psr7\Response(204));
        (new ProcessModOperationJob($operation->id))->handle($power, $prepare, $this->files, app(ContentRuntimeResolver::class));
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringContainsString('checksum verification', $operation->error_message);
        $this->assertSame(0, DB::table('beacon_content_installations')->count());
    }

    public static function modInstallerActors(): array
    {
        return [[1, false, true], [2, true, true], [2, false, false]];
    }

    public static function nonForgeEggs(): array
    {
        return [['Vanilla Minecraft', false], ['Paper', true], ['Fabric', false], ['CurseForge Generic', false]];
    }

    #[DataProvider('nonForgeEggs')]
    public function testVanillaPaperFabricAndEmptyCurseForgeKeepTheirInstallerBoundaries(string $egg, bool $plugins): void
    {
        DB::table('eggs')->where('id', 1)->update(['name' => $egg]);
        $capabilities = app(ServerSoftwareCapabilityService::class)->resolve($this->server());
        $this->assertFalse($capabilities['mods']);
        $this->assertSame($plugins, $capabilities['plugins']);
        $context = app(BeaconModController::class)->context(new BeaconModRequest(), $this->server())->getData(true)['data'];
        $this->assertFalse($context['enabled']);
        $this->assertNotEmpty($context['unavailable_reason']);
    }

    #[DataProvider('modInstallerActors')]
    public function testModInstallerKeepsOwnerAndRootAdminAuthorization(int $id, bool $admin, bool $allowed): void
    {
        $request = new BeaconModRequest();
        $route = new \Illuminate\Routing\Route('GET', '/mods', fn () => null);
        $route->bind(\Illuminate\Http\Request::create('/mods'));
        $route->setParameter('server', $this->server());
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => $id, 'root_admin' => $admin]));
        $this->assertSame($allowed, $request->authorize());
    }
}
