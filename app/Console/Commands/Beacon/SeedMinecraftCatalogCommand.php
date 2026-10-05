<?php

namespace Pterodactyl\Console\Commands\Beacon;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconCatalogProfile;
use Pterodactyl\Models\BeaconCatalogVersion;
use Pterodactyl\Models\BeaconResourcePreset;
use Pterodactyl\Models\BeaconCatalogApplication;

class SeedMinecraftCatalogCommand extends Command
{
    protected $description = 'Creates or updates the safe default Beacon Minecraft catalog.';
    protected $signature = 'p:beacon:catalog:seed-minecraft';

    public function handle(): int
    {
        $nest = Nest::query()->where('name', config('beacon.modpacks.nest.name'))->first();
        if (!$nest instanceof Nest) {
            $this->error('The Beacon nest does not exist. Run the database seeders first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($nest) {
            $application = BeaconCatalogApplication::query()->updateOrCreate(
                ['slug' => 'minecraft-java'],
                [
                    'name' => 'Minecraft: Java Edition',
                    'description' => 'Curated Minecraft Java server profiles managed by Beacon Panel.',
                    'enabled' => true,
                ]
            );

            $this->seedProfile($application->id, $nest, 'Vanilla Minecraft', 'vanilla', 'Vanilla', null);
            $this->seedProfile($application->id, $nest, 'Paper', 'paper', 'Paper', 'plugins');

            foreach ([
                ['small', 'Small', 1024, 5120, 100],
                ['medium', 'Medium', 2048, 10240, 200],
                ['large', 'Large', 4096, 20480, 400],
            ] as [$code, $name, $memory, $disk, $cpu]) {
                BeaconResourcePreset::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'memory' => $memory,
                        'swap' => 0,
                        'disk' => $disk,
                        'io' => 500,
                        'cpu' => $cpu,
                        'threads' => null,
                        'database_limit' => 1,
                        'allocation_limit' => 1,
                        'backup_limit' => 2,
                        'enabled' => true,
                    ]
                );
            }
        }, 5);

        $this->info('Beacon Minecraft catalog seeded. All profiles use runtime Eggs from the Beacon nest.');
        $this->comment('Fabric, Forge, NeoForge, and Quilt creation profiles require dedicated installation contracts before they can be enabled.');

        return self::SUCCESS;
    }

    private function seedProfile(
        int $applicationId,
        Nest $nest,
        string $eggName,
        string $code,
        string $name,
        ?string $contentDirectory,
    ): void {
        $egg = Egg::query()->where('nest_id', $nest->id)->where('name', $eggName)->first();
        if (!$egg instanceof Egg) {
            $this->warn("Skipping {$name}: the {$eggName} Egg is not installed.");

            return;
        }

        $profile = BeaconCatalogProfile::query()->updateOrCreate(
            ['application_id' => $applicationId, 'code' => $code],
            [
                'egg_id' => $egg->id,
                'name' => $name,
                'loader' => $code,
                'content_directory' => $contentDirectory,
                'enabled' => true,
            ]
        );

        foreach (config('beacon.minecraft_versions') as $version) {
            $environment = $code === 'paper' ? [
                'MINECRAFT_VERSION' => $version,
                'SERVER_JARFILE' => 'server.jar',
                'BUILD_NUMBER' => 'latest',
            ] : [
                'SERVER_JARFILE' => 'server.jar',
                'VANILLA_VERSION' => $version,
            ];

            BeaconCatalogVersion::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'version' => $version, 'loader_version' => ''],
                [
                    'docker_image' => null,
                    'startup' => null,
                    'environment' => $environment,
                    'enabled' => true,
                    'deprecated' => false,
                ]
            );
        }
    }
}
