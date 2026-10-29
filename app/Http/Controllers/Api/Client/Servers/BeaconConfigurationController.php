<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Beacon\Minecraft\VanillaConfigurationService;
use Pterodactyl\Beacon\Minecraft\MinecraftConfigurationAdapter;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationReadRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest;

class BeaconConfigurationController extends ClientApiController
{
    public function show(BeaconConfigurationReadRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => $this->configuration($server)->read($server)]);
    }

    public function update(BeaconConfigurationWriteRequest $request, Server $server): JsonResponse
    {
        // Each reviewed adapter enforces offline state, operation locks, fresh runtime/file
        // hashes and verified restore points within the same transaction as the write.
        $data = $this->configuration($server)->update($server, $request->string('hash')->toString(), $request->validated('properties'), $request->string('runtime_hash')->toString(), $request->validated('world_backup_uuid'));
        Activity::event('beacon:minecraft.configuration.update')
            ->actor($request->user())
            ->subject($server)
            ->property('keys', array_keys($request->validated('properties')))
            ->log();

        return new JsonResponse(['data' => $data]);
    }

    private function configuration(Server $server): VanillaConfigurationService
    {
        $adapter = MinecraftConfigurationAdapter::serviceClass($server);
        if ($adapter === null) {
            throw new ConflictHttpException('Managed Minecraft configuration is only available for supported Beacon Minecraft servers.');
        }

        return app($adapter);
    }
}
