<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Beacon\Minecraft\FabricPropertyPolicy;
use Pterodactyl\Beacon\Minecraft\FabricJarVersionReader;
use Pterodactyl\Beacon\Minecraft\JavaConfigurationAdapter;
use Pterodactyl\Beacon\Minecraft\FabricConfigurationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Runs the complete Vanilla writer/permission/backup/world/race contract for Fabric. */
class FabricConfigurationTest extends VanillaConfigurationTest
{
    protected const EGG = 'Fabric';
    protected const SERVICE = FabricConfigurationService::class;
    protected const POLICY = FabricPropertyPolicy::class;
    protected const VERSIONS = FabricJarVersionReader::class;
    protected const INSPECT_RUNTIME = true;
    protected const CACHED_READS = false;

    public function testFabricAdapterDoesNotClaimTheLoaderVersionIsMinecraft(): void
    {
        $read = $this->service->read($this->server());
        $this->assertSame('Fabric', $read['runtime_name']);
        $this->assertSame('1.20.1', $read['game_version']);
        $this->assertSame(FabricConfigurationService::class, JavaConfigurationAdapter::serviceClass($this->server()));
        $this->assertStringContainsString('not from Fabric Loader', implode(' ', $read['notes']));
        $this->assertStringContainsString('does not edit mods', implode(' ', $read['notes']));
    }

    public function testChangedLaunchContractInvalidatesSave(): void
    {
        $read = $this->service->read($this->server());
        $this->buildIdentity = 'changed-game-target-or-launcher';
        $this->expectException(ConflictHttpException::class);
        $this->save(['motd' => 'new'], $read);
    }

    public function testReadDoesNotReuseALauncherOnlyCacheForAChangedGameJar(): void
    {
        $first = $this->service->read($this->server());
        $this->buildIdentity = 'changed-underlying-game';
        $second = $this->service->read($this->server());
        $this->assertNotSame($first['runtime_hash'], $second['runtime_hash']);
    }

    public function testModsAndUnknownConfigurationFieldsRemainPrivateAndUnchanged(): void
    {
        $this->entries[] = ['name' => 'mods', 'file' => false, 'symlink' => false];
        $this->extraFiles['/config/private-mod.json'] = 'PRIVATE_MOD_FIXTURE';
        $this->content .= "custom-mod-secret=PRIVATE_MOD_FIXTURE\n";
        $read = $this->service->read($this->server());
        $this->assertStringNotContainsString('PRIVATE_MOD_FIXTURE', json_encode($read));
        $this->save(['motd' => 'Fabric only'], $read);
        $this->assertSame(['/server.properties'], array_values(array_unique($this->filePaths)));
        $this->assertSame('PRIVATE_MOD_FIXTURE', $this->extraFiles['/config/private-mod.json']);
        $this->assertStringContainsString('custom-mod-secret=PRIVATE_MOD_FIXTURE', $this->content);
    }
}
