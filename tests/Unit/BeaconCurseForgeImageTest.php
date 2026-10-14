<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Models\Egg;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class BeaconCurseForgeImageTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public static function javaVersions(): array
    {
        return [[8], [11], [16], [17], [21], [25]];
    }

    #[DataProvider('javaVersions')]
    public function testHotSpotIsSelectedEvenWhenOpenJ9IsListedFirst(int $java): void
    {
        $images = [
            'OpenJ9' => "ghcr.io/pterodactyl/yolks:java_{$java}j9",
            'wrong version' => 'ghcr.io/pterodactyl/yolks:java_99',
            'HotSpot' => "ghcr.io/pterodactyl/yolks:java_{$java}",
        ];
        $this->assertSame("ghcr.io/pterodactyl/yolks:java_{$java}", $this->select($images, $java));
    }

    public function testOpenJ9DoesNotSilentlySubstituteForMissingHotSpot(): void
    {
        $this->expectException(ModpackProviderException::class);
        $this->expectExceptionMessage('no trusted Java 17 HotSpot image');
        $this->select(['OpenJ9' => 'ghcr.io/pterodactyl/yolks:java_17j9'], 17);
    }

    public function testAnImageForAnotherJavaMajorIsNeverUsed(): void
    {
        $this->expectException(ModpackProviderException::class);
        $this->select(['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21'], 17);
    }

    private function select(array $images, int $java): string
    {
        $egg = \Mockery::mock(Egg::class);
        $egg->shouldReceive('offsetExists')->with('docker_images')->andReturn(true);
        $egg->shouldReceive('getAttribute')->with('docker_images')->andReturn($images);
        $service = (new \ReflectionClass(ModpackRuntimeService::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(ModpackRuntimeService::class, 'curseForgeImage'))->invoke($service, $egg, $java);
    }
}
