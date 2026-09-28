<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Beacon\Provisioning\CreateCatalogServerService;

class CreateCatalogServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function handle(CreateCatalogServerService $service): void
    {
        $operation = BeaconOperation::query()->findOrFail($this->operationId);
        $operation->forceFill([
            'status' => BeaconOperation::STATUS_RUNNING,
            'started_at' => $operation->started_at ?? now(),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            $server = $operation->server;
            if (!$server) {
                $server = \Pterodactyl\Models\Server::query()
                    ->where('external_id', 'beacon:' . $operation->uuid)
                    ->first();
            }
            if ($server) {
                $service->reconcileMetadata($operation, $server);
            } else {
                $server = $service->handle($operation);
            }

            $operation->forceFill([
                'server_id' => $server->id,
                'status' => BeaconOperation::STATUS_SUCCEEDED,
                'result' => ['server_uuid' => $server->uuid],
                'finished_at' => now(),
            ])->save();

            $activity = Activity::event('beacon:server.create')
                ->subject($server)
                ->property('correlation_id', $operation->correlation_id)
                ->property('operation_uuid', $operation->uuid);
            if ($operation->user) {
                $activity->actor($operation->user);
            }
            $activity->log();
        } catch (\Throwable $exception) {
            $operation->forceFill([
                'status' => BeaconOperation::STATUS_FAILED,
                'error_code' => 'provisioning_failed',
                'error_message' => 'Server provisioning failed. Review the protected application logs.',
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        BeaconOperation::query()->whereKey($this->operationId)->update([
            'status' => BeaconOperation::STATUS_FAILED,
            'error_code' => 'provisioning_failed',
            'error_message' => 'Server provisioning failed after all retry attempts.',
            'finished_at' => now(),
        ]);
    }
}
