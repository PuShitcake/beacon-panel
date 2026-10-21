<?php

namespace Pterodactyl\Beacon\Content\Providers;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

/** Individual Minecraft mods only. Never uses modpack files or an unofficial download proxy. */
class CurseForgeModProvider implements ContentProvider
{
    protected const CLASS_ID = 6;
    protected const PROJECT_TYPE = 'mod';
    protected const LOADERS = ['forge'];
    protected const FILE_LOADER = 'Forge';
    protected const MOD_LOADER_TYPE = 1;

    public function isConfigured(): bool
    {
        $key = config('beacon.modpacks.providers.curseforge.api_key');

        return config('beacon.modpacks.providers.curseforge.enabled', false) && is_string($key) && trim($key) !== '';
    }

    public function categories(): array
    {
        $response = $this->get('/categories', ['gameId' => 432, 'classId' => static::CLASS_ID]);

        return collect($this->list($response))->filter(fn ($category) => is_array($category)
            && ($category['gameId'] ?? null) === 432 && ($category['classId'] ?? null) === static::CLASS_ID
            && ($category['isClass'] ?? false) === false)
            ->map(fn (array $category) => ['value' => $this->id($category['id'] ?? null), 'label' => $this->string($category, 'name')])
            ->sortBy('label')->values()->all();
    }

    public function search(string $query, string $gameVersion, string $loader, string $projectType, int $offset = 0, ?string $category = null): array
    {
        $this->assertForge($loader, $projectType);
        $params = ['gameId' => 432, 'classId' => static::CLASS_ID, 'gameVersion' => $gameVersion, ... (static::MOD_LOADER_TYPE === null ? [] : ['modLoaderType' => static::MOD_LOADER_TYPE]),
            'searchFilter' => trim($query), 'index' => min(9980, max(0, $offset)), 'pageSize' => 20, 'sortField' => 6, 'sortOrder' => 'desc'];
        if ($category !== null) {
            if (!in_array($category, array_column($this->categories(), 'value'), true)) {
                throw new ProviderResponseException('The selected CurseForge mod category is not supported.');
            }
            $params['categoryId'] = $category;
        }
        $response = $this->get('/mods/search', $params);
        $projects = [];
        foreach ($this->list($response) as $project) {
            if (!$this->availableMod($project)) {
                continue;
            }
            $projects[] = ['id' => $this->id($project['id'] ?? null), 'slug' => $project['slug'] ?? null,
                'title' => $this->string($project, 'name'), 'description' => $this->string($project, 'summary'),
                'author' => (string) data_get($project, 'authors.0.name', ''), 'icon_url' => $this->image(data_get($project, 'logo.thumbnailUrl')),
                'downloads' => max(0, (int) ($project['downloadCount'] ?? 0)), 'server_side' => 'unknown'];
        }

        return ['offset' => $params['index'], 'limit' => 20, 'total' => min(10000, max(0, (int) data_get($response, 'pagination.totalCount', 0))), 'projects' => $projects];
    }

    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        $this->assertForge($loader, static::PROJECT_TYPE);
        $projectId = $this->id($projectId);
        $project = $this->project($projectId);
        $response = $this->get('/mods/' . $projectId . '/files', ['gameVersion' => $gameVersion, ... (static::MOD_LOADER_TYPE === null ? [] : ['modLoaderType' => static::MOD_LOADER_TYPE]), 'pageSize' => 50]);

