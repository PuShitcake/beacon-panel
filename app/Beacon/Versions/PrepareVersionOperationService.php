<?php

namespace Pterodactyl\Beacon\Versions;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PrepareVersionOperationService
{
    public function __construct(private InitiateBackupService $backups)
    {
    }

    public function handle(BeaconOperation $operation, Server $server): bool
    {
        $payload = $operation->payload;
        $uuid = $payload['safety_backup_uuid'] ?? null;
        if (!is_string($uuid)) {
            if ($server->backup_limit < 1) {
                throw new \RuntimeException('A backup slot is required before changing the Minecraft version.');
            }
            try {
                $backup = $this->backups->setIsLocked(true)->handle($server, 'Beacon version rollback backup');
            } catch (TooManyBackupsException) {
                throw new \RuntimeException('A free backup slot is required before changing the Minecraft version.');
            } catch (TooManyRequestsHttpException) {
                throw new \RuntimeException('Backup creation is temporarily rate limited. Try again after the backup cooldown.');
            }
            $payload['safety_backup_uuid'] = $backup->uuid;
            $operation->forceFill(['payload' => $payload])->save();

            return false;
        }

        $backup = Backup::query()->where('server_id', $server->id)->where('uuid', $uuid)->first();
        if (!$backup instanceof Backup) {
            throw new \RuntimeException('The version rollback backup no longer exists.');
        }
        if (is_null($backup->completed_at)) {
            if ($backup->created_at->lt(now()->subMinutes(30))) {
                throw new \RuntimeException('The version rollback backup did not finish within 30 minutes.');
            }

            return false;
        }
        if (!$backup->is_successful) {
            throw new \RuntimeException('The version rollback backup failed.');
        }

        return true;
    }
}
