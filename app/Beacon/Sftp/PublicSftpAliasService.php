<?php

namespace Pterodactyl\Beacon\Sftp;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\BeaconSftpAlias;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Models\BeaconSftpAliasReservation;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Services\Servers\GetUserPermissionsService;

class PublicSftpAliasService
{
    private const CREATE_ATTEMPTS = 10;

    public function __construct(
        private ConnectionInterface $connection,
        private GetUserPermissionsService $permissions,
        private PublicSftpAliasGenerator $generator,
    ) {
    }

    public function provision(User $user, Server $server): BeaconSftpAlias
    {
        $permissions = $this->permissions->handle($server, $user);
        if (!in_array('*', $permissions, true) && !in_array(Permission::ACTION_FILE_SFTP, $permissions, true)) {
            throw new HttpForbiddenException('You do not have permission to access SFTP for this server.');
        }

        return $this->connection->transaction(function () use ($user, $server) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = BeaconSftpAlias::query()
                ->where('user_id', $lockedUser->id)
                ->where('server_id', $server->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            for ($attempt = 1; $attempt <= self::CREATE_ATTEMPTS; ++$attempt) {
                $publicUsername = $this->generator->generate($lockedUser);
                $matches = [];
                if (preg_match(BeaconSftpAlias::PUBLIC_USERNAME_REGEX, $publicUsername, $matches) !== 1
                    || (int) $matches[1] !== $lockedUser->id) {
                    throw new HttpException(500, 'The SFTP alias generator returned an invalid username.');
                }

                if (BeaconSftpAliasReservation::query()->where('public_username', $publicUsername)->exists()) {
                    continue;
                }

                BeaconSftpAliasReservation::query()->create(['public_username' => $publicUsername]);

                return BeaconSftpAlias::query()->create([
                    'user_id' => $lockedUser->id,
                    'server_id' => $server->id,
                    'public_username' => $publicUsername,
                ]);
            }

            throw new HttpException(409, 'A unique public SFTP alias could not be generated.');
        }, 3);
    }

    public function revoke(User $user, Server $server): void
    {
        BeaconSftpAlias::query()
            ->where('user_id', $user->id)
            ->where('server_id', $server->id)
            ->delete();
    }
}
