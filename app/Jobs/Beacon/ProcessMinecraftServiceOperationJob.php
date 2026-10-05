<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Allocation;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\BeaconMinecraftService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\MinecraftServicePowerService;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceConfiguration;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceAllocationService;

class ProcessMinecraftServiceOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 60;
    public int $timeout = 300;
    public array $backoff = [5, 10, 30];

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function handle(
        MinecraftServicePowerService $power,
        MinecraftServiceConfiguration $configuration,
        MinecraftServiceAllocationService $allocations,
        DaemonServerRepository $wings,
    ): void {
        $operation = BeaconOperation::query()->findOrFail($this->operationId);
        if (in_array($operation->status, [BeaconOperation::STATUS_SUCCEEDED, BeaconOperation::STATUS_FAILED], true)) {
            return;
        }
        $server = $operation->server()->first();
        if (!$server instanceof Server) {
            throw new \RuntimeException('The server for this Minecraft service operation no longer exists.');
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

            $operation->refresh();
            $result = $this->mutate($operation, $server, $configuration, $allocations, $wings);
            if ($power->beginRestart($operation, $server, $result)) {
                $this->release(5);

                return;
            }

            $this->complete($operation, $server, $result, false);
        } catch (\Throwable $exception) {
            $this->reportSanitized($exception, 'operation');
            $rollbackFailed = str_contains(strtolower($exception->getMessage()), 'rollback also failed');
            try {
                if (!$rollbackFailed) {
                    $this->rollbackPersisted($operation->refresh(), $server, $configuration, $allocations, $wings);
                }
            } catch (\Throwable $rollbackException) {
                $this->reportSanitized($rollbackException, 'rollback');
                $rollbackFailed = true;
            }
            $operation->refresh()->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => 'minecraft_service_operation_failed',
                'error_message' => $rollbackFailed
                    ? 'The Minecraft service operation failed and rollback also failed. Manual review is required.'
                    : $this->safeError($exception),
                'result' => ['rollback' => ['status' => $rollbackFailed ? 'failed' : 'not_required_or_succeeded']],
                'finished_at' => now(),
            ])->save();
        }
    }

    private function mutate(
        BeaconOperation $operation,
        Server $server,
        MinecraftServiceConfiguration $configuration,
        MinecraftServiceAllocationService $allocations,
        DaemonServerRepository $wings,
    ): array {
        $service = (string) data_get($operation->payload, 'service');
        $action = (string) data_get($operation->payload, 'action');
        if (!in_array($service, ['rcon', 'query'], true)
            || !in_array($action, ['enable', 'disable', 'rotate'], true)
            || ($action === 'rotate' && $service !== 'rcon')) {
            throw new \InvalidArgumentException('The stored Minecraft service operation is invalid.');
        }

        $settingsExisted = BeaconMinecraftService::query()->where('server_id', $server->id)->exists();
        $settings = BeaconMinecraftService::query()->firstOrCreate(['server_id' => $server->id]);
        $settings->refresh();
        $flag = $service . '_enabled';
        $allocationRelation = $service === 'rcon' ? 'rconAllocation' : 'queryAllocation';
        $previous = [
            'enabled' => (bool) $settings->getAttribute($flag),
            'password' => $settings->rcon_password,
            'allocation_id' => $settings->getAttribute($service . '_allocation_id'),
            'last_operation_id' => $settings->last_operation_id,
        ];
        /** @var Allocation|null $previousAllocation */
        $previousAllocation = $settings->{$allocationRelation}()->first();
        $originalProperties = null;
        $allocation = null;

        try {
            if ($action === 'enable') {
                if ($previous['enabled']) {
                    throw new \RuntimeException(strtoupper($service) . ' is already enabled by Beacon.');
                }
                $allocation = $allocations->claim($server, $settings, $service);
                $wings->setServer($server->refresh())->sync();
                $values = $service === 'rcon'
                    ? [
                        'enable-rcon' => true,
                        'rcon.port' => $allocation->port,
                        'rcon.password' => $this->password(),
                    ]
                    : ['enable-query' => true, 'query.port' => $allocation->port];
                $originalProperties = $configuration->apply($server, $values);
                $settings->refresh()->forceFill([
                    $flag => true,
                    'rcon_password' => $service === 'rcon' ? $values['rcon.password'] : $settings->rcon_password,
                    'last_operation_id' => $operation->id,
                ])->save();
            } elseif ($action === 'disable') {
                if (!$previous['enabled'] || !$previousAllocation instanceof Allocation) {
                    throw new \RuntimeException(strtoupper($service) . ' is not managed or enabled by Beacon.');
                }
                $originalProperties = $configuration->apply($server, $service === 'rcon'
                    ? ['enable-rcon' => false, 'rcon.port' => null, 'rcon.password' => null]
                    : ['enable-query' => false, 'query.port' => null]);
                $allocations->release($server, $settings, $service);
                $wings->setServer($server->refresh())->sync();
                $settings->refresh()->forceFill([
                    $flag => false,
                    'rcon_password' => $service === 'rcon' ? null : $settings->rcon_password,
                    'last_operation_id' => $operation->id,
                ])->save();
                $allocation = $previousAllocation;
            } else {
                if (!$previous['enabled'] || !$previousAllocation instanceof Allocation) {
                    throw new \RuntimeException('RCON is not managed or enabled by Beacon.');
                }
                $password = $this->password();
                $originalProperties = $configuration->apply($server, [
                    'enable-rcon' => true,
                    'rcon.port' => $previousAllocation->port,
                    'rcon.password' => $password,
                ]);
                $settings->forceFill([
                    'rcon_password' => $password,
                    'last_operation_id' => $operation->id,
                ])->save();
                $allocation = $previousAllocation;
            }
        } catch (\Throwable $exception) {
            $rollbackFailed = false;
            try {
                if (is_string($originalProperties)) {
                    $configuration->restore($server, $originalProperties);
                }
                if ($action === 'enable') {
                    $settings->refresh();
                    if ($settings->getAttribute($service . '_allocation_id') !== null) {
                        $allocations->release($server, $settings, $service);
                        $wings->setServer($server->refresh())->sync();
                    }
                } elseif ($action === 'disable' && $previousAllocation instanceof Allocation) {
                    $allocations->restore($server, $settings, $service, $previousAllocation);
                    $wings->setServer($server->refresh())->sync();
                }
                $settings->refresh()->forceFill([
                    $flag => $previous['enabled'],
                    'rcon_password' => $previous['password'],
                    'last_operation_id' => $previous['last_operation_id'],
                ])->save();
            } catch (\Throwable $rollbackException) {
                $this->reportSanitized($rollbackException, 'rollback');
                $rollbackFailed = true;
            }
            if ($rollbackFailed) {
                throw new \RuntimeException('The Minecraft service change failed and rollback also failed. Manual review is required.', 0, $exception);
            }

            throw $exception;
        }

        $payload = $operation->payload;
        $payload['rollback_token'] = Crypt::encryptString(json_encode([
            'settings_existed' => $settingsExisted,
            'service' => $service,
            'action' => $action,
            'enabled' => $previous['enabled'],
            'password' => $previous['password'],
            'allocation_id' => $previous['allocation_id'],
            'last_operation_id' => $previous['last_operation_id'],
            'server_properties' => $originalProperties,
        ], JSON_THROW_ON_ERROR));
        $operation->forceFill(['payload' => $payload])->save();

        return [
            'service' => $service,
            'action' => $action,
            'enabled' => $action !== 'disable',
            'port' => $action === 'disable' ? null : $allocation?->port,
        ];
    }

    private function complete(BeaconOperation $operation, Server $server, mixed $result, bool $restarted): void
    {
        $payload = $operation->payload;
        unset($payload['pending_result'], $payload['rollback_token']);
        $payload['phase'] = 'complete';
        $payload['progress'] = ['stage' => 'complete', 'percent' => 100, 'message' => 'The Minecraft service operation completed.'];
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
            ->property('operation_uuid', $operation->uuid)
            ->property('service', data_get($operation->payload, 'service'))
            ->property('action', data_get($operation->payload, 'action'));
        if ($operation->user) {
            $activity->actor($operation->user);
        }
        $activity->log();
    }

    private function password(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function rollbackPersisted(
        BeaconOperation $operation,
        Server $server,
        MinecraftServiceConfiguration $configuration,
        MinecraftServiceAllocationService $allocations,
        DaemonServerRepository $wings,
    ): void {
        $token = data_get($operation->payload, 'rollback_token');
        if (!is_string($token) || $token === '') {
            return;
        }
        $state = data_get($wings->setServer($server)->getDetails(), 'state');
        if ($state !== 'offline') {
            throw new \RuntimeException('Automatic rollback requires the server to be offline. It was left for manual review.');
        }
        $snapshot = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !in_array($snapshot['service'] ?? null, ['rcon', 'query'], true)) {
            throw new \RuntimeException('The encrypted Minecraft service rollback snapshot is invalid.');
        }

        $service = $snapshot['service'];
        $settings = BeaconMinecraftService::query()->where('server_id', $server->id)->firstOrFail();
        if (($snapshot['action'] ?? null) === 'enable') {
            if ($settings->getAttribute($service . '_allocation_id') !== null) {
                $allocations->release($server, $settings, $service);
                $wings->setServer($server->refresh())->sync();
            }
        } elseif (($snapshot['action'] ?? null) === 'disable') {
            $allocationId = $snapshot['allocation_id'] ?? null;
            $allocation = is_int($allocationId) ? Allocation::query()->find($allocationId) : null;
            if (!$allocation instanceof Allocation) {
                throw new \RuntimeException('The previous Minecraft service allocation is unavailable for rollback.');
            }
            $allocations->restore($server, $settings, $service, $allocation);
            $wings->setServer($server->refresh())->sync();
        }

        $content = $snapshot['server_properties'] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('The Minecraft service rollback snapshot is missing server.properties.');
        }
        $configuration->restore($server, $content);
        $settings->refresh()->forceFill([
            $service . '_enabled' => (bool) ($snapshot['enabled'] ?? false),
            'rcon_password' => $snapshot['password'] ?? null,
            'last_operation_id' => $snapshot['last_operation_id'] ?? null,
        ])->save();
        if (($snapshot['settings_existed'] ?? true) === false
            && !$settings->rcon_enabled
            && !$settings->query_enabled
            && $settings->rcon_allocation_id === null
            && $settings->query_allocation_id === null) {
            $settings->delete();
        }

        $payload = $operation->payload;
        unset($payload['rollback_token'], $payload['pending_result']);
        $payload['phase'] = 'rolled_back';
        $operation->forceFill(['payload' => $payload])->save();
    }

    private function safeError(\Throwable $exception): string
    {
        return $exception instanceof \InvalidArgumentException
            || $exception instanceof \RuntimeException
            || $exception instanceof \Pterodactyl\Exceptions\DisplayException
                ? $exception->getMessage()
                : 'The Minecraft service operation failed. Review the protected application logs.';
    }

    private function reportSanitized(\Throwable $exception, string $stage): void
    {
        report(new \RuntimeException(sprintf(
            'Minecraft service %s failed with %s (code %d).',
            $stage,
            $exception::class,
            (int) $exception->getCode(),
        )));
    }

    public function failed(?\Throwable $exception = null): void
    {
        $operation = BeaconOperation::query()->find($this->operationId);
        if (!$operation instanceof BeaconOperation) {
            return;
        }
        $rollback = 'not_required_or_succeeded';
        try {
            $server = $operation->server()->first();
            if ($server instanceof Server) {
                $this->rollbackPersisted(
                    $operation,
                    $server,
                    app(MinecraftServiceConfiguration::class),
                    app(MinecraftServiceAllocationService::class),
                    app(DaemonServerRepository::class),
                );
            }
        } catch (\Throwable $rollbackException) {
            $this->reportSanitized($rollbackException, 'terminal rollback');
            $rollback = 'failed';
        }
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => 'minecraft_service_operation_failed',
            'error_message' => $rollback === 'failed'
                ? 'The Minecraft service operation exhausted its worker attempts and rollback failed. Manual review is required.'
                : 'The Minecraft service operation failed after all worker attempts.',
            'result' => ['rollback' => ['status' => $rollback]],
            'finished_at' => now(),
        ])->save();
    }
}
