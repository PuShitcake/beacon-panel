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

    public static function fileSizes(): array
    {
        return [[7], [5368709120]];
    }

    #[DataProvider('fileSizes')]
    public function testCurseForgeNormalizesAPluginWithNoForgeTagAndRequiresItsProviderHash(int $size): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => ['id' => 123, 'gameId' => 432, 'classId' => 5, 'isAvailable' => true, 'name' => 'Fixture']]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => [
                'id' => 456, 'modId' => 123, 'isAvailable' => true, 'fileStatus' => 4, 'releaseType' => 1, 'gameVersions' => ['1.20.1'],
                'fileName' => 'plugin.jar', 'fileLength' => $size, 'displayName' => 'Release', 'fileDate' => '2026-01-01', 'dependencies' => [],
                'hashes' => [['algo' => 1, 'value' => hash('sha1', 'fixture')]], 'downloadUrl' => 'https://edge.forgecdn.net/files/1/2/plugin.jar',
            ]]),
        ]);
        $provider = new CurseForgePluginProvider();
        $plan = (new DependencyPlanner($provider))->handle('123:456', '1.20.1', 'paper', 'plugin', 'curseforge', $provider);
        $this->assertSame(hash('sha1', 'fixture'), $plan['files'][0]['sha1']);
        $this->assertSame('plugin', $plan['project_type']);
        $this->assertSame($size, $plan['files'][0]['size']);
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

    #[DataProvider('fileSizes')]
    public function testHangarUsesExactProjectVersionPlatformAndSha256(int $size): void
    {
        $version = $this->version();
        $version['downloads']['PAPER']['fileInfo']['sizeBytes'] = $size;
        $provider = $this->hangar($version);
        $plan = (new DependencyPlanner($provider))->handle('123:456', '1.20.1', 'paper', 'plugin', 'hangar', $provider);
        $this->assertSame(hash('sha256', 'fixture'), $plan['files'][0]['sha256']);
        $this->assertNull($plan['files'][0]['sha512']);
        $this->assertSame($size, $plan['files'][0]['size']);
        Http::assertSent(fn ($request) => !$request->hasHeader('Authorization') && !$request->hasHeader('x-api-key'));
    }

    private function velocityVersion(): array
    {
        $version = $this->version();
        $version['downloads']['VELOCITY'] = $version['downloads']['PAPER'];
        $version['downloads']['VELOCITY']['fileInfo']['name'] = 'velocity-plugin.jar';
        $version['downloads']['VELOCITY']['fileInfo']['sha256Hash'] = hash('sha256', 'velocity');
        $version['platformDependencies']['VELOCITY'] = ['4.3.1'];
        $version['pluginDependencies']['VELOCITY'] = [];

        return $version;
    }

    public function testHangarVelocityAndPaperFilesOfTheSameReleaseNeverGetMixed(): void
    {
        $provider = $this->hangar($this->velocityVersion());
        $proxy = $provider->version('123:456:velocity');
        $paper = $provider->version('123:456');
        $this->assertSame(['velocity'], $proxy['loaders']);
        $this->assertSame(['4.3.1'], $proxy['game_versions']);
        $this->assertSame('velocity-plugin.jar', $proxy['file']['filename']);
        $this->assertSame(hash('sha256', 'velocity'), $proxy['file']['sha256']);
        $this->assertSame(['paper'], $paper['loaders']);
        $this->assertSame('plugin.jar', $paper['file']['filename']);
        $plan = (new DependencyPlanner($provider))->handle('123:456:velocity', '4.3.1', 'velocity', 'plugin', 'hangar', $provider);
        $this->assertSame('123:456:velocity', $plan['files'][0]['version_id']);
    }

    public function testPaperVersionIdCannotBePlannedForVelocity(): void
    {
        $provider = $this->hangar($this->velocityVersion());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        (new DependencyPlanner($provider))->handle('123:456', '4.3.1', 'velocity', 'plugin', 'hangar', $provider);
    }

    public function testVelocityDoesNotFallBackToPaperWhenItsDownloadIsMissing(): void
    {
        $provider = $this->hangar($this->version());
        $this->expectException(ProviderResponseException::class);
        $provider->version('123:456:velocity');
    }

    public function testVelocityListsReviewedAndUnreviewedExactPlatformVersionsAndTagsItsIds(): void
    {
        $valid = $this->velocityVersion();
        $unreviewed = $valid;
        $unreviewed['id'] = 457;
        $unreviewed['reviewState'] = 'unreviewed';
        $wrong = $valid;
        $wrong['platformDependencies']['VELOCITY'] = ['4.2.1'];
        Http::fake([
            'hangar.papermc.io/api/v1/projects/123' => Http::response($this->project()),
            'hangar.papermc.io/api/v1/projects/123/versions?*' => Http::response(['result' => [$valid, $unreviewed, $wrong]]),
            'hangar.papermc.io/api/v1/projects/123/versions/456' => Http::response($valid),
            'hangar.papermc.io/api/v1/projects/123/versions/457' => Http::response($unreviewed),
        ]);
        $versions = (new HangarPluginProvider())->versions('123', '4.3.1', 'velocity');
        $this->assertSame(['123:456:velocity', '123:457:velocity'], array_column($versions, 'id'));
        $this->assertSame(['reviewed', 'unreviewed'], array_column($versions, 'review_state'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'platform=VELOCITY') && str_contains($request->url(), 'platformVersion=4.3.1'));
    }

    public function testUnreviewedVelocityExactSelectionIsAvailableWithoutAFlag(): void
    {
        $version = $this->velocityVersion();
        $version['reviewState'] = 'unreviewed';
        $provider = $this->hangar($version);
        $this->assertSame('unreviewed', $provider->version('123:456:velocity')['review_state']);
    }

    public function testFreshProviderInstancesShareRawMetadataWithoutChangingItsReviewLabel(): void
    {
        $version = $this->velocityVersion();
        $version['reviewState'] = 'unreviewed';
        $provider = $this->hangar($version);
        $this->assertSame('unreviewed', $provider->version('123:456:velocity')['review_state']);
        $this->assertSame('unreviewed', (new HangarPluginProvider())->version('123:456:velocity')['review_state']);
    }

    #[DataProvider('unsafeHangar')]
    public function testUnreviewedFilesStillEnforceOtherDownloadPolicies(string $key, mixed $value): void
    {
        $version = $this->version();
        $version['reviewState'] = 'unreviewed';
        data_set($version, $key, $key === 'reviewState' ? 'rejected' : $value);
        $provider = $this->hangar($version);
        $this->expectException(ProviderResponseException::class);
        $provider->version('123:456');
    }

    public function testSearchIncludesBothReviewStatesWithoutChangingProviderPagination(): void
    {
        $reviewed = $this->project();
        $reviewed['supportedPlatforms'] = ['PAPER' => ['1.20.1']];
        $unreviewed = $reviewed;
        $unreviewed['id'] = 124;
        $unreviewedVersion = $this->version();
        $unreviewedVersion['projectId'] = 124;
        $unreviewedVersion['reviewState'] = 'unreviewed';
        Http::fake([
            'hangar.papermc.io/api/v1/projects?*' => Http::response(['result' => [$reviewed, $unreviewed], 'pagination' => ['count' => 2]]),
            'hangar.papermc.io/api/v1/projects/123/versions?*' => Http::response(['result' => [$this->version()]]),
            'hangar.papermc.io/api/v1/projects/124/versions?*' => Http::response(['result' => [$unreviewedVersion]]),
            'hangar.papermc.io/api/v1/projects/123/versions/456' => Http::response($this->version()),
            'hangar.papermc.io/api/v1/projects/124/versions/456' => Http::response($unreviewedVersion),
        ]);
        $provider = new HangarPluginProvider();
        $default = $provider->search('', '1.20.1', 'paper', 'plugin');
        $this->assertSame(['123', '124'], array_column($default['projects'], 'id'));
        $this->assertSame(2, $default['total']);
        $this->assertTrue($default['page_filtered']);
        $this->assertSame(['reviewed', 'unreviewed'], array_column($default['projects'], 'review_state'));
    }

    public function testReviewFilteringNeverFallsBackToUnverifiedResultsAfterItsDeadline(): void
    {
        Http::fake();
        $this->expectException(ProviderResponseException::class);
        (new \ReflectionMethod(HangarPluginProvider::class, 'get'))->invoke(new HangarPluginProvider(), '/projects', [], microtime(true) - 1);
    }

    public function testRequiredUnreviewedDependenciesAreIncludedAndRetainTheirMetadata(): void
    {
        $root = $this->version();
        $root['pluginDependencies']['PAPER'] = [['projectId' => 124, 'required' => true]];
        $dependency = $this->version();
        $dependency['id'] = 457;
        $dependency['projectId'] = 124;
        $dependency['reviewState'] = 'unreviewed';
        $dependency['downloads']['PAPER']['fileInfo']['name'] = 'dependency.jar';
        $project = $this->project();
        $project['id'] = 124;
        Http::fake([
            'hangar.papermc.io/api/v1/projects/123' => Http::response($this->project()),
            'hangar.papermc.io/api/v1/projects/124' => Http::response($project),
            'hangar.papermc.io/api/v1/projects/123/versions/456' => Http::response($root),
            'hangar.papermc.io/api/v1/projects/124/versions/457' => Http::response($dependency),
            'hangar.papermc.io/api/v1/projects/124/versions?*' => Http::response(['result' => [$dependency]]),
        ]);
        $provider = new HangarPluginProvider();
        $plan = (new DependencyPlanner($provider))->handle('123:456', '1.20.1', 'paper', 'plugin', 'hangar', $provider);
        $this->assertSame(['reviewed', 'unreviewed'], array_column($plan['files'], 'review_state'));
        $this->assertTrue($plan['files'][1]['dependency']);
    }

    public function testSelectedFilePlanUsesExactDetailReviewRatherThanCopyingTheSearchListStatus(): void
    {
        $listing = $this->velocityVersion();
        $listing['reviewState'] = 'unreviewed';
        Http::fake([
            'hangar.papermc.io/api/v1/projects/123' => Http::response($this->project()),
            'hangar.papermc.io/api/v1/projects/123/versions?*' => Http::response(['result' => [$listing]]),
            'hangar.papermc.io/api/v1/projects/123/versions/456' => Http::response($this->velocityVersion()),
        ]);
        $provider = new HangarPluginProvider();
        $this->assertSame('reviewed', $provider->versions('123', '4.3.1', 'velocity')[0]['review_state']);
        $plan = (new DependencyPlanner($provider))->handle('123:456:velocity', '4.3.1', 'velocity', 'plugin', 'hangar', $provider);
        $this->assertSame('reviewed', $plan['files'][0]['review_state']);
        $this->assertSame('reviewed', (new HangarPluginProvider())->version('123:456:velocity')['review_state']);
    }

    #[DataProvider('unsafeHangar')]
    public function testVelocityEnforcesTheSameDownloadAndDependencyPolicy(string $key, mixed $value): void
    {
        $version = $this->velocityVersion();
        data_set($version, str_replace('PAPER', 'VELOCITY', $key), $value);
        $provider = $this->hangar($version);
        $this->expectException(ProviderResponseException::class);
        $provider->version('123:456:velocity');
    }

    public static function unsafeHangar(): array
    {
        return [['projectId', 999], ['visibility', 'hidden'], ['reviewState', 'rejected'],
            ['downloads.PAPER.externalUrl', 'https://external.invalid/plugin.jar'],
            ['downloads.PAPER.downloadUrl', 'http://127.0.0.1/plugin.jar'],
            ['downloads.PAPER.downloadUrl', 'https://hangarcdn.papermc.io.evil.invalid/plugin.jar'],
            ['downloads.PAPER.fileInfo.name', '../plugin.jar'], ['downloads.PAPER.fileInfo.sha256Hash', 'invalid'],
            ['downloads.PAPER.fileInfo.sizeBytes', 0], ['downloads.PAPER.fileInfo.sizeBytes', -1], ['downloads.PAPER.fileInfo.sizeBytes', 1.5], ['platformDependencies.PAPER', []],
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
