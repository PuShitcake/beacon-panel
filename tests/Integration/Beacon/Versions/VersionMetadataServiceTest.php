<?php

namespace Pterodactyl\Tests\Integration\Beacon\Versions;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\BeaconCatalogProfile;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Beacon\Versions\VersionMetadataService;

class VersionMetadataServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testItReconcilesPaperAndForgeRuntimeMetadataFromTrustedTargets(): void
    {
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);
        $nest = Nest::query()->where('name', config('beacon.modpacks.nest.name'))->firstOrFail();
        $paperEgg = Egg::query()->where('nest_id', $nest->id)->where('name', 'Paper')->firstOrFail();
        $forgeEgg = Egg::query()->where('nest_id', $nest->id)->where('name', 'Forge Minecraft')->firstOrFail();
        $server = $this->createServerModel([
            'egg_id' => $paperEgg->id,
            'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        ]);
        $service = app(VersionMetadataService::class);

        $service->reconcile($server, [
            'software' => 'paper',
            'minecraft_version' => '1.21.8',
            'build_number' => 42,
            'loader_version' => null,
        ]);

        $paperProfile = BeaconCatalogProfile::query()->where('code', 'paper')->firstOrFail();
        $metadata = BeaconServerMetadata::query()->where('server_id', $server->id)->firstOrFail();
        $this->assertSame($paperProfile->id, $metadata->profile_id);
        $this->assertSame('plugins', $paperProfile->content_directory);
        $this->assertSame('1.21.8', $metadata->version->version);
        $this->assertSame('42', $metadata->version->environment['BUILD_NUMBER']);
        $presetId = $metadata->preset_id;

        $server->forceFill([
            'egg_id' => $forgeEgg->id,
            'nest_id' => $forgeEgg->nest_id,
            'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        ])->save();
        $service->reconcile($server->fresh(), [
            'software' => 'forge',
            'minecraft_version' => '1.21.8',
            'build_number' => 1,
            'loader_version' => '58.1.22',
        ]);

        $forgeProfile = BeaconCatalogProfile::query()->where('code', 'forge')->firstOrFail();
        $metadata->refresh();
        $this->assertSame($forgeProfile->id, $metadata->profile_id);
        $this->assertSame($presetId, $metadata->preset_id);
        $this->assertFalse($forgeProfile->enabled);
        $this->assertSame('mods', $forgeProfile->content_directory);
        $this->assertSame('58.1.22', $metadata->version->loader_version);
        $this->assertSame('1.21.8-58.1.22', $metadata->version->environment['FORGE_VERSION']);
    }
}
