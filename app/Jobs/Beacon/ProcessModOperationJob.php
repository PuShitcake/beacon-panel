<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Beacon\Content\ContentPowerService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProcessModOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 180;
    public int $timeout = 900;
    public array $backoff = [10, 30, 60];

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function handle(
        ContentPowerService $power,
        PrepareContentMutationService $prepare,
        DaemonFileRepository $files,
    ): void {
        $operation = BeaconOperation::query()->findOrFail($this->operationId);
        if (in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true)) {
            return;
        }
        $server = $operation->server()->first();
        if (!$server instanceof Server) {
            throw new \RuntimeException('The server for this mod operation no longer exists.');
        }
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_RUNNING,
            'started_at' => $operation->started_at ?? now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            if (data_get($operation->payload, 'phase') === 'wait_start') {
                if (!$power->waitForRestart($operation, $server)) {
                    $this->release(5);

                    return;
                }
                $this->complete($operation, $server, data_get($operation->payload, 'pending_result', []), true);

                return;
            }
            if (!$power->prepare($operation, $server)) {
                $this->release(5);

                return;
            }
            if (!$prepare->handle($operation, $server)) {
                $this->release(10);

                return;
            }

            $operation->refresh();
            $payload = $operation->payload;
            $payload['phase'] = 'mutating';
            $payload['progress'] = ['stage' => 'mutating', 'percent' => 55, 'message' => 'Applying the requested mod changes.'];
            $operation->forceFill(['payload' => $payload])->save();

            $repository = $files->setServer($server);
            $result = ($payload['action'] ?? null) === 'uninstall'
                ? $this->uninstall($operation, $server, $repository)
                : $this->install($operation, $server, $repository);

            if ($power->beginRestart($operation, $server, $result)) {
                $this->release(5);

                return;
            }

            $this->complete($operation, $server, $result, false);
        } catch (\Throwable $exception) {
            report($exception);
            $operation->refresh()->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => 'mod_operation_failed',
                'error_message' => $this->safeError($exception),
                'finished_at' => now(),
            ])->save();
        }
    }

    private function install(
        BeaconOperation $operation,
        Server $server,
        DaemonFileRepository $files,
    ): array {
        $payload = $operation->payload;
        $plan = data_get($payload, 'plan.files');
        if (!is_array($plan) || $plan === []) {
            throw new \InvalidArgumentException('The stored mod installation plan is invalid.');
        }
        $filenames = collect($plan)->pluck('filename')->filter('is_string')->values();
        if ($filenames->count() !== count($plan) || $filenames->unique()->count() !== $filenames->count()) {
            throw new \InvalidArgumentException('The stored mod plan contains invalid or duplicate filenames.');
        }

        $replaceIds = collect($payload['replace_installation_ids'] ?? [])->filter('is_int')->values()->all();
        $replace = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->whereIn('id', $replaceIds ?: [0])
            ->get();
        if (count($replaceIds) !== $replace->count()) {
            throw new ConflictHttpException('One or more managed mod records changed before the operation started.');
        }

        $this->ensureDirectory($files, '.beacon', '/');
        $this->ensureDirectory($files, 'mods', '/');
        $stageName = 'mod-stage-' . $operation->uuid;
        $rollbackName = 'mod-rollback-' . $operation->uuid;
        $this->ensureDirectory($files, $stageName, '/.beacon');
        if ($replace->isNotEmpty()) {
            $this->ensureDirectory($files, $rollbackName, '/.beacon');
        }

        $existing = collect($files->getDirectory('/mods'))->pluck('name')->filter('is_string');
        $replaceNames = $replace->where('status', 'installed')->pluck('filename');
        $collision = $filenames->first(fn (string $name) => $existing->contains($name) && !$replaceNames->contains($name));
        if (is_string($collision)) {
            throw new ConflictHttpException("The unmanaged or modpack file '{$collision}' already exists. No files were changed.");
        }

        $movedOld = false;
        $movedNew = false;
        try {
            foreach ($plan as $item) {
                $files->pull($item['url'], '/.beacon/' . $stageName, [
                    'filename' => $item['filename'],
                    'foreground' => true,
                ]);
            }
            $this->assertFilesMatch($files, '/.beacon/' . $stageName, $plan, 'download staging');
            if ($replace->isNotEmpty()) {
                $files->renameFiles('/', $replace->map(fn (BeaconContentInstallation $item) => [
                    'from' => $this->managedPath($item),
                    'to' => ".beacon/{$rollbackName}/{$item->filename}",
                ])->all());
                $movedOld = true;
            }
            $files->renameFiles('/', collect($plan)->map(fn (array $item) => [
                'from' => ".beacon/{$stageName}/{$item['filename']}",
                'to' => 'mods/' . $item['filename'],
            ])->all());
            $movedNew = true;
            $this->assertFilesMatch($files, '/mods', $plan, 'final installation');

            DB::transaction(function () use ($replaceIds, $server, $operation, $payload, $plan) {
                if ($replaceIds !== []) {
                    BeaconContentInstallation::query()
                        ->where('server_id', $server->id)
                        ->whereIn('id', $replaceIds)
                        ->delete();
                }
                $dependencyIds = data_get($payload, 'plan.resolved_dependencies', []);
                foreach ($plan as $item) {
                    BeaconContentInstallation::query()->create([
                        'server_id' => $server->id,
                        'operation_id' => $operation->id,
                        'provider' => 'modrinth',
                        'project_id' => $item['project_id'],
                        'project_name' => $item['project_name'] ?? $item['name'],
                        'version_id' => $item['version_id'],
                        'version_number' => $item['version_number'],
                        'icon_url' => $item['icon_url'] ?? null,
                        'project_type' => 'mod',
                        'loader' => data_get($payload, 'runtime.loader'),
                        'game_version' => data_get($payload, 'runtime.game_version'),
                        'destination' => 'mods',
                        'filename' => $item['filename'],
                        'sha512' => $item['sha512'],
                        'size' => $item['size'],
                        'dependencies' => $item['dependency'] ? [] : array_values(array_diff($dependencyIds, [$item['project_id']])),
                        'is_dependency' => (bool) $item['dependency'],
                        'disabled_path' => null,
                        'status' => 'installed',
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            $rollbackFailed = false;
            try {
                if ($movedNew) {
                    $files->deleteFiles('/mods', $filenames->all());
                }
                if ($movedOld) {
                    $files->renameFiles('/', $replace->map(fn (BeaconContentInstallation $item) => [
                        'from' => ".beacon/{$rollbackName}/{$item->filename}",
                        'to' => $this->managedPath($item),
                    ])->all());
                }
                $files->deleteFiles('/.beacon', array_filter([$stageName, $replace->isNotEmpty() ? $rollbackName : null]));
            } catch (\Throwable $rollbackException) {
                report($rollbackException);
                $rollbackFailed = true;
            }
            if ($rollbackFailed) {
                throw new \RuntimeException('The mod operation failed and its file rollback also failed. Manual file review is required.', 0, $exception);
            }

            throw $exception;
        }

        try {
            $files->deleteFiles('/.beacon', array_filter([$stageName, $replace->isNotEmpty() ? $rollbackName : null]));
        } catch (\Throwable $exception) {
            report($exception);
        }

        $root = collect($plan)->first(fn (array $item) => ($item['dependency'] ?? true) === false);

        return [
            'action' => $payload['action'],
            'project_id' => is_array($root) ? ($root['project_id'] ?? null) : null,
            'installed_files' => $filenames->all(),
            'safety_backup_uuid' => data_get($operation->payload, 'safety_backup_uuid'),
        ];
    }

    private function uninstall(
        BeaconOperation $operation,
        Server $server,
        DaemonFileRepository $files,
    ): array {
        $ids = collect(data_get($operation->payload, 'remove_installation_ids', []))->filter('is_int')->values()->all();
        $installations = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->whereIn('id', $ids ?: [0])
            ->get();
        if ($ids === [] || count($ids) !== $installations->count()) {
            throw new ConflictHttpException('One or more managed mod records changed before uninstall started.');
        }

        $missing = $installations->first(fn (BeaconContentInstallation $item) => !$this->pathExists($files, $this->managedPath($item)));
        if ($missing instanceof BeaconContentInstallation) {
            throw new ConflictHttpException("The managed file '{$missing->filename}' is missing. No records were removed.");
        }

        $this->ensureDirectory($files, '.beacon', '/');
        $rollbackName = 'mod-remove-' . $operation->uuid;
        $this->ensureDirectory($files, $rollbackName, '/.beacon');
        $moved = false;
        try {
            $files->renameFiles('/', $installations->map(fn (BeaconContentInstallation $item) => [
                'from' => $this->managedPath($item),
                'to' => ".beacon/{$rollbackName}/{$item->filename}",
            ])->all());
            $moved = true;
            DB::transaction(fn () => BeaconContentInstallation::query()
                ->where('server_id', $server->id)
                ->whereIn('id', $ids)
                ->delete());
        } catch (\Throwable $exception) {
            if ($moved) {
                try {
                    $files->renameFiles('/', $installations->map(fn (BeaconContentInstallation $item) => [
                        'from' => ".beacon/{$rollbackName}/{$item->filename}",
                        'to' => $this->managedPath($item),
                    ])->all());
                } catch (\Throwable $rollbackException) {
                    report($rollbackException);
                    throw new \RuntimeException('Mod uninstall failed and its file rollback also failed. Manual file review is required.', 0, $exception);
                }
            }

            throw $exception;
        }

        try {
            $files->deleteFiles('/.beacon', [$rollbackName]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return [
            'action' => 'uninstall',
            'removed_projects' => $installations->pluck('project_id')->values()->all(),
            'safety_backup_uuid' => data_get($operation->payload, 'safety_backup_uuid'),
        ];
    }

    private function ensureDirectory(DaemonFileRepository $files, string $name, string $root): void
    {
        $path = rtrim($root, '/') ?: '/';
        $exists = collect($files->getDirectory($path))->contains(fn (array $entry) => ($entry['name'] ?? null) === $name);
        if (!$exists) {
            $files->createDirectory($name, $path);
        }
    }

    private function managedPath(BeaconContentInstallation $installation): string
    {
        $path = $installation->status === 'disabled' ? $installation->disabled_path : null;
        if (is_string($path) && preg_match('#^mods/[A-Za-z0-9._+-]+\.disabled$#', $path)) {
            return $path;
        }

        return 'mods/' . $installation->filename;
    }

    private function pathExists(DaemonFileRepository $files, string $path): bool
    {
        $directory = '/' . trim(str_replace('\\', '/', dirname($path)), './');
        $directory = $directory === '/' ? '/' : rtrim($directory, '/');
        $name = basename($path);

        return collect($files->getDirectory($directory))
            ->contains(fn (array $entry) => ($entry['name'] ?? null) === $name);
    }

    private function assertFilesMatch(DaemonFileRepository $files, string $directory, array $plan, string $stage): void
    {
        $entries = collect($files->getDirectory($directory))->keyBy('name');
        foreach ($plan as $item) {
            $entry = $entries->get($item['filename']);
            if (!is_array($entry) || (int) ($entry['size'] ?? -1) !== (int) $item['size']) {
                throw new \RuntimeException("A mod file failed {$stage} verification. No unverified file was recorded as installed.");
            }
        }
    }

    private function complete(BeaconOperation $operation, Server $server, mixed $result, bool $restarted): void
    {
        $payload = $operation->payload;
        unset($payload['pending_result']);
        $payload['phase'] = 'complete';
        $payload['progress'] = ['stage' => 'complete', 'percent' => 100, 'message' => 'The mod operation completed.'];
        $result = is_array($result) ? $result : [];
        $result['server_restarted'] = $restarted;
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_SUCCEEDED,
            'payload' => $payload,
            'result' => $result,
            'finished_at' => now(),
        ])->save();

        $activity = Activity::event('beacon:' . $operation->type)
            ->subject($server)
            ->property('correlation_id', $operation->correlation_id)
            ->property('operation_uuid', $operation->uuid);
        if ($operation->user) {
            $activity->actor($operation->user);
        }
        $activity->log();
    }

    private function safeError(\Throwable $exception): string
    {
        return $exception instanceof ConflictHttpException
            || $exception instanceof \InvalidArgumentException
            || $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'The mod operation failed. Review the protected application logs.';
    }

    public function failed(?\Throwable $exception = null): void
    {
        BeaconOperation::query()->whereKey($this->operationId)->update([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => 'mod_operation_failed',
            'error_message' => 'The mod operation failed after all worker attempts.',
            'finished_at' => now(),
        ]);
    }
}
