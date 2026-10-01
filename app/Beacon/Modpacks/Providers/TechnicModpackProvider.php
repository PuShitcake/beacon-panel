<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class TechnicModpackProvider extends AbstractModpackProvider
{
    public function key(): string
    {
        return 'technic';
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $data = $this->get('/search', [
            'build' => (string) config('beacon.modpacks.providers.technic.build'),
            'q' => $query,
        ]);
        $packs = $data['modpacks'] ?? null;
        if (!is_array($packs)) {
            throw new ModpackProviderException('Technic returned an invalid search response.');
        }
        $items = collect($packs)->filter(fn ($pack) => is_array($pack))->map(fn (array $pack) => [
            'id' => $this->requiredString($pack, 'slug'),
            'slug' => $this->requiredString($pack, 'slug'),
            'name' => $this->requiredString($pack, 'name'),
            'description' => '',
            'author' => null,
            'icon_url' => $this->safeImageUrl($pack['iconUrl'] ?? null),
            'downloads' => 0,
            'website_url' => $this->safeExternalUrl($pack['url'] ?? null),
        ])->values()->all();

        return $this->page($items, $page, $pageSize);
    }

    public function versions(string $projectId): array
    {
        $pack = $this->pack($projectId);
        $minecraft = $this->nullableString($pack, 'minecraft');
        $loader = $this->loader($pack);

        return [[
            'id' => $this->nullableString($pack, 'version') ?? 'recommended',
            'name' => $this->nullableString($pack, 'displayName') ?? $projectId,
            'version' => $this->nullableString($pack, 'version') ?? 'recommended',
            'minecraft_version' => $minecraft,
            'loader' => $loader,
            'published_at' => null,
            'installable' => $minecraft !== null && is_string($pack['serverPackUrl'] ?? null),
        ]];
    }

    public function release(string $projectId, string $versionId): array
    {
        $pack = $this->pack($projectId);
        if (!is_string($pack['serverPackUrl'] ?? null) || $pack['serverPackUrl'] === '') {
            throw new ModpackProviderException('This Technic pack does not publish a server archive.');
        }
        $minecraft = $this->nullableString($pack, 'minecraft');
        $loader = $this->loader($pack);
        if (!$minecraft) {
            throw new ModpackProviderException('Technic did not identify a Minecraft version for this server pack.');
        }

        return [
            'provider' => $this->key(),
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'name' => $this->nullableString($pack, 'displayName') ?? $projectId,
            'icon_url' => $this->safeImageUrl($pack['icon'] ?? null),
            'version_id' => $versionId,
            'version_name' => $this->nullableString($pack, 'version') ?? $versionId,
            'minecraft_version' => $minecraft,
            'loader' => $loader,
            'loader_version' => $this->nullableString($pack, 'loaderVersion'),
            'java_version' => $this->javaVersion($minecraft),
            'install_mode' => 'archive',
            'archive' => [
                'url' => $this->safeDownloadUrl($this->requiredString($pack, 'serverPackUrl')),
                'filename' => 'technic-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $projectId) . '.zip',
                'size' => 0,
            ],
        ];
    }

    private function pack(string $projectId): array
    {
        return $this->get('/modpack/' . rawurlencode($projectId), [
            'build' => (string) config('beacon.modpacks.providers.technic.build'),
        ]);
    }

    private function loader(array $pack): ?string
    {
        $values = [(string) ($pack['loader'] ?? ''), (string) ($pack['tags'] ?? ''), (string) ($pack['description'] ?? '')];

        return $this->loaderFrom($values);
    }
}
