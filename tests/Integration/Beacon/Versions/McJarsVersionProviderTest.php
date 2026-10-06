<?php

namespace Pterodactyl\Tests\Integration\Beacon\Versions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Beacon\Versions\McJarsVersionProvider;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class McJarsVersionProviderTest extends IntegrationTestCase
{
    private string $previousCacheDriver;

    public function setUp(): void
    {
        parent::setUp();
        $this->previousCacheDriver = Cache::getDefaultDriver();
        Cache::setDefaultDriver('array');
        Cache::flush();
        config()->set('beacon.versions.base_url', 'https://versions.mcjars.app/api');
        config()->set('beacon.versions.software', ['vanilla', 'paper', 'forge']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Cache::setDefaultDriver($this->previousCacheDriver);
        parent::tearDown();
    }

    public function testItReturnsOnlySupportedReleaseVersionsInDescendingOrder(): void
    {
        Http::fake([
            'https://versions.mcjars.app/api/v2/builds/paper' => Http::response([
                'success' => true,
                'builds' => [
                    '1.20.4' => $this->version('release-old', 17),
                    '1.21.8' => $this->version('release-new', 21),
                    '1.22-snapshot' => $this->version('snapshot', 21, 'SNAPSHOT'),
                    '1.19.4' => $this->version('unsupported', 17, supported: false),
                    '1.23.0' => $this->version('unconfigured-java', 99),
                    '1.21.9' => $this->version('experimental-build', 21, experimental: true),
                ],
            ]),
        ]);

        $versions = app(McJarsVersionProvider::class)->versions('paper');

        $this->assertSame(['1.21.8', '1.20.4'], array_column($versions, 'version'));
        $this->assertSame(21, $versions[0]['java']);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent'));
    }

    public function testItResolvesOnlyABuildThatBelongsToTheSelectedSoftwareAndVersion(): void
    {
        Http::fake([
            'https://versions.mcjars.app/api/v2/builds/forge' => Http::response([
                'success' => true,
                'builds' => ['1.21.8' => $this->version('build-one', 21)],
            ]),
            'https://versions.mcjars.app/api/v2/builds/forge/1.21.8' => Http::response([
                'success' => true,
                'builds' => [[
                    'uuid' => '11111111-1111-4111-8111-111111111111',
                    'name' => '58.1.22',
                    'buildNumber' => 1,
                    'projectVersionId' => '58.1.22',
                    'experimental' => false,
                    'created' => null,
                ]],
            ]),
        ]);

        $target = app(McJarsVersionProvider::class)->target(
            'forge',
            '1.21.8',
            '11111111-1111-4111-8111-111111111111'
        );

        $this->assertSame('forge', $target['software']);
        $this->assertSame('58.1.22', $target['loader_version']);
        $this->assertSame(21, $target['java_version']);
    }

    public function testItRejectsAClientSuppliedBuildThatIsNotInTheCatalog(): void
    {
        Http::fake([
            'https://versions.mcjars.app/api/v2/builds/vanilla' => Http::response([
                'success' => true,
                'builds' => ['1.21.8' => $this->version('build-one', 21)],
            ]),
            'https://versions.mcjars.app/api/v2/builds/vanilla/1.21.8' => Http::response([
                'success' => true,
                'builds' => [],
            ]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');
        app(McJarsVersionProvider::class)->target(
            'vanilla',
            '1.21.8',
            '22222222-2222-4222-8222-222222222222'
        );
    }

    private function version(
        string $uuid,
        int $java,
        string $type = 'RELEASE',
        bool $supported = true,
        bool $experimental = false,
    ): array {
        $validUuid = match ($uuid) {
            'release-old' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'release-new' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'snapshot' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unsupported' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'unconfigured-java' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'experimental-build' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            default => '11111111-1111-4111-8111-111111111111',
        };

        return [
            'type' => $type,
            'supported' => $supported,
            'java' => $java,
            'latest' => [
                'uuid' => $validUuid,
                'name' => '#1',
                'buildNumber' => 1,
                'experimental' => $experimental,
            ],
        ];
    }
}
