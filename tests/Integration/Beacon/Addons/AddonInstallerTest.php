<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Beacon\Addons\AddonStore;
use Pterodactyl\Beacon\Addons\AddonRuntime;
use Pterodactyl\Beacon\Addons\AddonFilesystem;
use Pterodactyl\Models\BeaconAddonInstallation;
use Pterodactyl\Jobs\Beacon\ProcessAddonOperationJob;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconAddonRequest;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconAddonController;

/** All database tables are isolated in memory, all Wings I/O mocked. */
class AddonInstallerTest extends TestCase
{
    private $events;
    private AddonRuntime $runtime;
    private AddonStore $store;
    private AddonFilesystem $files;
    private DaemonServerRepository $servers;
    private DaemonPowerRepository $power;
    private PrepareContentMutationService $backup;
    private string $state = 'offline';
    private array $identity = ['version' => '1.21.100', 'selection' => 'selection', 'identity' => 'binary-sha256'];
    private array $prepared;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'addon_test');
        config()->set('database.connections.addon_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        foreach (['servers' => ['uuid', 'owner_id', 'egg_id', 'image', 'startup', 'status', 'installed_at', 'backup_limit'], 'users' => ['root_admin'], 'allocations' => ['server_id'],
            'beacon_operations' => ['uuid', 'api_key_id', 'server_id', 'user_id', 'actor_key', 'type', 'status', 'correlation_id', 'idempotency_key', 'request_hash', 'payload', 'result', 'error_code', 'error_message', 'started_at', 'finished_at']] as $table => $fields) {
            Schema::create($table, function (Blueprint $t) use ($fields) {
                $t->increments('id');
                foreach ($fields as $field) {
                    $t->text($field)->nullable();
                }
                $t->timestamps();
            });
        }
        (require base_path('database/migrations/2026_11_11_000000_create_beacon_addon_installations.php'))->up();
        DB::table('users')->insert(['id' => 1, 'root_admin' => false]);
        DB::table('servers')->insert(['id' => 1, 'owner_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'backup_limit' => 3]);
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('beacon.content.backup_before_mutation', true);
        Bus::fake();
        $this->runtime = \Mockery::mock(AddonRuntime::class);
        $this->runtime->allows('assertSupported')->andReturnNull();
        $this->runtime->allows('selection')->andReturn('selection');
        $this->runtime->allows('resolve')->andReturnUsing(fn () => $this->identity);
        $this->runtime->allows('assertWorld')->andReturnNull();
        $this->runtime->allows('assertMinimum')->andReturnNull();
        app()->instance(AddonRuntime::class, $this->runtime);
        $this->prepared = ['archive_id' => str_repeat('a', 64), 'archive_hash' => str_repeat('b', 64), 'source' => ['provider' => 'upload'],
            'manifest' => ['packs' => [['uuid' => AddonArchiveTest::manifest()['header']['uuid'], 'name' => 'Fixture', 'version' => [1, 0, 0], 'directory' => 'behavior_packs', 'scripts' => false, 'files' => ['manifest.json' => ['size' => 7, 'sha256' => hash('sha256', 'fixture')]]]], 'file_count' => 1, 'total_bytes' => 7]];
        $this->store = \Mockery::mock(AddonStore::class);
        $this->store->allows('load')->andReturnUsing(fn () => $this->prepared);
        $this->store->allows('path')->andReturn('private-fixture-archive');
        app()->instance(AddonStore::class, $this->store);
        $this->files = \Mockery::mock(AddonFilesystem::class);
        $this->files->allows('backupSafe')->andReturnNull();
        $this->files->allows('stagePath')->andReturn('/.beacon/stage');
        $this->files->allows('tree')->andReturn('tree-hash');
        $this->servers = \Mockery::mock(DaemonServerRepository::class);
        $this->servers->allows('setServer')->andReturnSelf();
        $this->servers->allows('getDetails')->andReturnUsing(fn () => ['state' => $this->state]);
        $this->power = \Mockery::mock(DaemonPowerRepository::class);
        $this->power->allows('setServer')->andReturnSelf();
        $this->backup = \Mockery::mock(PrepareContentMutationService::class);
        $this->backup->allows('handle')->andReturn(true);
        $activity = \Mockery::mock(\Pterodactyl\Services\Activity\ActivityLogService::class);
        foreach (['actor', 'subject', 'property'] as $method) {
            $activity->allows($method)->andReturnSelf();
        }
        $activity->allows('log')->andReturn(new \Pterodactyl\Models\ActivityLog());
        \Pterodactyl\Facades\Activity::shouldReceive('event')->andReturn($activity);
        \Illuminate\Support\Facades\Log::shouldReceive('error')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('addon_test');
        \Mockery::close();
        parent::tearDown();
    }

    public function testAddonBackupUsesNativeLockWithoutQuotaOverrideAndResetsServiceOptions(): void
    {
        $operation = $this->createOperation('backup');
        $server = Server::findOrFail(1);
        $native = \Mockery::mock(\Pterodactyl\Services\Backups\InitiateBackupService::class);
        $native->expects('setIgnoredFiles')->with([])->twice()->andReturnSelf();
        $native->expects('setIsLocked')->with(true)->once()->andReturnSelf();
        $native->expects('setIsLocked')->with(false)->once()->andReturnSelf();
        $native->expects('handle')->with($server, 'Beacon add-on safety backup')->once()->andReturn((new \Pterodactyl\Models\Backup())->forceFill(['uuid' => '44444444-4444-4444-8444-444444444444']));
        $service = new PrepareContentMutationService($this->servers, $native);
        $this->assertFalse($service->handle($operation, $server, true));
        $this->assertSame('44444444-4444-4444-8444-444444444444', $operation->refresh()->payload['safety_backup_uuid']);
    }

    public function testLegacyContentBackupOptionsAreUnchanged(): void
    {
        $operation = $this->createOperation('backup');
        $server = Server::findOrFail(1);
        $native = \Mockery::mock(\Pterodactyl\Services\Backups\InitiateBackupService::class);
        $native->expects('handle')->with($server, 'Beacon content safety backup', true)->once()->andReturn((new \Pterodactyl\Models\Backup())->forceFill(['uuid' => '44444444-4444-4444-8444-444444444444']));
        $native->shouldNotReceive('setIsLocked');
        $this->assertFalse((new PrepareContentMutationService($this->servers, $native))->handle($operation, $server));
    }

    public function testAddonBackupOptionsAreResetEvenWhenNativeQuotaCheckFails(): void
    {
        $native = \Mockery::mock(\Pterodactyl\Services\Backups\InitiateBackupService::class);
        $native->expects('setIgnoredFiles')->with([])->twice()->andReturnSelf();
        $native->expects('setIsLocked')->with(true)->once()->andReturnSelf();
        $native->expects('setIsLocked')->with(false)->once()->andReturnSelf();
        $native->expects('handle')->andThrow(new \RuntimeException('Quota fixture'));
        $this->expectException(\RuntimeException::class);
        (new PrepareContentMutationService($this->servers, $native))->handle($this->createOperation('backup'), Server::findOrFail(1), true);
    }

    public static function completedBackups(): array
    {
        return [[true, [], true], [false, [], false], [true, ['worlds/'], false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('completedBackups')]
    public function testCompletedAddonBackupMustStayFullAndLocked(bool $locked, array $ignored, bool $accepted): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('server_id');
            $table->string('uuid');
            $table->boolean('is_successful');
            $table->boolean('is_locked');
            $table->text('ignored_files');
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->softDeletes();
        });
        $uuid = '44444444-4444-4444-8444-444444444444';
        DB::table('backups')->insert(['server_id' => 1, 'uuid' => $uuid, 'is_successful' => true, 'is_locked' => $locked,
            'ignored_files' => json_encode($ignored), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $operation = $this->createOperation('backup');
        $operation->forceFill(['payload' => $operation->payload + ['safety_backup_uuid' => $uuid]])->save();
        $native = \Mockery::mock(\Pterodactyl\Services\Backups\InitiateBackupService::class);
        $native->shouldNotReceive('handle');
        if (!$accepted) {
            $this->expectException(ConflictHttpException::class);
        }
        $this->assertTrue((new PrepareContentMutationService($this->servers, $native))->handle($operation, Server::findOrFail(1), true));
    }

    private function request(array $data = [], int $actor = 1): BeaconAddonRequest
    {
        $r = BeaconAddonRequest::create('/addons', 'POST', $data);
        $r->setContainer(app());
        $r->setUserResolver(fn () => User::findOrFail($actor));
        $r->attributes->set('beacon_idempotency_key', 'fixture-key');
        $r->attributes->set('beacon_correlation_id', '22222222-2222-4222-8222-222222222222');
        $r->setRouteResolver(function () use ($r) {
            $route = (new \Illuminate\Routing\Route('POST', '/addons', fn () => null))->bind($r);
            $route->setParameter('server', Server::findOrFail(1));

            return $route;
        });

        return $r;
    }

    private function createOperation(string $phase = 'prepare'): BeaconOperation
    {
        return BeaconOperation::create(['server_id' => 1, 'user_id' => 1, 'uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'content.addon.install',
            'status' => 'pending', 'payload' => ['phase' => $phase, 'archive_id' => str_repeat('a', 64), 'world' => 'Bedrock level', 'action' => 'install', 'runtime' => $this->identity, 'cursor' => 0, 'tree' => 'tree-hash', 'restart' => false]]);
    }

    private function runJob(BeaconOperation $operation): void
    {
        $job = new ProcessAddonOperationJob($operation->id);
        $job->handle($this->store, $this->runtime, $this->files, $this->backup, $this->servers, $this->power);
    }

    public function testAdmissionIsIdempotentAndPublicResponseOmitsPrivatePlan(): void
    {
        $request = $this->request(['archive_id' => str_repeat('a', 64), 'world' => 'Bedrock level', 'acknowledge' => true]);
        $controller = app(BeaconAddonController::class);
        $first = $controller->install($request, Server::findOrFail(1));
        $second = $controller->install($request, Server::findOrFail(1));
        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, BeaconOperation::count());
        $this->assertArrayNotHasKey('payload', $first->getData(true)['data']);
        Bus::assertDispatchedTimes(ProcessAddonOperationJob::class, 1);
    }

    public function testUploadRequestAndPreviewHaveNoFixedFileByteLimit(): void
    {
        $request = $this->request(['provider' => 'upload']);
        $upload = \Illuminate\Http\UploadedFile::fake()->create('large.mcaddon', 512000, 'application/zip');
        $request->files->set('file', $upload);
        $validator = app('validator')->make($request->all(), $request->rules());
        $this->assertTrue($validator->passes(), implode(' ', $validator->errors()->all()));
        $this->store->expects('prepare')->withArgs(fn ($server, $path, $source) => $server->id === 1 && is_file($path) && $source === ['provider' => 'upload'])->andReturn($this->prepared);
        $this->store->expects('publicPreview')->with($this->prepared)->andReturn(['archive_id' => $this->prepared['archive_id']]);
        $response = app(BeaconAddonController::class)->preview($request, Server::findOrFail(1));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->prepared['archive_id'], $response->getData(true)['data']['archive_id']);
    }

    public function testDifferentIntentCannotReuseIdempotencyKey(): void
    {
        $controller = app(BeaconAddonController::class);
        $controller->install($this->request(['archive_id' => str_repeat('a', 64), 'world' => 'One', 'acknowledge' => true]), Server::findOrFail(1));
        $this->expectException(ConflictHttpException::class);
        $controller->install($this->request(['archive_id' => str_repeat('a', 64), 'world' => 'Two', 'acknowledge' => true]), Server::findOrFail(1));
    }

    public function testRequestIsOwnerOrRootAdminOnly(): void
    {
        DB::table('users')->insert(['id' => 2, 'root_admin' => false]);
        $this->assertTrue($this->request()->authorize());
        $this->assertFalse($this->request([], 2)->authorize());
        DB::table('users')->where('id', 2)->update(['root_admin' => true]);
        $this->assertTrue($this->request([], 2)->authorize());
    }

    public function testWorkerStagesVerifiesCommitsAndPersistsWithoutRestartingAnOfflineServer(): void
    {
        $operation = $this->createOperation();
        $this->files->expects('stage')->once();
        $this->files->expects('verify')->andReturn(['cursor' => 1, 'count' => 1]);
        $this->files->expects('snapshot')->andReturn(['files' => [], 'moves' => []]);
        $this->files->expects('commit')->once();
        $this->power->shouldNotReceive('send');
        for ($i = 0; $i < 3; ++$i) {
            $this->runJob($operation);
        }
        $this->assertSame('succeeded', $operation->refresh()->status);
        $this->assertSame(100, data_get($operation->payload, 'progress.percent'));
        $this->assertSame(1, BeaconAddonInstallation::count());
        $this->runJob($operation);
        $this->assertSame(1, BeaconAddonInstallation::count());
    }

    public function testNativeBackupCooldownReleasesJobWithoutMutatingFilesOrExposingExceptionText(): void
    {
        $operation = $this->createOperation('backup');
        $this->backup = \Mockery::mock(PrepareContentMutationService::class);
        $this->backup->expects('handle')->with(\Mockery::type(BeaconOperation::class), \Mockery::type(Server::class), true)
            ->andThrow(new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(120, 'PRIVATE_THROTTLE_TOKEN'));
        $this->files->shouldNotReceive('stage', 'verify', 'commit');
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->expects('release')->with(60)->once();
        $job = new ProcessAddonOperationJob($operation->id);
        $job->setJob($queueJob);
        $job->handle($this->store, $this->runtime, $this->files, $this->backup, $this->servers, $this->power);
        $operation->refresh();
        $this->assertSame('running', $operation->status);
        $this->assertSame('backup', $operation->payload['phase']);
        $this->assertSame(15, data_get($operation->payload, 'progress.percent'));
        $this->assertStringNotContainsString('PRIVATE', json_encode($operation->toArray()));
        $this->assertSame(0, BeaconAddonInstallation::count());
    }

    public function testFailedCommitRestoresFilesystemAndCreatesNoInstallation(): void
    {
        $operation = $this->createOperation('verify');
        $this->files->expects('verify')->andReturn(['cursor' => 1, 'count' => 1]);
        $this->files->expects('snapshot')->andReturn(['files' => [], 'moves' => []]);
        $this->files->expects('commit')->andThrow(new \PDOException('PRIVATE_SQL_PASSWORD'));
        $this->files->expects('rollback')->once();
        $this->runJob($operation);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringNotContainsString('PRIVATE', $operation->error_message);
        $this->assertSame(0, BeaconAddonInstallation::count());
    }

    public function testFailureToRestoreIsReportedWithoutLeakingRawErrors(): void
    {
        $operation = $this->createOperation('verify');
        $this->files->expects('verify')->andReturn(['cursor' => 1, 'count' => 1]);
        $this->files->expects('snapshot')->andReturn(['files' => [], 'moves' => []]);
        $this->files->expects('commit')->andThrow(new \RuntimeException('PRIVATE_TRANSPORT'));
        $this->files->expects('rollback')->andThrow(new \RuntimeException('PRIVATE_ROLLBACK'));
        $this->runJob($operation);
        $this->assertSame('addon_rollback_failed', $operation->refresh()->error_code);
        $this->assertStringNotContainsString('PRIVATE', $operation->error_message);
    }

    public function testChangedBinaryAndRevokedOwnershipFailBeforeFilesystemWork(): void
    {
        $operation = $this->createOperation();
        $this->identity['identity'] = 'different-binary';
        $this->files->shouldNotReceive('stage');
        $this->runJob($operation);
        $this->assertSame('failed', $operation->refresh()->status);
        $operation->forceFill(['status' => 'pending'])->save();
        DB::table('servers')->where('id', 1)->update(['owner_id' => 2]);
        $this->runJob($operation);
        $this->assertStringContainsString('authorized', $operation->refresh()->error_message);
    }

    public function testMigrationRollbackPreservesNativeTables(): void
    {
        $migration = require base_path('database/migrations/2026_11_11_000000_create_beacon_addon_installations.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('beacon_addon_installations'));
        $this->assertSame(1, Server::count());
        $migration->up();
        $this->assertSame(0, BeaconAddonInstallation::count());
    }

    public function testRunningServerIsStoppedBeforeStagingAndRestartedOnlyAfterCommit(): void
    {
        $operation = $this->createOperation();
        $this->state = 'running';
        $this->power->expects('send')->with('stop')->once()->andReturnUsing(function () {
            $this->state = 'offline';

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->power->expects('send')->with('start')->once()->andReturnUsing(function () {
            $this->state = 'running';

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->files->expects('stage')->once();
        $this->files->expects('verify')->andReturn(['cursor' => 1, 'count' => 1]);
        $this->files->expects('snapshot')->andReturn(['files' => [], 'moves' => []]);
        $this->files->expects('commit')->once();
        $this->runJob($operation);
        $this->assertSame('wait_stop', data_get($operation->refresh()->payload, 'phase'));
        $this->runJob($operation);
        $this->runJob($operation);
        $this->assertSame('wait_start', data_get($operation->refresh()->payload, 'phase'));
        $this->runJob($operation);
        $this->assertSame('succeeded', $operation->refresh()->status);
    }

    public function testInterruptedCommittedResultDoesNotReplayFilesystemWork(): void
    {
        $operation = $this->createOperation('committed');
        $payload = $operation->payload;
        $payload['pending_result'] = ['action' => 'install', 'world' => 'Bedrock level'];
        $operation->forceFill(['payload' => $payload])->save();
        $this->files->shouldNotReceive('commit');
        $this->runJob($operation);
        $this->assertSame('succeeded', $operation->refresh()->status);
    }
}
