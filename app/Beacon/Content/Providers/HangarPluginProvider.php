<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class HangarPluginProvider implements ContentProvider
{
    public const CATEGORIES = ['admin_tools', 'chat', 'dev_tools', 'economy', 'gameplay', 'games', 'protection', 'role_playing', 'world_management', 'misc'];

    public function search(string $query, string $gameVersion, string $loader, string $projectType, int $offset = 0, ?string $category = null): array
    {
        $this->assertPlatform($loader, $projectType);
        $platform = strtoupper($loader);
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            throw new ProviderResponseException('The selected Hangar category is not supported.');
        }
        $params = ['query' => $query, 'platform' => $platform, 'version' => $gameVersion, 'limit' => 20, 'offset' => $offset, 'sort' => 'downloads'];
        if ($category !== null) {
            $params['category'] = $category;
        }
        $response = $this->get('/projects', $params);
        $page = $this->items($response);
        if (count($page) > 20) {
            throw new ProviderResponseException('Hangar returned an oversized project page.');
        }
        $projects = [];
        // Review states in Hangar's indexed lists can differ from exact version details.
        // Share raw detail caches with the picker/plan so review labels stay consistent.
        $deadline = microtime(true) + 8;
        $page = array_values(array_filter($page, fn ($project) => is_array($project)
            && ($project['visibility'] ?? null) === 'public' && is_array($project['supportedPlatforms'][$platform] ?? null)
            && in_array($gameVersion, $project['supportedPlatforms'][$platform], true)));
        $this->prefetch(
            array_map(fn ($project) => '/projects/' . $this->id($project['id'] ?? null) . '/versions', $page),
            $this->versionQuery($gameVersion, $platform),
            $deadline
        );
        $newest = [];
        foreach ($page as $project) {
            $candidates = $this->candidatePaths($this->id($project['id']), $gameVersion, $platform, $deadline);
            if ($candidates !== []) {
                $newest[] = reset($candidates);
            }
        }
        $this->prefetch($newest, [], $deadline);
        foreach ($page as $project) {
            $latest = null;
            foreach ($this->eligibleReleases($project['id'] ?? null, $gameVersion, $platform, $deadline, true) as $release) {
                $latest = $release;
                break;
            }
            if ($latest === null) {
                continue;
            }
            $projects[] = ['id' => $this->id($project['id'] ?? null), 'slug' => null,
                'title' => $this->string($project, 'name'), 'description' => $this->string($project, 'description'),
                'author' => (string) data_get($project, 'namespace.owner', ''), 'downloads' => max(0, (int) data_get($project, 'stats.downloads', 0)),
                'icon_url' => $this->image($project['avatarUrl'] ?? null), 'server_side' => 'required', 'review_state' => $latest['reviewState']];
        }

        return ['projects' => $projects, 'offset' => $offset, 'limit' => 20, 'total' => max(0, (int) data_get($response, 'pagination.count', 0)), 'page_filtered' => true];
    }

    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        $this->assertPlatform($loader, 'plugin');
        $project = $this->project($projectId);
        $versions = [];
        foreach ($this->eligibleReleases($projectId, $gameVersion, strtoupper($loader), microtime(true) + 8) as $version) {
            $versions[] = $this->normalize($version, $project, $loader);
        }

        return collect($versions)->sortByDesc('published_at')->values()->all();
    }

    public function version(string $versionId): array
    {
        if (!preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18})(:velocity)?$/D', $versionId, $parts)) {
            throw new ProviderResponseException('Select a valid Hangar plugin version.');
        }
        $project = $this->project($parts[1]);
        $version = $this->release($parts[1], $parts[2]);

        return $this->normalize($version, $project, isset($parts[3]) ? 'velocity' : 'paper');
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

    private function normalize(array $version, array $project, string $loader = 'paper'): array
    {
        $platform = strtoupper($loader);
        $file = $version['downloads'][$platform] ?? [];
        $info = $file['fileInfo'] ?? [];
        $filename = $info['name'] ?? null;
        $size = $info['sizeBytes'] ?? null;
        $sha256 = $info['sha256Hash'] ?? null;
        if (($version['projectId'] ?? null) !== $project['id'] || ($version['visibility'] ?? null) !== 'public'
            || !$this->allowedReview($version['reviewState'] ?? null)
            || !empty($file['externalUrl']) || !is_string($filename) || strlen($filename) > 191
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/D', $filename)
            || !is_int($size) || $size < 1 || $size > config('beacon.content.max_file_bytes')
            || !is_string($sha256) || !preg_match('/^[a-fA-F0-9]{64}$/D', $sha256)) {
            throw new ProviderResponseException('The Hangar file is unavailable, external, unsafe, or outside the download policy.');
        }
        $games = $version['platformDependencies'][$platform] ?? null;
        $rawDependencies = $version['pluginDependencies'][$platform] ?? [];
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

        return ['id' => $this->id($project['id']) . ':' . $this->id($version['id']) . ($loader === 'velocity' ? ':velocity' : ''), 'project_id' => $this->id($project['id']),
            'project_name' => $this->string($project, 'name'), 'project_type' => 'plugin', 'icon_url' => $this->image($project['avatarUrl'] ?? null),
            'server_side' => 'required', 'name' => $this->string($version, 'name'), 'version_number' => $this->string($version, 'name'),
            'version_type' => strtolower((string) data_get($version, 'channel.name')) === 'release' ? 'release' : 'beta',
            'published_at' => $this->string($version, 'createdAt'), 'game_versions' => $games, 'loaders' => [$loader], 'dependencies' => $dependencies,
            'review_state' => $version['reviewState'],
            'file' => ['url' => $this->downloadUrl($this->string($file, 'downloadUrl')), 'filename' => $filename,
                'size' => $size, 'sha512' => null, 'sha256' => strtolower($sha256)]];
    }

    private function allowedReview(mixed $state): bool
    {
        return in_array($state, ['reviewed', 'unreviewed'], true);
    }

    private function eligible(mixed $version, string $game, string $platform): bool
    {
        return $this->candidate($version, $game, $platform) && $this->allowedReview($version['reviewState'] ?? null);
    }

    private function candidate(mixed $version, string $game, string $platform): bool
    {
        return is_array($version) && is_array($version['platformDependencies'][$platform] ?? null)
            && in_array($game, $version['platformDependencies'][$platform], true)
            && ($version['visibility'] ?? null) === 'public' && is_array($version['downloads'][$platform]['fileInfo'] ?? null)
            && empty($version['downloads'][$platform]['externalUrl']);
    }

    /** At most one bounded list page; stop early for Browse, inspect every choice for the picker. */
    private function eligibleReleases(mixed $projectId, string $game, string $platform, float $deadline, bool $firstOnly = false): \Generator
    {
        $projectId = $this->id($projectId);
        $candidates = $this->candidatePaths($projectId, $game, $platform, $deadline);
        // Browse normally needs only the newest candidate. The picker needs all choices;
        // fetch in small concurrent batches so checking details cannot multiply its timeout.
        if ($firstOnly && $candidates !== []) {
            $versionId = (string) array_key_first($candidates);
            unset($candidates[$versionId]);
            $detail = $this->release($projectId, $versionId, $deadline);
            if ($this->eligible($detail, $game, $platform)) {
                yield $detail;
            }
        }
        foreach (array_chunk($candidates, 4, true) as $batch) {
            $this->prefetch(array_values($batch), [], $deadline);
            foreach ($batch as $versionId => $path) {
                // Never promote an indexed review flag or fall back to it on a detail failure.
                $detail = $this->release($projectId, (string) $versionId, $deadline);
                if ($this->eligible($detail, $game, $platform)) {
                    yield $detail;
                }
            }
        }
    }

    private function candidatePaths(string $projectId, string $game, string $platform, float $deadline): array
    {
        $response = $this->get('/projects/' . $projectId . '/versions', $this->versionQuery($game, $platform), $deadline);
        $page = $this->items($response);
        if (count($page) > 25) {
            throw new ProviderResponseException('Hangar returned an oversized version page.');
        }
        $candidates = [];
        foreach (collect($page)->filter(fn ($version) => $this->candidate($version, $game, $platform))->sortByDesc('createdAt') as $version) {
            $versionId = $this->id($version['id'] ?? null);
            if ($this->id($version['projectId'] ?? null) !== $projectId) {
                throw new ProviderResponseException('Hangar returned an invalid plugin version.');
            }
            $candidates[$versionId] = '/projects/' . $projectId . '/versions/' . $versionId;
        }

        return $candidates;
    }

    private function release(string $projectId, string $versionId, ?float $deadline = null): array
    {
        $version = $this->get('/projects/' . $projectId . '/versions/' . $versionId, [], $deadline);
        if ($this->id($version['id'] ?? null) !== $versionId || $this->id($version['projectId'] ?? null) !== $projectId) {
            throw new ProviderResponseException('Hangar returned an invalid plugin version.');
        }

        return $version;
    }

    private function versionQuery(string $version, string $platform): array
    {
        return ['platform' => $platform, 'platformVersion' => $version, 'limit' => 25, 'includeHiddenChannels' => 'false'];
    }

    private function get(string $path, array $query = [], ?float $deadline = null): array
    {
        $this->assertDeadline($deadline);

        return Cache::remember($this->cacheKey($path, $query), 300, function () use ($path, $query, $deadline) {
            try {
                $response = $this->request(Http::acceptJson(), $deadline)
                    ->get('https://hangar.papermc.io/api/v1' . $path, $query);
            } catch (ConnectionException) {
                throw new ProviderResponseException('Hangar is temporarily unavailable. Try again later or select another provider.');
            }
            $this->assertDeadline($deadline);

            return $this->responseData($response);
        });
    }

    private function prefetch(array $paths, array $query, float $deadline): void
    {
        $this->assertDeadline($deadline);
        $missing = array_filter(array_unique($paths), fn ($path) => !Cache::has($this->cacheKey($path, $query)));
        foreach (array_chunk($missing, 4) as $batch) {
            $this->assertDeadline($deadline);
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(fn ($path) => $this->request($pool->as($path), $deadline)
                    ->get('https://hangar.papermc.io/api/v1' . $path, $query), $batch));
            } catch (ConnectionException) {
                throw new ProviderResponseException('Hangar is temporarily unavailable. Try again later or select another provider.');
            }
            $this->assertDeadline($deadline);
            foreach ($batch as $path) {
                $response = $responses[$path] ?? null;
                if ($response instanceof ConnectionException) {
                    throw new ProviderResponseException('Hangar is temporarily unavailable. Try again later or select another provider.');
                }
                if (!$response instanceof Response) {
                    throw new ProviderResponseException('Hangar returned an unsuccessful or invalid response.');
                }
                Cache::put($this->cacheKey($path, $query), $this->responseData($response), 300);
            }
        }
    }

    private function request(PendingRequest $request, ?float $deadline): PendingRequest
    {
        $options = ['allow_redirects' => false, 'cookies' => false];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        return $request->acceptJson()->withUserAgent(config('beacon.modrinth.user_agent'))->withOptions($options)
            ->timeout($deadline === null ? 10 : max(0.1, min(10, $deadline - microtime(true))));
    }

    private function responseData(Response $response): array
    {
        if ($response->status() !== 200 || strlen($response->body()) > 4194304 || !is_array($response->json())) {
            throw new ProviderResponseException('Hangar returned an unsuccessful or invalid response.');
        }

        return $response->json();
    }

    private function cacheKey(string $path, array $query): string
    {
        return 'beacon:hangar:' . hash('sha256', $path . '?' . http_build_query($query));
    }

    private function assertDeadline(?float $deadline): void
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            throw new ProviderResponseException('Hangar review filtering timed out. Try the search again; no review checks were skipped.');
        }
    }

    private function assertPlatform(string $loader, string $type): void
    {
        if (!in_array($loader, ['paper', 'velocity'], true) || $type !== 'plugin') {
            throw new ProviderResponseException('Hangar supports Paper and Velocity plugins for this installer.');
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
