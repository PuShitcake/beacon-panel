<?php

namespace Pterodactyl\Beacon\Versions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;

class McJarsVersionProvider
{
    private const MAX_RESPONSE_BYTES = 4194304;

    /**
     * @return array<int, array{version: string, java: int, latest_build: string, latest_build_uuid: string}>
     */
    public function versions(string $software): array
    {
        $this->assertSoftware($software);

        return Cache::remember($this->cacheKey("versions:{$software}"), $this->cacheTtl(), function () use ($software) {
            $data = $this->get("/v2/builds/{$software}");
            $builds = $data['builds'] ?? null;
            if (!is_array($builds)) {
                throw new \RuntimeException('MCJars returned an invalid version catalog.');
            }

            $versions = [];
            foreach ($builds as $version => $entry) {
                if (!is_string($version) || !is_array($entry)
                    || ($entry['type'] ?? null) !== 'RELEASE'
                    || ($entry['supported'] ?? false) !== true
                    || !is_array($entry['latest'] ?? null)
                    || ($entry['latest']['experimental'] ?? false) === true) {
                    continue;
                }
                $latest = $entry['latest'];
                $java = (int) ($entry['java'] ?? 0);
                $uuid = $latest['uuid'] ?? null;
                $image = config("beacon.modpacks.java_images.{$java}");
                if (!is_string($uuid) || !preg_match('/^[0-9a-f-]{36}$/i', $uuid)
                    || !is_string($image) || $image === '') {
                    continue;
                }
                $versions[] = [
                    'version' => $version,
                    'java' => $java,
                    'latest_build' => (string) ($latest['name'] ?? $latest['buildNumber'] ?? 'latest'),
                    'latest_build_uuid' => $uuid,
                ];
            }

            usort($versions, fn (array $left, array $right) => version_compare($right['version'], $left['version']));

            return $versions;
        });
    }

    /**
     * @return array<int, array{uuid: string, name: string, build_number: int, project_version: string|null, created_at: string|null}>
     */
    public function builds(string $software, string $version): array
    {
        $this->assertSoftware($software);
        $this->assertVersion($version);

        return Cache::remember($this->cacheKey("builds:{$software}:{$version}"), $this->cacheTtl(), function () use ($software, $version) {
            $data = $this->get("/v2/builds/{$software}/{$version}");
            if (!is_array($data['builds'] ?? null)) {
                throw new \RuntimeException('MCJars returned an invalid build catalog.');
            }

            return collect($data['builds'])
                ->filter(fn ($build) => is_array($build)
                    && is_string($build['uuid'] ?? null)
                    && ($build['experimental'] ?? false) === false)
                ->map(fn (array $build) => [
                    'uuid' => $build['uuid'],
                    'name' => (string) ($build['name'] ?? '#' . ($build['buildNumber'] ?? 'latest')),
                    'build_number' => (int) ($build['buildNumber'] ?? 0),
                    'project_version' => is_string($build['projectVersionId'] ?? null) ? $build['projectVersionId'] : null,
                    'created_at' => is_string($build['created'] ?? null) ? $build['created'] : null,
                ])
                ->sortByDesc('build_number')
                ->values()
                ->all();
        });
    }

    /**
     * Resolve a client selection against the provider again so clients cannot submit arbitrary download data.
     *
     * @return array{software: string, minecraft_version: string, build_uuid: string, build_name: string, build_number: int, loader_version: string|null, java_version: int}
     */
    public function target(string $software, string $version, string $buildUuid): array
    {
        $versionEntry = collect($this->versions($software))->firstWhere('version', $version);
        if (!is_array($versionEntry)) {
            throw new \InvalidArgumentException('The selected Minecraft version is not supported by MCJars.');
        }
        $build = collect($this->builds($software, $version))->firstWhere('uuid', $buildUuid);
        if (!is_array($build)) {
            throw new \InvalidArgumentException('The selected build does not belong to this Minecraft version.');
        }

        return [
            'software' => $software,
            'minecraft_version' => $version,
            'build_uuid' => $buildUuid,
            'build_name' => $build['name'],
            'build_number' => $build['build_number'],
            'loader_version' => $build['project_version'],
            'java_version' => $versionEntry['java'],
        ];
    }

    private function get(string $path): array
    {
        $options = ['allow_redirects' => false];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        try {
            $response = Http::baseUrl((string) config('beacon.versions.base_url'))
                ->acceptJson()
                ->withUserAgent('BeaconPanel/VersionManager')
                ->withOptions($options)
                ->timeout(max(5, (int) config('beacon.versions.timeout', 15)))
                ->retry(2, 250, throw: false)
                ->get($path)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            throw new \RuntimeException('The Minecraft version catalog is temporarily unavailable.');
        }
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('MCJars returned a response larger than the allowed limit.');
        }

        $data = $response->json();
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            throw new \RuntimeException('MCJars returned an invalid response.');
        }

        return $data;
    }

    private function assertSoftware(string $software): void
    {
        if (!in_array($software, config('beacon.versions.software', []), true)) {
            throw new \InvalidArgumentException('The selected server software is not supported.');
        }
    }

    private function assertVersion(string $version): void
    {
        if (!preg_match('/^(?:1\.)?[0-9]{1,2}(?:\.[0-9]{1,2}){0,2}$/', $version)) {
            throw new \InvalidArgumentException('The selected Minecraft version is invalid.');
        }
    }

    private function cacheKey(string $suffix): string
    {
        return 'beacon:versions:mcjars:' . $suffix;
    }

    private function cacheTtl(): \DateTimeInterface
    {
        return now()->addSeconds(max(30, (int) config('beacon.versions.cache_ttl', 300)));
    }
}
