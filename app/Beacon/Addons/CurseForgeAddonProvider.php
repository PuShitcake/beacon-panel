<?php

namespace Pterodactyl\Beacon\Addons;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Beacon\Content\Providers\CurseForgeModProvider;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

/** Official Bedrock game and Addons class; never queries Java mods or an unofficial proxy. */
class CurseForgeAddonProvider
{
    public const GAME = 78022;
    public const CLASS_ID = 4984;

    public function __construct(private CurseForgeModProvider $downloads)
    {
    }

    public function available(): bool
    {
        return $this->downloads->isConfigured();
    }

    public function search(string $query, int $offset): array
    {
        $result = $this->get('/mods/search', ['gameId' => self::GAME, 'classId' => self::CLASS_ID,
            'searchFilter' => trim($query), 'pageSize' => 20, 'index' => min(9980, $offset), 'sortField' => 6, 'sortOrder' => 'desc']);
        $projects = [];
        foreach ($result['data'] ?? [] as $project) {
            if ($this->projectAvailable($project)) {
                $projects[] = ['id' => (string) $project['id'], 'title' => (string) $project['name'],
                    'description' => (string) ($project['summary'] ?? ''), 'downloads' => max(0, (int) ($project['downloadCount'] ?? 0))];
            }
        }

        return ['projects' => $projects, 'offset' => $offset, 'limit' => 20, 'total' => min(10000, (int) data_get($result, 'pagination.totalCount', 0))];
    }

    public function versions(string $project): array
    {
        $this->project($project);
        $result = $this->get('/mods/' . $project . '/files', ['pageSize' => 50]);
        $files = [];
        foreach ($result['data'] ?? [] as $file) {
            try {
                $files[] = $this->file($file, $project);
            } catch (ProviderResponseException) {
                // Browse only: exact-file selection still performs all checks again.
            }
        }
        usort($files, fn ($a, $b) => strcmp($b['published_at'], $a['published_at']));

        return array_map(fn ($file) => array_diff_key($file, array_flip(['url', 'sha1', 'size'])), $files);
    }

    public function selected(string $version): array
    {
        if (!preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D', $version, $ids)) {
            throw new ProviderResponseException('Select a valid CurseForge Bedrock release.');
        }
        $project = $this->project($ids[1]);
        $file = $this->get('/mods/' . $ids[1] . '/files/' . $ids[2])['data'] ?? null;
        if (!is_array($file) || (string) ($file['id'] ?? '') !== $ids[2]) {
            throw new ProviderResponseException('The selected Bedrock release is unavailable.');
        }

        return $this->file($file, $ids[1]) + ['project_name' => $project['name']];
    }

    public function download(array $file, string $destination): void
    {
        // The shared bounded redirect resolver sends no API credentials to the CDN.
        $url = $this->downloads->resolveDownloadUrl($file['url']);
        try {
            $response = Http::withOptions(['stream' => true, 'allow_redirects' => false, 'cookies' => false])
                ->withUserAgent(config('beacon.modrinth.user_agent'))->timeout(30)->get($url);
            $body = $response->toPsrResponse()->getBody();
            $output = fopen($destination, 'xb');
            $size = 0;
            $hash = hash_init('sha1');
            try {
                if ($response->status() !== 200 || !$output) {
                    throw new \RuntimeException();
                }
                while (!$body->eof()) {
                    $chunk = $body->read(65536);
                    $size += strlen($chunk);
                    if ($size > $file['size'] || ($chunk === '' && !$body->eof()) || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new \RuntimeException();
                    }
                    hash_update($hash, $chunk);
                }
                if ($size !== $file['size'] || !hash_equals($file['sha1'], hash_final($hash))) {
                    throw new \RuntimeException();
                }
            } finally {
                $body->close();
                if (is_resource($output)) {
                    fclose($output);
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderResponseException('The Bedrock add-on download could not be verified. Try again later.', 0, $e);
        }
    }

    private function project(string $id): array
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new ProviderResponseException('Select a valid Bedrock project.');
        }
        $project = $this->get('/mods/' . $id)['data'] ?? null;
        if (!$this->projectAvailable($project) || (string) $project['id'] !== $id) {
            throw new ProviderResponseException('This is not an available Bedrock add-on with third-party downloads.');
        }

        return $project;
    }

    private function projectAvailable(mixed $project): bool
    {
        return is_array($project) && ($project['gameId'] ?? null) === self::GAME && ($project['classId'] ?? null) === self::CLASS_ID
            && ($project['isAvailable'] ?? false) === true && ($project['allowModDistribution'] ?? null) !== false;
    }

    private function file(mixed $file, string $project): array
    {
        if (!is_array($file) || !is_array($file['hashes'] ?? null)) {
            throw new ProviderResponseException('This release has no supported archive or required checksum.');
        }
        $hashes = array_values(array_filter($file['hashes'] ?? [], fn ($h) => is_array($h) && ($h['algo'] ?? null) === 1));
        if (!is_int($file['id'] ?? null) || $file['id'] < 1 || (string) ($file['modId'] ?? '') !== $project || ($file['isAvailable'] ?? false) !== true || !in_array($file['fileStatus'] ?? null, [4, 10], true)
            || !AddonPath::name($file['fileName'] ?? null) || !preg_match('/\.(?:mcpack|mcaddon|zip)$/iD', $file['fileName'])
            || !is_int($file['fileLength'] ?? null) || $file['fileLength'] < 1
            || count($hashes) !== 1 || !is_string($hashes[0]['value'] ?? null) || !preg_match('/^[a-f0-9]{40}$/iD', $hashes[0]['value'])
            || !is_string($file['downloadUrl'] ?? null) || !is_array($file['gameVersions'] ?? null) || !is_string($file['fileDate'] ?? null)) {
            throw new ProviderResponseException('This release has no supported archive or required checksum.');
        }

        return ['id' => $project . ':' . $file['id'], 'name' => (string) ($file['displayName'] ?? $file['fileName']),
            'game_versions' => array_values(array_filter($file['gameVersions'], 'is_string')), 'published_at' => $file['fileDate'],
            'url' => $file['downloadUrl'], 'size' => $file['fileLength'], 'sha1' => strtolower($hashes[0]['value'])];
    }

    private function get(string $path, array $query = []): array
    {
        if (!$this->available()) {
            throw new ProviderResponseException('CurseForge is unavailable. Ask an administrator to configure its API key, or upload an add-on.');
        }
        $key = config('beacon.modpacks.providers.curseforge.api_key');

        return Cache::remember('beacon:addons:cf:' . hash('sha256', $key . $path . json_encode($query)), 300, function () use ($key, $path, $query) {
            try {
                $response = Http::baseUrl(config('beacon.modpacks.providers.curseforge.base_url'))->acceptJson()->withHeaders(['x-api-key' => $key])
                    ->withUserAgent(config('beacon.modrinth.user_agent'))->withOptions(['allow_redirects' => false, 'cookies' => false])->timeout(10)->get($path, $query);
                if (!$response->successful() || strlen($response->body()) > 4194304 || !is_array($response->json('data'))) {
                    throw new \RuntimeException();
                }

                return $response->json();
            } catch (\Throwable $e) {
                throw new ProviderResponseException('CurseForge Bedrock is temporarily unavailable. Try again later or upload an add-on.', 0, $e);
            }
        });
    }
}
