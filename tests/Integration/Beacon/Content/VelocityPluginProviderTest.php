<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Content\DependencyPlanner;
use Pterodactyl\Beacon\Content\Providers\PluginProviderRegistry;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

/** Catalog fixtures only. No application DB, Wings, or live provider requests. */
class VelocityPluginProviderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('beacon.modpacks.providers.curseforge.enabled', true);
        config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** Bytes for worker checksums, not a plugin execution or platform-certification fixture. */
    public static function jar(?array $descriptor = null, bool $paperOnly = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'velocity-plugin-test-');
        $zip = new \ZipArchive();
        try {
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString($paperOnly ? 'plugin.yml' : 'velocity-plugin.json', $paperOnly
                ? "name: PaperOnly\nmain: example.Main\nversion: 1.0\n"
                : json_encode($descriptor ?? ['id' => 'fixture', 'main' => 'example.Main'], JSON_THROW_ON_ERROR));
            $zip->addFromString('example/Main.class', 'not executed: test fixture');
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function modrinth(string $bytes, array $loaders = ['velocity'], array $dependencies = []): void
    {
        $version = ['id' => 'version1', 'project_id' => 'project1', 'name' => 'Release', 'version_number' => '1.0', 'version_type' => 'release',
            'game_versions' => ['1.21.1'], 'loaders' => $loaders, 'dependencies' => $dependencies,
            'files' => [['url' => 'https://cdn.modrinth.com/data/project1/fixture.jar', 'filename' => 'fixture.jar', 'size' => strlen($bytes),
                'primary' => true, 'hashes' => ['sha512' => hash('sha512', $bytes)]]]];
        Http::fake([
            'api.modrinth.com/v2/project/project1' => Http::response(['title' => 'Fixture', 'project_type' => 'mod', 'server_side' => 'required']),
            'api.modrinth.com/v2/project/project1/version*' => Http::response([$version]),
            'api.modrinth.com/v2/version/version1' => Http::response($version),
        ]);
    }

    private function curseforge(string $bytes): void
    {
        $file = ['id' => 456, 'modId' => 123, 'isAvailable' => true, 'fileStatus' => 4, 'releaseType' => 1,
            'gameVersions' => ['1.21.1'], 'fileName' => 'fixture.jar', 'fileLength' => strlen($bytes), 'displayName' => 'Release', 'fileDate' => '2026-01-01', 'dependencies' => [],
            'hashes' => [['algo' => 1, 'value' => hash('sha1', $bytes)]], 'downloadUrl' => 'https://edge.forgecdn.net/files/1/2/fixture.jar'];
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => ['id' => 123, 'gameId' => 432, 'classId' => 5, 'isAvailable' => true, 'name' => 'Fixture']]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => $file]),
            'api.curseforge.com/v1/mods/123/files?*' => Http::response(['data' => [$file]]),
        ]);
    }

    public function testModrinthVelocityVersionsNeverFilterByTheProxyAsMinecraft(): void
    {
        $this->modrinth(self::jar());
        $provider = app(PluginProviderRegistry::class)->forPlatform('modrinth', 'velocity');
        $versions = $provider->versions('project1', '4.3.1', 'velocity');
        $this->assertSame(['1.21.1'], $versions[0]['game_versions']);
        $this->assertSame('provider_metadata', $versions[0]['compatibility']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/version?') && str_contains($r->url(), 'loaders=') && !str_contains($r->url(), 'game_versions'));
        $plan = (new DependencyPlanner($provider))->handle('version1', '4.3.1', 'velocity', 'plugin', 'modrinth', $provider);
        $this->assertSame('provider_metadata', $plan['files'][0]['compatibility']);
        $this->assertArrayNotHasKey('velocity_plugin', $plan['files'][0]);
        $this->assertSame('4.3.1', $plan['game_version']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cdn.modrinth.com'));
    }

    public function testModrinthVelocitySearchKeepsItsProviderLoaderFacet(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => []])]);
        app(PluginProviderRegistry::class)->forPlatform('modrinth', 'velocity')->search('', '4.3.1', 'velocity', 'plugin', 0, 'management');
        Http::assertSent(fn ($r) => in_array(['categories:velocity'], json_decode($r['facets'], true), true)
            && in_array(['categories:management'], json_decode($r['facets'], true), true) && !str_contains($r['facets'], 'versions:'));
    }

    public function testGenericModrinthKeepsItsMinecraftVersionFilter(): void
    {
        $this->modrinth(self::jar());
        (new \Pterodactyl\Beacon\Content\Providers\ModrinthProvider())->versions('project1', '1.21.1', 'velocity');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/version?') && json_decode($r['game_versions'], true) === ['1.21.1']);
    }

    public function testForgeModsCannotUseTheEmptyPluginVersionFilter(): void
    {
        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Minecraft version is required');
        (new \Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider())->versions('123', '', 'forge');
    }

    public function testCurseForgeCandidatesDoNotPretendToVerifyFilesOrProxyVersions(): void
    {
        $this->curseforge(self::jar(paperOnly: true));
        $provider = app(PluginProviderRegistry::class)->forPlatform('curseforge', 'velocity');
        $versions = $provider->versions('123', '4.3.1', 'velocity');
        $this->assertSame('123:456:velocity', $versions[0]['id']);
        $this->assertSame('provider_metadata', $versions[0]['compatibility']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/files?') && !str_contains($r->url(), 'gameVersion'));
        $plan = (new DependencyPlanner($provider))->handle('123:456:velocity', '4.3.1', 'velocity', 'plugin', 'curseforge', $provider);
        $this->assertSame('provider_metadata', $plan['files'][0]['compatibility']);
        $this->assertArrayNotHasKey('velocity_plugin', $plan['files'][0]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'forgecdn.net'));
    }

    public function testCurseForgeDefaultVelocitySearchUsesDiscoveryHintNotAMinecraftVersion(): void
    {
        Http::fake(['api.curseforge.com/v1/mods/search*' => Http::response(['data' => []])]);
        app(PluginProviderRegistry::class)->forPlatform('curseforge', 'velocity')->search('', '4.3.1', 'velocity', 'plugin');
        Http::assertSent(fn ($r) => $r['searchFilter'] === 'velocity' && $r['classId'] === 5 && !isset($r['gameVersion']) && !isset($r['modLoaderType']));
    }

    public static function providers(): array
    {
        return [['modrinth'], ['curseforge']];
    }

    #[DataProvider('providers')]
    public function testPlanningDoesNotDownloadOrInspectEvenPaperOnlyBytes(string $key): void
    {
        $bytes = self::jar(paperOnly: true);
        $key === 'modrinth' ? $this->modrinth($bytes) : $this->curseforge($bytes);
        $provider = app(PluginProviderRegistry::class)->forPlatform($key, 'velocity');
        $plan = (new DependencyPlanner($provider))->handle($key === 'modrinth' ? 'version1' : '123:456:velocity', '4.3.1', 'velocity', 'plugin', $key, $provider);
        $this->assertSame('provider_metadata', $plan['files'][0]['compatibility']);
        $this->assertArrayNotHasKey('velocity_plugin', $plan['files'][0]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'forgecdn.net') || str_contains($r->url(), 'cdn.modrinth.com'));
    }

    public function testWrongModrinthLoaderMetadataIsStillRejectedBeforeDownloading(): void
    {
        $this->modrinth(self::jar(), ['paper']);
        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('not a Velocity');
        app(PluginProviderRegistry::class)->forPlatform('modrinth', 'velocity')->version('version1');
    }

    public function testRequestLocalPlatformMetadataDoesNotChangeThePaperAdapter(): void
    {
        $this->modrinth(self::jar(), ['velocity', 'paper']);
        $registry = app(PluginProviderRegistry::class);
        $this->assertSame('provider_metadata', $registry->forPlatform('modrinth', 'velocity')->version('version1')['compatibility']);
        $this->assertArrayNotHasKey('compatibility', $registry->forPlatform('modrinth', 'paper')->version('version1'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cdn.modrinth.com'));
    }

    public function testCurseForgeCloneDoesNotChangeThePaperAdapterOrDisabledCredentials(): void
    {
        $this->curseforge(self::jar());
        $registry = app(PluginProviderRegistry::class);
        $registry->forPlatform('curseforge', 'velocity')->version('123:456:velocity');
        $paper = $registry->forPlatform('curseforge', 'paper')->version('123:456');
        $this->assertSame(['paper', 'spigot', 'bukkit'], $paper['loaders']);
        $this->assertArrayNotHasKey('compatibility', $paper);
        config()->set('beacon.modpacks.providers.curseforge.api_key', '');
        $this->expectException(ProviderResponseException::class);
        $registry->forPlatform('curseforge', 'velocity')->version('123:456:velocity');
    }

    public function testRequiredModrinthDependencyStillNeedsVelocityProviderMetadata(): void
    {
        $this->modrinth(self::jar(), ['velocity'], [['dependency_type' => 'required', 'project_id' => 'other', 'version_id' => 'dependency1']]);
        Http::fake([
            'api.modrinth.com/v2/version/dependency1' => Http::response(['id' => 'dependency1', 'project_id' => 'other', 'name' => 'Paper dependency',
                'version_number' => '1', 'version_type' => 'release', 'game_versions' => ['1.21.1'], 'loaders' => ['paper'], 'dependencies' => [],
                'files' => [['url' => 'https://cdn.modrinth.com/data/other/paper.jar', 'filename' => 'paper.jar', 'size' => 7, 'hashes' => ['sha512' => hash('sha512', 'fixture')]]]]),
            'api.modrinth.com/v2/project/other' => Http::response(['title' => 'Other', 'server_side' => 'required', 'project_type' => 'plugin']),
        ]);
        $provider = app(PluginProviderRegistry::class)->forPlatform('modrinth', 'velocity');
        $this->expectException(ProviderResponseException::class);
        (new DependencyPlanner($provider))->handle('version1', '4.3.1', 'velocity', 'plugin', 'modrinth', $provider);
    }
}
