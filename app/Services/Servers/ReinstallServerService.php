<?php

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeInstallationService;

class ReinstallServerService
{
    /**
     * ReinstallService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private NativeCurseForgeInstallationService $nativeCurseForge,
    ) {
    }

    /**
     * Reinstall a server on the remote daemon.
     *
     * @throws \Throwable
     * @throws DisplayException
     */
    public function handle(Server $server, bool $legacyModpackOperation = false): Server
    {
        if (!$server->canBeReinstalled()) {
            throw new DisplayException(trans('admin/server.exceptions.skipping_install_script'));
        }

        // Already-dispatched picker operations retain their own finalization/rollback owner.
        if (!$legacyModpackOperation && $this->nativeCurseForge->manages($server)) {
            $operation = $this->nativeCurseForge->begin($server, true);
            $this->nativeCurseForge->enqueue($operation);

            return $server->refresh();
        }

        return $this->connection->transaction(function () use ($server) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            if (\Pterodactyl\Models\BeaconOperation::query()->where('server_id', $server->id)
                ->where('type', 'like', 'plugin.%')
                ->whereIn('status', ['pending', 'running'])->exists()) {
                throw new DisplayException('Wait for the active plugin operation before reinstalling this server.');
            }
            $server->fill(['status' => Server::STATUS_INSTALLING])->save();

            $this->daemonServerRepository->setServer($server)->reinstall();

            return $server->refresh();
        });
    }
}
