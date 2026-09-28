<?php

namespace Pterodactyl\Http\Controllers\Api\Beacon;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Api\BeaconResponse;
use Pterodactyl\Http\Requests\Api\Beacon\BeaconReadRequest;

class OperationController
{
    public function show(BeaconReadRequest $request, BeaconOperation $operation): JsonResponse
    {
        abort_unless($operation->api_key_id === $request->user()->currentAccessToken()->id, 404);

        return BeaconResponse::make($request, $this->data($operation));
    }

    public static function data(BeaconOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'type' => $operation->type,
            'status' => $operation->status,
            'server_uuid' => $operation->server?->uuid ?? data_get($operation->result, 'server_uuid'),
            'error' => $operation->error_code ? [
                'code' => $operation->error_code,
                'message' => $operation->error_message,
            ] : null,
            'created_at' => $operation->created_at?->toAtomString(),
            'started_at' => $operation->started_at?->toAtomString(),
            'finished_at' => $operation->finished_at?->toAtomString(),
        ];
    }
}
