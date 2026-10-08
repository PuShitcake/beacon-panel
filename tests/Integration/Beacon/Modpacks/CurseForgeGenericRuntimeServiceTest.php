<?php

namespace Pterodactyl\Tests\Integration\Beacon\Modpacks;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class CurseForgeGenericRuntimeServiceTest extends IntegrationTestCase
{
    public function testItValidatesTheImportedCurseForgeGenericContract(): void
    {
        config()->set('beacon.modpacks.nest.name', 'Beacon CurseForge Test');
        config()->set('beacon.modpacks.nest.curseforge_egg', 'CurseForge Generic');
        $nest = Nest::factory()->create(['name' => 'Beacon CurseForge Test']);
        $egg = Egg::factory()->create([
            'nest_id' => $nest->id,
            'name' => 'CurseForge Generic',
            'docker_images' => [
                'Java 17' => 'ghcr.io/pterodactyl/yolks:java_17j9',
            ],
        ]);
        foreach (['PROJECT_ID', 'VERSION'] as $variable) {
            EggVariable::factory()->create([
                'egg_id' => $egg->id,
                'env_variable' => $variable,
            ]);
        }

        app(ModpackRuntimeService::class)->validateCurseForgeTarget([
            'provider' => 'curseforge',
            'project_id' => '123',
            'version_id' => '456',
            'java_version' => 17,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testItRejectsNonCurseForgeSelections(): void
    {
        $this->expectException(ModpackProviderException::class);
        $this->expectExceptionMessage('only accepts CurseForge');

        app(ModpackRuntimeService::class)->validateCurseForgeTarget([
            'provider' => 'modrinth',
            'project_id' => '123',
            'version_id' => '456',
            'java_version' => 17,
        ]);
    }
}
