<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\PendingRequest;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

abstract class AbstractModpackProvider implements ModpackProvider
{
    protected const MAX_RESPONSE_BYTES = 4194304;

    public function name(): string
    {
        return (string) config("beacon.modpacks.providers.{$this->key()}.name", $this->key());
    }

    public function isConfigured(): bool
    {
        return (bool) config("beacon.modpacks.providers.{$this->key()}.enabled", false);
    }

    public function unavailableReason(): ?string
    {
        return $this->isConfigured() ? null : 'This provider is disabled by the Panel administrator.';
    }

    protected function request(): PendingRequest
    {
        $options = ['allow_redirects' => false];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        return Http::baseUrl((string) config("beacon.modpacks.providers.{$this->key()}.base_url"))
            ->acceptJson()
            ->withUserAgent((string) config('beacon.modrinth.user_agent'))
            ->withOptions($options)
            ->timeout((int) config('beacon.modrinth.timeout'))
            ->retry(2, 250, throw: false);
    }

    protected function get(string $path, array $query = []): array
    {
        $key = 'beacon:modpacks:' . hash('sha256', $this->key() . '|' . $path . '|' . json_encode($query, JSON_THROW_ON_ERROR));

        return Cache::remember($key, now()->addSeconds((int) config('beacon.modrinth.cache_ttl', 300)), function () use ($path, $query) {
            $response = $this->request()->get($path, $query);
            if (!$response->successful()) {
                throw new ModpackProviderException("{$this->name()} request failed with HTTP {$response->status()}.");
            }
            if (strlen($response->body()) > static::MAX_RESPONSE_BYTES) {
                throw new ModpackProviderException("{$this->name()} returned a response larger than the allowed limit.");
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new ModpackProviderException("{$this->name()} returned invalid JSON.");
            }

            return $data;
        });
    }

    protected function requiredString(array $data, string $key): string
    {
        $value = data_get($data, $key);
        if (!is_string($value) && !is_int($value)) {
            throw new ModpackProviderException("{$this->name()} response is missing {$key}.");
        }
        $value = trim((string) $value);
        if ($value === '') {
            throw new ModpackProviderException("{$this->name()} response is missing {$key}.");
        }

        return $value;
    }

    protected function nullableString(array $data, string $key): ?string
    {
        $value = data_get($data, $key);

        return (is_string($value) || is_int($value)) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    protected function safeImageUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = config("beacon.modpacks.providers.{$this->key()}.image_hosts", []);

        return ($parts['scheme'] ?? null) === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443)
            && is_array($allowed)
            && in_array($host, $allowed, true)
            ? $url
            : null;
    }

    protected function safeExternalUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443)
            ? $url
            : null;
    }

    protected function safeDownloadUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = config("beacon.modpacks.providers.{$this->key()}.download_hosts", []);
        if (($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || !is_array($allowed)
            || !in_array($host, $allowed, true)) {
            throw new ModpackProviderException("{$this->name()} returned a download URL outside the approved CDN.");
        }

        return $url;
    }

    protected function loaderFrom(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $normalized = strtolower($value);
            foreach (['neoforge', 'fabric', 'quilt', 'forge', 'paper', 'vanilla'] as $loader) {
                if (str_contains($normalized, $loader)) {
                    return $loader;
                }
            }
        }

        return null;
    }

    protected function minecraftVersionFrom(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^1\.\d+(?:\.\d+)?$/', $value)) {
                return $value;
            }
        }

        return null;
    }

    protected function javaVersion(string $minecraftVersion): int
    {
        $parts = array_map('intval', explode('.', $minecraftVersion));
        $minor = $parts[1] ?? 0;
        $patch = $parts[2] ?? 0;
        if ($minor > 20 || ($minor === 20 && $patch >= 5)) {
            return 21;
        }
        if ($minor >= 18) {
            return 17;
        }
        if ($minor === 17) {
            return 16;
        }

        return 8;
    }

    protected function page(array $items, int $page, int $pageSize): array
    {
        $offset = max(0, ($page - 1) * $pageSize);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => count($items),
            'projects' => array_values(array_slice($items, $offset, $pageSize)),
        ];
    }
}
