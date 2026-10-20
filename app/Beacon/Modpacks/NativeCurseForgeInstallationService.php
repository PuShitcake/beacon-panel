<?php

namespace Pterodactyl\Beacon\Modpacks;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Operations\PublicOperationError;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Events\Server\Installed as ServerInstalled;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;
use Pterodactyl\Jobs\Beacon\FinalizeNativeCurseForgeInstallationJob;

/** Coordinates native Egg installs; deliberately does not run the retired picker's workflow. */
class NativeCurseForgeInstallationService
{
    public const TYPE = 'native_modpack.runtime';
    public const FAILURE = 'The modpack Java runtime could not be verified. Check the installation log and reinstall. The server was not started automatically.';

    public function __construct(private ModpackRuntimeService $runtime)
    {
    }

    public function manages(Server $server): bool
    {
        return !$server->skip_scripts && $this->runtime->isCurseForgeServer($server);
    }

    public function initialImage(): string
    {
        try {
            return $this->runtime->initialCurseForgeImage();
        } catch (ModpackProviderException) {
            throw new DisplayException('Configure a trusted Java 21 HotSpot image before creating a native CurseForge server.');
        }
    }

    public function validateSelection(array $environment): array
    {
        $project = $environment['PROJECT_ID'] ?? '';
        $version = $environment['VERSION'] ?? 'latest';
        if (!is_string($project) || !preg_match('/^(?:[1-9][0-9]{0,18}|zip)$/D', $project)) {
            throw new DisplayException('Enter a CurseForge Modpack Project ID, or zip for an uploaded server.zip, in Startup before installing.');
        }
        if (!is_string($version) || !preg_match('/^(?:[1-9][0-9]{0,18}|latest)$/D', $version)) {
            throw new DisplayException('Enter a CurseForge Modpack File ID or latest in Startup before installing.');
        }

        return ['PROJECT_ID' => $project, 'VERSION' => $version];
    }

