<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class BeaconCurseForgeImageTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function javaVersions(): array
    {
        return [[8], [11], [16], [17], [21], [25]];
    }

    #[DataProvider('javaVersions')]
    public function testAutomaticHotSpotImageDoesNotRequireAnEggDropdownEntry(int $java): void
    {
        config()->set("beacon.modpacks.java_images.{$java}", "ghcr.io/pterodactyl/yolks:java_{$java}");
        // The resolver has no Egg input: dropdown entries cannot supply or block the runtime.
        $this->assertSame("ghcr.io/pterodactyl/yolks:java_{$java}", $this->select($java));
    }

    public function testMissingConfiguredImageFailsWithoutAnOpenJ9Fallback(): void
    {
        $this->expectException(ModpackProviderException::class);
        $this->expectExceptionMessage('No trusted Java 17 HotSpot image is configured');
        $this->select(17);
    }

    public function testAnImageForAnotherJavaMajorIsNeverUsed(): void
    {
        config()->set('beacon.modpacks.java_images.17', 'ghcr.io/pterodactyl/yolks:java_21');
        $this->expectException(ModpackProviderException::class);
        $this->select(17);
    }

    public function testConfiguredOpenJ9DoesNotReplaceHotSpot(): void
    {
        config()->set('beacon.modpacks.java_images.17', 'ghcr.io/pterodactyl/yolks:java_17j9');
        $this->expectException(ModpackProviderException::class);
        $this->select(17);
    }

    public function testInstalledRuntimeSwitchesBetweenExactJavaMajors(): void
    {
        config()->set('beacon.modpacks.java_images', [
            17 => 'ghcr.io/pterodactyl/yolks:java_17',
            21 => 'ghcr.io/pterodactyl/yolks:java_21',
        ]);
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17', $this->select(17));
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_21', $this->select(21));
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17', $this->select(17));
    }

    public function testInvalidJavaMajorDoesNotUseAnotherConfiguredImage(): void
    {
        config()->set('beacon.modpacks.java_images.17', 'ghcr.io/pterodactyl/yolks:java_17');
        $this->expectException(ModpackProviderException::class);
        $this->select(0);
    }

    private function select(int $java): string
    {
        $service = (new \ReflectionClass(ModpackRuntimeService::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(ModpackRuntimeService::class, 'curseForgeImage'))->invoke($service, $java);
    }
}
