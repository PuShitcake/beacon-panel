<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RemoveContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;
    public int $timeout = 900;
    public array $backoff = [10, 30, 60];

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function handle(PrepareContentMutationService $prepare, DaemonFileRepository $files): void
    {
        $operation = BeaconOperation::query()->findOrFail($this->operationId);
        $server = $operation->server()->firstOrFail();
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_RUNNING,
            'started_at' => $operation->started_at ?? now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            if (!$prepare->handle($operation, $server)) {
                $this->release(10);

                return;
            }

            $installation = BeaconContentInstallation::query()
                ->where('server_id', $server->id)
                ->whereKey(data_get($operation->payload, 'installation_id'))
                ->first();

            if (!$installation instanceof BeaconContentInstallation) {
                $destination = data_get($operation->payload, 'destination');
                $filename = data_get($operation->payload, 'filename');
                if (!is_string($destination) || !in_array($destination, ['plugins', 'mods'], true) || !is_string($filename) || $filename === '') {
                    throw new \RuntimeException('The missing installation record cannot be reconciled safely.');
                }

                $fileStillExists = collect($files->setServer($server)->getDirectory('/' . $destination))
                    ->contains(fn (array $entry) => ($entry['name'] ?? null) === $filename);
                if ($fileStillExists) {
                    throw new ConflictHttpException('The installation record is missing but its managed file still exists. Manual review is required.');
                }

                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_SUCCEEDED,
                    'result' => [
                        'removed_project' => data_get($operation->payload, 'project_id'),
                        'safety_backup_uuid' => data_get($operation->payload, 'safety_backup_uuid'),
                    ],
                    'finished_at' => now(),
                ])->save();

                return;
            }

            $files->setServer($server)->deleteFiles('/' . $installation->destination, [$installation->filename]);
            $installation->delete();
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_SUCCEEDED,
                'result' => [
                    'removed_project' => $installation->project_id,
                    'safety_backup_uuid' => data_get($operation->payload, 'safety_backup_uuid'),
                ],
                'finished_at' => now(),
            ])->save();

            $activity = Activity::event('beacon:content.remove')
                ->subject($server)
                ->property('correlation_id', $operation->correlation_id)
                ->property('operation_uuid', $operation->uuid)
                ->property('project_id', $installation->project_id);
            if ($operation->user) {
                $activity->actor($operation->user);
            }
            $activity->log();
        } catch (\Throwable $exception) {
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => 'content_remove_failed',
                'error_message' => $exception instanceof ConflictHttpException
                    ? $exception->getMessage()
                    : 'Content removal failed. Review the protected application logs.',
                'finished_at' => now(),
            ])->save();

            if ($exception instanceof ConflictHttpException) {
                return;
            }

            throw $exception;
        }
    }
}
