<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Content\Providers\HangarPluginProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class HangarReviewDiscoveryTest extends TestCase
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
        parent::tearDown();
    }

    private function project(string $loader): array
    {
        return ['id' => 123, 'visibility' => 'public', 'name' => 'Fixture', 'description' => 'Plugin fixture',
            'supportedPlatforms' => [strtoupper($loader) => [$this->platformVersion($loader)]]];
    }

    private function platformVersion(string $loader): string
    {
        return $loader === 'velocity' ? '4.3.1' : '1.20.1';
    }

    private function release(string $loader, string $review = 'reviewed', int $id = 456): array
    {
        $platform = strtoupper($loader);

        return ['id' => $id, 'projectId' => 123, 'name' => 'Release ' . $id, 'visibility' => 'public', 'reviewState' => $review,
            'createdAt' => '2026-01-01', 'channel' => ['name' => 'Release'],
            'platformDependencies' => [$platform => [$this->platformVersion($loader)]], 'pluginDependencies' => [$platform => []],
            'downloads' => [$platform => ['externalUrl' => null, 'downloadUrl' => 'https://hangarcdn.papermc.io/plugin.jar',
                'fileInfo' => ['name' => 'plugin.jar', 'sizeBytes' => 7, 'sha256Hash' => hash('sha256', 'fixture')]]]];
    }

    private function fake(string $loader, array $listing, array $detail, int $status = 200): HangarPluginProvider
    {
        Http::fake([
            'hangar.papermc.io/api/v1/projects?*' => Http::response(['result' => [$this->project($loader)], 'pagination' => ['count' => 1]]),
            'hangar.papermc.io/api/v1/projects/123' => Http::response($this->project($loader)),
            'hangar.papermc.io/api/v1/projects/123/versions?*' => Http::response(['result' => $listing]),
            'hangar.papermc.io/api/v1/projects/123/versions/456' => Http::response($detail, $status),
        ]);

        return new HangarPluginProvider();
    }

    public static function platforms(): array
    {
        return [['paper'], ['velocity']];
    }

    #[DataProvider('platforms')]
    public function testReviewedDetailGetsTheCorrectLabelDespiteUnreviewedIndex(string $loader): void
    {
        $provider = $this->fake($loader, [$this->release($loader, 'unreviewed')], $this->release($loader));
        $search = $provider->search('', $this->platformVersion($loader), $loader, 'plugin');
        $this->assertSame(['123'], array_column($search['projects'], 'id'));
        $this->assertSame('reviewed', $search['projects'][0]['review_state']);
        $this->assertTrue($search['page_filtered']);
        $versions = $provider->versions('123', $this->platformVersion($loader), $loader);
        $this->assertCount(1, $versions);
        $this->assertSame('reviewed', $versions[0]['review_state']);
        $id = '123:456' . ($loader === 'velocity' ? ':velocity' : '');
        $this->assertSame($id, $versions[0]['id']);
        $this->assertSame('reviewed', $provider->version($id)['review_state']);
        // Browse, picker and exact selection share the same raw detail cache.
        Http::assertSentCount(4);
    }

    #[DataProvider('platforms')]
    public function testUnreviewedDetailIsShownWithoutInheritingAnIncorrectReviewedIndexLabel(string $loader): void
    {
        $provider = $this->fake($loader, [$this->release($loader)], $this->release($loader, 'unreviewed'));
        $game = $this->platformVersion($loader);
        $this->assertSame('unreviewed', $provider->search('', $game, $loader, 'plugin')['projects'][0]['review_state']);
        $this->assertSame('unreviewed', $provider->versions('123', $game, $loader)[0]['review_state']);
        $this->assertSame('unreviewed', (new HangarPluginProvider())->versions('123', $game, $loader)[0]['review_state']);
        Http::assertSentCount(4);
    }

    public static function excludedDetails(): array
    {
        return [['reviewState', 'rejected'], ['reviewState', null], ['reviewState', 'unknown'],
            ['visibility', 'hidden'], ['platformDependencies.VELOCITY', ['4.2.1']],
            ['platformDependencies.VELOCITY', null], ['downloads.VELOCITY.fileInfo', null],
            ['downloads.VELOCITY.externalUrl', 'https://external.invalid/plugin.jar']];
    }

    #[DataProvider('excludedDetails')]
    public function testShowingBothReviewStatesDoesNotRelaxOtherDetailRestrictions(string $key, mixed $value): void
    {
        $detail = $this->release('velocity');
        data_set($detail, $key, $value);
        $provider = $this->fake('velocity', [$this->release('velocity')], $detail);
        $this->assertSame([], $provider->search('', '4.3.1', 'velocity', 'plugin')['projects']);
        $this->assertSame([], $provider->versions('123', '4.3.1', 'velocity'));
    }

    public static function invalidIdentities(): array
    {
        return [['id', 999], ['projectId', 999], ['id', null], ['projectId', null]];
    }

    #[DataProvider('invalidIdentities')]
    public function testDetailMustMatchTheRequestedProjectAndVersion(string $key, mixed $value): void
    {
        $detail = $this->release('velocity');
        $detail[$key] = $value;
        $provider = $this->fake('velocity', [$this->release('velocity')], $detail);
        $this->expectException(ProviderResponseException::class);
        $provider->search('', '4.3.1', 'velocity', 'plugin');
    }

    public function testDetailFailureNeverFallsBackToReviewedIndexOrLeaksItsResponse(): void
    {
        $provider = $this->fake('velocity', [$this->release('velocity')], ['private' => 'PRIVATE_PROVIDER_RESPONSE'], 503);
        foreach (['search', 'versions'] as $method) {
            try {
                if ($method === 'search') {
                    $provider->search('', '4.3.1', 'velocity', 'plugin');
                } else {
                    $provider->versions('123', '4.3.1', 'velocity');
                }
                $this->fail('An unconfirmed index entry must not be accepted after detail failure.');
            } catch (ProviderResponseException $exception) {
                $this->assertStringNotContainsString('PRIVATE_PROVIDER_RESPONSE', $exception->getMessage());
            }
        }
    }

    public function testBrowseContinuesPastARejectedReleaseUsingBoundedBatches(): void
    {
        $newer = $this->release('velocity', 'reviewed', 457);
        $newer['createdAt'] = '2026-02-01';
        $oldest = $this->release('velocity', 'reviewed', 458);
        $oldest['createdAt'] = '2025-01-01';
        $provider = $this->fake('velocity', [$oldest, $this->release('velocity', 'unreviewed'), $newer], $this->release('velocity'));
        $detail = $newer;
        $detail['reviewState'] = 'rejected';
        Http::fake([
            'hangar.papermc.io/api/v1/projects/123/versions/457' => Http::response($detail),
            'hangar.papermc.io/api/v1/projects/123/versions/458' => Http::response($oldest),
        ]);
        $this->assertSame('reviewed', $provider->search('', '4.3.1', 'velocity', 'plugin')['projects'][0]['review_state']);
        Http::assertSentCount(5);
        $this->assertSame('reviewed', $provider->search('', '4.3.1', 'velocity', 'plugin')['projects'][0]['review_state']);
        Http::assertSentCount(5);
    }

    public function testDuplicateIndexedIdsDoNotCauseExtraDetailRequestsOrPickerChoices(): void
    {
        $version = $this->release('velocity');
        $provider = $this->fake('velocity', [$version, $version], $version);
        $this->assertCount(1, $provider->versions('123', '4.3.1', 'velocity'));
        Http::assertSentCount(3);
    }

    public function testAnOversizedVersionPageFailsBeforeAnyDetailRequest(): void
    {
        $provider = $this->fake('velocity', array_fill(0, 26, $this->release('velocity')), $this->release('velocity'));
        try {
            $provider->versions('123', '4.3.1', 'velocity');
            $this->fail('Version pages must be bounded.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringContainsString('oversized', $exception->getMessage());
            Http::assertSentCount(2);
        }
    }

    public function testExpiredReviewBudgetNeverRequestsUnverifiedDetailsEvenFromCache(): void
    {
        $provider = $this->fake('velocity', [$this->release('velocity')], $this->release('velocity'));
        $method = new \ReflectionMethod(HangarPluginProvider::class, 'eligibleReleases');
        $this->expectException(ProviderResponseException::class);
        iterator_to_array($method->invoke($provider, '123', '4.3.1', 'VELOCITY', microtime(true) - 1));
    }

    public function testPooledRequestsRetainDownloadAndCredentialRestrictionsAndReuseRawCache(): void
    {
        $listing = [];
        foreach (range(456, 461) as $id) {
            $listing[] = $this->release('velocity', 'unreviewed', $id);
        }
        Http::fake(function ($request, $options) use ($listing) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['cookies']);
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('x-api-key'));
            if (str_contains($request->url(), '/versions?')) {
                return Http::response(['result' => $listing]);
            }
            if (preg_match('~/versions/(\d+)$~', $request->url(), $parts)) {
                return Http::response($this->release('velocity', 'reviewed', (int) $parts[1]));
            }

            return Http::response($this->project('velocity'));
        });
        $provider = new HangarPluginProvider();
        $this->assertCount(6, $provider->versions('123', '4.3.1', 'velocity'));
        $this->assertCount(6, (new HangarPluginProvider())->versions('123', '4.3.1', 'velocity'));
        Http::assertSentCount(8);
    }

    public function testPooledConnectionFailureIsControlledAndNeverUsesIndexedReview(): void
    {
        $this->fake('velocity', [$this->release('velocity')], $this->release('velocity'));
        // A search prefetches the list, so exercise a pooled transport error there.
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'hangar.papermc.io/api/v1/projects?*' => Http::response(['result' => [$this->project('velocity')]]),
            'hangar.papermc.io/api/v1/projects/123/versions?*' => Http::failedConnection('PRIVATE_CONNECTION_MESSAGE'),
        ]);
        try {
            (new HangarPluginProvider())->search('', '4.3.1', 'velocity', 'plugin');
            $this->fail('Pooled failures must be explicit.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringContainsString('temporarily unavailable', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE_CONNECTION_MESSAGE', $exception->getMessage());
        }
    }
}
