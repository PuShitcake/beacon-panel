<?php

namespace Pterodactyl\Tests\Integration\Beacon\Modpacks;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Beacon\Modpacks\ManagedModpackBackupCleanupService;

class ManagedModpackBackupCleanupServiceTest extends IntegrationTestCase
{
    public function testCleanupDeletesOnlyUnlockedBackupsProvenToBelongToModpackOperations(): void
    {
        $server = $this->createServerModel();
        $managed = Backup::factory()->for($server)->create(['is_locked' => false]);
        $locked = Backup::factory()->for($server)->create(['is_locked' => true]);
        Backup::factory()->for($server)->create(['is_locked' => false, 'name' => 'User backup']);
        $this->recordOperation($server, $managed->uuid);
        $this->recordOperation($server, $locked->uuid);

        $delete = \Mockery::mock(DeleteBackupService::class);
        $delete->expects('handle')->once()->with(\Mockery::on(fn (Backup $backup) => $backup->is($managed)));

        $this->assertSame(1, (new ManagedModpackBackupCleanupService($delete))->cleanupStale($server));
    }

    public function testUnprovenUserBackupCannotBeDeletedDirectly(): void
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->for($server)->create(['is_locked' => false, 'name' => 'User backup']);
        $delete = \Mockery::mock(DeleteBackupService::class);
        $delete->expects('handle')->never();

        $this->assertFalse(
            (new ManagedModpackBackupCleanupService($delete))->deleteIfStale($server, $backup->uuid)
        );
    }

    private function recordOperation(Server $server, string $backupUuid): void
    {
        BeaconOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'actor_key' => 'test:' . Uuid::uuid4()->toString(),
            'type' => 'modpack.install',
            'status' => BeaconOperation::STATUS_SUCCEEDED,
            'correlation_id' => Uuid::uuid4()->toString(),
            'idempotency_key' => Uuid::uuid4()->toString(),
            'request_hash' => hash('sha256', $backupUuid),
            'payload' => ['rollback_backup_uuid' => $backupUuid],
            'result' => null,
        ]);
    }
}
