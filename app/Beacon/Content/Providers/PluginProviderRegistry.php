<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class PluginProviderRegistry
{
    public function __construct(private ModrinthPluginProvider $modrinth, private CurseForgePluginProvider $curseforge, private HangarPluginProvider $hangar)
    {
    }

    public function get(string $key): ContentProvider
    {
        return match ($key) {
            'curseforge' => $this->curseforge,
            'modrinth' => $this->modrinth,
            'hangar' => $this->hangar,
            default => throw new ProviderResponseException('Select CurseForge, Modrinth, or Hangar as the plugin provider.'),
        };
    }

    public function describe(): array
    {
        return [['id' => 'curseforge', 'name' => 'CurseForge', 'available' => $this->curseforge->isConfigured()],
            ['id' => 'modrinth', 'name' => 'Modrinth', 'available' => true], ['id' => 'hangar', 'name' => 'Hangar', 'available' => true]];
    }

    public function categories(string $key): array
    {
        $this->get($key);
        if ($key === 'curseforge') {
            return $this->curseforge->categories();
        }
        $values = $key === 'hangar' ? HangarPluginProvider::CATEGORIES : config('beacon.plugins.categories', []);

        return array_map(fn (string $value) => ['value' => $value, 'label' => ucwords(str_replace(['-', '_'], ' ', $value))], $values);
    }

    public function downloadUrl(string $provider, string $url): string
    {
        if ($provider === 'curseforge') {
            return $this->curseforge->resolveDownloadUrl($url);
        }
        if ($provider === 'hangar') {
            return $this->hangar->downloadUrl($url);
        }
        $parts = parse_url($url);
        if ($provider !== 'modrinth' || ($parts['scheme'] ?? null) !== 'https'
            || !in_array($parts['host'] ?? '', config('beacon.modrinth.allowed_download_hosts', []), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new ProviderResponseException('The plugin download is outside the approved provider policy.');
        }

        return $url;
    }
}
