<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\BeaconSftpAlias;
use Pterodactyl\Models\BeaconSftpAliasReservation;
use Pterodactyl\Beacon\Sftp\PublicSftpAliasGenerator;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class SftpAliasControllerTest extends ClientApiIntegrationTestCase
{
    public function testOwnerCanProvisionStablePublicAlias(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $first = $this->actingAs($user)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->json('data.username');

        $this->assertMatchesRegularExpression('/^@' . $user->id . '\.[a-z0-9]{8}$/', $first);
        $this->assertDatabaseHas('beacon_sftp_aliases', [
            'user_id' => $user->id,
            'server_id' => $server->id,
            'public_username' => $first,
        ]);
        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $first]);

        $this->actingAs($user)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->assertJsonPath('data.username', $first);

        $this->assertSame(1, BeaconSftpAlias::query()->where('user_id', $user->id)->where('server_id', $server->id)->count());
    }

    public function testSubuserRequiresSftpPermissionToProvisionAlias(): void
    {
        [$withoutPermission, $server] = $this->generateTestAccount([Permission::ACTION_FILE_READ]);

        $this->actingAs($withoutPermission)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertForbidden();

        $subuser = Subuser::query()->where('user_id', $withoutPermission->id)->where('server_id', $server->id)->sole();
        $subuser->update(['permissions' => [Permission::ACTION_FILE_READ, Permission::ACTION_FILE_SFTP]]);

        $this->actingAs($withoutPermission)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->assertJsonPath('data.username', fn (string $username) => preg_match('/^@' . $withoutPermission->id . '\.[a-z0-9]{8}$/', $username) === 1);
    }

    public function testRevokedSubuserAliasIsNeverReused(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_SFTP]);
        $first = $this->actingAs($user)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->json('data.username');

        $subuser = Subuser::query()->where('user_id', $user->id)->where('server_id', $server->id)->sole();
        $subuser->update(['permissions' => [Permission::ACTION_FILE_READ]]);

        $this->assertDatabaseMissing('beacon_sftp_aliases', ['public_username' => $first]);
        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $first]);

        $subuser->update(['permissions' => [Permission::ACTION_FILE_SFTP]]);
        $second = $this->actingAs($user)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->json('data.username');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $first]);
        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $second]);
    }

    public function testAliasGeneratorRetriesPermanentReservationCollisions(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $first = sprintf('@%d.aaaaaaaa', $user->id);
        $second = sprintf('@%d.bbbbbbbb', $user->id);
        BeaconSftpAliasReservation::query()->create(['public_username' => $first]);

        $generator = \Mockery::mock(PublicSftpAliasGenerator::class);
        $generator->expects('generate')->twice()->with(\Mockery::on(fn (User $value) => $value->id === $user->id))
            ->andReturn($first, $second);
        $this->app->instance(PublicSftpAliasGenerator::class, $generator);

        $this->actingAs($user)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->assertJsonPath('data.username', $second);

        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $first]);
        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $second]);
    }

    public function testOwnerTransferRevokesPreviousOwnerAlias(): void
    {
        [$owner, $server] = $this->generateTestAccount();
        $alias = $this->actingAs($owner)
            ->postJson($this->link($server, 'settings/sftp-alias'))
            ->assertOk()
            ->json('data.username');
        $newOwner = User::factory()->create();

        $server->update(['owner_id' => $newOwner->id]);

        $this->assertDatabaseMissing('beacon_sftp_aliases', ['public_username' => $alias]);
        $this->assertDatabaseHas('beacon_sftp_alias_reservations', ['public_username' => $alias]);
    }
}
