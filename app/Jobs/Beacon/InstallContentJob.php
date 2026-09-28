<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InstallContentJob implements ShouldQueue
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

        $rollbackCandidates = [];
        $mutationStarted = false;
        $repository = null;
        $destination = null;
        try {
            if (!$prepare->handle($operation, $server)) {
                $this->release(10);

                return;
            }

            $payload = $operation->payload;
            $destination = data_get($payload, 'destination');
            $plan = data_get($payload, 'plan.files');
            if (!is_string($destination) || !in_array($destination, ['plugins', 'mods'], true) || !is_array($plan) || $plan === []) {
                throw new \InvalidArgumentException('The stored content installation plan is invalid.');
            }
            $rollbackCandidates = collect($plan)->pluck('filename')->filter('is_string')->values()->all();
            if (count($rollbackCandidates) !== count($plan)) {
                throw new \InvalidArgumentException('The stored content installation filenames are invalid.');
            }
            if (count(array_unique($rollbackCandidates)) !== count($rollbackCandidates)) {
                throw new ConflictHttpException('The installation plan contains duplicate filenames.');
            }

            $repository = $files->setServer($server);
            $existingNames = collect($repository->getDirectory('/' . $destination))
                ->pluck('name')
                ->filter('is_string')
                ->all();
            foreach ($plan as $item) {
                if (in_array(data_get($item, 'filename'), $existingNames, true)) {
                    throw new ConflictHttpException('A file in the installation plan already exists on the server.');
                }
            }

            $mutationStarted = true;
            foreach ($plan as $item) {
                $repository->pull($item['url'], '/' . $destination, [
                    'filename' => $item['filename'],
                    'foreground' => true,
                ]);
            }

            DB::transaction(function () use ($operation, $server, $payload, $plan, $destination) {
                $dependencyIds = data_get($payload, 'plan.resolved_dependencies', []);
                foreach ($plan as $item) {
                    BeaconContentInstallation::query()->create([
                        'server_id' => $server->id,
                        'operation_id' => $operation->id,
                        'provider' => 'modrinth',
                        'project_id' => $item['project_id'],
                        'version_id' => $item['version_id'],
                        'project_type' => $payload['project_type'],
                        'loader' => $payload['loader'],
                        'game_version' => $payload['game_version'],
                        'destination' => $destination,
                        'filename' => $item['filename'],
                        'sha512' => $item['sha512'],
                        'size' => $item['size'],
                        'dependencies' => $item['dependency'] ? [] : array_values(array_diff($dependencyIds, [$item['project_id']])),
                        'status' => 'installed',
                    ]);
                }

                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_SUCCEEDED,
                    'result' => [
                        'installed_projects' => collect($plan)->pluck('project_id')->values()->all(),
                        'safety_backup_uuid' => data_get($operation->payload, 'safety_backup_uuid'),
                    ],
                    'finished_at' => now(),
                ])->save();
            });

            $activity = Activity::event('beacon:content.install')
                ->subject($server)
                ->property('correlation_id', $operation->correlation_id)
                ->property('operation_uuid', $operation->uuid)
                ->property('project_id', data_get($plan, '0.project_id'));
            if ($operation->user) {
                $activity->actor($operation->user);
            }
            $activity->log();
        } catch (\Throwable $exception) {
            $rollbackFailed = false;
            if ($mutationStarted && $rollbackCandidates !== [] && $repository instanceof DaemonFileRepository && is_string($destination)) {
                try {
                    $repository->deleteFiles('/' . $destination, $rollbackCandidates);
                    $remaining = collect($repository->getDirectory('/' . $destination))
                        ->pluck('name')
                        ->filter('is_string')
                        ->intersect($rollbackCandidates);
                    if ($remaining->isNotEmpty()) {
                        throw new \RuntimeException('One or more files remained after automatic rollback.');
                    }
                } catch (\Throwable $rollbackException) {
                    $rollbackFailed = true;
                    report($rollbackException);
                }
            }
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => $rollbackFailed ? 'content_install_rollback_failed' : 'content_install_failed',
                'error_message' => $rollbackFailed
                    ? 'Content installation and automatic rollback failed. Manual file review is required.'
                    : ($exception instanceof ConflictHttpException
                        ? $exception->getMessage()
                        : 'Content installation failed. Review the protected application logs.'),
                'result' => $rollbackFailed ? [
                    'rollback' => [
                        'status' => 'failed',
                        'destination' => $destination,
                        'files' => $rollbackCandidates,
                    ],
                ] : null,
                'finished_at' => now(),
            ])->save();

            if ($rollbackFailed || $exception instanceof ConflictHttpException) {
                return;
            }

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        BeaconOperation::query()->whereKey($this->operationId)->update([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => 'content_install_failed',
            'error_message' => 'Content installation failed after all retry attempts.',
            'finished_at' => now(),
        ]);
    }
}
