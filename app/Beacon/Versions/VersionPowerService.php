<?php

namespace Pterodactyl\Beacon\Versions;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class VersionPowerService
{
    public function __construct(
        private DaemonServerRepository $servers,
        private DaemonPowerRepository $power,
    ) {
    }

    public function prepare(BeaconOperation $operation, Server $server): bool
    {
        $payload = $operation->payload;
        $state = $this->state($server);
        if (($payload['phase'] ?? null) === 'wait_stop') {
            if ($state === 'offline') {
                $payload['phase'] = 'backup';
                $payload['progress'] = ['stage' => 'backup', 'percent' => 20, 'message' => 'Creating a mandatory safety backup.'];
                $operation->forceFill(['payload' => $payload])->save();

                return true;
            }
            $this->assertTransition($state, $payload, 'stop');

            return false;
        }
        if (($payload['phase'] ?? null) === 'backup') {
            if ($state !== 'offline') {
                throw new \RuntimeException('The server must remain offline while its version is changed.');
            }

            return true;
        }
        if ($state === 'offline') {
            $payload['restart_after_mutation'] = false;
            $payload['phase'] = 'backup';
            $payload['progress'] = ['stage' => 'backup', 'percent' => 20, 'message' => 'Creating a mandatory safety backup.'];
            $operation->forceFill(['payload' => $payload])->save();

            return true;
        }
        if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
            throw new \RuntimeException("The server is in the unsupported power state '{$state}'. No files were changed.");
        }

        $payload['restart_after_mutation'] = in_array($state, ['running', 'starting'], true);
        $payload['phase'] = 'wait_stop';
        $payload['power_transition_started_at'] = now()->toISOString();
        $payload['progress'] = ['stage' => 'stopping', 'percent' => 10, 'message' => 'Stopping the server safely.'];
        $operation->forceFill(['payload' => $payload])->save();
        if ($state !== 'stopping') {
            $this->power->setServer($server)->send('stop');
        }

        return false;
    }

    public function restart(BeaconOperation $operation, Server $server): bool
    {
        $payload = $operation->payload;
        if (($payload['restart_after_mutation'] ?? false) !== true) {
            return false;
        }
        $payload['phase'] = 'wait_start';
        $payload['power_transition_started_at'] = now()->toISOString();
        $payload['progress'] = ['stage' => 'starting', 'percent' => 90, 'message' => 'Starting the server with its new version.'];
        $operation->forceFill(['payload' => $payload])->save();
        $this->power->setServer($server)->send('start');

        return true;
    }

    public function prepareRollback(BeaconOperation $operation, Server $server, string $failureMessage): bool
    {
        $payload = $operation->payload;
        $state = $this->state($server);
        if ($state === 'offline') {
            return true;
        }
        if (($payload['phase'] ?? null) === 'wait_rollback_stop') {
            $this->assertTransition($state, $payload, 'stop');

            return false;
        }
        if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
            throw new \RuntimeException("The server entered the unexpected power state '{$state}' before rollback.");
        }

        $payload['phase'] = 'wait_rollback_stop';
        $payload['failure_message'] = $failureMessage;
        $payload['power_transition_started_at'] = now()->toISOString();
        $payload['progress'] = ['stage' => 'rollback', 'percent' => 88, 'message' => 'Stopping the server before restoring its safety backup.'];
        $operation->forceFill(['payload' => $payload])->save();
        if ($state !== 'stopping') {
            $this->power->setServer($server)->send('stop');
        }

        return false;
    }

    public function waitForStart(BeaconOperation $operation, Server $server): bool
    {
        $state = $this->state($server);
        if ($state === 'running') {
            return true;
        }
        $this->assertTransition($state, $operation->payload, 'start');

        return false;
    }

    private function state(Server $server): string
    {
        $state = data_get($this->servers->setServer($server)->getDetails(), 'state');
        if (!is_string($state) || $state === '') {
            throw new \RuntimeException('Wings did not return a valid server power state. No files were changed.');
        }

        return $state;
    }

    private function assertTransition(string $state, array $payload, string $action): void
    {
        $allowed = $action === 'stop' ? ['running', 'starting', 'stopping'] : ['offline', 'starting'];
        if (!in_array($state, $allowed, true)) {
            throw new \RuntimeException("The server entered the unexpected power state '{$state}' while attempting to {$action}.");
        }
        $startedAt = $payload['power_transition_started_at'] ?? null;
        if (!is_string($startedAt)
            || CarbonImmutable::parse($startedAt)->addSeconds(max(30, (int) config('beacon.versions.power_transition_timeout_seconds', 120)))->isPast()) {
            throw new \RuntimeException("The server did not {$action} within the configured time limit.");
        }
    }
}
