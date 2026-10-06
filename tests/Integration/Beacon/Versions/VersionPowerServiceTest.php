<?php

namespace Pterodactyl\Tests\Integration\Beacon\Versions;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Versions\VersionPowerService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class VersionPowerServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testRollbackGracefullyStopsARunningServerBeforeRestoringFiles(): void
    {
        $server = $this->createServerModel();
        $operation = $this->operation($server);
        $servers = \Mockery::mock(DaemonServerRepository::class);
        $servers->expects('setServer')->twice()->with(\Mockery::on(fn (Server $value) => $value->id === $server->id))->andReturnSelf();
        $servers->expects('getDetails')->twice()->andReturn(['state' => 'running'], ['state' => 'offline']);
        $power = \Mockery::mock(DaemonPowerRepository::class);
        $power->expects('setServer')->once()->with(\Mockery::on(fn (Server $value) => $value->id === $server->id))->andReturnSelf();
        $power->expects('send')->once()->with('stop');
        $service = new VersionPowerService($servers, $power);

        $this->assertFalse($service->prepareRollback($operation, $server, 'Installation failed.'));
        $operation->refresh();
        $this->assertSame('wait_rollback_stop', $operation->payload['phase']);
        $this->assertSame('Installation failed.', $operation->payload['failure_message']);
        $this->assertTrue($service->prepareRollback($operation, $server, 'Installation failed.'));
    }

    private function operation(Server $server): BeaconOperation
    {
        return BeaconOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'user_id' => $server->owner_id,
            'server_id' => $server->id,
            'actor_key' => 'user:' . $server->owner_id,
            'type' => 'version.change',
            'status' => BeaconOperation::STATUS_RUNNING,
            'correlation_id' => Uuid::uuid4()->toString(),
            'idempotency_key' => 'version-test-' . Uuid::uuid4()->toString(),
            'request_hash' => str_repeat('a', 64),
            'payload' => [
                'phase' => 'wait_install',
                'mutation_started' => true,
            ],
        ]);
    }
}
