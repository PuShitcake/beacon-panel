<?php

namespace Pterodactyl\Observers;

use Pterodactyl\Events;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\BeaconSftpAlias;
use Pterodactyl\Notifications\AddedToServer;
use Pterodactyl\Notifications\RemovedFromServer;

class SubuserObserver
{
    /**
     * Listen to the Subuser creating event.
     */
    public function creating(Subuser $subuser): void
    {
        event(new Events\Subuser\Creating($subuser));
    }

    /**
     * Listen to the Subuser created event.
     */
    public function created(Subuser $subuser): void
    {
        event(new Events\Subuser\Created($subuser));

        $subuser->user->notify(new AddedToServer([
            'user' => $subuser->user->name_first,
            'name' => $subuser->server->name,
            'uuidShort' => $subuser->server->uuidShort,
        ]));
    }

    /**
     * Listen to the Subuser deleting event.
     */
    public function deleting(Subuser $subuser): void
    {
        BeaconSftpAlias::query()
            ->where('user_id', $subuser->user_id)
            ->where('server_id', $subuser->server_id)
            ->delete();

        event(new Events\Subuser\Deleting($subuser));
    }

    /**
     * Revoke the public alias when a subuser loses SFTP permission. The reservation
     * remains so that a revoked public username can never be issued again.
     */
    public function updated(Subuser $subuser): void
    {
        if (!in_array(Permission::ACTION_FILE_SFTP, $subuser->permissions, true)) {
            BeaconSftpAlias::query()
                ->where('user_id', $subuser->user_id)
                ->where('server_id', $subuser->server_id)
                ->delete();
        }
    }

    /**
     * Listen to the Subuser deleted event.
     */
    public function deleted(Subuser $subuser): void
    {
        event(new Events\Subuser\Deleted($subuser));

        $subuser->user->notify(new RemovedFromServer([
            'user' => $subuser->user->name_first,
            'name' => $subuser->server->name,
        ]));
    }
}
