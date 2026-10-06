<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Versions\McJarsVersionProvider;
use Pterodactyl\Jobs\Beacon\ProcessVersionOperationJob;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class VersionManagerControllerTest extends ClientApiIntegrationTestCase
{
    protected function tearDown(): void
    {
        BeaconOperation::query()->delete();

        parent::tearDown();
    }

    public function testOnlyTheOwnerCanAccessVersionManagerContext(): void
    {
        [$owner, $server] = $this->paperServer();

        $this->actingAs($owner)
            ->getJson($this->link($server, 'beacon/versions/context'))
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.current.software', 'paper');

        $subuser = \Pterodactyl\Models\User::factory()->create();
        Subuser::query()->create([
            'user_id' => $subuser->id,
            'server_id' => $server->id,
            'permissions' => [Permission::ACTION_FILE_READ],
        ]);

        $this->actingAs($subuser)
            ->getJson($this->link($server, 'beacon/versions/context'))
            ->assertForbidden();
    }

    public function testChangeRequiresRequestMetadataAndReplaysTheOriginalOperation(): void
    {
        [$owner, $server] = $this->paperServer();
        $selection = [
            'software' => 'paper',
            'version' => '1.21.8',
            'build_uuid' => '11111111-1111-4111-8111-111111111111',
        ];
        $target = [
            'software' => 'paper',
            'minecraft_version' => '1.21.8',
            'build_uuid' => $selection['build_uuid'],
            'build_name' => '#42',
            'build_number' => 42,
            'loader_version' => null,
            'java_version' => 21,
        ];
        $provider = \Mockery::mock(McJarsVersionProvider::class);
        $provider->expects('target')->once()->with('paper', '1.21.8', $selection['build_uuid'])->andReturn($target);
        $provider->expects('versions')->once()->with('paper')->andReturn([
            ['version' => '1.21.8', 'java' => 21, 'latest_build' => '#42', 'latest_build_uuid' => $selection['build_uuid']],
        ]);
        $this->app->instance(McJarsVersionProvider::class, $provider);
        Queue::fake();

        $this->actingAs($owner)
            ->postJson($this->link($server, 'beacon/versions/change'), $selection)
            ->assertUnprocessable();
        $this->assertDatabaseCount('beacon_operations', 0);
        $this->assertDatabaseHas('servers', ['id' => $server->id]);

        $headers = [
            'X-Correlation-ID' => Uuid::uuid4()->toString(),
            'Idempotency-Key' => 'version-test-' . Uuid::uuid4()->toString(),
        ];

        $first = $this->actingAs($owner)
            ->postJson($this->link($server, 'beacon/versions/change'), $selection, $headers)
            ->assertAccepted();
        $operationUuid = $first->json('data.uuid');
        Queue::assertPushed(ProcessVersionOperationJob::class, 1);

        BeaconOperation::query()->where('uuid', $operationUuid)->update([
            'status' => BeaconOperation::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);

        $this->actingAs($owner)
            ->postJson($this->link($server, 'beacon/versions/change'), $selection, $headers)
            ->assertAccepted()
            ->assertJsonPath('data.uuid', $operationUuid)
            ->assertJsonPath('data.status', BeaconOperation::STATUS_SUCCEEDED);
        Queue::assertPushed(ProcessVersionOperationJob::class, 1);

        $differentSelection = $selection;
        $differentSelection['version'] = '1.21.7';
        $this->actingAs($owner)
            ->postJson($this->link($server, 'beacon/versions/change'), $differentSelection, $headers)
            ->assertConflict();
    }

    private function paperServer(): array
    {
        config()->set('beacon.versions.enabled', true);
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);
        $nest = Nest::query()->where('name', config('beacon.modpacks.nest.name'))->firstOrFail();
        $egg = Egg::query()->where('nest_id', $nest->id)->where('name', 'Paper')->firstOrFail();
        $owner = \Pterodactyl\Models\User::factory()->create();

        return [$owner, $this->createServerModel(['owner_id' => $owner->id, 'egg_id' => $egg->id])];
    }
}
