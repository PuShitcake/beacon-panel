<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\PaperWorldSafety;
use Pterodactyl\Beacon\Minecraft\PaperPropertyPolicy;
use Pterodactyl\Beacon\Minecraft\PaperJarVersionReader;
use Pterodactyl\Beacon\Minecraft\JavaConfigurationAdapter;
use Pterodactyl\Beacon\Minecraft\PaperConfigurationService;
use Pterodactyl\Beacon\Minecraft\PaperGeyserConfigurationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Runs the entire offline/file/permission/backup/race regression contract for Paper too. */
class PaperConfigurationTest extends VanillaConfigurationTest
{
    protected const EGG = 'Paper';
    protected const SERVICE = PaperConfigurationService::class;
    protected const POLICY = PaperPropertyPolicy::class;
    protected const VERSIONS = PaperJarVersionReader::class;
    protected const INSPECT_RUNTIME = true;
    protected const WORLD_SAFETY = PaperWorldSafety::class;

    public function testPaperResponseDoesNotClaimVanillaAndIncludesOverrideWarning(): void
    {
        $read = $this->service->read($this->server());
        $this->assertSame(static::EGG, $read['runtime_name']);
        $this->assertStringContainsString('override', implode(' ', $read['notes']));
        $this->assertSame(static::SERVICE, JavaConfigurationAdapter::serviceClass($this->server()));
    }

    public function testDifferentBuildOfTheSameGameInvalidatesSave(): void
    {
        $read = $this->service->read($this->server());
        $this->buildIdentity = 'different-official-build';
        $this->expectException(ConflictHttpException::class);
        $this->save(['motd' => 'new'], $read);
    }

    public function testExactAdaptersDoNotSilentlyPromoteOtherEggs(): void
    {
        foreach (['Purpur', 'CurseForge Generic Custom', 'Fabric Custom', 'Vanilla Bedrock', 'Paper + Geyser', 'Paper + Geyser + Floodgate Custom'] as $name) {
            DB::table('eggs')->where('id', 1)->update(['name' => $name]);
            $this->assertNull(JavaConfigurationAdapter::serviceClass($this->server()));
        }
    }

    public function testCombinedEggHasItsOwnAdapterNotThePlainPaperAdapter(): void
    {
        DB::table('eggs')->where('id', 1)->update(['name' => 'Paper + Geyser + Floodgate']);
        $this->assertFalse(PaperConfigurationService::supports($this->server()));
        $this->assertSame(PaperGeyserConfigurationService::class, JavaConfigurationAdapter::serviceClass($this->server()));
        DB::table('nests')->where('id', 1)->update(['name' => 'Minecraft']);
        $this->assertNull(JavaConfigurationAdapter::serviceClass($this->server()));
    }

    public function testNewSeedRejectsAnExistingSiblingDimensionEvenWithoutOverworld(): void
    {
        $read = $this->worldRead();
        $this->entries[] = ['name' => 'new-map_nether', 'file' => false, 'symlink' => false];
        try {
            $this->save(['level-name' => 'new-map', 'level-seed' => '123'], $read, $this->worldBackup($read));
            $this->fail('Expected dimension collision rejection');
        } catch (ConflictHttpException $error) {
            $this->assertStringContainsString('Nether or End', $error->getMessage());
            $this->assertSame(0, $this->writes);
        }
    }

    public function testSelectingMapRejectsSiblingSymlinks(): void
    {
        $read = $this->worldRead();
        $this->entries[] = ['name' => 'imported_nether', 'file' => false, 'symlink' => true];
        $this->expectException(ConflictHttpException::class);
        $this->save(['level-name' => 'imported'], $read, $this->worldBackup($read));
    }

    public function testUnsafeBukkitConfigurationBlocksOnlyWorldWrites(): void
    {
        $this->entries[] = ['name' => 'bukkit.yml', 'file' => true, 'symlink' => true, 'size' => 10];
        $this->assertSame('ordinary', $this->save(['motd' => 'ordinary'])['properties']['motd']);
        $read = $this->worldRead();
        $this->expectException(ConflictHttpException::class);
        $this->save(['level-name' => 'new-map'], $read, $this->worldBackup($read));
    }

    #[DataProvider('unsafeWorldContainers')]
    public function testUnsafeWorldContainerDoesNotReceiveAPropertiesWrite(string $yaml): void
    {
        $this->entries[] = ['name' => 'bukkit.yml', 'file' => true, 'symlink' => false, 'size' => strlen($yaml)];
        $this->extraFiles['/bukkit.yml'] = $yaml;
        $read = $this->worldRead();
        try {
            $this->save(['level-name' => 'new-map'], $read, $this->worldBackup($read));
            $this->fail('Expected world storage rejection');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $this->writes);
        }
    }

    public static function unsafeWorldContainers(): array
    {
        return [["settings:\n  world-container: elsewhere\n"], ['[invalid'], ['scalar'], ["settings: scalar\n"]];
    }

    public function testRegularRootWorldConfigurationCanSaveWithoutMovingDimensions(): void
    {
        $this->entries[] = ['name' => 'bukkit.yml', 'file' => true, 'symlink' => false, 'size' => 30];
        $this->extraFiles['/bukkit.yml'] = "settings:\n  world-container: .\n";
        $this->entries[] = ['name' => 'uploaded', 'file' => false, 'symlink' => false];
        $this->entries[] = ['name' => 'uploaded_nether', 'file' => false, 'symlink' => false];
        $this->directories['/uploaded'] = [['name' => 'level.dat', 'file' => true, 'symlink' => false, 'size' => 200]];
        $read = $this->worldRead();
        $saved = $this->save(['level-name' => 'uploaded'], $read, $this->worldBackup($read));
        $this->assertSame('uploaded', $saved['properties']['level-name']);
        $this->assertSame(1, $this->writes);
    }
}
