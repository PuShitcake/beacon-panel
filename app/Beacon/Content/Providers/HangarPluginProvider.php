<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class HangarPluginProvider implements ContentProvider
{
    public const CATEGORIES = ['admin_tools', 'chat', 'dev_tools', 'economy', 'gameplay', 'games', 'protection', 'role_playing', 'world_management', 'misc'];

    public function search(string $query, string $gameVersion, string $loader, string $projectType, int $offset = 0, ?string $category = null): array
    {
        $this->assertPlatform($loader, $projectType);
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            throw new ProviderResponseException('The selected Hangar category is not supported.');
        }
        $params = ['query' => $query, 'platform' => 'PAPER', 'version' => $gameVersion, 'limit' => 20, 'offset' => $offset, 'sort' => 'downloads'];
        if ($category !== null) {
            $params['category'] = $category;
        }
        $response = $this->get('/projects', $params);
        $projects = [];
        foreach ($this->items($response) as $project) {
            if (($project['visibility'] ?? null) !== 'public' || !in_array($gameVersion, $project['supportedPlatforms']['PAPER'] ?? [], true)) {
                continue;
            }
            $projects[] = ['id' => $this->id($project['id'] ?? null), 'slug' => null,
                'title' => $this->string($project, 'name'), 'description' => $this->string($project, 'description'),
                'author' => (string) data_get($project, 'namespace.owner', ''), 'downloads' => max(0, (int) data_get($project, 'stats.downloads', 0)),
                'icon_url' => $this->image($project['avatarUrl'] ?? null), 'server_side' => 'required'];
        }

        return ['projects' => $projects, 'offset' => $offset, 'limit' => 20, 'total' => max(0, (int) data_get($response, 'pagination.count', 0))];
    }

    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        $this->assertPlatform($loader, 'plugin');
        $project = $this->project($projectId);
        $response = $this->get('/projects/' . $this->id($projectId) . '/versions', [
            'platform' => 'PAPER', 'platformVersion' => $gameVersion, 'limit' => 25, 'includeHiddenChannels' => 'false',
        ]);

        return collect($this->items($response))->filter(fn ($version) => is_array($version)
            && in_array($gameVersion, $version['platformDependencies']['PAPER'] ?? [], true)
            && ($version['visibility'] ?? null) === 'public' && isset($version['downloads']['PAPER']['fileInfo'])
            && empty($version['downloads']['PAPER']['externalUrl']))
            ->map(fn (array $version) => $this->normalize($version, $project))->sortByDesc('published_at')->values()->all();
    }

    public function version(string $versionId): array
    {
        if (!preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D', $versionId, $parts)) {
            throw new ProviderResponseException('Select a valid Hangar plugin version.');
        }
        $project = $this->project($parts[1]);
        $version = $this->get('/projects/' . $parts[1] . '/versions/' . $parts[2]);
        if ($this->id($version['id'] ?? null) !== $parts[2]) {
            throw new ProviderResponseException('Hangar returned an invalid plugin version.');
        }

        return $this->normalize($version, $project);
    }

    public function downloadUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'hangarcdn.papermc.io'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new ProviderResponseException('Hangar did not provide a download on its approved CDN. External downloads are not supported.');
        }

        return $url;
    }

    private function project(string $id): array
    {
        $project = $this->get('/projects/' . $this->id($id));
        if ($this->id($project['id'] ?? null) !== $id || ($project['visibility'] ?? null) !== 'public') {
            throw new ProviderResponseException('The Hangar project is unavailable.');
        }

        return $project;
    }

    private function normalize(array $version, array $project): array
    {
        $file = $version['downloads']['PAPER'] ?? [];
        $info = $file['fileInfo'] ?? [];
        $filename = $info['name'] ?? null;
        $size = $info['sizeBytes'] ?? null;
        $sha256 = $info['sha256Hash'] ?? null;
        if (($version['projectId'] ?? null) !== $project['id'] || ($version['visibility'] ?? null) !== 'public'
            || !in_array($version['reviewState'] ?? null, ['reviewed'], true)
            || !empty($file['externalUrl']) || !is_string($filename) || strlen($filename) > 191
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/D', $filename)
            || !is_int($size) || $size < 1 || $size > config('beacon.content.max_file_bytes')
            || !is_string($sha256) || !preg_match('/^[a-fA-F0-9]{64}$/D', $sha256)) {
            throw new ProviderResponseException('The Hangar file is unreviewed, external, unsafe, or outside the download policy.');
        }
        $games = $version['platformDependencies']['PAPER'] ?? null;
        $rawDependencies = $version['pluginDependencies']['PAPER'] ?? [];
        if (!is_array($games) || $games === [] || !is_array($rawDependencies) || count($rawDependencies) > 256) {
            throw new ProviderResponseException('Hangar returned invalid platform or dependency metadata.');
        }
        $dependencies = [];
        foreach ($rawDependencies as $dependency) {
            if (!is_array($dependency) || !is_bool($dependency['required'] ?? null)) {
                throw new ProviderResponseException('Hangar returned invalid dependency metadata.');
            }
            if ($dependency['required'] && (!empty($dependency['externalUrl']) || empty($dependency['projectId']))) {
                throw new ProviderResponseException('A required Hangar dependency needs a manual external download. This plugin cannot be installed automatically.');
            }
            if (!empty($dependency['projectId'])) {
                $dependencies[] = ['project_id' => $this->id($dependency['projectId']), 'version_id' => null,
                    'dependency_type' => $dependency['required'] ? 'required' : 'optional'];
            }
        }

        return ['id' => $this->id($project['id']) . ':' . $this->id($version['id']), 'project_id' => $this->id($project['id']),
            'project_name' => $this->string($project, 'name'), 'project_type' => 'plugin', 'icon_url' => $this->image($project['avatarUrl'] ?? null),
            'server_side' => 'required', 'name' => $this->string($version, 'name'), 'version_number' => $this->string($version, 'name'),
            'version_type' => strtolower((string) data_get($version, 'channel.name')) === 'release' ? 'release' : 'beta',
            'published_at' => $this->string($version, 'createdAt'), 'game_versions' => $games, 'loaders' => ['paper'], 'dependencies' => $dependencies,
            'file' => ['url' => $this->downloadUrl($this->string($file, 'downloadUrl')), 'filename' => $filename,
                'size' => $size, 'sha512' => null, 'sha256' => strtolower($sha256)]];
    }

    private function get(string $path, array $query = []): array
    {
        return Cache::remember('beacon:hangar:' . hash('sha256', $path . '?' . http_build_query($query)), 300, function () use ($path, $query) {
            $options = ['allow_redirects' => false, 'cookies' => false];
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
                $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
            }
            try {
                $response = Http::acceptJson()->withUserAgent(config('beacon.modrinth.user_agent'))
                    ->withOptions($options)->timeout(10)
                    ->get('https://hangar.papermc.io/api/v1' . $path, $query);
            } catch (ConnectionException) {
                throw new ProviderResponseException('Hangar is temporarily unavailable. Try again later or select another provider.');
            }
            if ($response->status() !== 200 || strlen($response->body()) > 4194304 || !is_array($response->json())) {
                throw new ProviderResponseException('Hangar returned an unsuccessful or invalid response.');
            }

            return $response->json();
        });
    }

    private function assertPlatform(string $loader, string $type): void
    {
        if ($loader !== 'paper' || $type !== 'plugin') {
            throw new ProviderResponseException('Hangar currently supports Paper-compatible server plugins only.');
        }
    }

    private function items(array $response): array
    {
        if (!is_array($response['result'] ?? null) || !array_is_list($response['result'])) {
            throw new ProviderResponseException('Hangar returned an invalid list.');
        }

        return $response['result'];
    }

    private function id(mixed $id): string
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)) {
            throw new ProviderResponseException('Hangar returned an invalid identifier.');
        }

        return (string) $id;
    }

    private function string(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new ProviderResponseException('Hangar returned incomplete plugin metadata.');
        }

        return $data[$key];
    }

    private function image(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://hangarcdn.papermc.io/') && !preg_match('/[\x00-\x20\x7f\\\\]/', $url) ? $url : null;
    }
}