    public function begin(Server $server, bool $reinstall, bool $start = false): BeaconOperation
    {
        // The callback must never execute HTTP requests back to Wings synchronously.
        $driver = config('queue.connections.' . config('queue.default') . '.driver');
        if (!in_array($driver, ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            throw new DisplayException('An asynchronous Laravel queue worker is required for automatic modpack Java selection.');
        }

        return DB::transaction(function () use ($server, $reinstall, $start) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (!$this->manages($server) || $server->isSuspended() || $server->transfer()->exists()) {
                throw new DisplayException('This server cannot begin a native CurseForge installation in its current state.');
            }
            // Legacy picker metadata and its rollback backup still require explicit migration.
            if ($reinstall && BeaconModpackInstallation::query()->where('server_id', $server->id)->exists()) {
                throw new DisplayException('This instance still has managed metadata from the retired Modpack Installer. An administrator must migrate that metadata before native reinstall is enabled. No files were changed.');
            }
            if ($reinstall && BeaconContentInstallation::query()->where('server_id', $server->id)
                ->where('project_type', 'mod')->where('destination', 'mods')->exists()) {
                throw new DisplayException('Uninstall Beacon-managed add-on mods before reinstalling or changing this modpack. If Startup IDs were changed, restore the previous IDs to remove the mods first. Native reinstall can overwrite their files. No files were changed.');
            }
            if (BeaconOperation::query()->where('server_id', $server->id)
                ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])->exists()) {
                throw new DisplayException('Another Beacon operation is still running for this server.');
            }

            $selection = $this->selection($server);
            $uuid = Uuid::uuid4()->toString();
            $operation = BeaconOperation::query()->create([
                'uuid' => $uuid,
                'server_id' => $server->id,
                'actor_key' => 'native-install:' . $server->id,
                'type' => self::TYPE,
                'status' => BeaconOperation::STATUS_PENDING,
                'correlation_id' => $uuid,
                'idempotency_key' => $uuid,
                'payload' => [
                    'phase' => $reinstall ? 'prepare' : 'wait_install',
                    'egg_id' => $server->egg_id,
                    'selection_hash' => $this->selectionHash($selection),
                    'reinstall' => $reinstall,
                    'initial_install' => $server->installed_at === null,
                    'start_requested' => $start,
                ],
            ]);
            $server->forceFill(['status' => Server::STATUS_INSTALLING])->save();

            return $operation;
        });
    }

    public function enqueue(BeaconOperation $operation): void
    {
        if ($this->terminal($operation)) {
            return;
        }
        try {
            Bus::dispatch((new FinalizeNativeCurseForgeInstallationJob($operation->id))->afterCommit()->delay(3));
        } catch (\Throwable $exception) {
            $this->fail($operation, $exception, 'queue_dispatch');
            throw new DisplayException('The modpack runtime worker queue was unavailable. Restore the queue and reinstall.');
        }
    }

    /** True means this lifecycle owns the callback, including duplicate callbacks. No Wings I/O. */
    public function installed(Server $server, bool $successful, bool $reinstall): bool
    {
        if (!$this->manages($server) || BeaconOperation::query()->where('server_id', $server->id)
            ->where('type', 'like', 'modpack.%')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])->exists()) {
            return false;
        }
        $operation = DB::transaction(function () use ($server, $successful, $reinstall) {
            Server::query()->lockForUpdate()->findOrFail($server->id);
            $operation = BeaconOperation::query()->where('server_id', $server->id)
                ->where('type', self::TYPE)->latest('id')->lockForUpdate()->first();
            if (!$operation || !$this->manages($server)) {
                return null;
            }
            if ($this->terminal($operation) || ($operation->payload['phase'] ?? null) !== 'wait_install'
                || ($operation->payload['reinstall'] ?? null) !== $reinstall) {
                return $operation;
            }
            if (!$successful) {
                $this->fail($operation, null, 'egg_install');

                return $operation;
            }
            $operation->forceFill(['payload' => array_replace($operation->payload, ['phase' => 'verify'])])->save();

            return $operation;
        });
        if (!$operation) {
            return false;
        }
        // Create/Reinstall already dispatched the polling job. Do not depend on Redis or
        // dispatch another job from Wings' callback (including duplicate callbacks).

        return true;
    }

    /** Called only by the queue worker; false means poll again without busy-waiting. */
    public function process(
        BeaconOperation $operation,
        CurseForgeInstalledRuntimeService $installedRuntime,
        DaemonFileRepository $files,
        DaemonServerRepository $wings,
        DaemonPowerRepository $power,
    ): bool {
        if ($this->terminal($operation)) {
            return true;
        }
        $server = $operation->server()->first();
        if (!$server) {
            $this->fail($operation, null, 'server_missing');

            return true;
        }
        if ($operation->created_at->lt(now()->subHours(2))) {
            throw new DisplayException('The native modpack installation timed out. Check Wings and the queue worker before reinstalling.');
        }
        if (!$this->manages($server) || $server->egg_id !== $operation->payload['egg_id']
            || !hash_equals($operation->payload['selection_hash'], $this->selectionHash($this->selection($server)))) {
            throw new DisplayException('The Egg or modpack selection changed during installation. Reinstall the selected modpack again.');
        }
        if ($server->isSuspended() || $server->transfer()->exists()) {
            throw new DisplayException('The server became suspended or started transferring during runtime verification.');
        }
        $payload = $operation->payload;
        $phase = $payload['phase'];
        if ($phase === 'wait_install') {
            return false;
        }
        $operation->forceFill(['status' => BeaconOperation::STATUS_RUNNING, 'started_at' => $operation->started_at ?? now()])->save();

        if ($phase === 'prepare') {
            $state = $wings->setServer($server)->getDetails()['state'] ?? null;
            if ($state !== 'offline') {
                if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
                    throw new DisplayException('Wings did not return a valid power state for native modpack installation.');
                }
                if (!isset($payload['stop_requested_at'])) {
                    $payload['stop_requested_at'] = now()->timestamp;
                    $operation->forceFill(['payload' => $payload])->save();
                    if ($state !== 'stopping') {
                        $power->setServer($server)->send('stop');
                    }
                } elseif (now()->timestamp - $payload['stop_requested_at'] > 180) {
                    throw new DisplayException('The server did not stop in time. No new native reinstall was started.');
                }

                return false;
            }
            // Native reinstall overwrites pack files and has no automatic picker rollback.
            // Remove only the bounded old manifest, so it cannot verify a different pack later.
            $installedRuntime->prepare($files->setServer($server));
            $operation->forceFill(['payload' => array_replace($payload, ['phase' => 'wait_install'])])->save();
            $wings->setServer($server)->reinstall();

            return false;
        }
        if ($phase !== 'verify') {
            throw new \LogicException('Invalid native modpack runtime phase.');
        }
        // Wings sets offline after its HTTP callback returns. Allow that transition to finish.
        if (($wings->setServer($server)->getDetails()['state'] ?? null) !== 'offline') {
            return false;
        }
        $release = $installedRuntime->resolve($server, $files, $wings, []);
        $this->runtime->applyNativeCurseForgeImage($server, (int) $release['java_version']);

        DB::transaction(function () use ($operation, $server, $release) {
            $current = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ($current->status !== Server::STATUS_INSTALLING || !$this->manages($current)
                || $current->transfer()->exists()
                || !hash_equals($operation->payload['selection_hash'], $this->selectionHash($this->selection($current)))) {
                throw new DisplayException('The server configuration changed during Java verification. Review it before reinstalling.');
            }
            $server->forceFill(['status' => null, 'installed_at' => now()])->save();
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_SUCCEEDED,
                'result' => $release,
                'finished_at' => now(),
            ])->save();
        });

        $notification = $payload['initial_install'] ? 'send_install_notification' : 'send_reinstall_notification';
        if (config('pterodactyl.email.' . $notification, true)) {
            try {
                event(new ServerInstalled($server));
            } catch (\Throwable $exception) {
                PublicOperationError::report($exception, 'native_modpack.notification', $operation->id);
            }
        }
        if ($payload['start_requested']) {
            try {
                $power->setServer($server)->send('start');
            } catch (\Throwable $exception) {
                PublicOperationError::report($exception, 'native_modpack.start', $operation->id);
                $operation->forceFill(['result' => $release + ['automatic_start_failed' => true]])->save();
            }
        }

        return true;
    }

    public function fail(BeaconOperation $operation, ?\Throwable $exception, string $stage): void
    {
        if ($exception) {
            PublicOperationError::report($exception, 'native_modpack.' . $stage, $operation->id);
        }
        DB::transaction(function () use ($operation) {
            $operation->refresh();
            if ($this->terminal($operation)) {
                return;
            }
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => 'native_modpack_runtime_failed',
                'error_message' => self::FAILURE,
                'finished_at' => now(),
            ])->save();
            Server::query()->whereKey($operation->server_id)->where('status', Server::STATUS_INSTALLING)
                ->update(['status' => Server::STATUS_INSTALL_FAILED]);
        });
    }

    private function selection(Server $server): array
    {
        return $this->validateSelection($server->variables()->get()->pluck('server_value', 'env_variable')->all());
    }

    private function selectionHash(array $selection): string
    {
        return hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR));
    }

    private function terminal(BeaconOperation $operation): bool
    {
        return in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true);
    }
}
