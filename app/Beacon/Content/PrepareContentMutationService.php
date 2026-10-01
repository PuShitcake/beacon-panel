<?php

namespace Pterodactyl\Beacon\Content;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PrepareContentMutationService
{
    public function __construct(
        private DaemonServerRepository $serverRepository,
        private InitiateBackupService $backupService,
    ) {
    }

    /**
     * Returns false while an automatically-created safety backup is still running.
     */
    public function handle(BeaconOperation $operation, Server $server): bool
    {
        if (config('beacon.content.require_stopped_server')) {
            $details = $this->serverRepository->setServer($server)->getDetails();
            $state = data_get($details, 'state');
            if (!is_string($state) || $state === '') {
                throw new ConflictHttpException('Wings did not return a valid server state. No content was changed.');
            }
            if ($state !== 'offline') {
                throw new ConflictHttpException('Stop the server before changing managed content.');
            }
        }

        if (!config('beacon.content.backup_before_mutation')) {
            return true;
        }

        $payload = $operation->payload;
        $backupUuid = data_get($payload, 'safety_backup_uuid');
        if (!is_string($backupUuid)) {
            if ($server->backup_limit < 1) {
                throw new ConflictHttpException('A backup slot is required before managed content can be changed.');
            }

            $backup = $this->backupService->handle($server, 'Beacon content safety backup', true);
            $payload['safety_backup_uuid'] = $backup->uuid;
            $operation->forceFill(['payload' => $payload])->save();

            return false;
        }

        $backup = Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->first();

        if (!$backup instanceof Backup) {
            throw new \RuntimeException('The safety backup record no longer exists.');
        }
        if (is_null($backup->completed_at)) {
            if ($backup->created_at->lt(now()->subMinutes(30))) {
                throw new \RuntimeException('The safety backup did not complete within 30 minutes.');
            }

            return false;
        }
        if (!$backup->is_successful) {
            throw new \RuntimeException('The safety backup failed.');
        }

        return true;
    }
}
