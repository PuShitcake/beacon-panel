<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\PaperBuildIndex;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\PaperPropertyPolicy;
use Pterodactyl\Beacon\Minecraft\PaperJarVersionReader;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaperConfigurationRuntimeTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        \Mockery::close();
        parent::tearDown();
    }

    private function jar(array|string|null $metadata, ?string $legacy = null): string
    {
        $file = tempnam(sys_get_temp_dir(), 'paper-test-');
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($file, \ZipArchive::OVERWRITE));
            if ($metadata !== null) {
                $zip->addFromString('version.json', is_string($metadata) ? $metadata : json_encode($metadata));
            }
            if ($legacy !== null) {
                $zip->addFromString('patch.properties', $legacy);
            }
            $zip->addFromString('META-INF/MANIFEST.MF', 'Fixture only, never executable');
            $zip->close();

            return file_get_contents($file);
        } finally {
            unlink($file);
        }
    }

    private function reader(string $body): PaperJarVersionReader
    {
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('consume')->andReturnUsing(function ($server, $path, $limit, $consume) use ($body) {
            foreach (str_split($body, 47) as $chunk) {
                $consume($chunk);
            }
        });

        return new PaperJarVersionReader($reader, new PaperBuildIndex());
    }

    private function builds(string $hash, int $size): array
    {
        return [['id' => 1, 'downloads' => ['server:default' => ['checksums' => ['sha256' => $hash], 'size' => $size]]]];
    }

    public function testFullChecksumAndSizeMustMatchAnOfficialBuild(): void
    {
        $jar = $this->jar(['id' => '1.21.1', 'stable' => true]);
        $hash = hash('sha256', $jar);
        Http::fake(['https://fill.papermc.io/v3/projects/paper/versions/1.21.1/builds' => Http::response($this->builds($hash, strlen($jar)))]);
        $runtime = $this->reader($jar)->inspect(new Server(), '/server.jar', strlen($jar));
        $this->assertSame(['version' => '1.21.1', 'identity' => $hash], $runtime);
        $this->assertNull($this->reader($jar . 'changed')->inspect(new Server(), '/server.jar', strlen($jar) + 7));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'BeaconPanel/1.0 (https://github.com/PuShitcake/beacon-panel)'));
    }

    #[DataProvider('invalidMetadata')]
    public function testUntrustedJarHintsNeverAuthorizeEditing(array|string|null $metadata): void
    {
        $jar = $this->jar($metadata);
        $this->assertNull($this->reader($jar)->inspect(new Server(), '/server.jar', strlen($jar)));
        Http::assertNothingSent();
    }

    public static function invalidMetadata(): array
    {
        return [[null], ['{invalid'], [['id' => 'latest', 'stable' => true]], [['id' => '1.21.1', 'stable' => false]],
            [['id' => '../../secret', 'stable' => true]], [['id' => '99.1', 'stable' => true]], [str_repeat('x', 16385)]];
    }

    public function testFileSizeRaceFailsClosed(): void
    {
        $jar = $this->jar(['id' => '1.21.1', 'stable' => true]);
        $this->expectException(ConflictHttpException::class);
        $this->reader($jar)->inspect(new Server(), '/server.jar', strlen($jar) + 1);
    }

    #[DataProvider('invalidProvider')]
    public function testProviderFailureIsControlledAndNeverFallsBackToStartup(mixed $body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        try {
            (new PaperBuildIndex())->contains('1.21.1', str_repeat('a', 64), 100);
            $this->fail('Expected controlled metadata failure');
        } catch (ConflictHttpException $error) {
            $this->assertStringContainsString('Official Paper', $error->getMessage());
            $this->assertStringNotContainsString('RAW_PROVIDER_SECRET', $error->getMessage());
        }
    }

    public static function invalidProvider(): array
    {
        return [['RAW_PROVIDER_SECRET', 503], ['', 302], ['{invalid', 200], [['ok' => false], 200],
            [[['downloads' => ['server:default' => ['size' => 100, 'checksums' => ['sha256' => 'bad']]]]], 200],
            [str_repeat('x', 8388609), 200]];
    }

    public function testAbsentPropertiesAndPrivateFieldsAreNeverAdded(): void
    {
        $policy = new PaperPropertyPolicy();
        $definitions = $policy->definitions('1.21.1', ['motd' => 'Hi', 'rcon.password' => 'PRIVATE_FIXTURE']);
        $this->assertSame(['motd'], array_keys($definitions));
        $this->assertFalse($policy->supports('1.8'));
        $this->assertTrue($policy->supports('1.16.5'));
        $this->assertTrue($policy->supports('26.3'));
    }

    public function testLegacyPaperclipVersionIsOnlyAHintUntilFullChecksumMatches(): void
    {
        $jar = $this->jar(null, "version=1.16.5\nsourceUrl=https://never-fetch.invalid/private\noriginalHash=UNTRUSTED\n");
        $hash = hash('sha256', $jar);
        Http::fake(['https://fill.papermc.io/v3/projects/paper/versions/1.16.5/builds' => Http::response($this->builds($hash, strlen($jar)))]);
        $this->assertSame(['version' => '1.16.5', 'identity' => $hash], $this->reader($jar)->inspect(new Server(), '/server.jar', strlen($jar)));
        $changed = $jar . 'modified';
        $this->assertNull($this->reader($changed)->inspect(new Server(), '/server.jar', strlen($changed)));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://fill.papermc.io/v3/projects/paper/versions/1.16.5/builds');
    }

    public function testMalformedModernMetadataNeverFallsBackToLegacy(): void
    {
        $jar = $this->jar('{invalid', "version=1.16.5\n");
        $this->assertNull($this->reader($jar)->inspect(new Server(), '/server.jar', strlen($jar)));
        Http::assertNothingSent();
    }

    public function testLegacyFormatDoesNotGuessModernOrUnknownVersions(): void
    {
        foreach (['1.21.1', 'latest', '1.16.5-snapshot'] as $version) {
            $jar = $this->jar(null, 'version=' . $version);
            $this->assertNull($this->reader($jar)->inspect(new Server(), '/server.jar', strlen($jar)));
        }
        Http::assertNothingSent();
    }
}
