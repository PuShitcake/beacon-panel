<?php

namespace Pterodactyl\Beacon\Versions;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconCatalogProfile;
use Pterodactyl\Models\BeaconCatalogVersion;
use Pterodactyl\Models\BeaconResourcePreset;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Models\BeaconCatalogApplication;

class VersionMetadataService
{
    public function reconcile(Server $server, array $target): void
    {
        $software = (string) ($target['software'] ?? '');
        if (!in_array($software, ['vanilla', 'paper', 'forge'], true)) {
            throw new \InvalidArgumentException('The version target cannot be recorded in the Beacon catalog.');
        }

        DB::transaction(function () use ($server, $target, $software) {
            $application = BeaconCatalogApplication::query()->where('slug', 'minecraft-java')->firstOrFail();
            $metadata = BeaconServerMetadata::query()->where('server_id', $server->id)->lockForUpdate()->first();
            $presetId = $metadata?->preset_id
                ?? BeaconResourcePreset::query()->where('enabled', true)->orderBy('id')->value('id');
            if (!is_numeric($presetId)) {
                throw new \RuntimeException('No Beacon resource preset is available for runtime metadata.');
            }
            $presetId = (int) $presetId;

            $profile = BeaconCatalogProfile::query()->firstOrNew([
                'application_id' => $application->id,
                'code' => $software,
            ]);
            $profile->fill([
                'egg_id' => $server->egg_id,
                'name' => ucfirst($software),
                'loader' => $software,
                'content_directory' => match ($software) {
                    'paper' => 'plugins',
                    'forge' => 'mods',
                    default => null,
                },
            ]);
            if (!$profile->exists) {
                // Forge creation stays gated until its standalone provisioning contract is reviewed.
                $profile->enabled = $software !== 'forge';
            }
            $profile->save();

            $loaderVersion = $software === 'forge' ? (string) ($target['loader_version'] ?? '') : '';
            $version = BeaconCatalogVersion::query()->updateOrCreate([
                'profile_id' => $profile->id,
                'version' => (string) $target['minecraft_version'],
                'loader_version' => $loaderVersion,
            ], [
                'docker_image' => $server->image,
                'startup' => null,
                'environment' => $this->environment($software, $target),
                'enabled' => true,
                'deprecated' => false,
            ]);

            BeaconServerMetadata::query()->updateOrCreate(['server_id' => $server->id], [
                'application_id' => $application->id,
                'profile_id' => $profile->id,
                'version_id' => $version->id,
                'preset_id' => $presetId,
            ]);
        }, 5);
    }

    private function environment(string $software, array $target): array
    {
        return match ($software) {
            'vanilla' => [
                'SERVER_JARFILE' => 'server.jar',
                'VANILLA_VERSION' => $target['minecraft_version'],
            ],
            'paper' => [
                'SERVER_JARFILE' => 'server.jar',
                'MINECRAFT_VERSION' => $target['minecraft_version'],
                'BUILD_NUMBER' => (string) $target['build_number'],
                'DL_PATH' => '',
            ],
            'forge' => [
                'SERVER_JARFILE' => 'server.jar',
                'MC_VERSION' => $target['minecraft_version'],
                'BUILD_TYPE' => 'latest',
                'FORGE_VERSION' => $target['minecraft_version'] . '-' . $target['loader_version'],
            ],
        };
    }
}
