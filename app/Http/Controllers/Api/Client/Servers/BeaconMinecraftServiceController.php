<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Ramsey\Uuid\Uuid;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconMinecraftService;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceConfiguration;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Jobs\Beacon\ProcessMinecraftServiceOperationJob;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconMinecraftServiceRequest;

class BeaconMinecraftServiceController extends ClientApiController
{
    public function __construct(
        private MinecraftServiceConfiguration $configuration,
        private StartContentOperationService $operations,
    ) {
        parent::__construct();
    }

    public function context(BeaconMinecraftServiceRequest $request, Server $server): JsonResponse
    {
        $this->ensureEligible($server);
        $settings = BeaconMinecraftService::query()
            ->with(['rconAllocation', 'queryAllocation'])
            ->where('server_id', $server->id)
            ->first();
        $properties = $this->configuration->inspect($server);
        $active = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'minecraft-service.%')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->latest('id')
            ->first();

        return new JsonResponse(['data' => [
            'enabled' => (bool) config('beacon.minecraft_services.enabled'),
            'authorized' => true,
            'automatic_power' => true,
            'rcon' => $this->serviceData($server, $settings, $properties, 'rcon'),
            'query' => $this->serviceData($server, $settings, $properties, 'query'),
            'active_operation' => $active ? $this->operationData($active) : null,
        ]]);
    }

    public function history(BeaconMinecraftServiceRequest $request, Server $server): JsonResponse
    {
        $this->ensureEligible($server);

        return new JsonResponse(['data' => BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'minecraft-service.%')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (BeaconOperation $operation) => $this->operationData($operation))]);
    }

    public function operation(
        BeaconMinecraftServiceRequest $request,
        Server $server,
        string $operationUuid,
    ): JsonResponse {
        $operation = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('uuid', $operationUuid)
            ->where('type', 'like', 'minecraft-service.%')
            ->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    public function mutate(BeaconMinecraftServiceRequest $request, Server $server): JsonResponse
    {
        if (!(bool) config('beacon.minecraft_services.enabled')) {
            throw new ConflictHttpException('The RCON & Query Manager is disabled.');
        }
        $this->ensureEligible($server);
        $validated = $request->validate([
            'service' => 'required|string|in:rcon,query',
            'action' => 'required|string|in:enable,disable,rotate',
        ]);
        $service = $validated['service'];
        $action = $validated['action'];
        if ($service === 'query' && $action === 'rotate') {
            throw new ConflictHttpException('Query does not use a password and cannot be rotated.');
        }
        $saved = BeaconMinecraftService::query()->where('server_id', $server->id)->first();
        if ($action === 'enable' && !(bool) ($saved?->getAttribute($service . '_enabled') ?? false)) {
            $properties = $this->configuration->inspect($server);
            $key = $service === 'rcon' ? 'enable-rcon' : 'enable-query';
            if (strtolower((string) ($properties[$key] ?? 'false')) === 'true') {
                throw new ConflictHttpException(strtoupper($service) . ' is already enabled outside Beacon. No settings were changed.');
            }
        }

        [$operation, $created] = DB::transaction(function () use ($request, $server, $service, $action) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $key = $this->idempotencyKey($request);
            $actorKey = 'user:' . $request->user()->id;
            $replay = BeaconOperation::query()
                ->where('actor_key', $actorKey)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($replay instanceof BeaconOperation) {
                if ($replay->server_id !== $server->id || $replay->type !== "minecraft-service.{$service}.{$action}") {
                    throw new ConflictHttpException('The idempotency key was already used with a different request.');
                }

                return [$replay, false];
            }

            $settings = BeaconMinecraftService::query()->where('server_id', $server->id)->lockForUpdate()->first();
            $isEnabled = (bool) ($settings?->getAttribute($service . '_enabled') ?? false);
            if ($action === 'enable' && $isEnabled) {
                throw new ConflictHttpException(strtoupper($service) . ' is already enabled by Beacon.');
            }
            if ($action !== 'enable' && !$isEnabled) {
                throw new ConflictHttpException(strtoupper($service) . ' is not managed or enabled by Beacon.');
            }

            $active = BeaconOperation::query()
                ->where('server_id', $server->id)
                ->where(function ($query) {
                    $query->where('type', 'like', 'minecraft-service.%')
                        ->orWhere('type', 'like', 'mod.%')
                        ->orWhere('type', 'like', 'modpack.%')
                        ->orWhere('type', 'like', 'version.%');
                })
                ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
                ->latest('id')
                ->first();
            if ($active instanceof BeaconOperation
                && ($active->actor_key !== $actorKey || $active->idempotency_key !== $key)) {
                throw new ConflictHttpException('Another Beacon server mutation is already running.');
            }
            if ($active instanceof BeaconOperation) {
                if ($active->type !== "minecraft-service.{$service}.{$action}") {
                    throw new ConflictHttpException('The idempotency key was already used with a different request.');
                }

                return [$active, false];
            }

            return $this->operations->handle(
                $request->user(),
                $server,
                "minecraft-service.{$service}.{$action}",
                $this->correlationId($request),
                $key,
                [
                    'service' => $service,
                    'action' => $action,
                    'phase' => 'prepare',
                    'progress' => ['stage' => 'queued', 'percent' => 0, 'message' => 'Waiting for the worker.'],
                ],
            );
        }, 5);

        if ($created) {
            try {
                ProcessMinecraftServiceOperationJob::dispatch($operation->id);
            } catch (\Throwable $exception) {
                report($exception);
                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_FAILED,
                    'error_code' => 'minecraft_service_queue_unavailable',
                    'error_message' => 'The Minecraft service worker queue is unavailable. No settings were changed.',
                    'finished_at' => now(),
                ])->save();

                throw new ServiceUnavailableHttpException(null, 'The Minecraft service worker queue is unavailable. No settings were changed.', $exception);
            }
        }

        return new JsonResponse(['data' => $this->operationData($operation)], JsonResponse::HTTP_ACCEPTED);
    }

    private function serviceData(
        Server $server,
        ?BeaconMinecraftService $settings,
        array $properties,
        string $service,
    ): array {
        $allocation = $service === 'rcon' ? $settings?->rconAllocation : $settings?->queryAllocation;
        $tracked = (bool) ($settings?->getAttribute($service . '_enabled') ?? false);
        $propertyEnabled = strtolower((string) ($properties[$service === 'rcon' ? 'enable-rcon' : 'enable-query'] ?? 'false')) === 'true';
        $propertyPort = (int) ($properties[$service === 'rcon' ? 'rcon.port' : 'query.port'] ?? 0);
        $allocationValid = $allocation !== null
            && $allocation->server_id === $server->id
            && $allocation->id !== $server->allocation_id;
        $healthy = $tracked && $propertyEnabled && $allocationValid && $propertyPort === $allocation->port;
        $drift = null;
        if (!$tracked && $propertyEnabled) {
            $drift = strtoupper($service) . ' is enabled outside Beacon and will not be modified automatically.';
        } elseif ($tracked && !$healthy) {
            $drift = 'The saved Beacon state does not match server.properties or the assigned allocation.';
        }

        return [
            'managed' => $tracked,
            'enabled' => $healthy,
            'status' => $healthy ? 'enabled' : ($drift ? 'attention' : 'disabled'),
            'address' => $allocationValid ? $allocation->alias : null,
            'port' => $allocationValid ? $allocation->port : null,
            'password_configured' => $service === 'rcon' && $healthy && !empty($settings?->rcon_password),
            'drift' => $drift,
        ];
    }

    private function ensureEligible(Server $server): void
    {
        if (strcasecmp((string) $server->nest?->name, (string) config('beacon.modpacks.nest.name', 'Beacon')) !== 0) {
            throw new ConflictHttpException('RCON & Query Manager is available only for Minecraft servers in the Beacon nest.');
        }
    }

    private function operationData(BeaconOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'type' => $operation->type,
            'service' => data_get($operation->payload, 'service'),
            'action' => data_get($operation->payload, 'action'),
            'status' => $operation->status,
            'progress' => data_get($operation->payload, 'progress'),
            'result' => $operation->result,
            'error' => $operation->error_code ? [
                'code' => $operation->error_code,
                'message' => $operation->error_message,
            ] : null,
            'created_at' => $operation->created_at?->toAtomString(),
            'finished_at' => $operation->finished_at?->toAtomString(),
        ];
    }

    private function correlationId(Request $request): string
    {
        $value = $request->attributes->get('beacon_correlation_id');

        return is_string($value) && Uuid::isValid($value) ? $value : Uuid::uuid4()->toString();
    }

    private function idempotencyKey(Request $request): string
    {
        $value = $request->attributes->get('beacon_idempotency_key');
        if (!is_string($value) || $value === '') {
            throw new \LogicException('Minecraft service mutations require an idempotency key.');
        }

        return $value;
    }
}
