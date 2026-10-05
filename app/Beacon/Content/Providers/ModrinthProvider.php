<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class ModrinthProvider implements ContentProvider
{
    private const MAX_RESPONSE_BYTES = 2097152;

    public function search(string $query, string $gameVersion, string $loader, string $projectType, int $offset = 0): array
    {
        $query = trim($query);
        $typeFacet = $projectType === 'plugin' ? 'all_project_types:plugin' : "project_type:{$projectType}";
        $facets = [[$typeFacet], ["versions:{$gameVersion}"], ["categories:{$loader}"]];
        $data = $this->get('/search', [
            'query' => $query,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => $query === '' ? 'downloads' : 'relevance',
            'offset' => max(0, $offset),
            'limit' => 20,
        ]);

        if (!is_array($data) || !is_array($data['hits'] ?? null)) {
            throw new ProviderResponseException('Modrinth returned an invalid search response.');
        }

        return [
            'offset' => (int) ($data['offset'] ?? 0),
            'limit' => (int) ($data['limit'] ?? 20),
            'total' => (int) ($data['total_hits'] ?? 0),
            'projects' => collect($data['hits'])
                ->filter(fn ($project) => is_array($project) && ($project['server_side'] ?? null) !== 'unsupported')
                ->map(function ($project) {
                    return [
                        'id' => $this->requiredString($project, 'project_id'),
                        'slug' => $this->nullableString($project, 'slug'),
                        'title' => $this->requiredString($project, 'title'),
                        'description' => $this->requiredString($project, 'description'),
                        'author' => $this->requiredString($project, 'author'),
                        'project_type' => $this->requiredString($project, 'project_type'),
                        'icon_url' => $this->safeImageUrl($project['icon_url'] ?? null),
                        'downloads' => (int) ($project['downloads'] ?? 0),
                        'server_side' => $this->nullableString($project, 'server_side'),
                        'versions' => array_values(array_filter($project['versions'] ?? [], 'is_string')),
                    ];
                })->values()->all(),
        ];
    }

    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        $project = $this->project($projectId);
        $this->assertServerCompatible($project);
        $data = $this->get('/project/' . rawurlencode($projectId) . '/version', [
            'game_versions' => json_encode([$gameVersion], JSON_THROW_ON_ERROR),
            'loaders' => json_encode([$loader], JSON_THROW_ON_ERROR),
            'include_changelog' => 'false',
        ]);

        if (!is_array($data) || !array_is_list($data)) {
            throw new ProviderResponseException('Modrinth returned an invalid version list.');
        }

        return collect($data)->map(fn ($version) => $this->normalizeVersion($version, $project))->values()->all();
    }

    public function version(string $versionId): array
    {
        $version = $this->get('/version/' . rawurlencode($versionId));
        $projectId = $this->requiredString($version, 'project_id');
        $project = $this->project($projectId);
        $this->assertServerCompatible($project);

        return $this->normalizeVersion($version, $project);
    }

    private function project(string $projectId): array
    {
        $project = $this->get('/project/' . rawurlencode($projectId));
        if (!is_array($project)) {
            throw new ProviderResponseException('Modrinth returned an invalid project response.');
        }

        return $project;
    }

    private function get(string $path, array $query = []): array
    {
        $key = 'beacon:modrinth:' . hash('sha256', $path . '?' . http_build_query($query));

        return Cache::remember($key, config('beacon.modrinth.cache_ttl'), function () use ($path, $query) {
            $response = Http::baseUrl(config('beacon.modrinth.base_url'))
                ->acceptJson()
                ->withUserAgent(config('beacon.modrinth.user_agent'))
                ->withOptions(['allow_redirects' => false])
                ->timeout(config('beacon.modrinth.timeout'))
                ->retry(2, 250, throw: false)
                ->get($path, $query);

            if (!$response->successful()) {
                throw new ProviderResponseException("Modrinth request failed with HTTP {$response->status()}.");
            }

            if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
                throw new ProviderResponseException('Modrinth response exceeded the maximum allowed size.');
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new ProviderResponseException('Modrinth returned invalid JSON.');
            }

            return $data;
        });
    }

    private function normalizeVersion(array $version, array $project): array
    {
        $files = $version['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw new ProviderResponseException('Modrinth version has no downloadable files.');
        }

        $file = collect($files)->firstWhere('primary', true);
        if (!$file && count($files) === 1) {
            $file = Arr::first($files);
        }
        if (!is_array($file)) {
            throw new ProviderResponseException('Modrinth version does not identify one unambiguous primary file.');
        }

        $url = $this->safeDownloadUrl($this->requiredString($file, 'url'));
        $filename = $this->requiredString($file, 'filename');
        $sha512 = strtolower($this->requiredString($file, 'hashes.sha512'));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/', $filename)) {
            throw new ProviderResponseException('Modrinth returned an unsafe or unsupported filename.');
        }
        if (!preg_match('/^[a-f0-9]{128}$/', $sha512)) {
            throw new ProviderResponseException('Modrinth returned an invalid SHA-512 hash.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > config('beacon.content.max_file_bytes')) {
            throw new ProviderResponseException('Modrinth file exceeds the configured size policy.');
        }

        return [
            'id' => $this->requiredString($version, 'id'),
            'project_id' => $this->requiredString($version, 'project_id'),
            'project_name' => $this->requiredString($project, 'title'),
            'project_type' => $this->requiredString($project, 'project_type'),
            'icon_url' => $this->safeImageUrl($project['icon_url'] ?? null),
            'server_side' => $this->nullableString($project, 'server_side'),
            'name' => $this->requiredString($version, 'name'),
            'version_number' => $this->requiredString($version, 'version_number'),
            'version_type' => $this->requiredString($version, 'version_type'),
            'game_versions' => array_values(array_filter($version['game_versions'] ?? [], 'is_string')),
            'loaders' => array_values(array_filter($version['loaders'] ?? [], 'is_string')),
            'environment' => $this->nullableString($version, 'environment'),
            'dependencies' => array_values(array_filter($version['dependencies'] ?? [], 'is_array')),
            'file' => [
                'url' => $url,
                'filename' => $filename,
                'size' => $size,
                'sha512' => $sha512,
            ],
        ];
    }

    private function safeDownloadUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || !in_array(strtolower($parts['host'] ?? ''), config('beacon.modrinth.allowed_download_hosts'), true)) {
            throw new ProviderResponseException('Modrinth returned a download URL outside the approved CDN.');
        }

        return $url;
    }

    private function safeImageUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), config('beacon.modrinth.allowed_image_hosts'), true)
                ? $url
                : null;
    }

    private function assertServerCompatible(array $project): void
    {
        if (($project['server_side'] ?? null) === 'unsupported') {
            throw new ProviderResponseException('This Modrinth project is client-only and cannot be installed on a server.');
        }
    }

    private function requiredString(array $data, string $key): string
    {
        $value = data_get($data, $key);
        if (!is_string($value) || $value === '') {
            throw new ProviderResponseException("Modrinth response is missing {$key}.");
        }

        return $value;
    }

    private function nullableString(array $data, string $key): ?string
    {
        $value = data_get($data, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
