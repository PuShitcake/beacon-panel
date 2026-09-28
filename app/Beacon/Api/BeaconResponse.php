<?php

namespace Pterodactyl\Beacon\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BeaconResponse
{
    public static function make(Request $request, mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'correlation_id' => $request->attributes->get('beacon_correlation_id'),
            ],
            'error' => null,
        ], $status);
    }
}
