<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Beacon\Content\PluginPowerService;
use Pterodactyl\Beacon\Content\PluginRuntimeResolver;
use Pterodactyl\Jobs\Beacon\ProcessPluginOperationJob;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Pterodactyl\Beacon\Content\Providers\ModrinthPluginProvider;
use Pterodactyl\Beacon\Content\Providers\PluginProviderRegistry;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconPluginRequest;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconPluginController;

/** Never connects to the configured application database or a real Wings/provider. */
class PluginInstallerTest extends TestCase
{
    private $events;
    private DaemonFileRepository $files;
    private PluginRuntimeResolver $runtime;
    private array $disk = [];
    private ?string $failRenameTarget = null;
    private ?string $failAfterRenameTarget = null;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'plugin_test');
        config()->set('database.connections.plugin_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Cache::setDefaultDriver('array');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('beacon.modpacks.providers.curseforge.api_key', null);
        Bus::fake();
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('beacon.content.backup_before_mutation', true);
        foreach ([
            'users' => ['root_admin'], 'nests' => ['name'], 'eggs' => ['name', 'nest_id'],
            'egg_variables' => ['egg_id', 'env_variable'], 'server_variables' => ['server_id', 'variable_id', 'variable_value'],
            'server_transfers' => ['server_id', 'successful'], 'allocations' => ['server_id'],
            'beacon_server_metadata' => ['server_id', 'profile_id', 'version_id'], 'beacon_modpack_installations' => ['server_id'],
            'servers' => ['uuid', 'owner_id', 'egg_id', 'nest_id', 'status', 'installed_at', 'startup', 'image'],
            'beacon_operations' => ['uuid', 'server_id', 'api_key_id', 'user_id', 'actor_key', 'type', 'status', 'correlation_id', 'idempotency_key', 'request_hash', 'payload', 'result', 'started_at', 'finished_at', 'error_code', 'error_message'],
            'beacon_content_installations' => ['server_id', 'operation_id', 'provider', 'project_id', 'project_name', 'version_id', 'version_number', 'icon_url', 'project_type', 'loader', 'game_version', 'destination', 'filename', 'sha512', 'size', 'dependencies', 'is_dependency', 'disabled_path', 'status'],
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
        DB::table('eggs')->insert(['id' => 1, 'nest_id' => 1, 'name' => 'Paper + Geyser + Floodgate']);
        DB::table('users')->insert(['id' => 1, 'root_admin' => false]);
        DB::table('servers')->insert(['id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'owner_id' => 1,
            'egg_id' => 1, 'nest_id' => 1, 'installed_at' => '2026-01-01 00:00:00', 'status' => null, 'startup' => 'java -jar server.jar', 'image' => 'trusted-java']);
        DB::table('egg_variables')->insert(['id' => 1, 'egg_id' => 1, 'env_variable' => 'VERSION']);
        DB::table('server_variables')->insert(['server_id' => 1, 'variable_id' => 1, 'variable_value' => '1.20.1']);
        $this->files = \Mockery::mock(DaemonFileRepository::class);
        $this->files->allows('setServer')->andReturnSelf();
        $this->files->allows('getDirectory')->andReturnUsing(function ($path) {
            $entries = [];
            foreach ($this->disk as $file => $body) {
                if (str_replace('\\', '/', dirname($file)) === $path) {
                    $entries[] = ['name' => basename($file), 'size' => strlen($body), 'file' => true, 'symlink' => false];
                }
            }

            return $path === '/' ? array_merge($entries, [['name' => '.beacon'], ['name' => 'plugins']]) : $entries;
        });
        $this->files->allows('getContent')->andReturnUsing(fn ($path) => $this->disk[$path]);
        $this->files->allows('createDirectory')->andReturn(new \GuzzleHttp\Psr7\Response(204));
        $this->files->allows('renameFiles')->andReturnUsing(function ($root, $moves) {
            if ($this->failRenameTarget && collect($moves)->contains('to', $this->failRenameTarget)) {
                throw new \RuntimeException('PRIVATE_WINGS_MESSAGE');
            }
            foreach ($moves as $move) {
                $this->disk['/' . $move['to']] = $this->disk['/' . $move['from']];
                unset($this->disk['/' . $move['from']]);
            }
            if ($this->failAfterRenameTarget && collect($moves)->contains('to', $this->failAfterRenameTarget)) {
                throw new \RuntimeException('PRIVATE_LOST_HTTP_RESPONSE');
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->files->allows('deleteFiles')->andReturnUsing(function ($root, $names) {
            foreach ($names as $name) {
                $prefix = $root . '/' . $name;
                foreach (array_keys($this->disk) as $path) {
                    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                        unset($this->disk[$path]);
                    }
                }
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->runtime = new PluginRuntimeResolver($this->files);
        app()->instance(PluginRuntimeResolver::class, $this->runtime);
        $activity = \Mockery::mock(\Pterodactyl\Services\Activity\ActivityLogService::class);
        $activity->allows('subject')->andReturnSelf();
        $activity->allows('property')->andReturnSelf();
        $activity->allows('actor')->andReturnSelf();
        $activity->allows('log')->andReturn(new \Pterodactyl\Models\ActivityLog());
        \Pterodactyl\Facades\Activity::shouldReceive('event')->andReturn($activity);
        \Illuminate\Support\Facades\Log::shouldReceive('error')->byDefault()->andReturnNull();
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('plugin_test');
        Cache::flush();
        \Mockery::close();
        parent::tearDown();
    }

    private function server(): Server
    {
        return Server::query()->findOrFail(1);
    }

    private function request(string $method = 'POST', array $data = [], string $key = 'fixture-key'): BeaconPluginRequest
    {
        $request = BeaconPluginRequest::create('/plugins', $method, $data);
        $request->setContainer(app());
        $request->setUserResolver(fn () => User::query()->findOrFail(1));
        $request->attributes->set('beacon_idempotency_key', $key);
        $request->setRouteResolver(function () use ($method, $request) {
            $route = (new \Illuminate\Routing\Route($method, '/plugins', fn () => null))->bind($request);
            $route->setParameter('server', $this->server());

            return $route;
        });

        return $request;
    }

    public static function eggs(): array
    {
        return [['Paper', 'paper'], ['Paper + Geyser + Floodgate', 'paper'], ['Purpur', 'paper'], ['Spigot', 'spigot'], ['CraftBukkit', 'bukkit'],
            ['Vanilla Minecraft', null], ['CurseForge Generic', null], ['Fabric', null], ['Vanilla Bedrock', null], ['Unknown', null]];
    }

    #[DataProvider('eggs')]
    public function testExplicitBeaconPlatformBoundary(string $name, ?string $platform): void
    {
        DB::table('eggs')->update(['name' => $name]);
        $this->assertSame($platform, $this->runtime->platform($this->server()));
        DB::table('nests')->update(['name' => 'Minecraft']);
        $this->assertNull($this->runtime->platform($this->server()));
    }

    public function testManualServerNeedsNoCatalogMetadataAndActualVersionHistoryWins(): void
    {
        $this->assertSame('1.20.1', $this->runtime->resolve($this->server())['game_version']);
        $this->disk['/version_history.json'] = json_encode(['currentVersion' => 'git-Paper-123 (MC: 1.21.1)']);
        $this->assertSame('1.21.1', $this->runtime->resolve($this->server())['game_version']);
        $this->assertSame(0, DB::table('beacon_server_metadata')->count());
    }

    public function testLatestIsNotGuessedAndShowsAReadableInstruction(): void
    {
        DB::table('server_variables')->update(['variable_value' => 'latest']);
        $context = app(BeaconPluginController::class)->context($this->request('GET'), $this->server())->getData(true)['data'];
        $this->assertFalse($context['enabled']);
        $this->assertSame('curseforge', $context['provider']);
        $this->assertStringContainsString('Start the server once', $context['unavailable_reason']);
    }

    public function testRuntimeSelectionDriftFailsClosed(): void
    {
        $runtime = $this->runtime->resolve($this->server());
        DB::table('server_variables')->update(['variable_value' => '1.21.1']);
        $this->expectException(ConflictHttpException::class);
        $this->runtime->assertOperationRuntime($this->server(), $runtime);
    }

    public function testOwnerAndRootAdminOnly(): void
    {
        $request = $this->request('GET');
        $this->assertTrue($request->authorize());
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => 999, 'root_admin' => false]));
        $this->assertFalse($request->authorize());
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => 999, 'root_admin' => true]));
        $this->assertTrue($request->authorize());
    }

    private function version(): array
    {
        return ['id' => 'v1', 'project_id' => 'plugin', 'project_name' => 'Fixture plugin', 'name' => 'Release', 'icon_url' => null,
            'version_number' => '1.0', 'version_type' => 'release', 'project_type' => 'plugin', 'server_side' => 'required',
            'game_versions' => ['1.20.1'], 'loaders' => ['paper'], 'dependencies' => [],
            'file' => ['url' => 'https://cdn.modrinth.com/data/plugin/fixture.jar', 'filename' => 'fixture.jar', 'size' => 7, 'sha512' => hash('sha512', 'fixture')]];
    }

    public function testPlanHidesUrlsAndRequestsAreIdempotent(): void
    {
        $provider = \Mockery::mock(ModrinthPluginProvider::class);
        $provider->allows('version')->with('v1')->andReturn($this->version());
        app()->instance(ModrinthPluginProvider::class, $provider);
        $controller = app(BeaconPluginController::class);
        $request = $this->request('POST', ['provider' => 'modrinth', 'version_id' => 'v1']);
        $plan = $controller->plan($request, $this->server())->getData(true)['data'];
        $this->assertArrayNotHasKey('url', $plan['files'][0]);
        $first = $controller->install($request, $this->server())->getData(true)['data'];
        $second = $controller->install($request, $this->server())->getData(true)['data'];
        $this->assertSame($first['uuid'], $second['uuid']);
        $this->assertArrayNotHasKey('payload', $first);
        Bus::assertDispatchedTimes(ProcessPluginOperationJob::class, 1);
        $this->assertSame('plugin.install', BeaconOperation::query()->firstOrFail()->type);
    }

    public static function providers(): array
    {
        return [['curseforge'], ['modrinth'], ['hangar']];
    }

    #[DataProvider('providers')]
    public function testRealWorkerInstallUninstallHashAndProviderIsolation(string $provider): void
    {
        $runtime = $this->runtime->resolve($this->server());
        $url = 'https://cdn.modrinth.com/fixture.jar';
        $registry = \Mockery::mock(PluginProviderRegistry::class);
        $registry->expects('downloadUrl')->with($provider, $url)->andReturn($url);
        app()->instance(PluginProviderRegistry::class, $registry);
        $this->files->expects('pull')->andReturnUsing(function ($url, $directory, $options) {
            $this->disk[$directory . '/' . $options['filename']] = 'fixture';

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->files->expects('getFileHashes')->andReturn(['size' => 7, 'sha1' => hash('sha1', 'fixture'), 'sha256' => hash('sha256', 'fixture'), 'sha512' => hash('sha512', 'fixture')]);
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'user_id' => 1, 'uuid' => 'plugin-install', 'type' => 'plugin.install', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'action' => 'install', 'provider' => $provider, 'runtime' => $runtime, 'plan' => ['provider' => $provider, 'files' => [
                ['project_id' => '123', 'project_name' => 'Fixture', 'version_id' => '123:456', 'version_number' => '1.0', 'name' => 'Release', 'url' => $url, 'filename' => 'fixture.jar', 'size' => 7,
                    'sha1' => hash('sha1', 'fixture'), 'sha256' => hash('sha256', 'fixture'), 'sha512' => hash('sha512', 'fixture'), 'dependency' => false],
            ]]]]);
        $power = \Mockery::mock(PluginPowerService::class);
        $power->expects('prepare')->twice()->andReturn(true);
        $power->expects('beginRestart')->twice()->andReturn(false);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->twice()->andReturn(true);
        (new ProcessPluginOperationJob($operation->id))->handle($power, $prepare, $this->files, $this->runtime);
        $this->assertSame('succeeded', $operation->refresh()->status, $operation->error_message ?? '');
        $this->assertSame('fixture', $this->disk['/plugins/fixture.jar']);
        $installation = BeaconContentInstallation::query()->firstOrFail();
        $this->assertSame('plugin', $installation->project_type);
        $this->assertSame('plugins', $installation->destination);
        $this->assertSame($provider, $installation->provider);
        $this->assertSame(hash('sha512', 'fixture'), $installation->sha512);
        $other = DB::table('beacon_content_installations')->insertGetId(['server_id' => 1, 'provider' => 'modrinth', 'project_type' => 'mod', 'destination' => 'mods', 'project_id' => '123']);
        $remove = BeaconOperation::query()->create(['server_id' => 1, 'user_id' => 1, 'uuid' => 'plugin-remove', 'type' => 'plugin.uninstall', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'action' => 'uninstall', 'provider' => $provider, 'runtime' => $runtime, 'remove_installation_ids' => [$installation->id]]]);
        (new ProcessPluginOperationJob($remove->id))->handle($power, $prepare, $this->files, $this->runtime);
        $this->assertSame('succeeded', $remove->refresh()->status, $remove->error_message ?? '');
        $this->assertArrayNotHasKey('/plugins/fixture.jar', $this->disk);
        $this->assertSame([$other], DB::table('beacon_content_installations')->pluck('id')->all());
    }

    public function testRawErrorsAndCooldownAreNotLeaked(): void
    {
        $job = new ProcessPluginOperationJob(1);
        $method = new \ReflectionMethod($job, 'safeError');
        $this->assertStringNotContainsString('PRIVATE', $method->invoke($job, new \PDOException('SQL password PRIVATE')));
        $this->assertSame(
            'Backup creation is temporarily rate limited. Try again after the backup cooldown.',
            $method->invoke($job, new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(null, 'PRIVATE'))
        );
    }

    public static function failedReplacements(): array
    {
        return [['checksum'], ['rename'], ['lost-response']];
    }

    #[DataProvider('failedReplacements')]
    public function testFailedReplacementRestoresOldFilesAndRecords(string $failure): void
    {
        $this->disk['/plugins/old.jar'] = 'old-file';
        $this->disk['/plugins/config.yml'] = 'user-config';
        $id = DB::table('beacon_content_installations')->insertGetId(['server_id' => 1, 'provider' => 'modrinth', 'project_type' => 'plugin', 'destination' => 'plugins',
            'project_id' => 'root', 'version_id' => 'old', 'filename' => 'old.jar', 'status' => 'installed']);
        $registry = \Mockery::mock(PluginProviderRegistry::class);
        $registry->expects('downloadUrl')->andReturn('https://cdn.modrinth.com/new.jar');
        app()->instance(PluginProviderRegistry::class, $registry);
        $this->files->expects('pull')->andReturnUsing(function ($url, $directory) {
            $this->disk[$directory . '/new.jar'] = 'fixture';

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $this->files->allows('getFileHashes')->andReturn(['size' => 7, 'sha512' => hash('sha512', $failure === 'checksum' ? 'wrong' : 'fixture')]);
        if ($failure === 'rename') {
            $this->failRenameTarget = 'plugins/new.jar';
        }
        if ($failure === 'lost-response') {
            $this->failAfterRenameTarget = 'plugins/new.jar';
        }
        $operation = BeaconOperation::query()->create(['uuid' => 'failure', 'server_id' => 1, 'type' => 'plugin.update', 'status' => 'running',
            'payload' => ['provider' => 'modrinth', 'action' => 'update', 'replace_installation_ids' => [$id], 'plan' => ['provider' => 'modrinth', 'files' => [
                ['project_id' => 'root', 'version_id' => 'new', 'name' => 'New', 'url' => 'https://cdn.modrinth.com/new.jar', 'filename' => 'new.jar', 'size' => 7, 'sha512' => hash('sha512', 'fixture'), 'dependency' => false],
            ]]]]);
        try {
            (new \ReflectionMethod(ProcessPluginOperationJob::class, 'install'))->invoke(new ProcessPluginOperationJob($operation->id), $operation, $this->server(), $this->files);
            $this->fail('Replacement must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('old-file', $this->disk['/plugins/old.jar']);
            $this->assertSame('user-config', $this->disk['/plugins/config.yml']);
            $this->assertArrayNotHasKey('/plugins/new.jar', $this->disk);
            $this->assertSame('old', DB::table('beacon_content_installations')->where('id', $id)->value('version_id'));
        }
    }

    public function testAnotherServerInstallationCannotBeRemoved(): void
    {
        $id = DB::table('beacon_content_installations')->insertGetId(['server_id' => 999, 'project_type' => 'plugin', 'destination' => 'plugins', 'is_dependency' => false]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(BeaconPluginController::class)->uninstall($this->request('DELETE'), $this->server(), $id);
    }

    public function testAChangedIdempotentRequestIsRejected(): void
    {
        $provider = \Mockery::mock(ModrinthPluginProvider::class);
        $provider->allows('version')->andReturnUsing(function ($id) {
            $version = $this->version();
            $version['id'] = $id;

            return $version;
        });
        app()->instance(ModrinthPluginProvider::class, $provider);
        $controller = app(BeaconPluginController::class);
        $controller->install($this->request('POST', ['provider' => 'modrinth', 'version_id' => 'v1']), $this->server());
        $this->expectException(ConflictHttpException::class);
        $controller->install($this->request('POST', ['provider' => 'modrinth', 'version_id' => 'v2']), $this->server());
    }

    public function testWorkerRechecksRuntimeAfterTheBackup(): void
    {
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'user_id' => 1, 'uuid' => 'drift', 'type' => 'plugin.install', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'runtime' => $this->runtime->resolve($this->server())]]);
        $power = \Mockery::mock(PluginPowerService::class);
        $power->expects('prepare')->andReturn(true);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->andReturnUsing(function () {
            DB::table('server_variables')->update(['variable_value' => '1.21.1']);

            return true;
        });
        $this->files->shouldNotReceive('pull');
        (new ProcessPluginOperationJob($operation->id))->handle($power, $prepare, $this->files, $this->runtime);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringContainsString('runtime changed', $operation->error_message);
    }

    public function testInterruptedAndUnauthorizedWorkersNeverResumeFileChanges(): void
    {
        foreach (['mutating', 'revoked'] as $case) {
            $operation = BeaconOperation::query()->create(['server_id' => 1, 'user_id' => 1, 'uuid' => $case, 'type' => 'plugin.install', 'status' => 'pending',
                'payload' => ['phase' => $case === 'mutating' ? 'mutating' : 'prepare']]);
            if ($case === 'revoked') {
                DB::table('servers')->update(['owner_id' => 999]);
            }
            $power = \Mockery::mock(PluginPowerService::class);
            $power->shouldNotReceive('prepare');
            $prepare = \Mockery::mock(PrepareContentMutationService::class);
            $prepare->shouldNotReceive('handle');
            $this->files->shouldNotReceive('pull');
            (new ProcessPluginOperationJob($operation->id))->handle($power, $prepare, $this->files, $this->runtime);
            $this->assertSame('failed', $operation->refresh()->status);
        }
    }

    public function testQueueFailureHookCannotOverwriteCompletedOperation(): void
    {
        $operation = BeaconOperation::query()->create(['type' => 'plugin.install', 'status' => 'succeeded']);
        (new ProcessPluginOperationJob($operation->id))->failed(new \RuntimeException('PRIVATE'));
        $this->assertSame('succeeded', $operation->refresh()->status);
    }

    public function testRunningServerIsStoppedGracefullyAndRestartedAfterMutation(): void
    {
        $wings = \Mockery::mock(\Pterodactyl\Repositories\Wings\DaemonServerRepository::class);
        $wings->allows('setServer')->andReturnSelf();
        $wings->expects('getDetails')->times(3)->andReturn(['state' => 'running'], ['state' => 'offline'], ['state' => 'running']);
        $power = \Mockery::mock(\Pterodactyl\Repositories\Wings\DaemonPowerRepository::class);
        $power->allows('setServer')->andReturnSelf();
        $power->expects('send')->with('stop');
        $power->expects('send')->with('start');
        $service = new PluginPowerService($wings, $power);
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'type' => 'plugin.install', 'status' => 'running', 'payload' => ['phase' => 'prepare']]);
        $this->assertFalse($service->prepare($operation, $this->server()));
        $this->assertSame('wait_stop', $operation->refresh()->payload['phase']);
        $this->assertTrue($service->prepare($operation, $this->server()));
        $this->assertTrue($service->beginRestart($operation, $this->server(), ['action' => 'install']));
        $this->assertTrue($service->waitForRestart($operation, $this->server()));
    }

    public function testBackupCooldownFailureDoesNotDownloadOrLeakDetails(): void
    {
        $operation = BeaconOperation::query()->create(['server_id' => 1, 'user_id' => 1, 'uuid' => 'cooldown', 'type' => 'plugin.install', 'status' => 'pending',
            'payload' => ['phase' => 'prepare', 'runtime' => $this->runtime->resolve($this->server())]]);
        $power = \Mockery::mock(PluginPowerService::class);
        $power->expects('prepare')->andReturn(true);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->andThrow(new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(null, 'PRIVATE_BACKUP_MESSAGE'));
        $this->files->shouldNotReceive('pull');
        (new ProcessPluginOperationJob($operation->id))->handle($power, $prepare, $this->files, $this->runtime);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertStringContainsString('backup cooldown', $operation->error_message);
        $this->assertStringNotContainsString('PRIVATE', $operation->error_message);
    }

    public function testNativePaperReinstallCannotRaceAnActivePluginOperation(): void
    {
        BeaconOperation::query()->create(['server_id' => 1, 'type' => 'plugin.install', 'status' => 'pending']);
        $wings = \Mockery::mock(\Pterodactyl\Repositories\Wings\DaemonServerRepository::class);
        $wings->shouldNotReceive('reinstall');
        $native = \Mockery::mock(\Pterodactyl\Beacon\Modpacks\NativeCurseForgeInstallationService::class);
        $native->expects('manages')->andReturn(false);
        $service = new \Pterodactyl\Services\Servers\ReinstallServerService(DB::connection(), $wings, $native);
        $this->expectException(\Pterodactyl\Exceptions\DisplayException::class);
        $this->expectExceptionMessage('active plugin operation');
        $service->handle($this->server());
    }
}
