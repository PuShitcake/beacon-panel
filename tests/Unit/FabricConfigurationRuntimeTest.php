<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\FabricPropertyPolicy;
use Pterodactyl\Beacon\Minecraft\FabricJarVersionReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Synthetic non-executable launcher ZIPs; no application DB, providers or real game boot. */
class FabricConfigurationRuntimeTest extends TestCase
{
    private const LOADER = 'libraries/net/fabricmc/fabric-loader/0.19.3/fabric-loader-0.19.3.jar';
    private const MAPPING = 'libraries/net/fabricmc/intermediary/1.20.1/intermediary-1.20.1.jar';
    private array $bodies;
    private array $directories;
    private array $reads;
    private int $propertyReads;
    private bool $drift = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->reads = [];
        $this->propertyReads = 0;
        $this->bodies = ['/server.jar' => $this->jar(), '/minecraft-server.jar' => 'official-game-fixture',
            '/fabric-server-launcher.properties' => "serverJar=minecraft-server.jar\n"];
        $this->directories = ['/' => []];
        foreach (array_keys($this->bodies) as $path) {
            $this->directories['/'][] = $this->file(basename($path), strlen($this->bodies[$path]));
        }
        foreach ([self::LOADER, self::MAPPING] as $library) {
            $directory = '/';
            $segments = explode('/', $library);
            foreach ($segments as $i => $segment) {
                $this->directories[$directory] ??= [];
                if (!collect($this->directories[$directory])->contains('name', $segment)) {
                    $this->directories[$directory][] = $i === count($segments) - 1 ? $this->file($segment, 42) : ['name' => $segment, 'file' => false, 'symlink' => false];
                }
                $directory = rtrim($directory, '/') . '/' . $segment;
            }
        }
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function file(string $name, int $size): array
    {
        return ['name' => $name, 'file' => true, 'symlink' => false, 'size' => $size];
    }

