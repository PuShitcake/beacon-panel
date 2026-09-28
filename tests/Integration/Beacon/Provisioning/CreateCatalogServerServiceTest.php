<?php

namespace Pterodactyl\Tests\Integration\Beacon\Provisioning;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconCatalogProfile;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Jobs\Beacon\CreateCatalogServerJob;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Beacon\Provisioning\CreateCatalogServerService;

class CreateCatalogServerServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testServiceBuildsTrustedRuntimeFromCatalogInsteadOfCallerInput(): void
    {
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);
        $owner = \Pterodactyl\Models\User::factory()->create();
        $actor = \Pterodactyl\Models\User::factory()->create(['root_admin' => true]);
        $key = ApiKey::factory()->create(['user_id' => $actor->id, 'key_type' => ApiKey::TYPE_APPLICATION]);
        $operation = BeaconOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'api_key_id' => $key->id,
            'user_id' => $actor->id,
            'actor_key' => 'api-key:' . $key->id,
            'type' => 'server.create',
            'status' => BeaconOperation::STATUS_PENDING,
            'correlation_id' => Uuid::uuid4()->toString(),
            'idempotency_key' => 'beacon-test-' . Uuid::uuid4()->toString(),
            'request_hash' => str_repeat('a', 64),
            'payload' => [
                'name' => 'Trusted Paper',
                'description' => null,
                'owner_uuid' => $owner->uuid,
                'application' => 'minecraft-java',
                'profile' => 'paper',
                'version' => config('beacon.minecraft_versions.0'),
                'preset' => 'small',
                'deployment' => ['locations' => [1], 'port_range' => ['25565']],
                'start_on_completion' => false,
            ],
        ]);
        $server = $this->createServerModel();

        $nativeService = \Mockery::mock(ServerCreationService::class);
        $nativeService->expects('handle')
            ->with(
                \Mockery::on(function (array $data) use ($operation, $owner) {
                    return $data['external_id'] === 'beacon:' . $operation->uuid
                        && $data['owner_id'] === $owner->id
                        && $data['environment']['SERVER_JARFILE'] === 'server.jar'
                        && $data['environment']['MINECRAFT_VERSION'] === config('beacon.minecraft_versions.0')
                        && $data['memory'] === 1024
                        && $data['disk'] === 5120
                        && !array_key_exists('docker_image', $operation->payload)
                        && !array_key_exists('startup', $operation->payload);
                }),
                \Mockery::type(DeploymentObject::class)
            )
            ->once()
            ->andReturn($server);

        $result = (new CreateCatalogServerService($nativeService))->handle($operation);

        $this->assertSame($server, $result);
        $this->assertDatabaseHas('beacon_server_metadata', ['server_id' => $server->id]);
    }

    public function testJobReconcilesMetadataBeforeSucceedingARecoveredProvisioningRetry(): void
    {
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);
        $owner = \Pterodactyl\Models\User::factory()->create();
        $actor = \Pterodactyl\Models\User::factory()->create(['root_admin' => true]);
        $key = ApiKey::factory()->create(['user_id' => $actor->id, 'key_type' => ApiKey::TYPE_APPLICATION]);
        $profile = BeaconCatalogProfile::query()->where('code', 'paper')->firstOrFail();
        $operation = BeaconOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'api_key_id' => $key->id,
            'user_id' => $actor->id,
            'actor_key' => 'api-key:' . $key->id,
            'type' => 'server.create',
            'status' => BeaconOperation::STATUS_RUNNING,
            'correlation_id' => Uuid::uuid4()->toString(),
            'idempotency_key' => 'beacon-test-' . Uuid::uuid4()->toString(),
            'request_hash' => str_repeat('a', 64),
            'payload' => [
                'name' => 'Recovered Paper',
                'description' => null,
                'owner_uuid' => $owner->uuid,
                'application' => 'minecraft-java',
                'profile' => 'paper',
                'version' => config('beacon.minecraft_versions.0'),
                'preset' => 'small',
                'deployment' => ['locations' => [1]],
                'start_on_completion' => false,
            ],
        ]);
        $server = $this->createServerModel([
            'external_id' => 'beacon:' . $operation->uuid,
            'owner_id' => $owner->id,
            'egg_id' => $profile->egg_id,
        ]);
        $nativeService = \Mockery::mock(ServerCreationService::class);
        $nativeService->expects('handle')->never();

        (new CreateCatalogServerJob($operation->id))->handle(new CreateCatalogServerService($nativeService));

        $this->assertDatabaseHas('beacon_server_metadata', [
            'server_id' => $server->id,
            'profile_id' => $profile->id,
        ]);
        $this->assertDatabaseHas('beacon_operations', [
            'id' => $operation->id,
            'server_id' => $server->id,
            'status' => BeaconOperation::STATUS_SUCCEEDED,
        ]);
    }
}
