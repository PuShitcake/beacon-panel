<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Content\DependencyPlanner;
use Pterodactyl\Beacon\Content\Providers\ModrinthProvider;
use Pterodactyl\Beacon\Content\Providers\HangarPluginProvider;
use Pterodactyl\Beacon\Content\Providers\CurseForgePluginProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class PluginProviderTest extends TestCase
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

    public function testCurseForgeOnlySearchesBukkitPluginsWithoutTheForgeFilter(): void
    {
        Http::fake(['api.curseforge.com/v1/mods/search*' => Http::response(['data' => [
            ['id' => 123, 'gameId' => 432, 'classId' => 5, 'isAvailable' => true, 'allowModDistribution' => true, 'name' => 'Plugin', 'summary' => 'Fixture'],
            ['id' => 456, 'gameId' => 432, 'classId' => 6, 'isAvailable' => true, 'name' => 'Wrong class', 'summary' => 'A mod'],
        ]])]);
        $result = (new CurseForgePluginProvider())->search('', '1.20.1', 'paper', 'plugin');
        $this->assertSame(['123'], array_column($result['projects'], 'id'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'classId=5') && !str_contains($request->url(), 'modLoaderType'));
    }

    public function testCurseForgeNormalizesAPluginWithNoForgeTagAndRequiresItsProviderHash(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => ['id' => 123, 'gameId' => 432, 'classId' => 5, 'isAvailable' => true, 'name' => 'Fixture']]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => [
                'id' => 456, 'modId' => 123, 'isAvailable' => true, 'fileStatus' => 4, 'releaseType' => 1, 'gameVersions' => ['1.20.1'],
                'fileName' => 'plugin.jar', 'fileLength' => 7, 'displayName' => 'Release', 'fileDate' => '2026-01-01', 'dependencies' => [],
                'hashes' => [['algo' => 1, 'value' => hash('sha1', 'fixture')]], 'downloadUrl' => 'https://edge.forgecdn.net/files/1/2/plugin.jar',
            ]]),
        ]);
        $provider = new CurseForgePluginProvider();
        $plan = (new DependencyPlanner($provider))->handle('123:456', '1.20.1', 'paper', 'plugin', 'curseforge', $provider);
        $this->assertSame(hash('sha1', 'fixture'), $plan['files'][0]['sha1']);
        $this->assertSame('plugin', $plan['project_type']);
    }

    private function project(): array
    {
        return ['id' => 123, 'visibility' => 'public', 'name' => 'Plugin', 'description' => 'Fixture', 'avatarUrl' => 'https://hangarcdn.papermc.io/avatars/1.png'];
    }

    private function version(): array
    {
        return ['id' => 456, 'projectId' => 123, 'name' => '1.0', 'visibility' => 'public', 'reviewState' => 'reviewed',
            'createdAt' => '2026-01-01', 'channel' => ['name' => 'Release'], 'platformDependencies' => ['PAPER' => ['1.20.1']],
            'pluginDependencies' => ['PAPER' => []], 'downloads' => ['PAPER' => ['externalUrl' => null,
                'downloadUrl' => 'https://hangarcdn.papermc.io/plugins/owner/plugin/plugin.jar',
                'fileInfo' => ['name' => 'plugin.jar', 'sizeBytes' => 7, 'sha256Hash' => hash('sha256', 'fixture')]]]];
    }

    private function hangar(array $version): HangarPluginProvider
    {
        Http::fake([
            'hangar.papermc.io/api/v1/projects/123' => Http::response($this->project()),
            'hangar.papermc.io/api/v1/projects/123/versions/456' => Http::response($version),
        ]);

        return new HangarPluginProvider();
    }

    public function testHangarUsesExactProjectVersionPlatformAndSha256(): void
    {
        $provider = $this->hangar($this->version());
        $plan = (new DependencyPlanner($provider))->handle('123:456', '1.20.1', 'paper', 'plugin', 'hangar', $provider);
        $this->assertSame(hash('sha256', 'fixture'), $plan['files'][0]['sha256']);
        $this->assertNull($plan['files'][0]['sha512']);
        Http::assertSent(fn ($request) => !$request->hasHeader('Authorization') && !$request->hasHeader('x-api-key'));
    }

    public static function unsafeHangar(): array
    {
        return [['projectId', 999], ['visibility', 'hidden'], ['reviewState', 'unreviewed'],
            ['downloads.PAPER.externalUrl', 'https://external.invalid/plugin.jar'],
            ['downloads.PAPER.downloadUrl', 'http://127.0.0.1/plugin.jar'],
            ['downloads.PAPER.downloadUrl', 'https://hangarcdn.papermc.io.evil.invalid/plugin.jar'],
            ['downloads.PAPER.fileInfo.name', '../plugin.jar'], ['downloads.PAPER.fileInfo.sha256Hash', 'invalid'],
            ['downloads.PAPER.fileInfo.sizeBytes', 9999999999], ['platformDependencies.PAPER', []],
            ['pluginDependencies.PAPER', [['name' => 'External', 'required' => true, 'projectId' => null, 'externalUrl' => 'https://external.invalid/']]]];
    }

    #[DataProvider('unsafeHangar')]
    public function testHangarRejectsUnsafeMetadataInsteadOfGuessing(string $key, mixed $value): void
    {
        $version = $this->version();
        data_set($version, $key, $value);
        $provider = $this->hangar($version);
        $this->expectException(ProviderResponseException::class);
        $provider->version('123:456');
    }

    public function testHangarFailuresDoNotExposeResponseBodies(): void
    {
        Http::fake(['*' => Http::response('PRIVATE_RESPONSE', 500)]);
        try {
            (new HangarPluginProvider())->search('', '1.20.1', 'paper', 'plugin');
            $this->fail('Provider must fail.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
    }

    public function testModrinthPluginCategoryDoesNotUseModOnlyValidation(): void
    {
        Http::fake(['*' => Http::response(['hits' => [], 'offset' => 0, 'limit' => 20, 'total_hits' => 0])]);
        (new ModrinthProvider())->search('', '1.20.1', 'paper', 'plugin', 0, 'management');
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'all_project_types:plugin') && str_contains(urldecode($request->url()), 'categories:management'));
    }

    public function testModrinthUsesTheExactFileLoaderRatherThanGlobalMixedProjectType(): void
    {
        Http::fake([
            'api.modrinth.com/v2/project/mixed' => Http::response(['id' => 'mixed', 'title' => 'Mixed project', 'project_type' => 'mod', 'server_side' => 'required']),
            'api.modrinth.com/v2/version/plugin-file' => Http::response(['id' => 'plugin-file', 'project_id' => 'mixed', 'name' => 'Bukkit release', 'version_number' => '1',
                'version_type' => 'release', 'game_versions' => ['1.20.1'], 'loaders' => ['bukkit', 'paper'], 'dependencies' => [],
                'files' => [['primary' => true, 'url' => 'https://cdn.modrinth.com/plugin.jar', 'filename' => 'plugin.jar', 'size' => 7, 'hashes' => ['sha512' => hash('sha512', 'fixture')]]]]),
        ]);
        $provider = new \Pterodactyl\Beacon\Content\Providers\ModrinthPluginProvider();
        $this->assertSame('plugin', $provider->version('plugin-file')['project_type']);
        // The original provider used by Mod Installer is not reclassified.
        $this->assertSame('mod', (new ModrinthProvider())->version('plugin-file')['project_type']);
    }
}
