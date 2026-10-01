<?php

namespace Pterodactyl\Http\Middleware\Api\Beacon;

use Ramsey\Uuid\Uuid;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequireBeaconRequestMetadata
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $correlationId = $request->header('X-Correlation-ID');
        if ($request->isMethodSafe()) {
            $correlationId = Uuid::isValid($correlationId ?? '') ? $correlationId : Uuid::uuid4()->toString();
        } elseif (!Uuid::isValid($correlationId ?? '')) {
            throw ValidationException::withMessages(['X-Correlation-ID' => 'A valid X-Correlation-ID UUID header is required for mutations.']);
        }

        if (!$request->isMethodSafe()) {
            $idempotencyKey = $request->header('Idempotency-Key');
            if (!is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) {
                throw ValidationException::withMessages(['Idempotency-Key' => 'An Idempotency-Key header containing 16 to 128 safe characters is required.']);
            }

            $request->attributes->set('beacon_idempotency_key', $idempotencyKey);
        }

        $request->attributes->set('beacon_correlation_id', $correlationId);
        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
