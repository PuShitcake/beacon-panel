<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class VoidsWrathModpackProvider extends AbstractModpackProvider
{
    public function key(): string
    {
        return 'voidswrath';
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $data = $this->get('/api/modpacks');
        $packs = array_is_list($data) ? $data : ($data['modpacks'] ?? $data['data'] ?? null);
        if (!is_array($packs)) {
            throw new ModpackProviderException('Voids Wrath returned an invalid modpack list.');
        }
        $query = mb_strtolower(trim($query));
        $items = collect($packs)->filter(fn ($pack) => is_array($pack))->filter(fn (array $pack) => $query === '' || str_contains(
            mb_strtolower((string) ($pack['name'] ?? $pack['title'] ?? '') . ' ' . (string) ($pack['description'] ?? '')),
            $query
        ))->map(fn (array $pack) => [
            'id' => $this->requiredString($pack, isset($pack['id']) ? 'id' : 'slug'),
            'slug' => $this->nullableString($pack, 'slug'),
            'name' => $this->nullableString($pack, 'name') ?? $this->requiredString($pack, 'title'),
            'description' => $this->nullableString($pack, 'description') ?? '',
            'author' => $this->nullableString($pack, 'author'),
            'icon_url' => $this->safeImageUrl($pack['icon'] ?? $pack['image'] ?? null),
            'downloads' => (int) ($pack['downloads'] ?? 0),
            'website_url' => $this->safeExternalUrl($pack['url'] ?? null),
        ])->values()->all();

        return $this->page($items, $page, $pageSize);
    }

    public function versions(string $projectId): array
    {
        $pack = $this->find($projectId);
        $versions = $pack['versions'] ?? $pack['modVersions'] ?? [$pack];

        return collect($versions)->filter(fn ($version) => is_array($version))->map(function (array $version) use ($pack) {
            $minecraft = $this->nullableString($version, 'minecraftVersion') ?? $this->nullableString($pack, 'minecraftVersion');
            $loader = $this->loaderFrom([
                (string) ($version['loader'] ?? $pack['loader'] ?? ''),
                (string) ($version['description'] ?? $pack['description'] ?? ''),
            ]);
            $id = $this->nullableString($version, 'id') ?? $this->nullableString($version, 'version') ?? 'latest';

            return [
                'id' => $id,
                'name' => $this->nullableString($version, 'name') ?? $id,
                'version' => $this->nullableString($version, 'version') ?? $id,
                'minecraft_version' => $minecraft,
                'loader' => $loader,
                'published_at' => $this->nullableString($version, 'publishedAt'),
                'installable' => $minecraft !== null
                    && is_string($version['serverPackUrl'] ?? $pack['serverPackUrl'] ?? null),
            ];
        })->values()->all();
    }

    public function release(string $projectId, string $versionId): array
    {
        $pack = $this->find($projectId);
        $versions = $pack['versions'] ?? $pack['modVersions'] ?? [$pack];
        $version = collect($versions)->first(fn ($item) => is_array($item) && in_array($versionId, [
            (string) ($item['id'] ?? ''), (string) ($item['version'] ?? ''),
        ], true));
        if (!is_array($version)) {
            throw new ModpackProviderException('The selected Voids Wrath version was not found.');
        }
        $minecraft = $this->nullableString($version, 'minecraftVersion') ?? $this->nullableString($pack, 'minecraftVersion');
        $loader = $this->loaderFrom([
            (string) ($version['loader'] ?? $pack['loader'] ?? ''),
            (string) ($version['description'] ?? $pack['description'] ?? ''),
        ]);
        if (!$minecraft) {
            throw new ModpackProviderException('Voids Wrath did not identify a Minecraft version for this server pack.');
        }
        $url = $this->nullableString($version, 'serverPackUrl') ?? $this->nullableString($pack, 'serverPackUrl');
        if (!$url) {
            throw new ModpackProviderException('This Voids Wrath version does not provide a server archive.');
        }

        return [
            'provider' => $this->key(),
            'project_id' => $projectId,
            'project_slug' => $this->nullableString($pack, 'slug'),
            'name' => $this->nullableString($pack, 'name') ?? $this->requiredString($pack, 'title'),
            'icon_url' => $this->safeImageUrl($pack['icon'] ?? $pack['image'] ?? null),
            'version_id' => $versionId,
            'version_name' => $this->nullableString($version, 'version') ?? $versionId,
            'minecraft_version' => $minecraft,
            'loader' => $loader,
            'loader_version' => $this->nullableString($version, 'loaderVersion'),
            'java_version' => $this->javaVersion($minecraft),
            'install_mode' => 'archive',
            'archive' => [
                'url' => $this->safeDownloadUrl($url),
                'filename' => 'voidswrath-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $versionId) . '.zip',
                'size' => (int) ($version['serverPackSize'] ?? 0),
            ],
        ];
    }

    private function find(string $projectId): array
    {
        $data = $this->get('/api/modpacks');
        $packs = array_is_list($data) ? $data : ($data['modpacks'] ?? $data['data'] ?? []);
        $pack = collect($packs)->first(fn ($item) => is_array($item) && in_array($projectId, [
            (string) ($item['id'] ?? ''), (string) ($item['slug'] ?? ''),
        ], true));
        if (!is_array($pack)) {
            throw new ModpackProviderException('The requested Voids Wrath modpack was not found.');
        }

        return $pack;
    }
}
