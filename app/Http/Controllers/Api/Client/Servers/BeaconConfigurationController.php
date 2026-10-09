<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Minecraft\ServerPropertiesService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationReadRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest;

class BeaconConfigurationController extends ClientApiController
{
    public function __construct(
        private ServerPropertiesService $properties,
        private DaemonServerRepository $serverRepository,
        private ServerSoftwareCapabilityService $capabilities,
    ) {
        parent::__construct();
    }

    public function show(BeaconConfigurationReadRequest $request, Server $server): JsonResponse
    {
        $this->ensureMinecraftServer($server);

        return new JsonResponse(['data' => $this->properties->read($server)]);
    }

    public function update(BeaconConfigurationWriteRequest $request, Server $server): JsonResponse
    {
        $this->ensureMinecraftServer($server);
        if (BeaconOperation::query()
            ->where('server_id', $server->id)
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->where(function ($query) {
                $query->where('type', 'like', 'minecraft-service.%')
                    ->orWhere('type', 'like', 'modpack.%');
            })
            ->exists()) {
            throw new ConflictHttpException('Wait for the active Beacon operation before changing Minecraft configuration.');
        }
        if (config('beacon.minecraft_configuration.require_stopped_server')) {
            $details = $this->serverRepository->setServer($server)->getDetails();
            $state = data_get($details, 'state');
            if (!is_string($state) || $state === '') {
                throw new ConflictHttpException('Wings did not return a valid server state. No configuration was changed.');
            }
            if ($state !== 'offline') {
                throw new ConflictHttpException('Stop the server before changing Minecraft configuration.');
            }
        }

        $data = $this->properties->update($server, $request->string('hash')->toString(), $request->validated('properties'));
        Activity::event('beacon:minecraft.configuration.update')
            ->actor($request->user())
            ->subject($server)
            ->property('keys', array_keys($request->validated('properties')))
            ->log();

        return new JsonResponse(['data' => $data]);
    }

    private function ensureMinecraftServer(Server $server): void
    {
        $capabilities = $this->capabilities->resolve($server);
        if (!$capabilities['configuration']) {
            throw new ConflictHttpException('Managed Minecraft configuration is only available for supported Beacon Minecraft servers.');
        }

        if ($capabilities['software'] === 'curseforge'
            && !BeaconModpackInstallation::query()
                ->where('server_id', $server->id)
                ->where('status', 'installed')
                ->whereNotNull('installed_at')
                ->exists()) {
            throw new ConflictHttpException('Install a modpack before using Minecraft Configuration.');
        }
    }
}
