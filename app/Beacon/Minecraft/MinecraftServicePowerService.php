<?php

namespace Pterodactyl\Beacon\Minecraft;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class MinecraftServicePowerService
{
    public function __construct(
        private DaemonServerRepository $servers,
        private DaemonPowerRepository $power,
    ) {
    }

    public function prepare(BeaconOperation $operation, Server $server): bool
    {
        $payload = $operation->payload;
        $phase = (string) ($payload['phase'] ?? 'prepare');
        $state = $this->state($server);

        if ($phase === 'wait_stop') {
            if ($state === 'offline') {
                $payload['phase'] = 'mutating';
                $payload['progress'] = ['stage' => 'configuring', 'percent' => 40, 'message' => 'Applying the Minecraft service configuration.'];
                $operation->forceFill(['payload' => $payload])->save();

                return true;
            }
            if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
                throw new \RuntimeException("The server entered the unsupported power state '{$state}'. No settings were changed.");
            }
            if ($this->timedOut($payload)) {
                throw new \RuntimeException('The server did not stop within the configured time limit. No settings were changed.');
            }

            return false;
        }

        if ($phase === 'mutating') {
            if ($state !== 'offline') {
                throw new \RuntimeException('The server must remain offline while RCON or Query is being configured.');
            }

            return true;
        }
        if ($state === 'offline') {
            $payload['restart_after_mutation'] = false;
            $payload['phase'] = 'mutating';
            $payload['progress'] = ['stage' => 'configuring', 'percent' => 40, 'message' => 'Applying the Minecraft service configuration.'];
            $operation->forceFill(['payload' => $payload])->save();

            return true;
        }
        if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
            throw new \RuntimeException("The server is in the unsupported power state '{$state}'. No settings were changed.");
        }

        $payload['restart_after_mutation'] = in_array($state, ['running', 'starting'], true);
        $payload['phase'] = 'wait_stop';
        $payload['power_transition_started_at'] = now()->toISOString();
        $payload['progress'] = ['stage' => 'stopping', 'percent' => 15, 'message' => 'Stopping the server before changing its network services.'];
        $operation->forceFill(['payload' => $payload])->save();
        if ($state !== 'stopping') {
            $this->power->setServer($server)->send('stop');
        }

        return false;
    }

    public function beginRestart(BeaconOperation $operation, Server $server, array $result): bool
    {
        $payload = $operation->payload;
        if (($payload['restart_after_mutation'] ?? false) !== true) {
            return false;
        }

        $payload['phase'] = 'wait_start';
        $payload['pending_result'] = $result;
        $payload['power_transition_started_at'] = now()->toISOString();
        $payload['progress'] = ['stage' => 'starting', 'percent' => 90, 'message' => 'Starting the server with the updated network configuration.'];
        $operation->forceFill(['payload' => $payload])->save();
        $this->power->setServer($server)->send('start');

        return true;
    }

    public function waitForRestart(BeaconOperation $operation, Server $server): bool
    {
        $state = $this->state($server);
        if ($state === 'running') {
            return true;
        }
        if (!in_array($state, ['offline', 'starting'], true)) {
            throw new \RuntimeException("The settings were changed, but the server entered the unexpected power state '{$state}'. Start it manually.");
        }
        if ($this->timedOut($operation->payload)) {
            throw new \RuntimeException('The settings were changed, but the server did not start within the configured time limit. Start it manually.');
        }

        return false;
    }

    private function state(Server $server): string
    {
        $state = data_get($this->servers->setServer($server)->getDetails(), 'state');
        if (!is_string($state) || $state === '') {
            throw new \RuntimeException('Wings did not return a valid server power state. No settings were changed.');
        }

        return $state;
    }

    private function timedOut(array $payload): bool
    {
        $startedAt = $payload['power_transition_started_at'] ?? null;
        if (!is_string($startedAt) || $startedAt === '') {
            throw new \RuntimeException('The power transition is missing its start time. Manual review is required.');
        }

        try {
            $deadline = CarbonImmutable::parse($startedAt)
                ->addSeconds(max(30, (int) config('beacon.minecraft_services.power_transition_timeout_seconds', 120)));
        } catch (\Throwable) {
            throw new \RuntimeException('The power transition has an invalid start time. Manual review is required.');
        }

        return $deadline->isPast();
    }
}
