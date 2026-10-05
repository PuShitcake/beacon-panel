<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Pterodactyl\Beacon\Content\DependencyPlanner;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Beacon\Content\Providers\ContentProvider;

class DependencyPlannerTest extends IntegrationTestCase
{
    public function testItResolvesRequiredDependenciesAndReportsConflictsForInstalledStateChecks(): void
    {
        config()->set('beacon.content.max_dependency_depth', 8);
        config()->set('beacon.content.max_dependencies', 10);
        config()->set('beacon.content.max_total_bytes', 10000);

        $provider = \Mockery::mock(ContentProvider::class);
        $provider->expects('version')->with('root-version')->andReturn($this->version(
            'root-version',
            'root-project',
            'root.jar',
            [
                ['dependency_type' => 'required', 'version_id' => 'dependency-version', 'project_id' => 'dependency-project'],
                ['dependency_type' => 'incompatible', 'project_id' => 'conflicting-project'],
            ],
        ));
        $provider->expects('version')->with('dependency-version')->andReturn(
            $this->version('dependency-version', 'dependency-project', 'dependency.jar')
        );

        $plan = (new DependencyPlanner($provider))->handle('root-version', '1.21.8', 'paper', 'plugin');

        $this->assertSame(['root-project', 'dependency-project'], array_column($plan['files'], 'project_id'));
        $this->assertFalse($plan['files'][0]['dependency']);
        $this->assertTrue($plan['files'][1]['dependency']);
        $this->assertSame('conflicting-project', $plan['incompatible_dependencies'][0]['project_id']);
    }

    public function testItSelectsAStableReleaseForARequiredProjectDependency(): void
    {
        config()->set('beacon.content.max_dependency_depth', 8);
        config()->set('beacon.content.max_dependencies', 10);
        config()->set('beacon.content.max_total_bytes', 10000);

        $provider = \Mockery::mock(ContentProvider::class);
        $provider->expects('version')->with('root-version')->andReturn($this->version(
            'root-version',
            'root-project',
            'root.jar',
            [['dependency_type' => 'required', 'project_id' => 'dependency-project']],
        ));
        $provider->expects('versions')->with('dependency-project', '1.21.8', 'paper')->andReturn([
            ['id' => 'beta-version', 'version_type' => 'beta'],
            ['id' => 'release-version', 'version_type' => 'release'],
        ]);
        $provider->expects('version')->with('release-version')->andReturn(
            $this->version('release-version', 'dependency-project', 'dependency.jar')
        );

        $plan = (new DependencyPlanner($provider))->handle('root-version', '1.21.8', 'paper', 'plugin');

        $this->assertSame(['root-version', 'release-version'], array_column($plan['files'], 'version_id'));
    }

    private function version(string $id, string $projectId, string $filename, array $dependencies = []): array
    {
        return [
            'id' => $id,
            'project_id' => $projectId,
            'project_name' => $projectId,
            'project_type' => 'plugin',
            'icon_url' => null,
            'server_side' => 'required',
            'name' => $id,
            'version_number' => '1.0.0',
            'game_versions' => ['1.21.8'],
            'loaders' => ['paper'],
            'dependencies' => $dependencies,
            'file' => [
                'url' => "https://cdn.modrinth.com/{$filename}",
                'filename' => $filename,
                'size' => 100,
                'sha512' => str_repeat('a', 128),
            ],
        ];
    }
}
