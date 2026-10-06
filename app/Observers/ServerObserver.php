<?php

namespace Pterodactyl\Observers;

use Pterodactyl\Events;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconSftpAlias;
use Illuminate\Foundation\Bus\DispatchesJobs;

class ServerObserver
{
    use DispatchesJobs;

    /**
     * Listen to the Server creating event.
     */
    public function creating(Server $server): void
    {
        event(new Events\Server\Creating($server));
    }

    /**
     * Listen to the Server created event.
     */
    public function created(Server $server): void
    {
        event(new Events\Server\Created($server));
    }

    /**
     * Listen to the Server deleting event.
     */
    public function deleting(Server $server): void
    {
        event(new Events\Server\Deleting($server));
    }

    /**
     * Listen to the Server deleted event.
     */
    public function deleted(Server $server): void
    {
        event(new Events\Server\Deleted($server));
    }

    /**
     * Listen to the Server saving event.
     */
    public function saving(Server $server): void
    {
        event(new Events\Server\Saving($server));
    }

    /**
     * Listen to the Server saved event.
     */
    public function saved(Server $server): void
    {
        event(new Events\Server\Saved($server));
    }

    /**
     * Listen to the Server updating event.
     */
    public function updating(Server $server): void
    {
        event(new Events\Server\Updating($server));
    }

    /**
     * Listen to the Server saved event.
     */
    public function updated(Server $server): void
    {
        if ($server->wasChanged('owner_id')) {
            BeaconSftpAlias::query()
                ->where('user_id', (int) $server->getOriginal('owner_id'))
                ->where('server_id', $server->id)
                ->delete();
        }

        event(new Events\Server\Updated($server));
    }
}
