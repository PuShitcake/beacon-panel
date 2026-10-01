<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Services\Backups\DeleteBackupService;

class ManagedModpackBackupCleanupService
{
    public function __construct(private DeleteBackupService $deleteBackup)
    {
    }

    public function cleanupStale(Server $server): int
    {
        $managedUuids = $this->managedUuids($server);
        if ($managedUuids === []) {
            return 0;
        }

        $referencedUuids = BeaconModpackInstallation::query()
            ->where('server_id', $server->id)
            ->pluck('safety_backup_uuid')
            ->all();
        $deleted = 0;

        $backups = Backup::query()
            ->where('server_id', $server->id)
            ->whereIn('uuid', $managedUuids)
            ->where('is_locked', false)
            ->get();
        foreach ($backups as $backup) {
            if (in_array($backup->uuid, $referencedUuids, true)) {
                continue;
            }

            try {
                $this->deleteBackup->handle($backup);
                ++$deleted;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $deleted;
    }

    public function deleteIfStale(Server $server, string $backupUuid): bool
    {
        if (!in_array($backupUuid, $this->managedUuids($server), true)) {
            return false;
        }
        if (BeaconModpackInstallation::query()
            ->where('server_id', $server->id)
            ->where('safety_backup_uuid', $backupUuid)
            ->exists()) {
            return false;
        }

        $backup = Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->where('is_locked', false)
            ->first();
        if (!$backup instanceof Backup) {
            return false;
        }

        $this->deleteBackup->handle($backup);

        return true;
    }

    /** @return string[] */
    private function managedUuids(Server $server): array
    {
        $uuids = [];
        $operations = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'modpack.%')
            ->get(['payload', 'result']);

        foreach ($operations as $operation) {
            foreach ([
                data_get($operation->payload, 'rollback_backup_uuid'),
                data_get($operation->payload, 'restored_backup_uuid'),
                data_get($operation->result, 'rollback_backup_uuid'),
                data_get($operation->result, 'restored_backup_uuid'),
                data_get($operation->result, 'rollback.backup_uuid'),
            ] as $uuid) {
                if (is_string($uuid) && $uuid !== '') {
                    $uuids[] = $uuid;
                }
            }
        }

        return array_values(array_unique($uuids));
    }
}
