<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Beacon\Versions\VersionPowerService;
use Pterodactyl\Services\Backups\DownloadLinkService;
use Pterodactyl\Beacon\Versions\VersionRuntimeService;
use Pterodactyl\Beacon\Versions\VersionMetadataService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Beacon\Versions\PrepareVersionOperationService;

class ProcessVersionOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 360;
    public int $timeout = 900;
    public array $backoff = [10, 30, 60];

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function handle(
        VersionPowerService $power,
        PrepareVersionOperationService $prepare,
        VersionRuntimeService $runtime,
        VersionMetadataService $metadata,
        ReinstallServerService $reinstall,
        DaemonFileRepository $files,
        DaemonBackupRepository $backups,
        DownloadLinkService $downloadLinks,
    ): void {
        $operation = BeaconOperation::query()->findOrFail($this->operationId);
        if (in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true)) {
            return;
        }
        $server = $operation->server()->first();
        if (!$server instanceof Server) {
            throw new \RuntimeException('The server for this version operation no longer exists.');
        }
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_RUNNING,
            'started_at' => $operation->started_at ?? now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $phase = (string) data_get($operation->payload, 'phase', 'prepare');
        try {
            $this->assertNotTimedOut($operation);
            if ($phase === 'wait_rollback') {
                $this->finishRollback($operation, $server);

                return;
            }
            if ($phase === 'wait_rollback_stop') {
                $failure = (string) data_get($operation->payload, 'failure_message', 'The version change failed.');
                if (!$power->prepareRollback($operation, $server, $failure)) {
                    $this->release(5);

                    return;
                }
                $this->beginRollback($operation, $server, $runtime, $backups, $downloadLinks, new \RuntimeException($failure));
                $this->release(10);

                return;
            }
            if ($phase === 'recover_worker_failure') {
                if ($server->fresh()->status === Server::STATUS_INSTALLING) {
                    $this->release(10);

                    return;
                }
                $failure = 'The version worker stopped unexpectedly.';
                if (!$power->prepareRollback($operation, $server, $failure)) {
                    $this->release(5);

                    return;
                }
                $this->beginRollback($operation, $server, $runtime, $backups, $downloadLinks, new \RuntimeException($failure));
                $this->release(10);

                return;
            }
            if ($phase === 'wait_start') {
                if (!$power->waitForStart($operation, $server)) {
                    $this->release(5);

                    return;
                }
                $this->complete($operation, $server, $metadata, true);

                return;
            }
            if (in_array($phase, ['prepare', 'wait_stop', 'backup'], true)) {
                if (!$power->prepare($operation, $server)) {
                    $this->release(5);

                    return;
                }
                $operation->refresh();
                if (!$prepare->handle($operation, $server)) {
                    $this->release(10);

                    return;
                }
                $payload = $operation->refresh()->payload;
                $payload['original_runtime'] = $payload['original_runtime'] ?? $runtime->snapshot($server);
                $payload['mutation_started'] = true;
                $payload['phase'] = 'install';
                $payload['progress'] = ['stage' => 'installing', 'percent' => 55, 'message' => 'Installing the selected Minecraft runtime.'];
                $payload['preserved_incompatible_paths'] = $this->incompatiblePaths(
                    (string) data_get($payload, 'current.software'),
                    (string) data_get($payload, 'target.software'),
                );
                $operation->forceFill(['payload' => $payload])->save();
                $server = $runtime->switch($server, $payload['target']);
                $reinstall->handle($server);
                $payload['phase'] = 'wait_install';
                $operation->forceFill(['payload' => $payload])->save();
                $this->release(10);

                return;
            }
            if ($phase === 'wait_install') {
                if ($this->installationRunning($server)) {
                    $this->release(10);

                    return;
                }
                $files->setServer($server)->putContent('/eula.txt', "eula=true\n");
                $payload = $operation->payload;
                $payload['phase'] = 'finalize';
                $payload['progress'] = ['stage' => 'finalizing', 'percent' => 85, 'message' => 'Finalizing the new runtime.'];
                $operation->forceFill(['payload' => $payload])->save();
                if ($power->restart($operation, $server)) {
                    $this->release(5);

                    return;
                }
                $this->complete($operation, $server, $metadata, false);

                return;
            }

            throw new \RuntimeException('The version operation has an invalid phase.');
        } catch (\Throwable $exception) {
            report($exception);
            $operation->refresh();
            if (($operation->payload['mutation_started'] ?? false) === true && $phase !== 'recover_worker_failure') {
                try {
                    if (!$power->prepareRollback($operation, $server, $this->safeError($exception))) {
                        $this->release(5);

                        return;
                    }
                    $this->beginRollback($operation, $server, $runtime, $backups, $downloadLinks, $exception);
                    $this->release(10);

                    return;
                } catch (\Throwable $rollbackException) {
                    report($rollbackException);
                    $this->fail($operation, 'version_rollback_failed', 'The version change failed and automatic rollback could not be started. Manual recovery is required.', [
                        'rollback' => ['status' => 'failed'],
                    ]);

                    return;
                }
            }
            $this->fail($operation, 'version_operation_failed', $this->safeError($exception));
            $this->unlockBackup($operation, $server);
        }
    }

    private function installationRunning(Server $server): bool
    {
        $status = $server->fresh()->status;
        if ($status === Server::STATUS_INSTALLING) {
            return true;
        }
        if (in_array($status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true)) {
            throw new \RuntimeException('Wings failed to install the selected Minecraft runtime.');
        }
        if (!is_null($status)) {
            throw new \RuntimeException('The server entered an unexpected state during the version installation.');
        }

        return false;
    }

    private function beginRollback(
        BeaconOperation $operation,
        Server $server,
        VersionRuntimeService $runtime,
        DaemonBackupRepository $backups,
        DownloadLinkService $downloadLinks,
        \Throwable $exception,
    ): void {
        $payload = $operation->payload;
        if (!is_array($payload['original_runtime'] ?? null)) {
            throw new \RuntimeException('The original runtime snapshot is unavailable.');
        }
        $backup = Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $payload['safety_backup_uuid'] ?? '')
            ->firstOrFail();
        $server = $runtime->restore($server, $payload['original_runtime']);
        $server->forceFill(['status' => Server::STATUS_RESTORING_BACKUP])->save();
        $url = $backup->disk === Backup::ADAPTER_AWS_S3
            ? $downloadLinks->handle($backup, $operation->user ?? $server->user)
            : null;
        $backups->setServer($server)->restore($backup, $url, true);
        $payload['phase'] = 'wait_rollback';
        $payload['failure_message'] = $this->safeError($exception);
        $payload['progress'] = ['stage' => 'rollback', 'percent' => 90, 'message' => 'The change failed. Restoring the safety backup.'];
        $operation->forceFill(['payload' => $payload])->save();
    }

    private function finishRollback(BeaconOperation $operation, Server $server): void
    {
        $fresh = $server->fresh();
        if ($fresh->status === Server::STATUS_RESTORING_BACKUP) {
            $this->release(10);

            return;
        }
        if (!is_null($fresh->status)) {
            $this->fail($operation, 'version_rollback_failed', 'The version change failed and rollback did not complete. Manual recovery is required.', [
                'rollback' => ['status' => 'failed'],
            ]);

            return;
        }
        $this->fail($operation, 'version_install_failed', (string) data_get($operation->payload, 'failure_message', 'The version change failed.'), [
            'rollback' => ['status' => 'succeeded', 'backup_uuid' => data_get($operation->payload, 'safety_backup_uuid')],
        ]);
        $this->unlockBackup($operation, $server);
    }

    private function complete(
        BeaconOperation $operation,
        Server $server,
        VersionMetadataService $metadata,
        bool $restarted,
    ): void {
        $payload = $operation->payload;
        $payload['phase'] = 'complete';
        $payload['progress'] = ['stage' => 'complete', 'percent' => 100, 'message' => 'Minecraft version change completed.'];
        DB::transaction(function () use ($operation, $server, $metadata, $payload, $restarted) {
            $metadata->reconcile($server->fresh(), $payload['target']);
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_SUCCEEDED,
                'payload' => $payload,
                'result' => [
                    'target' => $payload['target'],
                    'restarted' => $restarted,
                    'safety_backup_uuid' => $payload['safety_backup_uuid'] ?? null,
                    'preserved_incompatible_paths' => $payload['preserved_incompatible_paths'] ?? [],
                ],
                'finished_at' => now(),
            ])->save();
        }, 5);
        $this->unlockBackup($operation, $server);
        try {
            $activity = Activity::event('beacon:version.change')
                ->subject($server)
                ->property('operation_uuid', $operation->uuid)
                ->property('target', $payload['target']);
            if ($operation->user) {
                $activity->actor($operation->user);
            }
            $activity->log();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function incompatiblePaths(string $from, string $to): array
    {
        $paths = [];
        if ($from === 'paper' && $to !== 'paper') {
            $paths[] = 'plugins';
        }
        if ($from === 'forge' && $to !== 'forge') {
            $paths[] = 'mods';
        }

        return $paths;
    }

    private function assertNotTimedOut(BeaconOperation $operation): void
    {
        $minutes = max(5, (int) config('beacon.versions.operation_timeout_minutes', 45));
        if ($operation->started_at?->lt(now()->subMinutes($minutes))) {
            throw new \RuntimeException('The version operation exceeded its configured time limit.');
        }
    }

    private function safeError(\Throwable $exception): string
    {
        return $exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException
            ? $exception->getMessage()
            : 'The version operation failed. Review the protected application logs.';
    }

    private function fail(BeaconOperation $operation, string $code, string $message, ?array $result = null): void
    {
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'result' => $result,
            'finished_at' => now(),
        ])->save();
    }

    private function unlockBackup(BeaconOperation $operation, Server $server): void
    {
        try {
            Backup::query()
                ->where('server_id', $server->id)
                ->where('uuid', data_get($operation->payload, 'safety_backup_uuid', ''))
                ->update(['is_locked' => false]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        $operation = BeaconOperation::query()->find($this->operationId);
        if (!$operation instanceof BeaconOperation
            || in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true)) {
            return;
        }
        $payload = $operation->payload;
        if (($payload['mutation_started'] ?? false) === true && ($payload['recovery_dispatched'] ?? false) !== true) {
            $payload['recovery_dispatched'] = true;
            $payload['phase'] = 'recover_worker_failure';
            $payload['progress'] = ['stage' => 'rollback', 'percent' => 90, 'message' => 'The worker stopped unexpectedly. Starting recovery.'];
            $operation->forceFill(['status' => BeaconOperation::STATUS_PENDING, 'payload' => $payload])->save();
            self::dispatch($operation->id);

            return;
        }
        $this->fail($operation, 'version_job_failed', 'The version worker stopped after all retry attempts. Manual review may be required.');
    }
}
