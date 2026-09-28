<?php

namespace Pterodactyl\Tests\Integration\Beacon\Content;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Beacon\Content\Providers\ModrinthProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class ModrinthProviderTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('beacon.content.max_file_bytes', 1024 * 1024);
    }

    public function testItNormalizesAnExactVersionAndAcceptsOnlyTheModrinthCdn(): void
    {
        Http::fake([
            'https://api.modrinth.com/v2/version/version-one' => Http::response($this->version()),
        ]);

        $version = app(ModrinthProvider::class)->version('version-one');

        $this->assertSame('project-one', $version['project_id']);
        $this->assertSame('plugin.jar', $version['file']['filename']);
        $this->assertSame(str_repeat('a', 128), $version['file']['sha512']);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent'));
    }

    public function testItRejectsDownloadUrlsOutsideTheApprovedCdn(): void
    {
        $response = $this->version();
        $response['files'][0]['url'] = 'https://example.com/plugin.jar';
        Http::fake([
            'https://api.modrinth.com/v2/version/version-one' => Http::response($response),
        ]);

        $this->expectException(ProviderResponseException::class);
        app(ModrinthProvider::class)->version('version-one');
    }

    public function testItRejectsMultipleFilesWhenModrinthDoesNotIdentifyAPrimaryFile(): void
    {
        $response = $this->version();
        $response['files'][0]['primary'] = false;
        $response['files'][] = array_merge($response['files'][0], [
            'filename' => 'plugin-sources.jar',
            'url' => 'https://cdn.modrinth.com/data/project-one/versions/version-one/plugin-sources.jar',
        ]);
        Http::fake([
            'https://api.modrinth.com/v2/version/version-one' => Http::response($response),
        ]);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('unambiguous primary file');
        app(ModrinthProvider::class)->version('version-one');
    }

    private function version(): array
    {
        return [
            'id' => 'version-one',
            'project_id' => 'project-one',
            'name' => 'Version One',
            'version_number' => '1.0.0',
            'version_type' => 'release',
            'game_versions' => ['1.21.8'],
            'loaders' => ['paper'],
            'dependencies' => [],
            'files' => [[
                'primary' => true,
                'url' => 'https://cdn.modrinth.com/data/project-one/versions/version-one/plugin.jar',
                'filename' => 'plugin.jar',
                'size' => 1024,
                'hashes' => ['sha512' => str_repeat('a', 128)],
            ]],
        ];
    }
}
