<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Modpacks\Providers\AbstractModpackProvider;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;
use Pterodactyl\Beacon\Modpacks\Providers\CurseForgeModpackProvider;

class BeaconModpackJavaVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public static function releases(): array
    {
        return [
            ['1.16.5', 8], ['1.17', 16], ['1.17.1', 16], ['1.18.2', 17],
            ['1.20.1', 17], ['1.20.4', 17], ['1.20.5', 21], ['1.21.11', 21],
            ['26.1', 25], ['26.1.1', 25], ['26.3', 25],
        ];
    }

    #[DataProvider('releases')]
    public function testProviderUsesTheTrustedJavaMajor(string $version, int $java): void
    {
        $provider = \Mockery::mock(CurseForgeModpackProvider::class)->makePartial();
        $this->assertSame($java, (new \ReflectionMethod(AbstractModpackProvider::class, 'javaVersion'))->invoke($provider, $version));
    }

    public function testYearNumberedReleasesAreDiscoverableWithoutAcceptingSnapshots(): void
    {
        $provider = \Mockery::mock(CurseForgeModpackProvider::class)->makePartial();
        $method = new \ReflectionMethod(AbstractModpackProvider::class, 'minecraftVersionFrom');
        $this->assertSame('26.1', $method->invoke($provider, ['Fabric', '26.1-snapshot-1', '26.1']));
        $this->assertNull($method->invoke($provider, ['Fabric', '26.1-snapshot-1']));
    }

    public function testUnknownVersionsDoNotSilentlyBecomeJavaEight(): void
    {
        $provider = \Mockery::mock(CurseForgeModpackProvider::class)->makePartial();
        $this->expectException(ModpackProviderException::class);
        (new \ReflectionMethod(AbstractModpackProvider::class, 'javaVersion'))->invoke($provider, 'latest');
    }
}
