<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Database\Seeders\BeaconModpackNestSeeder;
use Pterodactyl\Console\Commands\Beacon\CleanupRetiredEggsCommand;

class BeaconRetiredEggCleanupContractTest extends TestCase
{
    public function testCleanupTargetsOnlyRetiredNonReferenceEggs(): void
    {
        $retired = (new \ReflectionClass(CleanupRetiredEggsCommand::class))->getConstant('RETIRED_EGGS');

        $this->assertSame([
            'Forge Minecraft',
            'Beacon Modpack Installer',
            'Sponge (SpongeVanilla)',
        ], $retired);
        $this->assertSame([], array_intersect($retired, [
            'CurseForge Generic',
            'Fabric',
            'Paper + Geyser + Floodgate',
            'Vanilla Bedrock',
        ]));
    }

    public function testSeederCannotRecreateRetiredRuntimeEggs(): void
    {
        $runtimeEggs = (new \ReflectionClass(BeaconModpackNestSeeder::class))->getConstant('RUNTIME_EGGS');

        $this->assertSame(['Vanilla Minecraft', 'Paper'], $runtimeEggs);
    }
}
