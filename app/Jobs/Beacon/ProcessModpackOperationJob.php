<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Services\Backups\DownloadLinkService;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\PrepareModpackOperationService;
use Pterodactyl\Beacon\Modpacks\ManagedModpackBackupCleanupService;
use Pterodactyl\Beacon\Content\ReconcileManagedModsForModpackService;

class ProcessModpackOperationJob implements ShouldQueue
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
        PrepareModpackOperationService $prepare,
        ModpackRuntimeService $runtime,
        ReinstallServerService $reinstall,
        DaemonFileRepository $files,
        DaemonBackupRepository $backups,
        DaemonPowerRepository $power,
        DaemonServerRepository $servers,
        DownloadLinkService $downloadLinks,
        DeleteBackupService $deleteBackup,
        ManagedModpackBackupCleanupService $managedBackups,
        ReconcileManagedModsForModpackService $managedMods,
    ): void {
        $operation = BeaconOperation::query()->findOrFail($this->operationId);
        if (in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true)) {
            return;
        }
        $server = $operation->server()->first();
        if (!$server instanceof Server) {
            throw new \RuntimeException('The server for this modpack operation no longer exists.');
        }
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_RUNNING,
            'started_at' => $operation->started_at ?? now(),
        ])->save();

        $payload = $operation->payload;
        $phase = (string) ($payload['phase'] ?? 'prepare');

        try {
            if ($phase === 'wait_rollback') {
                $this->finishRollback($operation, $server);

                return;
            }
            if ($phase === 'recover_worker_failure') {
                $server->refresh();
                if ($server->status === Server::STATUS_INSTALLING) {
                    $this->release(10);

                    return;
                }
                $this->beginRollback(
                    $operation,
                    $server,
                    $runtime,
                    $backups,
                    $downloadLinks,
                    new \RuntimeException('The modpack worker stopped unexpectedly during installation.')
                );
                $this->release(10);

                return;
            }
            $this->assertNotTimedOut($operation);
            if (in_array($payload['action'] ?? null, ['uninstall', 'restore'], true)) {
                $this->restoreInstallation($operation, $server, $runtime, $backups, $power, $servers, $downloadLinks, $managedBackups);

                return;
            }
            if (($payload['runtime_mode'] ?? null) === 'curseforge_generic') {
                $this->processCurseForgeEgg(
                    $operation,
                    $server,
                    $payload,
                    $phase,
                    $prepare,
                    $runtime,
                    $reinstall,
                    $files,
                    $deleteBackup,
                    $managedMods,
                );

                return;
            }

            if ($phase === 'prepare') {
                $runtime->validateTargets($payload['release']);
                if (!$prepare->handle($operation, $server)) {
                    $this->release(10);

                    return;
                }
                $payload = $operation->fresh()->payload;
                $payload['original_runtime'] = $payload['original_runtime'] ?? $runtime->snapshot($server);
                $payload['phase'] = 'switch_installer';
                $payload['progress'] = ['stage' => 'preparing', 'percent' => 20, 'message' => 'Preparing the installer.'];
                $operation->forceFill(['payload' => $payload])->save();
                $phase = 'switch_installer';
            }

            if ($phase === 'switch_installer') {
                $repository = $files->setServer($server);
                $this->ensureBeaconDirectory($repository);
                if (($payload['mutation_started'] ?? false) !== true) {
                    $payload['mutation_started'] = true;
                    $operation->forceFill(['payload' => $payload])->save();
                }
                if (!array_key_exists('managed_mods_preserved', $payload)) {
                    $payload['managed_mods_preserved'] = ($payload['action'] ?? null) === 'install'
                        && ($payload['delete_files'] ?? false) === true
                            ? []
                            : $managedMods->preserve(
                                $server,
                                $repository,
                                $operation->uuid,
                                (string) ($payload['action'] ?? 'install'),
                            );
                    $operation->forceFill(['payload' => $payload])->save();
                }
                $manifest = array_merge($payload['release'], [
                    'max_archive_bytes' => (int) config('beacon.modpacks.max_archive_bytes'),
                    'max_extracted_bytes' => (int) config('beacon.modpacks.max_extracted_bytes'),
                ]);
                $repository->putContent('/.beacon/modpack-install.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $repository->putContent('/.beacon/install-result.json', json_encode([
                    'status' => 'pending',
                    'operation_uuid' => $operation->uuid,
                ], JSON_THROW_ON_ERROR));
                $payload['phase'] = 'wait_installer';
                $payload['progress'] = ['stage' => 'installing', 'percent' => 40, 'message' => 'Installing the selected modpack.'];
                $operation->forceFill(['payload' => $payload])->save();
                $payload['preserved_paths'] = $this->cleanFilesIfRequested($repository, $payload, $operation->uuid);
                $operation->forceFill(['payload' => $payload])->save();
                $server = $runtime->switchToInstaller($server);
                $reinstall->handle($server);
                $this->release(10);

                return;
            }

            if ($phase === 'wait_installer') {
                if ($this->waitForServerOperation($server)) {
                    $this->release(10);

                    return;
                }
                $result = json_decode($files->setServer($server)->getContent('/.beacon/install-result.json', 65536), true);
                if (!is_array($result) || ($result['status'] ?? null) !== 'succeeded') {
                    $message = is_array($result) && is_string($result['message'] ?? null)
                        ? trim($result['message'])
                        : '';
                    throw new \RuntimeException($message !== '' ? "The Beacon installer failed: {$message}" : 'The Beacon installer did not produce a successful result.');
                }
                foreach (['minecraft_version', 'loader', 'loader_version'] as $target) {
                    if (is_string($result[$target] ?? null) && $result[$target] !== '') {
                        $payload['release'][$target] = $result[$target];
                    }
                }
                if (!is_string($payload['release']['loader'] ?? null) || $payload['release']['loader'] === '') {
                    throw new \RuntimeException('The installed server pack did not identify a supported Minecraft loader.');
                }
                $this->restorePreservedFiles($files->setServer($server), $payload, $operation->uuid);
                $payload['preserved_paths_restored'] = true;
                $runtime->validateTargets($payload['release']);
                $payload['phase'] = 'switch_runtime';
                $payload['progress'] = ['stage' => 'runtime', 'percent' => 75, 'message' => 'Applying the compatible Minecraft runtime.'];
                $operation->forceFill(['payload' => $payload])->save();
                $phase = 'switch_runtime';
            }

            if ($phase === 'switch_runtime') {
                $server = $runtime->switchToRuntime($server, $payload['release']);
                if (($payload['release']['install_mode'] ?? null) === 'modrinth') {
                    $payload['phase'] = 'wait_runtime';
                    $operation->forceFill(['payload' => $payload])->save();
                    $reinstall->handle($server);
                    $this->release(10);

                    return;
                }
                $payload['phase'] = 'finalize';
                $operation->forceFill(['payload' => $payload])->save();
                $phase = 'finalize';
            }

            if ($phase === 'wait_runtime') {
                if ($this->waitForServerOperation($server)) {
                    $this->release(10);

                    return;
                }
                $payload['phase'] = 'finalize';
                $operation->forceFill(['payload' => $payload])->save();
                $phase = 'finalize';
            }

            if ($phase === 'finalize') {
                if (!array_key_exists('managed_mod_reconciliation', $payload)) {
                    $payload['managed_mod_reconciliation'] = $managedMods->restore(
                        $files->setServer($server),
                        $operation->uuid,
                        $payload['release'],
                        $payload['managed_mods_preserved'] ?? [],
                    );
                    $operation->forceFill(['payload' => $payload])->save();
                }
                $this->finalizeInstallation($operation, $server, $payload, $deleteBackup);
            }
        } catch (\Throwable $exception) {
            report($exception);
            if ($phase === 'recover_worker_failure') {
                $this->failOperation(
                    $operation,
                    'modpack_rollback_failed',
                    'The worker stopped unexpectedly and automatic recovery could not be started. Manual recovery is required.',
                    ['rollback' => ['status' => 'failed']]
                );

                return;
            }
            if (($payload['mutation_started'] ?? false) === true) {
                try {
                    $this->beginRollback($operation, $server, $runtime, $backups, $downloadLinks, $exception);
                    $this->release(10);

                    return;
                } catch (\Throwable $rollbackException) {
                    report($rollbackException);
                    $this->failOperation($operation, 'modpack_rollback_failed', 'Installation failed and automatic rollback could not be started. Manual recovery is required.', [
                        'rollback' => ['status' => 'failed'],
                    ]);

                    return;
                }
            }

            $this->failOperation($operation, 'modpack_operation_failed', $this->safeError($exception));
            $this->unlockUnusedInstallBackup($operation, $server);
        }
    }

    private function processCurseForgeEgg(
        BeaconOperation $operation,
        Server $server,
        array &$payload,
        string &$phase,
        PrepareModpackOperationService $prepare,
        ModpackRuntimeService $runtime,
        ReinstallServerService $reinstall,
        DaemonFileRepository $files,
        DeleteBackupService $deleteBackup,
        ReconcileManagedModsForModpackService $managedMods,
    ): void {
        if ($phase === 'prepare') {
            $runtime->validateCurseForgeTarget($payload['release']);
            if (!$prepare->handle($operation, $server)) {
                $this->release(10);

                return;
            }
            $payload = $operation->fresh()->payload;
            $payload['original_runtime'] = $payload['original_runtime'] ?? $runtime->snapshot($server);
            $payload['phase'] = 'switch_curseforge';
            $payload['progress'] = [
                'stage' => 'preparing',
                'percent' => 20,
                'message' => 'Preparing the CurseForge Generic Egg.',
            ];
            $operation->forceFill(['payload' => $payload])->save();
            $phase = 'switch_curseforge';
        }

        if ($phase === 'switch_curseforge') {
            $repository = $files->setServer($server);
            if (($payload['mutation_started'] ?? false) !== true) {
                $payload['mutation_started'] = true;
                $operation->forceFill(['payload' => $payload])->save();
            }
            if (!array_key_exists('managed_mods_preserved', $payload)) {
                $payload['managed_mods_preserved'] = ($payload['action'] ?? null) === 'install'
                    && ($payload['delete_files'] ?? false) === true
                        ? []
                        : $managedMods->preserve(
                            $server,
                            $repository,
                            $operation->uuid,
                            (string) ($payload['action'] ?? 'install'),
                        );
                $operation->forceFill(['payload' => $payload])->save();
            }
            $payload['preserved_paths'] = $this->cleanFilesIfRequested($repository, $payload, $operation->uuid);
            $payload['phase'] = 'wait_curseforge';
            $payload['progress'] = [
                'stage' => 'installing',
                'percent' => 40,
                'message' => 'Installing the selected modpack with CurseForge Generic.',
            ];
            $operation->forceFill(['payload' => $payload])->save();
            $server = $runtime->switchToCurseForge($server, $payload['release']);
            $reinstall->handle($server);
            $this->release(10);

            return;
        }

        if ($phase === 'wait_curseforge') {
            if ($this->waitForServerOperation($server)) {
                $this->release(10);

                return;
            }
            $repository = $files->setServer($server);
            $this->restorePreservedFiles($repository, $payload, $operation->uuid);
            $payload['preserved_paths_restored'] = true;
            $payload['phase'] = 'finalize_curseforge';
            $payload['progress'] = [
                'stage' => 'finalizing',
                'percent' => 85,
                'message' => 'Finalizing the CurseForge modpack installation.',
            ];
            $operation->forceFill(['payload' => $payload])->save();
            $phase = 'finalize_curseforge';
        }

        if ($phase === 'finalize_curseforge') {
            if (!array_key_exists('managed_mod_reconciliation', $payload)) {
                $payload['managed_mod_reconciliation'] = $managedMods->restore(
                    $files->setServer($server),
                    $operation->uuid,
                    $payload['release'],
                    $payload['managed_mods_preserved'] ?? [],
                );
                $operation->forceFill(['payload' => $payload])->save();
            }
            $this->finalizeInstallation($operation, $server, $payload, $deleteBackup);
        }
    }

    private function restoreInstallation(
        BeaconOperation $operation,
        Server $server,
        ModpackRuntimeService $runtime,
        DaemonBackupRepository $backups,
        DaemonPowerRepository $power,
        DaemonServerRepository $servers,
        DownloadLinkService $downloadLinks,
        ManagedModpackBackupCleanupService $managedBackups,
    ): void {
        $payload = $operation->payload;
        $phase = (string) ($payload['phase'] ?? 'prepare_restore');
        if ($phase === 'wait_start') {
            $state = $this->wingsState($servers, $server);
            if ($state === 'running') {
                $payload['phase'] = 'complete';
                $payload['progress'] = ['stage' => 'complete', 'percent' => 100, 'message' => 'Modpack uninstalled and server restarted.'];
                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_SUCCEEDED,
                    'payload' => $payload,
                    'result' => [
                        'restored_backup_uuid' => $payload['restored_backup_uuid'] ?? null,
                        'server_restarted' => true,
                    ],
                    'finished_at' => now(),
                ])->save();
                $this->log($operation, $server, (string) ($payload['action'] ?? 'uninstall'));

                return;
            }
            if (!in_array($state, ['offline', 'starting'], true)) {
                throw new \RuntimeException("The modpack was uninstalled, but the server entered the unexpected power state '{$state}'. Start it manually.");
            }
            if ($this->powerTransitionTimedOut($payload)) {
                throw new \RuntimeException('The modpack was uninstalled, but the server did not start within the configured time limit. Start it manually.');
            }
            $this->release(5);

            return;
        }

        $installation = BeaconModpackInstallation::query()
            ->where('server_id', $server->id)
            ->whereKey($payload['installation_id'] ?? 0)
            ->firstOrFail();
        if ($phase === 'wait_restore') {
            $fresh = $server->fresh();
            if ($fresh->status === Server::STATUS_RESTORING_BACKUP) {
                $this->release(10);

                return;
            }
            if (!is_null($fresh->status)) {
                throw new \RuntimeException('The backup restore did not complete successfully.');
            }
            $backupUuid = $installation->safety_backup_uuid;
            $restart = ($payload['action'] ?? null) === 'uninstall' && ($payload['restart_after_restore'] ?? false) === true;
            $payload['restored_backup_uuid'] = $backupUuid;
            $payload['phase'] = $restart ? 'wait_start' : 'complete';
            $payload['progress'] = $restart
                ? ['stage' => 'starting', 'percent' => 90, 'message' => 'Starting the restored server.']
                : ['stage' => 'complete', 'percent' => 100, 'message' => 'The server was restored to its pre-modpack state.'];
            if ($restart) {
                $payload['power_transition_started_at'] = now()->toISOString();
            }

            DB::transaction(function () use ($installation, $operation, $payload, $server, $backupUuid, $restart) {
                $installation->delete();
                BeaconContentInstallation::query()
                    ->where('server_id', $server->id)
                    ->where('project_type', 'mod')
                    ->delete();
                Backup::query()
                    ->where('server_id', $server->id)
                    ->where('uuid', $backupUuid)
                    ->update(['is_locked' => false]);
                $operation->forceFill([
                    'status' => $restart ? BeaconOperation::STATUS_RUNNING : BeaconOperation::STATUS_SUCCEEDED,
                    'payload' => $payload,
                    'result' => $restart ? null : [
                        'restored_backup_uuid' => $backupUuid,
                        'server_restarted' => false,
                    ],
                    'finished_at' => $restart ? null : now(),
                ])->save();
            });

            try {
                $managedBackups->deleteIfStale($server, $backupUuid);
            } catch (\Throwable $exception) {
                report($exception);
            }

            if ($restart) {
                $power->setServer($server)->send('start');
                $this->release(5);

                return;
            }

            $this->log($operation, $server, (string) ($payload['action'] ?? 'restore'));

            return;
        }

        $state = $this->wingsState($servers, $server);
        if ($phase === 'wait_stop') {
            if ($state !== 'offline') {
                if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
                    throw new \RuntimeException("The server entered the unexpected power state '{$state}' while stopping. No modpack files were changed.");
                }
                if ($this->powerTransitionTimedOut($payload)) {
                    throw new \RuntimeException('The server did not stop within the configured time limit. No modpack files were changed.');
                }
                $this->release(5);

                return;
            }
            $phase = 'prepare_restore';
        }

        if ($phase === 'prepare_restore' && ($payload['action'] ?? null) === 'uninstall' && $state !== 'offline') {
            if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
                throw new \RuntimeException("The server is in the unsupported power state '{$state}'. No modpack files were changed.");
            }
            $payload['restart_after_restore'] = in_array($state, ['running', 'starting'], true);
            $payload['power_transition_started_at'] = now()->toISOString();
            $payload['phase'] = 'wait_stop';
            $payload['progress'] = ['stage' => 'stopping', 'percent' => 10, 'message' => 'Stopping the server before uninstalling the modpack.'];
            $operation->forceFill(['payload' => $payload])->save();
            if ($state !== 'stopping') {
                $power->setServer($server)->send('stop');
            }
            $this->release(5);

            return;
        }

        if ($state !== 'offline') {
            throw new \RuntimeException('Stop the server before restoring or uninstalling its modpack.');
        }

        $backup = Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $installation->safety_backup_uuid)
            ->first();
        if (!$backup instanceof Backup || !$backup->is_successful || is_null($backup->completed_at)) {
            throw new \RuntimeException('The original modpack safety backup is unavailable.');
        }
        $server = $runtime->restore($server, $installation->original_runtime);
        $server->forceFill(['status' => Server::STATUS_RESTORING_BACKUP])->save();
        $url = $backup->disk === Backup::ADAPTER_AWS_S3
            ? $downloadLinks->handle($backup, $operation->user ?? $server->user)
            : null;
        $backups->setServer($server)->restore($backup, $url, true);
        $payload['phase'] = 'wait_restore';
        $payload['progress'] = ['stage' => 'restore', 'percent' => 70, 'message' => 'Restoring the server to its pre-modpack state.'];
        $operation->forceFill(['payload' => $payload])->save();
        $installation->forceFill(['status' => 'restoring'])->save();
        $this->release(10);
    }

    private function wingsState(DaemonServerRepository $servers, Server $server): string
    {
        $state = data_get($servers->setServer($server)->getDetails(), 'state');
        if (!is_string($state) || $state === '') {
            throw new \RuntimeException('Wings did not return a valid server power state. No modpack files were changed.');
        }

        return $state;
    }

    private function powerTransitionTimedOut(array $payload): bool
    {
        $startedAt = $payload['power_transition_started_at'] ?? null;
        if (!is_string($startedAt) || $startedAt === '') {
            throw new \RuntimeException('The modpack power transition is missing its start time. Manual review is required.');
        }

        try {
            $deadline = \Carbon\CarbonImmutable::parse($startedAt)
                ->addSeconds(max(30, (int) config('beacon.modpacks.power_transition_timeout_seconds', 120)));
        } catch (\Throwable) {
            throw new \RuntimeException('The modpack power transition has an invalid start time. Manual review is required.');
        }

        return $deadline->isPast();
    }

    private function beginRollback(
        BeaconOperation $operation,
        Server $server,
        ModpackRuntimeService $runtime,
        DaemonBackupRepository $backups,
        DownloadLinkService $downloadLinks,
        \Throwable $exception,
    ): void {
        $payload = $operation->payload;
        $backup = Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $payload['rollback_backup_uuid'] ?? '')
            ->firstOrFail();
        $server = $runtime->restore($server, $payload['original_runtime']);
        $server->forceFill(['status' => Server::STATUS_RESTORING_BACKUP])->save();
        $url = $backup->disk === Backup::ADAPTER_AWS_S3
            ? $downloadLinks->handle($backup, $operation->user ?? $server->user)
            : null;
        $backups->setServer($server)->restore($backup, $url, true);
        $payload['phase'] = 'wait_rollback';
        $payload['failure_message'] = $this->safeError($exception);
        $payload['progress'] = ['stage' => 'rollback', 'percent' => 90, 'message' => 'Installation failed. Restoring the safety backup.'];
        $operation->forceFill(['payload' => $payload])->save();
    }

    private function finishRollback(BeaconOperation $operation, Server $server): void
    {
        $payload = $operation->payload;
        $fresh = $server->fresh();
        if ($fresh->status === Server::STATUS_RESTORING_BACKUP) {
            $this->release(10);

            return;
        }
        if (!is_null($fresh->status)) {
            $this->failOperation($operation, 'modpack_rollback_failed', 'Installation failed and the rollback did not complete. Manual recovery is required.', [
                'rollback' => ['status' => 'failed'],
            ]);

            return;
        }
        $this->failOperation($operation, 'modpack_install_failed', $payload['failure_message'] ?? 'Modpack installation failed.', [
            'rollback' => ['status' => 'succeeded', 'backup_uuid' => $payload['rollback_backup_uuid']],
        ]);
        Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $payload['rollback_backup_uuid'])
            ->update(['is_locked' => false]);
    }

    private function finalizeInstallation(
        BeaconOperation $operation,
        Server $server,
        array $payload,
        DeleteBackupService $deleteBackup,
    ): void {
        $release = $payload['release'];
        $existing = BeaconModpackInstallation::query()->where('server_id', $server->id)->first();
        $originalRuntime = $payload['original_runtime'];
        $safetyBackup = $payload['rollback_backup_uuid'];
        $payload['phase'] = 'complete';
        $payload['progress'] = ['stage' => 'complete', 'percent' => 100, 'message' => 'Modpack installation completed.'];
        if ($existing instanceof BeaconModpackInstallation) {
            $originalRuntime = $existing->original_runtime;
            $safetyBackup = $existing->safety_backup_uuid;
        }

        DB::transaction(function () use ($operation, $server, $release, $originalRuntime, $safetyBackup, $payload) {
            BeaconModpackInstallation::query()->updateOrCreate(['server_id' => $server->id], [
                'operation_id' => $operation->id,
                'provider' => $release['provider'],
                'project_id' => $release['project_id'],
                'project_slug' => $release['project_slug'],
                'name' => $release['name'],
                'icon_url' => $release['icon_url'],
                'version_id' => $release['version_id'],
                'version_name' => $release['version_name'],
                'minecraft_version' => $release['minecraft_version'],
                'loader' => $release['loader'],
                'loader_version' => $release['loader_version'],
                'status' => 'installed',
                'safety_backup_uuid' => $safetyBackup,
                'original_runtime' => $originalRuntime,
                'manifest' => $release,
                'installed_at' => now(),
            ]);
            foreach ($payload['managed_mod_reconciliation'] ?? [] as $change) {
                if (!is_array($change) || !is_int($change['id'] ?? null)) {
                    continue;
                }
                BeaconContentInstallation::query()
                    ->where('server_id', $server->id)
                    ->whereKey($change['id'])
                    ->update([
                        'status' => $change['status'] ?? 'disabled',
                        'disabled_path' => $change['disabled_path'] ?? null,
                    ]);
            }
            if (($payload['action'] ?? null) === 'install' && ($payload['delete_files'] ?? false) === true) {
                BeaconContentInstallation::query()
                    ->where('server_id', $server->id)
                    ->where('project_type', 'mod')
                    ->delete();
            }
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_SUCCEEDED,
                'payload' => $payload,
                'result' => [
                    'provider' => $release['provider'],
                    'project_id' => $release['project_id'],
                    'version_id' => $release['version_id'],
                    'rollback_backup_uuid' => $payload['rollback_backup_uuid'] ?? null,
                    'managed_mods' => $payload['managed_mod_reconciliation'] ?? [],
                ],
                'finished_at' => now(),
            ])->save();
        });
        $this->log($operation, $server, (string) ($payload['action'] ?? 'install'));

        if (in_array($payload['action'] ?? null, ['update', 'reinstall'], true)) {
            $temporary = Backup::query()
                ->where('server_id', $server->id)
                ->where('uuid', $payload['rollback_backup_uuid'] ?? '')
                ->where('is_locked', false)
                ->first();
            if ($temporary instanceof Backup) {
                try {
                    $deleteBackup->handle($temporary);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    private function ensureBeaconDirectory(DaemonFileRepository $files): void
    {
        $exists = collect($files->getDirectory('/'))->contains(fn (array $entry) => ($entry['name'] ?? null) === '.beacon');
        if (!$exists) {
            $files->createDirectory('.beacon', '/');
        }
    }

    private function cleanFilesIfRequested(DaemonFileRepository $files, array $payload, string $operationUuid): array
    {
        $action = $payload['action'] ?? 'install';
        if (!(bool) ($payload['delete_files'] ?? false) && !in_array($action, ['update', 'reinstall'], true)) {
            return [];
        }
        $preserve = ['.beacon'];
        if ($action !== 'install') {
            $preserve = array_merge($preserve, [
                'world', 'world_nether', 'world_the_end', 'config', 'defaultconfigs',
                'server.properties', 'whitelist.json', 'ops.json', 'banned-ips.json', 'banned-players.json',
            ]);
        }
        $rootNames = collect($files->getDirectory('/'))
            ->pluck('name')
            ->filter(fn ($name) => is_string($name))
            ->values();
        $preserved = $rootNames
            ->filter(fn (string $name) => $name !== '.beacon' && in_array($name, $preserve, true))
            ->values()
            ->all();
        if ($preserved !== []) {
            $directory = "preserved-{$operationUuid}";
            $files->createDirectory($directory, '/.beacon');
            $files->renameFiles('/', collect($preserved)->map(fn (string $name) => [
                'from' => $name,
                'to' => ".beacon/{$directory}/{$name}",
            ])->all());
        }
        $names = $rootNames
            ->reject(fn (string $name) => in_array($name, $preserve, true))
            ->values()
            ->all();
        if ($names !== []) {
            $files->deleteFiles('/', $names);
        }

        return $preserved;
    }

    private function restorePreservedFiles(DaemonFileRepository $files, array $payload, string $operationUuid): void
    {
        $preserved = collect($payload['preserved_paths'] ?? [])->filter(fn ($name) => is_string($name))->values();
        if ($preserved->isEmpty() || ($payload['preserved_paths_restored'] ?? false) === true) {
            return;
        }

        $rootNames = collect($files->getDirectory('/'))->pluck('name')->filter(fn ($name) => is_string($name));
        $replace = $preserved->filter(fn (string $name) => $rootNames->contains($name))->values()->all();
        if ($replace !== []) {
            $files->deleteFiles('/', $replace);
        }

        $directory = "preserved-{$operationUuid}";
        $files->renameFiles('/', $preserved->map(fn (string $name) => [
            'from' => ".beacon/{$directory}/{$name}",
            'to' => $name,
        ])->all());
        $files->deleteFiles('/.beacon', [$directory]);
    }

    private function waitForServerOperation(Server $server): bool
    {
        $status = $server->fresh()->status;
        if ($status === Server::STATUS_INSTALLING) {
            return true;
        }
        if (in_array($status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true)) {
            throw new \RuntimeException('The Wings installation process failed.');
        }
        if (!is_null($status)) {
            throw new \RuntimeException('The server entered an unexpected state during installation.');
        }

        return false;
    }

    private function assertNotTimedOut(BeaconOperation $operation): void
    {
        if ($operation->started_at?->lt(now()->subMinutes((int) config('beacon.modpacks.operation_timeout_minutes')))) {
            throw new \RuntimeException('The modpack operation exceeded its configured time limit.');
        }
    }

    private function failOperation(BeaconOperation $operation, string $code, string $message, ?array $result = null): void
    {
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'result' => $result,
            'finished_at' => now(),
        ])->save();
    }

    private function unlockUnusedInstallBackup(BeaconOperation $operation, Server $server): void
    {
        $payload = $operation->payload;
        if (($payload['action'] ?? null) !== 'install' || ($payload['mutation_started'] ?? false) === true) {
            return;
        }
        Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $payload['rollback_backup_uuid'] ?? '')
            ->update(['is_locked' => false]);
    }

    private function safeError(\Throwable $exception): string
    {
        return $exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException
            ? $exception->getMessage()
            : 'The modpack operation failed. Review the protected application logs.';
    }

    private function log(BeaconOperation $operation, Server $server, string $action): void
    {
        $activity = Activity::event("beacon:modpack.{$action}")
            ->subject($server)
            ->property('correlation_id', $operation->correlation_id)
            ->property('operation_uuid', $operation->uuid);
        if ($operation->user) {
            $activity->actor($operation->user);
        }
        $activity->log();
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
            $payload['progress'] = [
                'stage' => 'rollback',
                'percent' => 90,
                'message' => 'The worker stopped unexpectedly. Starting automatic recovery.',
            ];
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_PENDING,
                'payload' => $payload,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
            ])->save();
            self::dispatch($operation->id);

            return;
        }

        $this->failOperation(
            $operation,
            'modpack_job_failed',
            'The modpack worker stopped after all retry attempts. Manual review may be required.'
        );
        $server = Server::query()->find($operation->server_id);
        if ($server instanceof Server) {
            $this->unlockUnusedInstallBackup($operation, $server);
        }
    }
}