        return collect($this->list($response))
            ->filter(fn ($file) => is_array($file) && is_array($file['gameVersions'] ?? null) && in_array($gameVersion, $file['gameVersions'], true)
                && (static::FILE_LOADER === null || in_array(static::FILE_LOADER, $file['gameVersions'] ?? [], true)) && is_string($file['downloadUrl'] ?? null))
            ->map(fn (array $file) => $this->normalize($file, $project))
            ->sortByDesc('published_at')->values()->all();
    }

    public function version(string $versionId): array
    {
        if (!preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D', $versionId, $ids)) {
            throw new ProviderResponseException('Select a valid CurseForge mod file.');
        }
        $project = $this->project($ids[1]);
        $response = $this->get('/mods/' . $ids[1] . '/files/' . $ids[2]);
        $file = $response['data'] ?? null;
        if (!is_array($file) || $this->id($file['id'] ?? null) !== $ids[2]) {
            throw new ProviderResponseException('CurseForge returned an invalid mod file.');
        }

        return $this->normalize($file, $project);
    }

    /** Resolve at execution time: Wings remote-file pulls do not follow CDN redirects. */
    public function resolveDownloadUrl(string $url): string
    {
        if (!$this->isConfigured()) {
            throw new ProviderResponseException('CurseForge mod downloads are unavailable. An administrator must configure the provider.');
        }
        $seen = [];
        for ($redirects = 0; $redirects <= 3; ++$redirects) {
            $url = $this->download($url);
            if (isset($seen[$url])) {
                throw new ProviderResponseException('The CurseForge download redirect chain contains a loop.');
            }
            $seen[$url] = true;
            try {
                // No API credentials, cookies or automatic redirects on CDN probes. HEAD avoids
                // fetching an unbounded JAR into Panel; the existing worker verifies the bytes.
                $response = Http::withUserAgent(config('beacon.modrinth.user_agent'))
                    ->withOptions($this->httpOptions())->timeout(min(10, max(1, (int) config('beacon.modrinth.timeout', 10))))
                    ->head($url);
            } catch (ConnectionException) {
                throw new ProviderResponseException('The CurseForge download endpoint is temporarily unavailable.');
            }
            try {
                if ($response->status() === 200) {
                    return $url;
                }
                if (!in_array($response->status(), [301, 302, 303, 307, 308], true) || $redirects === 3) {
                    throw new ProviderResponseException('CurseForge did not provide a successful direct download within the redirect limit.');
                }
                $locations = $response->toPsrResponse()->getHeader('Location');
                if (count($locations) !== 1 || !is_string($locations[0]) || $locations[0] === ''
                    || strlen($locations[0]) > 4096 || preg_match('/[\x00-\x20\x7f]/', $locations[0]) || str_contains($locations[0], '\\')) {
                    throw new ProviderResponseException('CurseForge returned an invalid download redirect.');
                }
                try {
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($locations[0]));
                } catch (\InvalidArgumentException) {
                    throw new ProviderResponseException('CurseForge returned an invalid download redirect.');
                }
                // Validate the resolved target BEFORE another request, including relative URLs.
                $this->download($url);
            } finally {
                $response->close();
            }
        }

        throw new ProviderResponseException('CurseForge did not provide a successful direct download within the redirect limit.');
    }

    private function project(string $id): array
    {
        $response = $this->get('/mods/' . $id);
        $project = $response['data'] ?? null;
        if (!$this->availableMod($project) || $this->id($project['id'] ?? null) !== $id) {
            throw new ProviderResponseException('This CurseForge project is unavailable, disallows third-party downloads, or is not a Minecraft mod.');
        }

        return $project;
    }

    private function availableMod(mixed $project): bool
    {
        return is_array($project) && ($project['gameId'] ?? null) === 432 && ($project['classId'] ?? null) === static::CLASS_ID
            && ($project['isAvailable'] ?? false) === true && ($project['allowModDistribution'] ?? null) !== false;
    }

    private function normalize(array $file, array $project): array
    {
        $projectId = $this->id($project['id'] ?? null);
        if ($this->id($file['modId'] ?? null) !== $projectId || ($file['isAvailable'] ?? false) !== true
            || !in_array($file['fileStatus'] ?? null, [4, 10], true)) {
            throw new ProviderResponseException('The selected CurseForge file is unavailable or does not belong to this mod.');
        }
        $tags = $file['gameVersions'] ?? [];
        if (!is_array($tags) || (static::FILE_LOADER !== null && !in_array(static::FILE_LOADER, $tags, true))) {
            throw new ProviderResponseException('The selected CurseForge mod file does not support Forge.');
        }
        $filename = $this->string($file, 'fileName');
        $size = $file['fileLength'] ?? null;
        if (strlen($filename) > 191 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/D', $filename)
            || !is_int($size) || $size < 1 || $size > config('beacon.content.max_file_bytes')) {
            throw new ProviderResponseException('CurseForge returned an unsafe filename or a file outside the size policy.');
        }
        if (!is_array($file['hashes'] ?? null) || !is_array($file['dependencies'] ?? null)
            || !array_is_list($file['dependencies']) || count($file['dependencies']) > 256) {
            throw new ProviderResponseException('CurseForge returned invalid hash or dependency metadata.');
        }
        $hashes = array_values(array_filter($file['hashes'], fn ($hash) => is_array($hash) && ($hash['algo'] ?? null) === 1));
        $sha1 = count($hashes) === 1 ? ($hashes[0]['value'] ?? null) : null;
        if (!is_string($sha1) || !preg_match('/^[a-fA-F0-9]{40}$/D', $sha1)) {
            throw new ProviderResponseException('CurseForge did not provide an unambiguous SHA-1 file hash.');
        }
        $dependencies = [];
        foreach ($file['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency)) {
                throw new ProviderResponseException('CurseForge returned invalid dependency metadata.');
            }
            $type = match ($dependency['relationType'] ?? null) {
                1 => 'embedded', 2 => 'optional', 3, 6 => 'required', 4 => 'tool', 5 => 'incompatible',
                default => throw new ProviderResponseException('CurseForge returned an unsupported dependency relationship.'),
            };
            $dependencies[] = ['project_id' => $this->id($dependency['modId'] ?? null), 'version_id' => null, 'dependency_type' => $type];
        }

        return ['id' => $projectId . ':' . $this->id($file['id'] ?? null), 'project_id' => $projectId,
            'project_name' => $this->string($project, 'name'), 'project_type' => static::PROJECT_TYPE, 'icon_url' => $this->image(data_get($project, 'logo.thumbnailUrl')),
            'server_side' => 'unknown', 'name' => $this->string($file, 'displayName'), 'version_number' => $filename,
            'version_type' => match ($file['releaseType'] ?? null) {
                1 => 'release', 2 => 'beta', 3 => 'alpha', default => throw new ProviderResponseException('CurseForge returned an invalid release type.'),
            },
            'published_at' => $this->string($file, 'fileDate'), 'game_versions' => array_values(array_filter($tags, 'is_string')),
            'loaders' => static::LOADERS, 'dependencies' => $dependencies,
            'file' => ['url' => $this->download($file['downloadUrl'] ?? null), 'filename' => $filename, 'size' => $size, 'sha512' => null, 'sha1' => strtolower($sha1)]];
    }

    private function get(string $path, array $query = []): array
    {
        // Check BEFORE consulting the cache: disabling/removing credentials must take effect now.
        if (!$this->isConfigured()) {
            throw new ProviderResponseException('CurseForge is unavailable. An administrator must configure its API key. You can select Modrinth instead.');
        }
        $key = (string) config('beacon.modpacks.providers.curseforge.api_key');
        $cache = 'beacon:curseforge-mods:' . hash('sha256', $key . '|' . $path . '?' . http_build_query($query));

        return Cache::remember($cache, (int) config('beacon.modrinth.cache_ttl', 300), function () use ($path, $query, $key) {
            try {
                $response = Http::baseUrl(config('beacon.modpacks.providers.curseforge.base_url'))
                    ->acceptJson()->withHeaders(['x-api-key' => $key])->withUserAgent(config('beacon.modrinth.user_agent'))
                    ->withOptions($this->httpOptions())->timeout((int) config('beacon.modrinth.timeout', 10))->get($path, $query);
            } catch (ConnectionException) {
                throw new ProviderResponseException('CurseForge is temporarily unavailable. Try again later or select Modrinth.');
            }
            if (!$response->successful() || strlen($response->body()) > 4194304 || !is_array($response->json())) {
                throw new ProviderResponseException('CurseForge returned an unsuccessful or invalid response. Try again later or select Modrinth.');
            }

            return $response->json();
        });
    }

    private function list(array $response): array
    {
        if (!is_array($response['data'] ?? null) || !array_is_list($response['data'])) {
            throw new ProviderResponseException('CurseForge returned an invalid list response.');
        }

        return $response['data'];
    }

    private function id(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value)) {
            throw new ProviderResponseException('CurseForge returned an invalid identifier.');
        }

        return (string) $value;
    }

    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new ProviderResponseException('CurseForge returned incomplete mod metadata.');
        }

        return $data[$key];
    }

    private function assertForge(string $loader, string $type): void
    {
        if (!in_array($loader, static::LOADERS, true) || $type !== static::PROJECT_TYPE) {
            throw new ProviderResponseException('The CurseForge Mod Installer supports Forge mods only.');
        }
    }

    private function download(mixed $url): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')
            || ($parts['scheme'] ?? null) !== 'https' || filter_var($parts['host'] ?? '', FILTER_VALIDATE_IP) !== false
            || !in_array(strtolower($parts['host'] ?? ''), config('beacon.modpacks.providers.curseforge.download_hosts', []), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new ProviderResponseException('This CurseForge file has no permitted third-party download on the approved CDN.');
        }

        return $url;
    }

    private function httpOptions(): array
    {
        $options = ['allow_redirects' => false, 'cookies' => false];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        return $options;
    }

    private function image(mixed $url): ?string
    {
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), config('beacon.modpacks.providers.curseforge.image_hosts', []), true)
            && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443) ? $url : null;
    }
}
