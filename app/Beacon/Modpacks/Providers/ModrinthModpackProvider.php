<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Illuminate\Support\Arr;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class ModrinthModpackProvider extends AbstractModpackProvider
{
    public function key(): string
    {
        return 'modrinth';
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $offset = ($page - 1) * $pageSize;
        $data = $this->get('/search', [
            'query' => $query,
            'facets' => json_encode([['project_type:modpack']], JSON_THROW_ON_ERROR),
            'index' => 'relevance',
            'offset' => $offset,
            'limit' => $pageSize,
        ]);
        if (!is_array($data['hits'] ?? null)) {
            throw new ModpackProviderException('Modrinth returned an invalid modpack search response.');
        }

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int) ($data['total_hits'] ?? 0),
            'projects' => collect($data['hits'])->map(fn (array $pack) => [
                'id' => $this->requiredString($pack, 'project_id'),
                'slug' => $this->nullableString($pack, 'slug'),
                'name' => $this->requiredString($pack, 'title'),
                'description' => $this->nullableString($pack, 'description') ?? '',
                'author' => $this->nullableString($pack, 'author'),
                'icon_url' => $this->safeImageUrl($pack['icon_url'] ?? null),
                'downloads' => (int) ($pack['downloads'] ?? 0),
                'website_url' => 'https://modrinth.com/modpack/' . rawurlencode((string) ($pack['slug'] ?? $pack['project_id'])),
            ])->values()->all(),
        ];
    }

    public function versions(string $projectId): array
    {
        $data = $this->get('/project/' . rawurlencode($projectId) . '/version');
        if (!array_is_list($data)) {
            throw new ModpackProviderException('Modrinth returned an invalid modpack version response.');
        }

        return collect($data)->map(fn (array $version) => $this->versionData($version))->values()->all();
    }

    public function release(string $projectId, string $versionId): array
    {
        $version = $this->get('/version/' . rawurlencode($versionId));
        if ($this->requiredString($version, 'project_id') !== $projectId) {
            throw new ModpackProviderException('The selected Modrinth version does not belong to this modpack.');
        }
        $files = $version['files'] ?? [];
        $file = collect(is_array($files) ? $files : [])->firstWhere('primary', true);
        if (!is_array($file) && count($files) === 1) {
            $file = Arr::first($files);
        }
        if (!is_array($file)) {
            throw new ModpackProviderException('Modrinth did not identify one primary modpack archive.');
        }
        $filename = $this->requiredString($file, 'filename');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+ -]*\.mrpack$/i', $filename)) {
            throw new ModpackProviderException('Modrinth returned an unsupported modpack archive name.');
        }
        $gameVersion = $this->minecraftVersionFrom($version['game_versions'] ?? []);
        $loader = $this->loaderFrom($version['loaders'] ?? []);
        if (!$gameVersion || !$loader) {
            throw new ModpackProviderException('The selected Modrinth version has no supported Minecraft loader target.');
        }
        $sha512 = strtolower($this->requiredString($file, 'hashes.sha512'));
        if (!preg_match('/^[a-f0-9]{128}$/', $sha512)) {
            throw new ModpackProviderException('Modrinth returned an invalid archive hash.');
        }
        $project = $this->get('/project/' . rawurlencode($projectId));
        if (($project['project_type'] ?? null) !== 'modpack') {
            throw new ModpackProviderException('The selected Modrinth project is not a modpack.');
        }

        return [
            'provider' => $this->key(),
            'project_id' => $projectId,
            'project_slug' => $this->nullableString($project, 'slug'),
            'name' => $this->requiredString($project, 'title'),
            'icon_url' => $this->safeImageUrl($project['icon_url'] ?? null),
            'version_id' => $versionId,
            'version_name' => $this->requiredString($version, 'version_number'),
            'minecraft_version' => $gameVersion,
            'loader' => $loader,
            'loader_version' => $this->loaderVersion($version, $loader),
            'java_version' => $this->javaVersion($gameVersion),
            'install_mode' => 'modrinth',
            'archive' => [
                'url' => $this->safeDownloadUrl($this->requiredString($file, 'url')),
                'filename' => $filename,
                'size' => (int) ($file['size'] ?? 0),
                'sha512' => $sha512,
            ],
        ];
    }

    private function versionData(array $version): array
    {
        $gameVersion = $this->minecraftVersionFrom($version['game_versions'] ?? []);
        $loader = $this->loaderFrom($version['loaders'] ?? []);

        return [
            'id' => $this->requiredString($version, 'id'),
            'name' => $this->requiredString($version, 'name'),
            'version' => $this->requiredString($version, 'version_number'),
            'minecraft_version' => $gameVersion,
            'loader' => $loader,
            'published_at' => $this->nullableString($version, 'date_published'),
            'installable' => $gameVersion !== null && $loader !== null,
        ];
    }

    private function loaderVersion(array $version, string $loader): ?string
    {
        $dependencies = $version['dependencies'] ?? [];
        if (!is_array($dependencies)) {
            return null;
        }
        foreach ($dependencies as $dependency) {
            if (is_array($dependency) && ($dependency['project_id'] ?? null) === $loader) {
                return $this->nullableString($dependency, 'version_id');
            }
        }

        return null;
    }
}
