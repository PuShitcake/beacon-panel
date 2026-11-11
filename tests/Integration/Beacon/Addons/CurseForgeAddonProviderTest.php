<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Beacon\Addons\CurseForgeAddonProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class CurseForgeAddonProviderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('beacon.modpacks.providers.curseforge.enabled', true);
        config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-only-key');
    }

    private function project(array $changes = []): array
    {
        return array_replace(['id' => 123, 'gameId' => 78022, 'classId' => 4984, 'isAvailable' => true, 'allowModDistribution' => true, 'name' => 'Bedrock fixture'], $changes);
    }

    private function file(array $changes = []): array
    {
        return array_replace(['id' => 456, 'modId' => 123, 'isAvailable' => true, 'fileStatus' => 4, 'fileName' => 'fixture.mcaddon', 'fileLength' => 7,
            'gameVersions' => ['1.21.0'], 'fileDate' => '2026-01-01', 'downloadUrl' => 'https://edge.forgecdn.net/files/1/2/fixture.mcaddon',
            'hashes' => [['algo' => 1, 'value' => hash('sha1', 'fixture')]]], $changes);
    }

    public function testSearchUsesBedrockGameAndAddonClassOnly(): void
    {
        Http::fake(['api.curseforge.com/v1/mods/search*' => Http::response(['data' => [$this->project(), $this->project(['gameId' => 432])], 'pagination' => ['totalCount' => 2]])]);
        $result = app(CurseForgeAddonProvider::class)->search('', 0);
        $this->assertSame(['123'], array_column($result['projects'], 'id'));
        Http::assertSent(fn ($r) => $r['gameId'] === 78022 && $r['classId'] === 4984 && !isset($r['modLoaderType']));
    }

    public function testVersionsOmitMissingHashesButExactFileStillRejectsThem(): void
    {
        $invalid = $this->file(['hashes' => [['algo' => 2, 'value' => str_repeat('a', 32)]]]);
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project()]),
            'api.curseforge.com/v1/mods/123/files?*' => Http::response(['data' => [$invalid, $this->file(['id' => 457])]]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => $invalid]),
        ]);
        $provider = app(CurseForgeAddonProvider::class);
        $versions = $provider->versions('123');
        $this->assertSame(['123:457'], array_column($versions, 'id'));
        $this->assertArrayNotHasKey('url', $versions[0]);
        $this->expectException(ProviderResponseException::class);
        $provider->selected('123:456');
    }

    public function testAuthorDisabledDownloadsAndJavaProjectsCannotBeSelected(): void
    {
        Http::fake(['api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project(['allowModDistribution' => false])])]);
        $this->expectException(ProviderResponseException::class);
        app(CurseForgeAddonProvider::class)->selected('123:456');
    }

    public function testLargeReleasesAreListedAndSelectedWithRequiredChecksums(): void
    {
        $file = $this->file(['fileLength' => 5368709120]);
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project()]),
            'api.curseforge.com/v1/mods/123/files?*' => Http::response(['data' => [$file]]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => $file]),
        ]);
        $provider = app(CurseForgeAddonProvider::class);
        $this->assertSame(['123:456'], array_column($provider->versions('123'), 'id'));
        $this->assertSame(5368709120, $provider->selected('123:456')['size']);
    }

    public function testRemovingKeyTakesEffectBeforeCachedMetadata(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $provider = app(CurseForgeAddonProvider::class);
        $provider->search('', 0);
        config()->set('beacon.modpacks.providers.curseforge.api_key', '');
        $this->expectException(ProviderResponseException::class);
        $provider->search('', 0);
    }

    public function testMalformedFilesAreSkippedAndBracketedDisplayFilenamesRemainSupported(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project()]),
            'api.curseforge.com/v1/mods/123/files?*' => Http::response(['data' => [null, 'invalid', $this->file(['hashes' => 'invalid']), $this->file(['id' => 0]), $this->file(['fileName' => 'Light [V4.1].mcaddon'])]]),
        ]);
        $this->assertSame(['123:456'], array_column(app(CurseForgeAddonProvider::class)->versions('123'), 'id'));
    }

    public function testPunctuationNamesAreSelectableButPathsAndMissingHashesRemainRejected(): void
    {
        $file = $this->file(['fileName' => "ThatMob's Verity [Add-on] - V26.50.mcaddon"]);
        Http::fake([
            'api.curseforge.com/v1/mods/123' => Http::response(['data' => $this->project()]),
            'api.curseforge.com/v1/mods/123/files?*' => Http::response(['data' => [$file,
                $this->file(['id' => 457, 'fileName' => "Verity's [BP]/../escape.mcaddon"]),
                $this->file(['id' => 458, 'fileName' => 'C:\\escape.mcaddon']),
                $this->file(['id' => 459, 'fileName' => "Verity's [BP].mcaddon", 'hashes' => []])]]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => $file]),
        ]);
        $provider = app(CurseForgeAddonProvider::class);
        $this->assertSame(['123:456'], array_column($provider->versions('123'), 'id'));
        $this->assertSame('123:456', $provider->selected('123:456')['id']);
    }

    public function testErrorsNeverExposeBodyKeyOrDownloadUrls(): void
    {
        Http::fake(['*' => Http::response('PRIVATE_BODY_AND_URL', 403)]);
        try {
            app(CurseForgeAddonProvider::class)->search('', 0);
            $this->fail('Expected safe failure.');
        } catch (ProviderResponseException $e) {
            $this->assertStringNotContainsString('PRIVATE', $e->getMessage());
            $this->assertStringNotContainsString('fixture-only-key', $e->getMessage());
        }
    }
}
