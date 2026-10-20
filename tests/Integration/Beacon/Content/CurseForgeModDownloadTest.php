<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class CurseForgeModDownloadTest extends TestCase
{
    private const EDGE = 'https://edge.forgecdn.net/files/1/2/fixture.jar';
    private const DIRECT = 'https://mediafilez.forgecdn.net/files/1/2/fixture.jar';

    public function setUp(): void
    {
        parent::setUp();
        config()->set('beacon.modpacks.providers.curseforge.enabled', true);
        config()->set('beacon.modpacks.providers.curseforge.api_key', 'fixture-not-a-real-key');
        Http::preventStrayRequests();
    }

    public static function redirects(): array
    {
        return [[301], [302], [303], [307], [308]];
    }

    #[DataProvider('redirects')]
    public function testItResolvesApprovedRedirectsWithoutForwardingCredentialsOrFollowingAutomatically(int $status): void
    {
        $options = [];
        Http::fake(function ($request, $requestOptions) use ($status, &$options) {
            $options[] = $requestOptions;
            $this->assertSame('HEAD', $request->method());
            $this->assertFalse($request->hasHeader('x-api-key'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('Cookie'));

            return $request->url() === self::EDGE ? Http::response('', $status, ['Location' => self::DIRECT]) : Http::response('', 200);
        });
        $this->assertSame(self::DIRECT, (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE));
        Http::assertSentCount(2);
        foreach ($options as $option) {
            $this->assertFalse($option['allow_redirects']);
            $this->assertFalse($option['cookies']);
            $this->assertLessThanOrEqual(10, $option['timeout']);
        }
    }

    public function testItAcceptsADirect200AndClosesTheResponseWithoutReadingAJar(): void
    {
        $response = new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '999999999'], 'unread body');
        Http::fake([self::DIRECT => \GuzzleHttp\Promise\Create::promiseFor($response)]);
        $this->assertSame(self::DIRECT, (new CurseForgeModProvider())->resolveDownloadUrl(self::DIRECT));
        $this->assertFalse($response->getBody()->isReadable());
        Http::assertSentCount(1);
    }

    public static function relativeRedirects(): array
    {
        return [
            ['//mediafilez.forgecdn.net/files/1/2/fixture.jar', self::DIRECT],
            ['/direct/fixture.jar', 'https://edge.forgecdn.net/direct/fixture.jar'],
            ['../fixture.jar', 'https://edge.forgecdn.net/files/1/fixture.jar'],
            [' https://mediafilez.forgecdn.net/files/1/2/fixture.jar ', self::DIRECT],
        ];
    }

    #[DataProvider('relativeRedirects')]
    public function testRelativeTargetsAreResolvedAndValidatedBeforeTheNextRequest(string $location, string $expected): void
    {
        Http::fake([self::EDGE => Http::response('', 302, ['Location' => $location]), $expected => Http::response('', 200)]);
        $this->assertSame($expected, (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE));
        Http::assertSentCount(2);
    }

    public static function unsafeLocations(): array
    {
        return array_map(fn ($target) => [$target], [
            'https://evil.invalid/fixture.jar', 'https://127.0.0.1/fixture.jar', 'https://[::1]/fixture.jar',
            'https://edge.forgecdn.net.evil.invalid/fixture.jar', 'https://evil.invalid@edge.forgecdn.net/fixture.jar',
            'https://name:pass@mediafilez.forgecdn.net/fixture.jar', 'http://mediafilez.forgecdn.net/fixture.jar',
            'https://mediafilez.forgecdn.net:8443/fixture.jar', 'https://mediafilez.forgecdn.net/fixture.jar#fragment',
            '//169.254.169.254/metadata', 'file:///etc/passwd', 'ftp://edge.forgecdn.net/fixture.jar',
            "https://mediafilez.forgecdn.net/fixt\ture.jar", 'https://edge.forgecdn.net\\@evil.invalid/fixture.jar',
            '', str_repeat('a', 4097), 'https://[invalid/fixture.jar',
        ]);
    }

    #[DataProvider('unsafeLocations')]
    public function testUnsafeRedirectsNeverReachTheTarget(string $location): void
    {
        Http::fake([self::EDGE => Http::response('', 302, ['Location' => $location])]);
        try {
            (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE);
            $this->fail('Unsafe redirect must be rejected before a request.');
        } catch (ProviderResponseException) {
            Http::assertSentCount(1);
        }
    }

    public function testUnapprovedInitialUrlMakesNoRequest(): void
    {
        $this->expectException(ProviderResponseException::class);
        try {
            (new CurseForgeModProvider())->resolveDownloadUrl('https://evil.invalid/fixture.jar');
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function unsuccessfulResponses(): array
    {
        return [[204], [206], [300], [304], [305], [400], [403], [404], [405], [429], [500]];
    }

    #[DataProvider('unsuccessfulResponses')]
    public function testNon200OrSupportedRedirectResponsesFailWithASanitizedError(int $status): void
    {
        Http::fake([self::EDGE => Http::response('PRIVATE_CDN_BODY', $status)]);
        try {
            (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE);
            $this->fail('Unsuccessful CDN response must not become a download URL.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringNotContainsString('PRIVATE_CDN_BODY', $exception->getMessage());
            $this->assertStringNotContainsString(self::EDGE, $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public static function invalidHeaders(): array
    {
        return [[[]], [['Location' => [self::DIRECT, 'https://evil.invalid/']]]];
    }

    #[DataProvider('invalidHeaders')]
    public function testMissingOrMultipleLocationHeadersAreRejected(array $headers): void
    {
        Http::fake([self::EDGE => Http::response('', 302, $headers)]);
        try {
            (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE);
            $this->fail('An unambiguous Location is required.');
        } catch (ProviderResponseException) {
            Http::assertSentCount(1);
        }
    }

    public function testLoopsStopBeforeRepeatingARequest(): void
    {
        Http::fake([self::EDGE => Http::response('', 302, ['Location' => self::DIRECT]), self::DIRECT => Http::response('', 302, ['Location' => self::EDGE])]);
        try {
            (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE);
            $this->fail('Loop must stop.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringContainsString('loop', $exception->getMessage());
            Http::assertSentCount(2);
        }
    }

    public static function boundaryResponses(): array
    {
        return [[200], [302]];
    }

    #[DataProvider('boundaryResponses')]
    public function testThreeRedirectsAreAllowedButAnotherIsRejected(int $lastStatus): void
    {
        Http::fakeSequence()->push('', 302, ['Location' => '/a'])->push('', 302, ['Location' => '/b'])
            ->push('', 302, ['Location' => '/c'])->push('', $lastStatus, ['Location' => '/d']);
        try {
            $this->assertSame('https://edge.forgecdn.net/c', (new CurseForgeModProvider())->resolveDownloadUrl(self::EDGE));
            $this->assertSame(200, $lastStatus);
        } catch (ProviderResponseException) {
            $this->assertSame(302, $lastStatus);
        }
        Http::assertSentCount(4);
    }

    public function testCredentialRemovalPreventsEvenAFormerlyResolvedDownload(): void
    {
        Http::fake([self::EDGE => Http::response('', 200)]);
        $provider = new CurseForgeModProvider();
        $provider->resolveDownloadUrl(self::EDGE);
        config()->set('beacon.modpacks.providers.curseforge.api_key', null);
        try {
            $provider->resolveDownloadUrl(self::EDGE);
            $this->fail('Removed credentials must stop a queued download.');
        } catch (ProviderResponseException) {
            Http::assertSentCount(1);
        }
    }

    public function testTransportDetailsStayPrivate(): void
    {
        $provider = new CurseForgeModProvider();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('PRIVATE_TRANSPORT_DETAIL'));
        try {
            $provider->resolveDownloadUrl(self::EDGE);
            $this->fail('Transport failure must stop the resolver.');
        } catch (ProviderResponseException $exception) {
            $this->assertStringNotContainsString('PRIVATE_TRANSPORT_DETAIL', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-not-a-real-key', $exception->getMessage());
        }
    }
}
