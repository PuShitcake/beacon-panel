<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PrepareModpackOperationService
{
    public function __construct(
        private DaemonServerRepository $servers,
        private InitiateBackupService $backups,
        private ManagedModpackBackupCleanupService $managedBackups,
    ) {
    }

    public function handle(BeaconOperation $operation, Server $server): bool
    {
        $details = $this->servers->setServer($server)->getDetails();
        $state = data_get($details, 'state');
        if (!is_string($state) || $state === '') {
            throw new ConflictHttpException('Wings did not return a valid server state. No modpack files were changed.');
        }
        if ($state !== 'offline') {
            throw new ConflictHttpException('Stop the server before changing its modpack.');
        }

        $payload = $operation->payload;
        $backupUuid = data_get($payload, 'rollback_backup_uuid');
        if (!is_string($backupUuid)) {
            if ($server->backup_limit < 1) {
                throw new ConflictHttpException('A backup slot is required before a modpack can be changed.');
            }
            $lockBackup = ($payload['action'] ?? null) === 'install';
            $backup = $this->createBackup($server, $lockBackup);
            $payload['rollback_backup_uuid'] = $backup->uuid;
            $payload['progress'] = ['stage' => 'backup', 'percent' => 10, 'message' => 'Creating safety backup.'];
            $operation->forceFill(['payload' => $payload])->save();

            return false;
        }

        $backup = Backup::query()->where('server_id', $server->id)->where('uuid', $backupUuid)->first();
        if (!$backup instanceof Backup) {
            throw new \RuntimeException('The modpack rollback backup no longer exists.');
        }
        if (is_null($backup->completed_at)) {
            if ($backup->created_at->lt(now()->subMinutes(30))) {
                throw new \RuntimeException('The modpack rollback backup did not finish within 30 minutes.');
            }

            return false;
        }
        if (!$backup->is_successful) {
            throw new \RuntimeException('The modpack rollback backup failed.');
        }
        if (($payload['action'] ?? null) === 'install' && !$backup->is_locked) {
            $backup->forceFill(['is_locked' => true])->save();
        }

        return true;
    }

    private function createBackup(Server $server, bool $locked): Backup
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                return $this->backups
                    ->setIsLocked($locked)
                    ->handle($server, 'Beacon modpack rollback backup');
            } catch (TooManyBackupsException) {
                if ($attempt === 0) {
                    $this->managedBackups->cleanupStale($server);

                    continue;
                }

                throw new \RuntimeException('A free backup slot is required before changing this modpack.');
            } catch (TooManyRequestsHttpException) {
                throw new \RuntimeException('Backup creation is temporarily rate limited. Try again after the backup cooldown.');
            }
        }

        throw new \RuntimeException('A free backup slot is required before changing this modpack.');
    }
}
