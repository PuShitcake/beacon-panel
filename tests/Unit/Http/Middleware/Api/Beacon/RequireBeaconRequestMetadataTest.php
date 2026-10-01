<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware\Api\Beacon;

use Ramsey\Uuid\Uuid;
use Illuminate\Http\Request;
use Pterodactyl\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Http\Middleware\Api\Beacon\RequireBeaconRequestMetadata;

class RequireBeaconRequestMetadataTest extends TestCase
{
    public function testUnsafeRequestReceivesValidatedIdempotencyMetadataWithoutNamedRoute(): void
    {
        $correlationId = Uuid::uuid4()->toString();
        $idempotencyKey = Uuid::uuid4()->toString();
        $request = Request::create('/api/client/servers/test/beacon/modpacks/install', 'POST', [], [], [], [
            'HTTP_X_CORRELATION_ID' => $correlationId,
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        ]);

        $response = (new RequireBeaconRequestMetadata())->handle($request, fn () => response()->noContent());

        $this->assertSame($correlationId, $request->attributes->get('beacon_correlation_id'));
        $this->assertSame($idempotencyKey, $request->attributes->get('beacon_idempotency_key'));
        $this->assertSame($correlationId, $response->headers->get('X-Correlation-ID'));
    }

    public function testUnsafeRequestWithoutIdempotencyKeyIsRejected(): void
    {
        $request = Request::create('/api/client/servers/test/beacon/modpacks/install', 'POST', [], [], [], [
            'HTTP_X_CORRELATION_ID' => Uuid::uuid4()->toString(),
        ]);

        $this->expectException(ValidationException::class);

        (new RequireBeaconRequestMetadata())->handle($request, fn () => response()->noContent());
    }

    public function testSafeRequestDoesNotRequireIdempotencyKey(): void
    {
        $request = Request::create('/api/client/servers/test/beacon/modpacks/context', 'GET');

        (new RequireBeaconRequestMetadata())->handle($request, fn () => response()->noContent());

        $this->assertTrue(Uuid::isValid((string) $request->attributes->get('beacon_correlation_id')));
        $this->assertNull($request->attributes->get('beacon_idempotency_key'));
    }
}
