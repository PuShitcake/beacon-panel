<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Content\DependencyPlanner;
use Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class CurseForgeModProviderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::flush();
        config()->set('beacon.modpacks.providers.curseforge.enabled', true);
        config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
        config()->set('beacon.content.max_file_bytes', 1024);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        \Mockery::close();
        parent::tearDown();
    }

    private function project(int $id = 123): array
    {
        return ['id' => $id, 'gameId' => 432, 'classId' => 6, 'name' => 'Fixture mod', 'summary' => 'A Forge mod',
            'slug' => 'fixture-mod', 'authors' => [['name' => 'Fixture']], 'downloadCount' => 10,
            'isAvailable' => true, 'allowModDistribution' => true, 'logo' => ['thumbnailUrl' => 'https://media.forgecdn.net/fixture.png']];
    }

    private function file(int $id = 456, int $project = 123): array
    {
        return ['id' => $id, 'modId' => $project, 'isAvailable' => true, 'fileStatus' => 4, 'releaseType' => 1,
            'fileName' => 'fixture.jar', 'displayName' => 'Fixture release', 'fileLength' => 20,
            'fileDate' => '2026-01-01T00:00:00Z', 'gameVersions' => ['1.20.1', 'Forge'],
            'downloadUrl' => 'https://edge.forgecdn.net/files/1/2/fixture.jar', 'dependencies' => [],
            'hashes' => [['algo' => 1, 'value' => str_repeat('a', 40)]]];
    }

    private function fakeFile(array $file, ?array $project = null): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => $file]),
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $project ?? $this->project()]),
        ]);
    }

    public function testCategoriesAndSearchUseOnlyMinecraftModsForgeAndTheSelectedCategory(): void
    {
        Http::fake([
            'api.curseforge.com/v1/categories*' => Http::response(['data' => [
                ['id' => 412, 'gameId' => 432, 'classId' => 6, 'isClass' => false, 'name' => 'Technology'],
                ['id' => 999, 'gameId' => 432, 'classId' => 4471, 'name' => 'Modpacks'],
            ]]),
            'api.curseforge.com/v1/mods/search*' => Http::response(['data' => [$this->project(), array_replace($this->project(), ['classId' => 4471])], 'pagination' => ['totalCount' => 1]]),
        ]);
        $provider = new CurseForgeModProvider();
        $this->assertSame([['value' => '412', 'label' => 'Technology']], $provider->categories());
        $result = $provider->search('', '1.20.1', 'forge', 'mod', 0, '412');
        $this->assertCount(1, $result['projects']);
        $this->assertSame('123', $result['projects'][0]['id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/mods/search')
            && $request['gameId'] === 432 && $request['classId'] === 6 && $request['gameVersion'] === '1.20.1'
            && $request['modLoaderType'] === 1 && $request['categoryId'] === '412'
            && $request->hasHeader('x-api-key', 'fixture-not-a-real-key'));
    }

    public function testVersionUsesNamespacedIdsHashesAndDependenciesWithoutInventingSha512(): void
    {
        $file = $this->file();
        $file['dependencies'] = [['modId' => 999, 'relationType' => 3], ['modId' => 888, 'relationType' => 5]];
        $this->fakeFile($file);
        $version = (new CurseForgeModProvider())->version('123:456');
        $this->assertSame('123:456', $version['id']);
        $this->assertSame('123', $version['project_id']);
        $this->assertSame(['forge'], $version['loaders']);
        $this->assertSame(str_repeat('a', 40), $version['file']['sha1']);
        $this->assertNull($version['file']['sha512']);
        $this->assertSame('required', $version['dependencies'][0]['dependency_type']);
        $this->assertSame('incompatible', $version['dependencies'][1]['dependency_type']);
    }

    public function testVersionsRemainFilteredEvenIfTheProviderReturnsOtherLoadersOrGameVersions(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/123/files*' => Http::response(['data' => [$this->file(), array_replace($this->file(457), ['gameVersions' => ['1.20.1', 'Fabric']]), array_replace($this->file(458), ['gameVersions' => ['1.21.1', 'Forge']])]]),
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project()]),
        ]);
        $versions = (new CurseForgeModProvider())->versions('123', '1.20.1', 'forge');
        $this->assertSame(['123:456'], array_column($versions, 'id'));
    }

    public static function invalidFiles(): array
    {
        return [
            [['downloadUrl' => null]], [['downloadUrl' => 'https://evil.invalid/fixture.jar']],
            [['downloadUrl' => 'https://user:pass@edge.forgecdn.net/fixture.jar']],
            [['downloadUrl' => 'http://edge.forgecdn.net/fixture.jar']], [['downloadUrl' => 'https://edge.forgecdn.net:8443/fixture.jar']],
            [['fileName' => '../fixture.jar']], [['fileName' => "fixture.jar\n"]], [['fileName' => 'pack.zip']],
            [['fileLength' => 2048]], [['fileLength' => 0]], [['hashes' => []]], [['hashes' => [['algo' => 2, 'value' => str_repeat('a', 32)]]]],
            [['gameVersions' => ['1.20.1', 'NeoForge']]], [['modId' => 999]], [['id' => 999]],
            [['isAvailable' => false]], [['fileStatus' => 6]], [['dependencies' => [['modId' => 1, 'relationType' => 999]]]],
            [['hashes' => 'invalid']], [['dependencies' => null]], [['dependencies' => ['not-an-object']]], [['gameVersions' => 'invalid']],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function testUnsafeOrUnavailableFilesFailClosed(array $changes): void
    {
        $this->fakeFile(array_replace($this->file(), $changes));
        $this->expectException(ProviderResponseException::class);
        (new CurseForgeModProvider())->version('123:456');
    }

    public function testModpackAndDistributionDisabledProjectsCannotBeUsedAsMods(): void
    {
        $this->fakeFile($this->file(), array_replace($this->project(), ['classId' => 4471, 'allowModDistribution' => false]));
        $this->expectException(ProviderResponseException::class);
        (new CurseForgeModProvider())->version('123:456');
    }

    public function testDisabledProviderDoesNotUsePreviouslyCachedResponsesOrMakeRequests(): void
    {
        $this->fakeFile($this->file());
        $provider = new CurseForgeModProvider();
        $provider->version('123:456');
        config()->set('beacon.modpacks.providers.curseforge.api_key', null);
        try {
            $provider->version('123:456');
            $this->fail('A removed credential must take effect before cached responses.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringContainsString('configure its API key', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function testProviderErrorsNeverIncludeTheResponseBodyOrApiKey(): void
    {
        Http::fake(['*' => Http::response('PRIVATE_PROVIDER_RESPONSE', 403)]);
        try {
            (new CurseForgeModProvider())->categories();
            $this->fail('HTTP errors must not be treated as success.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_RESPONSE', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-not-a-real-key', $exception->getMessage());
        }
    }

    public function testConnectionFailuresRemainControlledAndDoNotDiscloseTransportDetails(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('PRIVATE_TRANSPORT_DETAIL'));
        try {
            (new CurseForgeModProvider())->categories();
            $this->fail('Provider connection failures must be explicit.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringNotContainsString('PRIVATE_TRANSPORT_DETAIL', $exception->getMessage());
            $this->assertStringContainsString('temporarily unavailable', $exception->getMessage());
        }
    }

    public function testUnknownCategoryCannotReachTheSearchEndpoint(): void
    {
        Http::fake(['api.curseforge.com/v1/categories*' => Http::response(['data' => [
            ['id' => 412, 'gameId' => 432, 'classId' => 6, 'name' => 'Technology'],
        ]])]);
        try {
            (new CurseForgeModProvider())->search('', '1.20.1', 'forge', 'mod', 0, '999');
            $this->fail('An unknown category must not be forwarded.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringContainsString('category', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function testDependenciesAreResolvedWithinCurseForgeAndBoundToTheSameRuntime(): void
    {
        $root = $this->file();
        $root['dependencies'] = [['modId' => 999, 'relationType' => 3]];
        $dependency = array_replace($this->file(777, 999), ['fileName' => 'dependency.jar']);
        Http::fake([
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => $root]),
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project()]),
            'api.curseforge.com/v1/mods/999/files/777' => Http::response(['data' => $dependency]),
            'api.curseforge.com/v1/mods/999/files*' => Http::response(['data' => [$dependency]]),
            'api.curseforge.com/v1/mods/999' => Http::response(['data' => $this->project(999)]),
        ]);
        $provider = new CurseForgeModProvider();
        $plan = (new DependencyPlanner($provider))->handle('123:456', '1.20.1', 'forge', 'mod', 'curseforge', $provider);
        $this->assertSame('curseforge', $plan['provider']);
        $this->assertCount(2, $plan['files']);
        $this->assertSame('999:777', $plan['files'][1]['version_id']);
        $this->assertTrue($plan['files'][1]['dependency']);
    }
}
