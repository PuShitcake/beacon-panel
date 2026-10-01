<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Pterodactyl\Beacon\Modpacks\Providers\AbstractModpackProvider;

class BeaconModpackProviderUrlSafetyTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository(['beacon' => require dirname(__DIR__, 2) . '/config/beacon.php']));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function testImagesAreLimitedToApprovedHttpsHosts(): void
    {
        $provider = new UrlSafetyModpackProvider('modrinth');
        $trusted = 'https://cdn.modrinth.com/data/project/icon.png?size=96';
        $uppercaseHost = 'https://CDN.MODRINTH.COM/data/project/icon.png';

        $this->assertSame($trusted, $provider->image($trusted));
        $this->assertSame($uppercaseHost, $provider->image($uppercaseHost));
        $this->assertSame('https://cdn.modrinth.com:443/icon.png', $provider->image('https://cdn.modrinth.com:443/icon.png'));
        $this->assertNull($provider->image('http://cdn.modrinth.com/icon.png'));
        $this->assertNull($provider->image('https://user:pass@cdn.modrinth.com/icon.png'));
        $this->assertNull($provider->image('https://cdn.modrinth.com:444/icon.png'));
        $this->assertNull($provider->image('https://cdn.modrinth.com.evil.example/icon.png'));
        $this->assertNull($provider->image('https://tracking.example/icon.png'));
        $this->assertNull($provider->image('javascript:alert(1)'));
        $this->assertNull($provider->image(null));
    }

    public function testExternalLinksRequireSafeHttpsUrls(): void
    {
        $provider = new UrlSafetyModpackProvider('modrinth');

        $this->assertSame(
            'https://modrinth.com/modpack/test',
            $provider->external('https://modrinth.com/modpack/test')
        );
        $this->assertNull($provider->external('http://modrinth.com/modpack/test'));
        $this->assertNull($provider->external('https://user@modrinth.com/modpack/test'));
        $this->assertNull($provider->external('https://modrinth.com:444/modpack/test'));
        $this->assertNull($provider->external('javascript:alert(1)'));
        $this->assertNull($provider->external(null));
    }

    public function testImagesFailClosedWithoutAnAllowlist(): void
    {
        config()->set('beacon.modpacks.providers.modrinth.image_hosts', []);

        $this->assertNull((new UrlSafetyModpackProvider('modrinth'))->image('https://cdn.modrinth.com/icon.png'));
    }

    public function testConfiguredProviderImageHostsAreUsable(): void
    {
        $cases = [
            'modrinth' => 'https://cdn.modrinth.com/icon.png',
            'curseforge' => 'https://media.forgecdn.net/icon.png',
            'ftb' => 'https://cdn.feed-the-beast.com/icon.png',
            'technic' => 'https://cdn.technicpack.net/icon.png',
            'voidswrath' => 'https://www.voidswrath.com/icon.png',
        ];

        foreach ($cases as $providerKey => $url) {
            $this->assertSame($url, (new UrlSafetyModpackProvider($providerKey))->image($url));
        }

        $this->assertNull((new UrlSafetyModpackProvider('atlauncher'))->image('https://cdn.atlauncher.com/icon.png'));
    }
}

class UrlSafetyModpackProvider extends AbstractModpackProvider
{
    public function __construct(private string $providerKey)
    {
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        return [];
    }

    public function versions(string $projectId): array
    {
        return [];
    }

    public function release(string $projectId, string $versionId): array
    {
        return [];
    }

    public function image(mixed $url): ?string
    {
        return $this->safeImageUrl($url);
    }

    public function external(mixed $url): ?string
    {
        return $this->safeExternalUrl($url);
    }
}