    private function jar(?string $classpath = null, string $main = 'net.fabricmc.loader.impl.launch.server.FabricServerLauncher', ?string $embedded = null, array $extra = []): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fabric-test-');
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($file, \ZipArchive::OVERWRITE));
            $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\nMain-Class: $main\r\nClass-Path: " . ($classpath ?? self::LOADER . ' ' . self::MAPPING) . "\r\n\r\n");
            $zip->addFromString('fabric-server-launch.properties', $embedded ?? "launch.mainClass=net.fabricmc.loader.impl.launch.knot.KnotServer\n");
            foreach ($extra as $name => $body) {
                $zip->addFromString($name, $body);
            }
            $zip->close();

            return file_get_contents($file);
        } finally {
            unlink($file);
        }
    }

    private function reader(): FabricJarVersionReader
    {
        $files = \Mockery::mock(VanillaFileReader::class);
        $files->allows('read')->andReturnUsing(function ($server, $path, $limit) {
            $this->reads[] = $path;
            if ($path === '/fabric-server-launcher.properties' && ++$this->propertyReads > 1 && $this->drift) {
                return 'serverJar=changed.jar';
            }
            $this->assertLessThanOrEqual($limit, strlen($this->bodies[$path]));

            return $this->bodies[$path];
        });
        $files->allows('consume')->andReturnUsing(function ($server, $path, $limit, $consume) {
            $this->reads[] = $path;
            foreach (str_split($this->bodies[$path], 5) as $chunk) {
                $consume($chunk);
            }
        });
        $repository = \Mockery::mock(DaemonFileRepository::class);
        $repository->allows('setServer')->andReturnSelf();
        $repository->allows('getDirectory')->andReturnUsing(function ($directory) {
            $this->reads[] = 'directory:' . $directory;

            return $this->directories[$directory] ?? [];
        });
        $reader = \Mockery::mock(FabricJarVersionReader::class, [$files, $repository])->makePartial();
        $reader->allows('identify')->andReturnUsing(fn ($hash, $size) => $hash === sha1('official-game-fixture') && $size === strlen('official-game-fixture') ? '1.20.1' : null);

        return $reader;
    }

    private function inspect(?string $startup = null): ?array
    {
        foreach ($this->directories['/'] as &$entry) {
            if (isset($this->bodies['/' . $entry['name']])) {
                $entry['size'] = strlen($this->bodies['/' . $entry['name']]);
            }
        }

        return $this->reader()->inspect((new Server())->forceFill(['startup' => $startup ?? 'java -Xms128M -jar {{SERVER_JARFILE}}']), '/server.jar', strlen($this->bodies['/server.jar']));
    }

    public function testGameJarIdentityNotLoaderOrStartupVersionSuppliesMinecraftVersion(): void
    {
        $result = $this->inspect();
        $this->assertSame('1.20.1', $result['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['identity']);
        $this->assertContains('/minecraft-server.jar', $this->reads);
        $this->assertNotContains('/mods', $this->reads);
        $this->assertNotContains('/config', $this->reads);
    }

    public function testModifiedGameJarCannotBeAuthorizedByItsNameOrMappingVersion(): void
    {
        $this->bodies['/minecraft-server.jar'] = 'modified-game-fixtur';
        $this->directories['/'][1]['size'] = strlen($this->bodies['/minecraft-server.jar']);
        $this->assertNull($this->inspect());
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeOrMissingGameTargetIsNeverRead(string $properties): void
    {
        $this->bodies['/fabric-server-launcher.properties'] = $properties;
        $this->assertNull($this->inspect());
        $this->assertNotContains('/minecraft-server.jar', $this->reads);
    }

    public static function unsafeTargets(): array
    {
        return [['serverJar=../secret.jar'], ['serverJar=/other.jar'], ['serverJar=https://example.invalid/a.jar'],
            ['serverJar=server.jar'], ['serverJar=libraries/game.jar'], ['serverJar=C:\\private.jar'], ['# no target'], ["serverJar=missing.jar\n"], ["serverJar=bad\\uZZZZ.jar\n"]];
    }

    #[DataProvider('unsafeStartup')]
    public function testOverridesCannotSubstituteTheRuntime(string $startup): void
    {
        $this->assertNull($this->inspect($startup));
        $this->assertSame([], $this->reads);
    }

    public static function unsafeStartup(): array
    {
        return [['java -Dfabric.gameJarPath=other.jar -jar {{SERVER_JARFILE}}'], ['java -Dfabric.gameVersion=99.1 -jar {{SERVER_JARFILE}}'],
            ['java -Duser.dir=other -jar {{SERVER_JARFILE}}'], ['java @args.txt -jar {{SERVER_JARFILE}}'], ['java -jar other.jar'],
            ['java -jar {{SERVER_JARFILE}} --world elsewhere'], ['java -cp evil.jar -jar {{SERVER_JARFILE}}'], ['java -javaagent:agent.jar -jar {{SERVER_JARFILE}}'],
            ['java -jar {{SERVER_JARFILE}}; execute'], ['java -jar {{SERVER_JARFILE}} | execute'], ['echo -jar {{SERVER_JARFILE}}'],
            ['java -jar other.jar -jar {{SERVER_JARFILE}}'], ['java $FLAGS -jar {{SERVER_JARFILE}}'], ['java -D"fabric.gameJarPath=other.jar" -jar {{SERVER_JARFILE}}']];
    }

    #[DataProvider('unsafeClasspaths')]
    public function testUnknownClasspathContractsNeverTriggerExternalFetches(string $classpath): void
    {
        $this->bodies['/server.jar'] = $this->jar($classpath);
        $this->assertNull($this->inspect());
        $this->assertNotContains('/minecraft-server.jar', $this->reads);
    }

    public static function unsafeClasspaths(): array
    {
        return [['https://example.invalid/library.jar'], ['../private.jar'], ['libraries/../private.jar'], ['libraries/%2e%2e/private.jar'],
            ['libraries/net/fabricmc/fabric-loader/0.12.5/fabric-loader-0.12.5.jar'], [self::LOADER . ' ' . self::LOADER], [self::MAPPING],
            [str_repeat(self::LOADER . ' ', 33)], ['file:///private.jar'], ['libraries/C:/private.jar']];
    }

    public function testSymlinkedLibrariesDirectoryIsRejectedBeforeAnyNestedRead(): void
    {
        foreach ($this->directories['/'] as &$entry) {
            if ($entry['name'] === 'libraries') {
                $entry['symlink'] = true;
            }
        }
        $this->assertNull($this->inspect());
        $this->assertNotContains('directory:/libraries', $this->reads);
    }

    public function testGameAndLaunchPropertiesSymlinksAreNotRead(): void
    {
        $this->directories['/'][1]['symlink'] = true;
        $this->assertNull($this->inspect());
        $this->assertNotContains('/minecraft-server.jar', $this->reads);
        $this->directories['/'][2]['symlink'] = true;
        $this->reads = [];
        $this->assertNull($this->inspect());
        $this->assertNotContains('/fabric-server-launcher.properties', $this->reads);
    }

    public function testExecutableClassesOrAutomaticDownloadLaunchersAreNotAccepted(): void
    {
        $this->bodies['/server.jar'] = $this->jar(extra: ['custom/Main.class' => 'not executable']);
        $this->assertNull($this->inspect());
        $this->bodies['/server.jar'] = $this->jar(main: 'net.fabricmc.installer.ServerLauncher');
        $this->assertNull($this->inspect());
    }

    public function testCustomMainClassOrUnexpectedEmbeddedSettingsAreRejected(): void
    {
        foreach (['launch.mainClass=custom.Main', "launch.mainClass=net.fabricmc.loader.impl.launch.knot.KnotServer\nextra=value"] as $embedded) {
            $this->bodies['/server.jar'] = $this->jar(embedded: $embedded);
            $this->assertNull($this->inspect());
        }
    }

    public function testLaunchPropertiesDriftDuringInspectionFailsClosed(): void
    {
        $this->drift = true;
        $this->expectException(ConflictHttpException::class);
        $this->inspect();
    }

    public function testDifferentLaunchPropertiesBytesInvalidateIdentityWithoutAStartupChange(): void
    {
        $first = $this->inspect();
        $this->bodies['/fabric-server-launcher.properties'] .= "# changed\n";
        $second = $this->inspect();
        $this->assertNotSame($first['identity'], $second['identity']);
    }

    public function testTruncatedOrOversizedLauncherFailsBeforeGameRead(): void
    {
        $server = (new Server())->forceFill(['startup' => 'java -jar {{SERVER_JARFILE}}']);
        $this->assertNull($this->reader()->inspect($server, '/server.jar', 1048577));
        $this->assertSame([], $this->reads);
        $this->expectException(ConflictHttpException::class);
        $this->reader()->inspect($server, '/server.jar', strlen($this->bodies['/server.jar']) + 1);
    }

    public function testGeneratedManifestLineContinuationsAreDecoded(): void
    {
        $classpath = substr(self::LOADER, 0, 36) . "\r\n " . substr(self::LOADER, 36) . ' ' . self::MAPPING;
        $this->bodies['/server.jar'] = $this->jar($classpath);
        $this->assertSame('1.20.1', $this->inspect()['version']);
    }

    public function testLaunchPropertiesSizeDriftIsNotAUsableContract(): void
    {
        ++$this->directories['/'][2]['size'];
        $this->expectException(ConflictHttpException::class);
        $this->reader()->inspect((new Server())->forceFill(['startup' => 'java -jar {{SERVER_JARFILE}}']), '/server.jar', strlen($this->bodies['/server.jar']));
    }

    public function testMappingHintCannotOverrideVerifiedGameVersion(): void
    {
        $this->bodies['/server.jar'] = $this->jar(self::LOADER . ' ' . str_replace('1.20.1', '1.21.1', self::MAPPING));
        $this->directories['/libraries/net/fabricmc/intermediary'][0]['name'] = '1.21.1';
        $this->directories['/libraries/net/fabricmc/intermediary/1.21.1'] = [$this->file('intermediary-1.21.1.jar', 42)];
        $this->assertNull($this->inspect());
        $this->assertContains('/minecraft-server.jar', $this->reads);
    }

    public function testFabricPolicyUsesExistingJavaKeysAndDoesNotInventModOptions(): void
    {
        $policy = new FabricPropertyPolicy();
        $this->assertFalse($policy->supports('1.12.2'));
        $this->assertTrue($policy->supports('1.14'));
        $this->assertTrue($policy->supports('26.3'));
        $this->assertSame(['motd'], array_keys($policy->definitions('1.20.1', ['motd' => 'Hi', 'custom-mod-secret' => 'PRIVATE'])));
    }

    public function testMavenBuildMetadataPlusIsALiteralSafeLibraryPathCharacter(): void
    {
        // The official Loader 0.20.1 catalog contains this version, not a guessed filename.
        $version = '0.17.5+mixin.0.8.7';
        $library = 'libraries/net/fabricmc/sponge-mixin/' . $version . '/sponge-mixin-' . $version . '.jar';
        $this->bodies['/server.jar'] = $this->jar(self::LOADER . ' ' . self::MAPPING . ' ' . $library);
        $this->directories['/libraries/net/fabricmc'][] = ['name' => 'sponge-mixin', 'file' => false, 'symlink' => false];
        $this->directories['/libraries/net/fabricmc/sponge-mixin'] = [['name' => $version, 'file' => false, 'symlink' => false]];
        $this->directories['/libraries/net/fabricmc/sponge-mixin/' . $version] = [$this->file('sponge-mixin-' . $version . '.jar', 42)];
        $runtime = $this->inspect();
        $this->assertNotNull($runtime);
        $this->assertSame('1.20.1', $runtime['version']);
    }
}
