<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Beacon\Addons\AddonPath;
use PHPUnit\Framework\Attributes\DataProvider;

class AddonPathTest extends TestCase
{
    public static function safeNames(): array
    {
        return [["ThatMob's Verity 4.0.0 by PnTMC [BP]"], ['Pack [resources]'], ["owner's texture [v1].png"], ['pack (v1)+test.mcaddon'], ['manifest.json'], ['_locked'], ['[RP] textures']];
    }

    #[DataProvider('safeNames')]
    public function testOrdinaryPunctuationIsAcceptedConsistently(string $name): void
    {
        $this->assertTrue(AddonPath::name($name));
        $this->assertTrue(AddonPath::archive($name));
        $this->assertTrue(AddonPath::archive($name . '/'));
        $this->assertTrue(AddonPath::archive($name . "/textures/owner's [texture].png"));
    }

    public static function unsafePaths(): array
    {
        return [[''], ['.'], ['..'], ['/manifest.json'], ['//host/share'], ['../manifest.json'], ['pack/../manifest.json'],
            ['pack/./manifest.json'], ['pack//manifest.json'], ['pack//'], ['pack/..'], ['C:/manifest.json'], ['C:\\manifest.json'],
            ['pack\\manifest.json'], ["pack/evil\0.json"], ["pack/new\nline.json"], ['pack/%2e%2e/file'],
            ["Verity's [BP]/../outside"], ["Verity's [BP]\\..\\outside"], ['pack/manifest.json:stream'], ['pack/.. /outside'], ['pack/file.'], [str_repeat('a', 201)]];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsRemainRejected(string $path): void
    {
        $this->assertFalse(AddonPath::archive($path));
    }

    public function testANameIsOnlyOneSegmentAndInputMustBeAString(): void
    {
        $this->assertFalse(AddonPath::name('pack/manifest.json'));
        $this->assertFalse(AddonPath::name(null));
        $this->assertFalse(AddonPath::archive([]));
    }
}
