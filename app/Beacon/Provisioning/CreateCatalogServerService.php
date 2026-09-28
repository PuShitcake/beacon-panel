<?php

namespace Pterodactyl\Beacon\Provisioning;

use Illuminate\Support\Arr;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconCatalogProfile;
use Pterodactyl\Models\BeaconCatalogVersion;
use Pterodactyl\Models\BeaconResourcePreset;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Models\BeaconCatalogApplication;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Services\Servers\ServerCreationService;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CreateCatalogServerService
{
    public function __construct(private ServerCreationService $serverCreationService)
    {
    }

    public function handle(BeaconOperation $operation): Server
    {
        [$application, $profile, $version, $preset, $owner] = $this->resolveSelection($operation);
        $payload = $operation->payload;
        $images = array_values($profile->egg->docker_images ?? []);
        $image = $version->docker_image ?: Arr::first($images);
        $startup = $version->startup ?: $profile->egg->startup;
        if (!is_string($image) || $image === '' || !is_string($startup) || $startup === '') {
            throw new UnprocessableEntityHttpException('The selected catalog version has no trusted runtime configuration.');
        }

        $deployment = new DeploymentObject();
        $deployment->setLocations($payload['deployment']['locations']);
        $deployment->setDedicated($payload['deployment']['dedicated_ip'] ?? false);
        $deployment->setPorts($payload['deployment']['port_range'] ?? []);

        $server = $this->serverCreationService->handle([
            'external_id' => 'beacon:' . $operation->uuid,
            'name' => $payload['name'],
            'description' => $payload['description'] ?? '',
            'owner_id' => $owner->id,
            'egg_id' => $profile->egg_id,
            'image' => $image,
            'startup' => $startup,
            'environment' => $version->environment,
            'memory' => $preset->memory,
            'swap' => $preset->swap,
            'disk' => $preset->disk,
            'io' => $preset->io,
            'cpu' => $preset->cpu,
            'threads' => $preset->threads,
            'database_limit' => $preset->database_limit,
            'allocation_limit' => $preset->allocation_limit,
            'backup_limit' => $preset->backup_limit,
            'start_on_completion' => $payload['start_on_completion'] ?? false,
        ], $deployment);

        $this->persistMetadata($server, $application, $profile, $version, $preset);

        return $server;
    }

    public function reconcileMetadata(BeaconOperation $operation, Server $server): void
    {
        [$application, $profile, $version, $preset] = $this->resolveSelection($operation);
        if ($server->external_id !== 'beacon:' . $operation->uuid || $server->egg_id !== $profile->egg_id) {
            throw new \LogicException('The recovered server does not match the trusted Beacon provisioning request.');
        }

        $this->persistMetadata($server, $application, $profile, $version, $preset);
    }

    private function resolveSelection(BeaconOperation $operation): array
    {
        $payload = $operation->payload;
        $application = BeaconCatalogApplication::query()
            ->where('slug', $payload['application'])
            ->where('enabled', true)
            ->firstOrFail();
        $profile = BeaconCatalogProfile::query()
            ->with('egg')
            ->where('application_id', $application->id)
            ->where('code', $payload['profile'])
            ->where('enabled', true)
            ->firstOrFail();
        $version = BeaconCatalogVersion::query()
            ->where('profile_id', $profile->id)
            ->where('version', $payload['version'])
            ->where('enabled', true)
            ->where('deprecated', false)
            ->firstOrFail();
        $preset = BeaconResourcePreset::query()
            ->where('code', $payload['preset'])
            ->where('enabled', true)
            ->firstOrFail();
        $owner = User::query()->where('uuid', $payload['owner_uuid'])->firstOrFail();

        return [$application, $profile, $version, $preset, $owner];
    }

    private function persistMetadata(
        Server $server,
        BeaconCatalogApplication $application,
        BeaconCatalogProfile $profile,
        BeaconCatalogVersion $version,
        BeaconResourcePreset $preset,
    ): void {
        BeaconServerMetadata::query()->updateOrCreate(['server_id' => $server->id], [
            'server_id' => $server->id,
            'application_id' => $application->id,
            'profile_id' => $profile->id,
            'version_id' => $version->id,
            'preset_id' => $preset->id,
        ]);
    }
}
