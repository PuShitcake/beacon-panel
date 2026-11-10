<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\ActivityLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\RconAuthenticationProbe;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\MinecraftServicePowerService;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceConfiguration;
use Pterodactyl\Jobs\Beacon\ProcessMinecraftServiceOperationJob;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceAllocationService;

class MinecraftServiceWorkerTest extends TestCase
{
    private $events;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'rcon_worker');
        config()->set('database.connections.rcon_worker', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        foreach (['nests' => ['name'], 'eggs' => ['name', 'nest_id'], 'users' => ['root_admin'],
            'servers' => ['uuid', 'egg_id', 'owner_id', 'status', 'allocation_id'], 'server_transfers' => ['server_id', 'successful'],
            'allocations' => ['server_id', 'ip', 'port'],
            'beacon_minecraft_services' => ['server_id', 'rcon_enabled', 'rcon_password', 'rcon_allocation_id', 'last_operation_id'],
            'beacon_operations' => ['server_id', 'user_id', 'type', 'status', 'payload', 'result', 'error_code', 'error_message', 'started_at', 'finished_at']] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->increments('id');
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
                $table->timestamps();
            });
        }
        DB::table('nests')->insert(['id' => 1, 'name' => 'Beacon']);
        DB::table('eggs')->insert(['id' => 1, 'name' => 'Velocity', 'nest_id' => 1]);
        DB::table('users')->insert(['id' => 1, 'root_admin' => 0]);
        DB::table('servers')->insert(['id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'egg_id' => 1, 'owner_id' => 1, 'allocation_id' => 1]);
        DB::table('beacon_operations')->insert(['id' => 1, 'server_id' => 1, 'user_id' => 1, 'type' => 'minecraft-service.rcon.enable', 'status' => 'pending',
            'payload' => json_encode(['service' => 'rcon', 'action' => 'enable', 'phase' => 'prepare', 'configuration_driver' => 'velocircon'])]);
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('rcon_worker');
        parent::tearDown();
    }

    private function rejected(): BeaconOperation
    {
        $power = \Mockery::mock(MinecraftServicePowerService::class);
        $power->shouldNotReceive('prepare');
        $power->shouldNotReceive('waitForRestart');
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->shouldNotReceive('setServer');
        $allocations = \Mockery::mock(MinecraftServiceAllocationService::class);
        $allocations->shouldNotReceive('claim');
        $allocations->shouldNotReceive('release');
        (new ProcessMinecraftServiceOperationJob(1))->handle($power, new MinecraftServiceConfiguration($files), $allocations, \Mockery::mock(DaemonServerRepository::class));
        $operation = BeaconOperation::findOrFail(1);
        $this->assertSame('failed', $operation->status);
        $this->assertSame(0, DB::table('beacon_minecraft_services')->count());

        return $operation;
    }

    public function testRevokedOwnershipCannotReachWingsOrAllocations(): void
    {
        DB::table('servers')->update(['owner_id' => 2]);
        $this->assertStringContainsString('no longer authorized', $this->rejected()->error_message);
    }

    public function testBedrockAndDriverChangesCannotWriteJavaOrVelocityFiles(): void
    {
        DB::table('eggs')->update(['name' => 'Vanilla Bedrock']);
        $this->rejected();
    }

    public function testInterruptedMutationIsNotReplayed(): void
    {
        $op = BeaconOperation::findOrFail(1);
        $op->forceFill(['payload' => array_replace($op->payload, ['phase' => 'applying'])])->save();
        $failed = $this->rejected();
        $this->assertStringContainsString('interrupted', $failed->error_message);
        $this->assertSame('manual_review', $failed->result['rollback']['status']);
    }

    public function testLegacyJavaJobCannotBecomeAVelocityJob(): void
    {
        $op = BeaconOperation::findOrFail(1);
        $payload = $op->payload;
        unset($payload['configuration_driver']);
        $op->forceFill(['payload' => $payload])->save();
        $this->assertStringContainsString('runtime changed', $this->rejected()->error_message);
    }

    public function testTerminalInterruptionWithoutSnapshotCannotClaimRollbackSucceeded(): void
    {
        $op = BeaconOperation::findOrFail(1);
        $op->forceFill(['payload' => array_replace($op->payload, ['phase' => 'applying'])])->save();
        (new ProcessMinecraftServiceOperationJob(1))->failed(new \RuntimeException('PRIVATE_FIXTURE_ERROR'));
        $op->refresh();
        $this->assertSame('failed', $op->status);
        $this->assertSame('manual_review', $op->result['rollback']['status']);
        $this->assertStringContainsString('Manual review', $op->error_message);
        $this->assertStringNotContainsString('PRIVATE_FIXTURE_ERROR', json_encode($op->result));
    }

    public function testPreWriteDriftCannotReleaseAPreExistingAllocation(): void
    {
        DB::table('allocations')->insert(['id' => 2, 'server_id' => 1, 'ip' => '127.0.0.1', 'port' => 25610]);
        DB::table('beacon_minecraft_services')->insert(['id' => 1, 'server_id' => 1, 'rcon_enabled' => 0, 'rcon_allocation_id' => 2]);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->shouldNotReceive('setServer');
        $configuration = \Mockery::mock(MinecraftServiceConfiguration::class, [$files])->makePartial();
        $configuration->expects('inspect')->andReturn(['enable-rcon' => 'true']);
        $power = \Mockery::mock(MinecraftServicePowerService::class);
        $power->expects('prepare')->andReturnTrue();
        $power->shouldNotReceive('beginRestart');
        $allocations = \Mockery::mock(MinecraftServiceAllocationService::class);
        $allocations->shouldNotReceive('claim');
        $allocations->shouldNotReceive('release');
        $allocations->shouldNotReceive('restore');
        $wings = \Mockery::mock(DaemonServerRepository::class);
        $wings->shouldNotReceive('setServer');
        (new ProcessMinecraftServiceOperationJob(1))->handle($power, $configuration, $allocations, $wings);
        $operation = BeaconOperation::findOrFail(1);
        $this->assertSame('failed', $operation->status);
        $this->assertStringContainsString('enabled outside Beacon', $operation->error_message);
        $this->assertSame('not_required_or_succeeded', $operation->result['rollback']['status']);
        $this->assertSame(2, \Pterodactyl\Models\BeaconMinecraftService::findOrFail(1)->rcon_allocation_id);
        $this->assertSame(1, \Pterodactyl\Models\Allocation::findOrFail(2)->server_id);
    }

    private function listener(bool $authenticated): BeaconOperation
    {
        $op = BeaconOperation::findOrFail(1);
        $op->forceFill(['payload' => array_replace($op->payload, ['phase' => 'wait_start', 'power_transition_started_at' => now()->toISOString(),
            'pending_result' => ['service' => 'rcon', 'action' => 'enable', 'enabled' => true, 'port' => 25610]])])->save();
        DB::table('allocations')->insert(['id' => 2, 'server_id' => 1, 'ip' => '127.0.0.1', 'port' => 25610]);
        DB::table('beacon_minecraft_services')->insert(['id' => 1, 'server_id' => 1, 'rcon_enabled' => 1, 'rcon_allocation_id' => 2, 'rcon_password' => Crypt::encryptString('TEST_ONLY')]);
        $power = \Mockery::mock(MinecraftServicePowerService::class);
        $power->expects('waitForRestart')->andReturnTrue();
        $probe = \Mockery::mock(RconAuthenticationProbe::class);
        $probe->expects('authenticate')->with('127.0.0.1', 25610, 'TEST_ONLY')->andReturn($authenticated);
        app()->instance(RconAuthenticationProbe::class, $probe);
        $job = new ProcessMinecraftServiceOperationJob(1);
        if (!$authenticated) {
            $queued = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
            $queued->expects('release')->with(5);
            $job->setJob($queued);
        } else {
            Activity::shouldReceive('event', 'subject', 'property', 'actor')->andReturnSelf();
            Activity::shouldReceive('log')->andReturn(new ActivityLog());
        }
        $files = \Mockery::mock(DaemonFileRepository::class);
        $job->handle($power, new MinecraftServiceConfiguration($files), \Mockery::mock(MinecraftServiceAllocationService::class), \Mockery::mock(DaemonServerRepository::class));

        return BeaconOperation::findOrFail(1);
    }

    public function testUnverifiedListenerStaysPendingInsteadOfReportingSuccess(): void
    {
        $op = $this->listener(false);
        $this->assertSame('running', $op->status);
        $this->assertNull($op->finished_at);
    }

    public function testSuccessRequiresActualAuthenticationAndDoesNotExposeThePassword(): void
    {
        $op = $this->listener(true);
        $this->assertSame('succeeded', $op->status);
        $this->assertTrue($op->result['connection_verified']);
        $this->assertStringNotContainsString('TEST_ONLY', json_encode($op->result));
    }
}
