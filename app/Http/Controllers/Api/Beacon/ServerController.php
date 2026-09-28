<?php

namespace Pterodactyl\Http\Controllers\Api\Beacon;

use Illuminate\Http\Request;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Beacon\Api\BeaconResponse;
use Pterodactyl\Jobs\Beacon\CreateCatalogServerJob;
use Pterodactyl\Services\Servers\SuspensionService;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Http\Requests\Api\Beacon\BeaconReadRequest;
use Pterodactyl\Http\Requests\Api\Beacon\BeaconWriteRequest;
use Pterodactyl\Beacon\Operations\StartServerProvisioningService;
use Pterodactyl\Http\Requests\Api\Beacon\StoreBeaconServerRequest;

class ServerController
{
    public function __construct(
        private StartServerProvisioningService $startProvisioningService,
        private ReinstallServerService $reinstallServerService,
        private SuspensionService $suspensionService,
        private ServerDeletionService $serverDeletionService,
    ) {
    }

    public function store(StoreBeaconServerRequest $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        assert($token instanceof ApiKey);

        [$operation, $created] = $this->startProvisioningService->handle(
            $token,
            $request->user()->id,
            $request->attributes->get('beacon_correlation_id'),
            $request->attributes->get('beacon_idempotency_key'),
            $request->validated(),
        );

        if ($created) {
            CreateCatalogServerJob::dispatch($operation->id);
        }

        return BeaconResponse::make($request, OperationController::data($operation), 202);
    }

    public function show(BeaconReadRequest $request, Server $server): JsonResponse
    {
        return BeaconResponse::make($request, $this->serverData($server));
    }

    public function reinstall(BeaconWriteRequest $request, Server $server): JsonResponse
    {
        $this->reinstallServerService->handle($server);
        $this->logLifecycle($request, $server, 'reinstall');

        return BeaconResponse::make($request, $this->serverData($server->refresh()), 202);
    }

    public function suspend(BeaconWriteRequest $request, Server $server): JsonResponse
    {
        $this->suspensionService->toggle($server);
        $this->logLifecycle($request, $server, 'suspend');

        return BeaconResponse::make($request, $this->serverData($server->refresh()));
    }

    public function unsuspend(BeaconWriteRequest $request, Server $server): JsonResponse
    {
        $this->suspensionService->toggle($server, SuspensionService::ACTION_UNSUSPEND);
        $this->logLifecycle($request, $server, 'unsuspend');

        return BeaconResponse::make($request, $this->serverData($server->refresh()));
    }

    public function delete(BeaconWriteRequest $request, Server $server): JsonResponse
    {
        $uuid = $server->uuid;
        $this->logLifecycle($request, $server, 'delete');
        $this->serverDeletionService->handle($server);

        return BeaconResponse::make($request, ['server_uuid' => $uuid, 'deleted' => true]);
    }

    private function serverData(Server $server): array
    {
        return [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'status' => $server->status,
            'suspended' => $server->isSuspended(),
            'owner_uuid' => $server->user->uuid,
            'node_id' => $server->node_id,
            'egg_id' => $server->egg_id,
            'created_at' => $server->created_at?->toAtomString(),
        ];
    }

    private function logLifecycle(Request $request, Server $server, string $action): void
    {
        Activity::event("beacon:server.{$action}")
            ->subject($server)
            ->property('correlation_id', $request->attributes->get('beacon_correlation_id'))
            ->log();
    }
}
