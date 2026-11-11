<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Addons\AddonStore;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Beacon\Addons\AddonRuntime;
use Pterodactyl\Beacon\Addons\AddonFilesystem;
use Pterodactyl\Models\BeaconAddonInstallation;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Pterodactyl\Beacon\Addons\CurseForgeAddonProvider;
use Pterodactyl\Beacon\Operations\PublicOperationError;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ProcessAddonOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 900;
    public int $timeout = 55;

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('addon-operation:' . $this->operationId))->releaseAfter(5)->expireAfter(70)];
    }

    public function handle(AddonStore $store, AddonRuntime $runtime, AddonFilesystem $files, PrepareContentMutationService $backup, DaemonServerRepository $servers, DaemonPowerRepository $power): void
    {
        $operation = BeaconOperation::where('type', 'like', 'content.addon.%')->findOrFail($this->operationId);
        if (in_array($operation->status, ['succeeded', 'failed'], true)) {
            return;
        }
        $server = $operation->server;
        try {
            if (!$server instanceof Server || !$operation->user || (!$operation->user->root_admin && $server->owner_id !== $operation->user->id)) {
                throw new ConflictHttpException('This add-on operation is no longer authorized.');
            }
            $operation->forceFill(['status' => 'running', 'started_at' => $operation->started_at ?? now()])->save();
            $payload = $operation->payload;
            $phase = $payload['phase'] ?? 'prepare';
            $runtime->assertSupported($server);
            if (!hash_equals($payload['runtime']['selection'], $runtime->selection($server))) {
                throw new ConflictHttpException('The Bedrock runtime selection changed. No further add-on changes were made.');
            }
            if ($operation->started_at->lt(now()->subMinutes(35))) {
                throw new ConflictHttpException('The add-on operation exceeded its deadline. Review its safety backup.');
            }
            $state = data_get($servers->setServer($server)->getDetails(), 'state');
            if (!in_array($state, ['running', 'starting', 'stopping', 'offline'], true)) {
                throw new ConflictHttpException('Wings did not return a supported Bedrock power state.');
            }
            if ($phase === 'wait_start') {
                if ($state !== 'running') {
                    if (now()->gt(\Carbon\Carbon::parse($payload['power_started'])->addSeconds(180))) {
                        throw new ConflictHttpException('The add-on files were changed, but Bedrock did not start in time. Start it manually.');
                    }
                    $this->release(5);

                    return;
                }
                $this->complete($operation, $payload['pending_result']);

                return;
            }
            if ($phase === 'committing') {
                // Never replay a possibly completed filesystem mutation after a crash.
                throw new ConflictHttpException('An add-on commit was interrupted. Its safety backup needs manual review.');
            }
            if ($phase === 'committed') {
                $this->finishCommitted($operation, $server, $power, $state);

                return;
            }
            if ($phase === 'prepare') {
                if ($runtime->resolve($server, $payload['runtime']) !== $payload['runtime']) {
                    throw new ConflictHttpException('The Bedrock binary changed. Refresh the add-on preview.');
                }
                $payload['restart'] = in_array($state, ['running', 'starting'], true);
                $payload['power_started'] = now()->toISOString();
                $this->phase($operation, $payload, $state === 'offline' ? 'backup' : 'wait_stop', 10, 'Stopping Bedrock and preparing a full safety backup.');
                if (in_array($state, ['running', 'starting'], true)) {
                    $power->setServer($server)->send('stop');
                }
                $this->release(5);

                return;
            }
            if ($phase === 'wait_stop' && $state !== 'offline') {
                if (now()->gt(\Carbon\Carbon::parse($payload['power_started'])->addSeconds(180))) {
                    throw new ConflictHttpException('Bedrock did not stop in time. No add-on files were changed.');
                }
                $this->release(5);

                return;
            }
            if ($state !== 'offline') {
                throw new ConflictHttpException('Bedrock must stay offline while its add-ons are changed.');
            }
            if (in_array($phase, ['backup', 'wait_stop'], true)) {
                if (!config('beacon.content.backup_before_mutation')) {
                    throw new ConflictHttpException('Enable full managed-content safety backups before changing add-ons.');
                }
                $files->backupSafe($server);
                try {
                    $ready = $backup->handle($operation, $server, true);
                } catch (TooManyRequestsHttpException $e) {
                    $this->phase($operation, $operation->fresh()->payload, 'backup', 15, 'Waiting for the native backup cooldown. No add-on files have changed.');
                    $this->release(max(5, min(60, (int) ($e->getHeaders()['Retry-After'] ?? 30))));

                    return;
                }
                if (!$ready) {
                    $operation->refresh();
                    $this->phase($operation, $operation->payload, 'backup', 20, 'Creating a full safety backup.');
                    $this->release(10);

                    return;
                }
                $operation->refresh();
                $payload = $operation->payload;
                $prepared = $store->load($server, $payload['archive_id']);
                if ($payload['action'] !== 'uninstall' && $prepared['source']['provider'] === 'curseforge' && !app(CurseForgeAddonProvider::class)->available()) {
                    throw new ConflictHttpException('CurseForge is disabled. No add-on files were changed.');
                }
                if ($runtime->resolve($server, $payload['runtime']) !== $payload['runtime']) {
                    throw new ConflictHttpException('The Bedrock binary changed while the backup was running.');
                }
                if ($payload['action'] !== 'uninstall') {
                    $runtime->assertMinimum($prepared['manifest'], $payload['runtime']['version']);
                }
                $runtime->assertWorld($server, $payload['world']);
                $remove = $payload['action'] === 'uninstall';
                $payload['cursor'] = 0;
                $this->phase($operation, $payload, 'staging', 35, 'Preparing add-on files.');
                if (!$remove) {
                    $files->stage($operation, $server, $prepared, $store->path($server, $payload['archive_id']));
                }
                $payload = $operation->payload;
                $payload['tree'] = $files->tree($server, $files->stagePath($operation), $prepared['manifest']['packs'], $remove);
                $this->phase($operation, $payload, 'verify', 45, 'Verifying every add-on file.');
                $this->release(1);

                return;
            }
            if ($phase !== 'verify') {
                throw new ConflictHttpException('An interrupted add-on staging attempt needs manual review.');
            }
            $prepared = $store->load($server, $payload['archive_id']);
            $packs = $prepared['manifest']['packs'];
            $remove = $payload['action'] === 'uninstall';
            $progress = $files->verify($operation, $server, $packs, $remove, $payload['cursor']);
            $payload['cursor'] = $progress['cursor'];
            $this->phase($operation, $payload, 'verify', 45 + (int) (35 * $progress['cursor'] / max(1, $progress['count'])), 'Verifying every add-on file.');
            if ($progress['cursor'] < $progress['count']) {
                $this->release(1);

                return;
            }
            if (!hash_equals($payload['tree'], $files->tree($server, $files->stagePath($operation), $packs, $remove))) {
                throw new ConflictHttpException('Add-on files changed during verification. No world pack list was changed.');
            }
            if ($runtime->resolve($server->fresh(), $payload['runtime']) !== $payload['runtime']) {
                throw new ConflictHttpException('The Bedrock binary changed before activating the add-on.');
            }
            $snapshot = $files->snapshot($operation, $server, $packs, $remove);
            $payload['restore'] = $snapshot;
            $this->phase($operation, $payload, 'committing', 85, 'Updating the selected world pack lists.');
            DB::transaction(function () use ($operation, $server, $files, $snapshot, $prepared, $remove, $payload) {
                Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                $files->commit($server, $snapshot);
                if ($remove) {
                    $record = BeaconAddonInstallation::where('server_id', $server->id)->whereKey($payload['installation_id'])->firstOrFail();
                    if ($record->world !== $payload['world'] || $record->archive_id !== $payload['archive_id']) {
                        throw new ConflictHttpException('The managed add-on record changed.');
                    }
                    $record->delete();
                } else {
                    BeaconAddonInstallation::create(['server_id' => $server->id, 'operation_id' => $operation->id, 'world' => $payload['world'],
                        'provider' => $prepared['source']['provider'], 'project_id' => $prepared['source']['project_id'] ?? null,
                        'version_id' => $prepared['source']['version_id'] ?? null, 'archive_id' => $payload['archive_id'], 'manifest' => $prepared['manifest'] + ['runtime' => $payload['runtime']]]);
                }
                $next = $operation->payload;
                unset($next['restore']);
                $next['phase'] = 'committed';
                $next['pending_result'] = ['action' => $payload['action'], 'world' => $payload['world'], 'packs' => array_column($prepared['manifest']['packs'], 'name')];
                $operation->forceFill(['payload' => $next])->save();
            });
            $operation->refresh();
            $this->finishCommitted($operation, $server, $power, 'offline');
        } catch (\Throwable $e) {
            PublicOperationError::report($e, 'addon.operation', $this->operationId);
            $operation->refresh();
            $snapshot = data_get($operation->payload, 'restore');
            $rollbackFailed = false;
            if ($server instanceof Server && is_array($snapshot)) {
                try {
                    if (data_get($servers->setServer($server)->getDetails(), 'state') !== 'offline') {
                        throw new \RuntimeException();
                    }
                    $files->rollback($server, $snapshot);
                } catch (\Throwable $rollback) {
                    $rollbackFailed = true;
                    PublicOperationError::report($rollback, 'addon.rollback', $this->operationId);
                }
            }
            $operation->forceFill(['status' => 'failed', 'error_code' => $rollbackFailed ? 'addon_rollback_failed' : 'addon_operation_failed',
                'error_message' => $rollbackFailed ? 'The add-on operation failed and needs manual recovery from its safety backup.' : PublicOperationError::message($e, 'The add-on operation could not be completed. Review its safety backup and try again.'),
                'finished_at' => now()])->save();
        }
    }

    private function phase(BeaconOperation $operation, array $payload, string $phase, int $percent, string $message): void
    {
        $payload['phase'] = $phase;
        $payload['progress'] = ['stage' => $phase, 'percent' => $percent, 'message' => $message];
        $operation->forceFill(['payload' => $payload])->save();
    }

    private function complete(BeaconOperation $operation, array $result): void
    {
        $payload = $operation->payload;
        $payload['progress'] = ['stage' => 'completed', 'percent' => 100, 'message' => 'Add-on operation completed.'];
        $operation->forceFill(['payload' => $payload, 'status' => 'succeeded', 'result' => $result, 'finished_at' => now()])->save();
        try {
            \Pterodactyl\Facades\Activity::event('beacon:addon.completed')->actor($operation->user)->subject($operation->server)->property('operation', $operation->uuid)->log();
        } catch (\Throwable $e) {
            PublicOperationError::report($e, 'addon.activity', $operation->id);
        }
    }

    private function finishCommitted(BeaconOperation $operation, Server $server, DaemonPowerRepository $power, string $state): void
    {
        $payload = $operation->payload;
        if (($payload['restart'] ?? false) && $state !== 'running') {
            $payload['power_started'] = now()->toISOString();
            $this->phase($operation, $payload, 'wait_start', 95, 'Starting Bedrock with the updated pack lists.');
            if ($state === 'offline') {
                $power->setServer($server)->send('start');
            }
            $this->release(5);

            return;
        }
        $this->complete($operation, $payload['pending_result']);
    }
}
