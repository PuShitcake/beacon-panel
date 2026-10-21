<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Beacon\Content\PluginPowerService;
use Pterodactyl\Beacon\Content\PluginRuntimeResolver;
use Pterodactyl\Beacon\Operations\PublicOperationError;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Pterodactyl\Beacon\Content\Providers\PluginProviderRegistry;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class ProcessPluginOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 900;
    public int $timeout = 900;
    public array $backoff = [10, 30, 60];

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function middleware(): array
    {
        return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('plugin-operation:' . $this->operationId))->releaseAfter(10)->expireAfter(930)];
    }

    public function handle(
        PluginPowerService $power,
        PrepareContentMutationService $prepare,
        DaemonFileRepository $files,
        PluginRuntimeResolver $runtime,
    ): void {
        $operation = BeaconOperation::query()->where('type', 'like', 'plugin.%')->findOrFail($this->operationId);
        if (in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true)) {
            return;
        }
        $server = $operation->server()->first();
        if (!$server instanceof Server) {
            throw new \RuntimeException('The server for this plugin operation no longer exists.');
        }
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_RUNNING,
            'started_at' => $operation->started_at ?? now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            if (!config('beacon.content.backup_before_mutation')) {
                throw new ConflictHttpException('Enable managed-content safety backups before changing plugins.');
            }
            $actor = $operation->user;
            if (!$actor || (!$actor->root_admin && $server->owner_id !== $actor->id)) {
                throw new ConflictHttpException('This plugin operation is no longer authorized. An administrator must review its state.');
            }
            if (data_get($operation->payload, 'phase') === 'wait_start') {
                if (!$power->waitForRestart($operation, $server)) {
                    $this->release(5);

                    return;
                }
                $this->complete($operation, $server, data_get($operation->payload, 'pending_result', []), true);

                return;
            }
            if (data_get($operation->payload, 'phase') === 'mutating') {
                throw new ConflictHttpException('A previous plugin mutation was interrupted. Review its safety backup and server files before retrying.');
            }
            $runtime->assertOperationRuntime($server, data_get($operation->payload, 'runtime', []));
            if (!$power->prepare($operation, $server)) {
                $this->release(5);

                return;
            }
            if (!$prepare->handle($operation, $server)) {
                $this->release(10);

                return;
            }

            // Startup IDs can change while a safety backup is pending. Check again before I/O.
            $runtime->assertOperationRuntime($server->fresh(), data_get($operation->payload, 'runtime', []));

            $operation->refresh();
            $payload = $operation->payload;
            $payload['phase'] = 'mutating';
            $payload['progress'] = ['stage' => 'mutating', 'percent' => 55, 'message' => 'Applying the requested plugin changes.'];
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
            PublicOperationError::report($exception, 'plugin.operation', $this->operationId);
            $operation->refresh()->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => 'plugin_operation_failed',
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
        $provider = $payload['provider'] ?? 'modrinth';
        if (!in_array($provider, ['modrinth', 'curseforge', 'hangar'], true)
            || data_get($payload, 'plan.provider', $provider) !== $provider) {
            throw new ConflictHttpException('The stored plugin provider is invalid. No plugin files were changed.');
        }
        $plan = data_get($payload, 'plan.files');
        if (!is_array($plan) || $plan === []) {
            throw new \InvalidArgumentException('The stored plugin installation plan is invalid.');
        }
        $filenames = collect($plan)->pluck('filename')->filter(fn ($value) => is_string($value))->values();
        if ($filenames->count() !== count($plan) || $filenames->unique()->count() !== $filenames->count()) {
            throw new \InvalidArgumentException('The stored plugin plan contains invalid or duplicate filenames.');
        }
        foreach ($plan as $item) {
            if (strlen($item['filename']) > 191 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/D', $item['filename'])
                || !is_int($item['size'] ?? null) || $item['size'] < 1 || $item['size'] > config('beacon.content.max_file_bytes')) {
                throw new ConflictHttpException('The stored plugin file violates the filename or size policy. No plugin files were changed.');
            }
        }

        $replaceIds = collect($payload['replace_installation_ids'] ?? [])->filter(fn ($value) => is_int($value))->values()->all();
        $replace = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('provider', $provider)
            ->where('project_type', 'plugin')->where('destination', 'plugins')
            ->whereIn('id', $replaceIds ?: [0])
            ->get();
        if (count($replaceIds) !== $replace->count()) {
            throw new ConflictHttpException('One or more managed plugin records changed before the operation started.');
        }

        $this->ensureDirectory($files, '.beacon', '/');
        $this->ensureDirectory($files, 'plugins', '/');
        $stageName = 'plugin-stage-' . $operation->uuid;
        $rollbackName = 'plugin-rollback-' . $operation->uuid;
        $this->ensureDirectory($files, $stageName, '/.beacon');
        if ($replace->isNotEmpty()) {
            $this->ensureDirectory($files, $rollbackName, '/.beacon');
        }

        $existing = collect($files->getDirectory('/plugins'))->pluck('name')->filter(fn ($value) => is_string($value));
        $replaceNames = $replace->where('status', 'installed')->pluck('filename');
        $collision = $filenames->first(fn (string $name) => $existing->contains($name) && !$replaceNames->contains($name));
        if (is_string($collision)) {
            throw new ConflictHttpException("The unmanaged or modpack file '{$collision}' already exists. No files were changed.");
        }

        $movedOld = [];
        $movedNew = [];
        try {
            $downloadUrls = [];
            foreach ($plan as $index => $item) {
                try {
                    $downloadUrls[$index] = app(PluginProviderRegistry::class)->downloadUrl($provider, $item['url']);
                } catch (ProviderResponseException $exception) {
                    throw new ConflictHttpException('The plugin download could not be prepared safely. No plugin files were changed. Try again later.', $exception);
                }
            }
            foreach ($plan as $index => $item) {
                $files->pull($downloadUrls[$index], '/.beacon/' . $stageName, [
                    'filename' => $item['filename'],
                    'foreground' => true,
                ]);
            }
            $this->assertFilesMatch($files, '/.beacon/' . $stageName, $plan, 'download staging');
            foreach ($plan as &$item) {
                $algorithm = match ($provider) {
                    'curseforge' => 'sha1', 'hangar' => 'sha256', default => 'sha512',
                };
                $expected = $item[$algorithm] ?? null;
                if (!is_string($expected) || !preg_match('/^[a-f0-9]{' . ['sha1' => 40, 'sha256' => 64, 'sha512' => 128][$algorithm] . '}$/D', $expected)) {
                    throw new ConflictHttpException('The stored plugin file hash is invalid. No plugin files were changed.');
                }
                $hashes = $files->getFileHashes('/.beacon/' . $stageName . '/' . $item['filename'], min((int) $item['size'], (int) config('beacon.content.max_file_bytes')));
                if ($hashes['size'] !== (int) $item['size'] || !hash_equals($expected, $hashes[$algorithm])) {
                    throw new ConflictHttpException('A downloaded plugin failed checksum verification. No plugin files were changed.');
                }
                // CurseForge supplies SHA-1. Persist the SHA-512 computed from the verified bytes
                // in the existing native content record; no schema change or invented hash.
                $item['sha512'] = $hashes['sha512'];
            }
            unset($item);
            if ($replace->isNotEmpty()) {
                foreach ($replace as $item) {
                    $move = ['from' => $this->managedPath($item), 'to' => ".beacon/{$rollbackName}/{$item->filename}"];
                    $movedOld[] = $move; // Record before I/O: a lost HTTP response can still mean a completed rename.
                    $files->renameFiles('/', [$move]);
                }
            }
            foreach ($plan as $item) {
                $movedNew[] = $item;
                $files->renameFiles('/', [['from' => ".beacon/{$stageName}/{$item['filename']}", 'to' => 'plugins/' . $item['filename']]]);
            }
            $this->assertFilesMatch($files, '/plugins', $plan, 'final installation');

            DB::transaction(function () use ($replaceIds, $server, $operation, $payload, $plan, $provider) {
                if ($replaceIds !== []) {
                    BeaconContentInstallation::query()
                        ->where('server_id', $server->id)->where('project_type', 'plugin')->where('destination', 'plugins')->where('provider', $provider)
                        ->whereIn('id', $replaceIds)
                        ->delete();
                }
                $dependencyIds = data_get($payload, 'plan.resolved_dependencies', []);
                foreach ($plan as $item) {
                    BeaconContentInstallation::query()->create([
                        'server_id' => $server->id,
                        'operation_id' => $operation->id,
                        'provider' => $provider,
                        'project_id' => $item['project_id'],
                        'project_name' => $item['project_name'] ?? $item['name'],
                        'version_id' => $item['version_id'],
                        'version_number' => $item['version_number'],
                        'icon_url' => $item['icon_url'] ?? null,
                        'project_type' => 'plugin',
                        'loader' => data_get($payload, 'runtime.loader'),
                        'game_version' => data_get($payload, 'runtime.game_version'),
                        'destination' => 'plugins',
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
                foreach ($movedNew as $item) {
                    if ($this->pathExists($files, 'plugins/' . $item['filename'])) {
                        $hashes = $files->getFileHashes('/plugins/' . $item['filename'], $item['size']);
                        if (!hash_equals($item['sha512'], $hashes['sha512'])) {
                            throw new ConflictHttpException('A changed plugin file could not be rolled back safely. Manual review is required.');
                        }
                        $files->deleteFiles('/plugins', [$item['filename']]);
                    }
                }
                $this->restoreMoves($files, $movedOld);
                $files->deleteFiles('/.beacon', array_filter([$stageName, $replace->isNotEmpty() ? $rollbackName : null]));
            } catch (\Throwable $rollbackException) {
                PublicOperationError::report($rollbackException, 'plugin.rollback', $this->operationId);
                $rollbackFailed = true;
            }
            if ($rollbackFailed) {
                throw new ConflictHttpException('The plugin operation failed and its file rollback also failed. Manual file review is required.', $exception);
            }

            throw $exception;
        }

        try {
            $files->deleteFiles('/.beacon', array_filter([$stageName, $replace->isNotEmpty() ? $rollbackName : null]));
        } catch (\Throwable $exception) {
            PublicOperationError::report($exception, 'plugin.file_change', $this->operationId);
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
        $ids = collect(data_get($operation->payload, 'remove_installation_ids', []))->filter(fn ($value) => is_int($value))->values()->all();
        $installations = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('provider', data_get($operation->payload, 'provider', 'modrinth'))
            ->where('project_type', 'plugin')->where('destination', 'plugins')
            ->whereIn('id', $ids ?: [0])
            ->get();
        if ($ids === [] || count($ids) !== $installations->count()) {
            throw new ConflictHttpException('One or more managed plugin records changed before uninstall started.');
        }

        $missing = $installations->first(fn (BeaconContentInstallation $item) => !$this->pathExists($files, $this->managedPath($item)));
        if ($missing instanceof BeaconContentInstallation) {
            throw new ConflictHttpException("The managed file '{$missing->filename}' is missing. No records were removed.");
        }

        $this->ensureDirectory($files, '.beacon', '/');
        $rollbackName = 'plugin-remove-' . $operation->uuid;
        $this->ensureDirectory($files, $rollbackName, '/.beacon');
        $moved = [];
        try {
            foreach ($installations as $item) {
                $move = ['from' => $this->managedPath($item), 'to' => ".beacon/{$rollbackName}/{$item->filename}"];
                $moved[] = $move;
                $files->renameFiles('/', [$move]);
            }
            DB::transaction(fn () => BeaconContentInstallation::query()
                ->where('server_id', $server->id)
                ->whereIn('id', $ids)
                ->delete());
        } catch (\Throwable $exception) {
            if ($moved) {
                try {
                    $this->restoreMoves($files, $moved);
                } catch (\Throwable $rollbackException) {
                    PublicOperationError::report($rollbackException, 'plugin.uninstall_rollback', $this->operationId);
                    throw new ConflictHttpException('Plugin uninstall failed and its file rollback also failed. Manual file review is required.', $exception);
                }
            }

            throw $exception;
        }

        try {
            $files->deleteFiles('/.beacon', [$rollbackName]);
        } catch (\Throwable $exception) {
            PublicOperationError::report($exception, 'plugin.uninstall', $this->operationId);
        }

        return [
            'action' => 'uninstall',
            'removed_projects' => $installations->pluck('project_id')->values()->all(),
            'safety_backup_uuid' => data_get($operation->payload, 'safety_backup_uuid'),
        ];
    }

    private function restoreMoves(DaemonFileRepository $files, array $moves): void
    {
        foreach (array_reverse($moves) as $move) {
            if ($this->pathExists($files, $move['to'])) {
                if ($this->pathExists($files, $move['from'])) {
                    throw new ConflictHttpException('A plugin rollback target already exists. Manual review is required.');
                }
                $files->renameFiles('/', [['from' => $move['to'], 'to' => $move['from']]]);
            } elseif (!$this->pathExists($files, $move['from'])) {
                throw new ConflictHttpException('A plugin rollback file is missing. Manual review is required.');
            }
        }
    }

    private function ensureDirectory(DaemonFileRepository $files, string $name, string $root): void
    {
        $path = rtrim($root, '/') ?: '/';
        $entry = collect($files->getDirectory($path))->first(fn (array $entry) => ($entry['name'] ?? null) === $name);
        if (is_array($entry) && (($entry['symlink'] ?? false) || ($entry['file'] ?? false))) {
            throw new ConflictHttpException('Managed plugin directories cannot be files or symbolic links.');
        }
        if (!$entry) {
            $files->createDirectory($name, $path);
        }
    }

    private function managedPath(BeaconContentInstallation $installation): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/D', $installation->filename)) {
            throw new ConflictHttpException('The stored plugin filename is unsafe. No files were changed.');
        }
        $path = $installation->status === 'disabled' ? $installation->disabled_path : null;
        if (is_string($path) && preg_match('#^plugins/[A-Za-z0-9._+-]+\.disabled$#', $path)) {
            return $path;
        }

        return 'plugins/' . $installation->filename;
    }

    private function pathExists(DaemonFileRepository $files, string $path): bool
    {
        $parent = str_replace('\\', '/', dirname($path));
        $directory = $parent === '.' ? '/' : '/' . trim($parent, '/');
        $directory = $directory === '/' ? '/' : rtrim($directory, '/');
        $name = basename($path);

        return collect($files->getDirectory($directory))
            ->contains(fn (array $entry) => ($entry['name'] ?? null) === $name && ($entry['file'] ?? false) === true && ($entry['symlink'] ?? false) === false);
    }

    private function assertFilesMatch(DaemonFileRepository $files, string $directory, array $plan, string $stage): void
    {
        $entries = collect($files->getDirectory($directory))->keyBy('name');
        foreach ($plan as $item) {
            $entry = $entries->get($item['filename']);
            if (!is_array($entry) || ($entry['file'] ?? false) !== true || ($entry['symlink'] ?? false) !== false
                || (int) ($entry['size'] ?? -1) !== (int) $item['size']) {
                throw new ConflictHttpException('A plugin file failed verification. No unverified file was recorded as installed.');
            }
        }
    }

    private function complete(BeaconOperation $operation, Server $server, mixed $result, bool $restarted): void
    {
        $payload = $operation->payload;
        unset($payload['pending_result']);
        $payload['phase'] = 'complete';
        $payload['progress'] = ['stage' => 'complete', 'percent' => 100, 'message' => 'The plugin operation completed.'];
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
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
            return 'Backup creation is temporarily rate limited. Try again after the backup cooldown.';
        }

        return PublicOperationError::message($exception, 'The plugin operation failed. Review the protected application logs.');
    }

    public function failed(?\Throwable $exception = null): void
    {
        BeaconOperation::query()->whereKey($this->operationId)->whereIn('status', ['pending', 'running'])->update([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => 'plugin_operation_failed',
            'error_message' => 'The plugin operation failed after all worker attempts.',
            'finished_at' => now(),
        ]);
    }
}
