<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Models\Server;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\ModpackMinecraftVersionPolicy;
use Pterodactyl\Beacon\Modpacks\CurseForgeInstalledRuntimeService;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class BeaconCurseForgeInstalledRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function service(): CurseForgeInstalledRuntimeService
    {
        return new CurseForgeInstalledRuntimeService(new ModpackMinecraftVersionPolicy());
    }

    private function manifest(string $version = '1.18.2', string $loader = 'forge-40.3.0'): string
    {
        return json_encode(['minecraft' => ['version' => $version, 'modLoaders' => [['id' => $loader, 'primary' => true]]]], JSON_THROW_ON_ERROR);
    }

    public function testInstalledManifestCorrectsAmbiguousProviderTagsWithoutReplacingProjectIdentity(): void
    {
        $server = \Mockery::mock(Server::class);
        $states = \Mockery::mock(DaemonServerRepository::class);
        $states->expects('setServer')->with($server)->andReturnSelf();
        $states->expects('getDetails')->andReturn(['state' => 'offline']);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('setServer')->with($server)->andReturnSelf();
        $files->expects('getDirectory')->with('/')->andReturn([$this->regularFile()]);
        $files->expects('getContent')->with('/client.manifest.json', 1048576)->andReturn($this->manifest());
        $release = ['minecraft_version' => '1.18.1', 'loader' => 'forge', 'java_version' => 17, 'project_id' => '123', 'version_id' => '456'];

        $resolved = $this->service()->resolve($server, $files, $states, $release);
        $this->assertSame('1.18.2', $resolved['minecraft_version']);
        $this->assertSame('40.3.0', $resolved['loader_version']);
        $this->assertSame(17, $resolved['java_version']);
        $this->assertSame('curseforge_client_manifest', $resolved['runtime_source']);
        $this->assertSame('123', $resolved['project_id']);
        $this->assertSame('456', $resolved['version_id']);
    }

    public function testYearNumberedMinecraftSelectsJava25AndIgnoresUntrustedRuntimeFields(): void
    {
        $manifest = json_decode($this->manifest('26.1', 'fabric-0.18.0'), true);
        $manifest += ['java_version' => 8, 'image' => 'untrusted:latest', 'startup' => 'untrusted', 'download_url' => 'untrusted'];
        $resolved = $this->service()->parse(json_encode($manifest));
        $this->assertSame(25, $resolved['java_version']);
        $this->assertSame('fabric', $resolved['loader']);
        $this->assertCount(5, $resolved);
        $this->assertArrayNotHasKey('image', $resolved);
        $this->assertArrayNotHasKey('startup', $resolved);
        $this->assertArrayNotHasKey('download_url', $resolved);
    }

    public static function invalidManifests(): array
    {
        $runtime = ['version' => '1.20.1', 'modLoaders' => [['id' => 'forge-47.4.0', 'primary' => true]]];

        return [
            'invalid json' => ['{'],
            'missing runtime' => ['{}'],
            'snapshot' => [json_encode(['minecraft' => array_replace($runtime, ['version' => '26.1-snapshot-1'])])],
            'unknown future Java' => [json_encode(['minecraft' => array_replace($runtime, ['version' => '27.1'])])],
            'non-string version' => [json_encode(['minecraft' => array_replace($runtime, ['version' => 26.1])])],
            'ambiguous primary' => [json_encode(['minecraft' => array_replace($runtime, ['modLoaders' => [$runtime['modLoaders'][0], $runtime['modLoaders'][0]]])])],
            'no primary' => [json_encode(['minecraft' => array_replace($runtime, ['modLoaders' => [['id' => 'forge-47.4.0', 'primary' => false]]])])],
            'unsupported loader' => [json_encode(['minecraft' => array_replace($runtime, ['modLoaders' => [['id' => 'unknown-1.0', 'primary' => true]]])])],
            'unsafe loader identifier' => [json_encode(['minecraft' => array_replace($runtime, ['modLoaders' => [['id' => 'forge-47;command', 'primary' => true]]])])],
            'oversized' => [str_repeat(' ', 1048577)],
        ];
    }

    #[DataProvider('invalidManifests')]
    public function testInvalidMetadataFailsClosed(string $content): void
    {
        $this->expectException(ModpackProviderException::class);
        $this->service()->parse($content);
    }

    public function testPreviousManifestIsRemovedBeforeNativeReinstall(): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('getDirectory')->with('/')->andReturn([$this->regularFile()]);
        $files->expects('deleteFiles')->with('/', ['client.manifest.json']);
        $this->service()->prepare($files);
        $this->addToAssertionCount(1);
    }

    public function testFirstInstallHasNoManifestToRemove(): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('getDirectory')->with('/')->andReturn([]);
        $this->service()->prepare($files);
        $this->addToAssertionCount(1);
    }

    public static function unsafeFiles(): array
    {
        return [
            [['file' => true, 'symlink' => true, 'size' => 100]],
            [['file' => false, 'symlink' => false, 'size' => 100]],
            [['file' => true, 'symlink' => false, 'size' => 1048577]],
            [['file' => true, 'symlink' => false, 'size' => -1]],
            [[]],
        ];
    }

    #[DataProvider('unsafeFiles')]
    public function testUnsafeOldManifestIsNeverReadOrDeleted(array $entry): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('getDirectory')->with('/')->andReturn([array_merge($entry, ['name' => 'client.manifest.json'])]);
        $this->expectException(ModpackProviderException::class);
        $this->service()->prepare($files);
    }

    public function testMissingFreshManifestCannotBeReportedAsVerified(): void
    {
        $server = \Mockery::mock(Server::class);
        $states = \Mockery::mock(DaemonServerRepository::class);
        $states->expects('setServer')->with($server)->andReturnSelf();
        $states->expects('getDetails')->andReturn(['state' => 'offline']);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('setServer')->with($server)->andReturnSelf();
        $files->expects('getDirectory')->with('/')->andReturn([]);
        $this->expectException(ModpackProviderException::class);
        $this->expectExceptionMessage('fresh client manifest');
        $this->service()->resolve($server, $files, $states, []);
    }

    public function testRunningServerIsNeverReadOrSwitched(): void
    {
        $server = \Mockery::mock(Server::class);
        $states = \Mockery::mock(DaemonServerRepository::class);
        $states->expects('setServer')->with($server)->andReturnSelf();
        $states->expects('getDetails')->andReturn(['state' => 'running']);
        $this->expectException(ModpackProviderException::class);
        $this->service()->resolve($server, \Mockery::mock(DaemonFileRepository::class), $states, []);
    }

    private function regularFile(): array
    {
        return ['name' => 'client.manifest.json', 'file' => true, 'symlink' => false, 'size' => 200];
    }
}
