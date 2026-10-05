<?php

namespace Pterodactyl\Tests\Integration\Beacon\Catalog;

use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\BeaconCatalogApplication;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class SeedMinecraftCatalogCommandTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testCommandSeedsAnIdempotentNonCommercialMinecraftCatalog(): void
    {
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);
        $this->artisan('p:beacon:catalog:seed-minecraft')->assertExitCode(0);

        $application = BeaconCatalogApplication::query()
            ->with(['profiles.versions', 'profiles.egg.nest'])
            ->where('slug', 'minecraft-java')
            ->sole();

        $this->assertSame('Minecraft: Java Edition', $application->name);
        $this->assertSame(['paper', 'vanilla'], $application->profiles->pluck('code')->sort()->values()->all());
        $this->assertTrue($application->profiles->every(
            fn ($profile) => $profile->egg->nest->name === 'Beacon'
        ));
        $this->assertTrue($application->profiles->every(
            fn ($profile) => $profile->versions->count() === count(config('beacon.minecraft_versions'))
        ));
        $this->assertFalse($application->profiles->flatMap->versions->pluck('version')->contains('latest'));
        $this->assertDatabaseCount('beacon_resource_presets', 3);

        $columns = Schema::getColumnListing('beacon_resource_presets');
        $this->assertNotContains('price', $columns);
        $this->assertNotContains('billing_cycle', $columns);
    }
}
