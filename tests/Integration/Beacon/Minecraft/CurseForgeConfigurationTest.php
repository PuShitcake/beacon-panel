<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Beacon\Minecraft\CurseForgePropertyPolicy;
use Pterodactyl\Beacon\Minecraft\JavaConfigurationAdapter;
use Pterodactyl\Beacon\Minecraft\CurseForgeConfigurationService;
use Pterodactyl\Beacon\Minecraft\CurseForgeConfigurationRuntimeReader;

/** Full shared writer/permissions/concurrency/backup contract with mocked verified provenance. */
class CurseForgeConfigurationTest extends VanillaConfigurationTest
{
    protected const EGG = 'CurseForge Generic';
    protected const SERVICE = CurseForgeConfigurationService::class;
    protected const POLICY = CurseForgePropertyPolicy::class;
    protected const VERSIONS = CurseForgeConfigurationRuntimeReader::class;
    protected const INSPECT_RUNTIME = true;
    protected const CACHED_READS = false;
    protected const MUTATION_RUNTIME_READS = 3;
    protected const RUNTIME_FILE = 'client.manifest.json';
    protected const RUNTIME_SIZE = 100;
    private int $provenanceChecks = 0;
    private bool $driftBeforeWrite = false;

    protected function configureRuntimeReader(\Mockery\MockInterface $reader): void
    {
        $reader->allows('unavailableReason')->andReturnUsing(function () {
            if (++$this->provenanceChecks === 3 && $this->driftBeforeWrite) {
                $this->buildIdentity = 'changed-after-restore-point';
            }

            return null;
        });
    }

    public function testNativeRuntimeUsesItsOwnAdapterAndExplainsProvenance(): void
    {
        $read = $this->service->read($this->server());
        $this->assertSame(self::SERVICE, JavaConfigurationAdapter::serviceClass($this->server()));
        $this->assertSame('CurseForge modpack', $read['runtime_name']);
        $this->assertSame('verified_curseforge_manifest', $read['version_source']);
        $this->assertStringContainsString('verified installed CurseForge manifest', implode(' ', $read['notes']));
        $this->assertStringContainsString('does not edit mod configuration', implode(' ', $read['notes']));
        $this->assertStringNotContainsString('PRIVATE_FIXTURE', json_encode($read));
    }

    public function testSameGameDifferentInstallationInvalidatesTheForm(): void
    {
        $first = $this->service->read($this->server());
        $this->buildIdentity = 'another-native-operation-same-game';
        $second = $this->service->read($this->server());
        $this->assertNotSame($first['runtime_hash'], $second['runtime_hash']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->save(['motd' => 'stale'], $first);
    }

    public function testProvenanceIsRecheckedAfterRestorePointBeforeAnyWrite(): void
    {
        $read = $this->service->read($this->server());
        $original = $this->content;
        $this->driftBeforeWrite = true;
        try {
            $this->save(['motd' => 'do not write'], $read);
            $this->fail('Expected the final runtime guard');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            $this->assertStringContainsString('changed before saving', $error->getMessage());
        }
        $this->assertSame(0, $this->writes);
        $this->assertSame($original, $this->content);
        $this->assertCount(1, \Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }
}
