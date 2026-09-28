<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PrepareContentMutationServiceTest extends IntegrationTestCase
{
    public function testItFailsClosedWhenWingsDoesNotReturnAServerState(): void
    {
        config()->set('beacon.content.require_stopped_server', true);
        $server = Server::factory()->make();
        $repository = \Mockery::mock(DaemonServerRepository::class);
        $repository->expects('setServer')->with($server)->andReturnSelf();
        $repository->expects('getDetails')->andReturn([]);
        $backups = \Mockery::mock(InitiateBackupService::class);
        $backups->expects('handle')->never();
        $operation = new BeaconOperation();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('valid server state');
        (new PrepareContentMutationService($repository, $backups))->handle($operation, $server);
    }
}
