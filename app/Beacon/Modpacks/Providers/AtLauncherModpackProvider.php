<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class AtLauncherModpackProvider extends AbstractModpackProvider
{
    public function key(): string
    {
        return 'atlauncher';
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $data = $this->get('/packs/full/public');
        $packs = array_is_list($data) ? $data : ($data['data'] ?? $data['packs'] ?? null);
        if (!is_array($packs)) {
            throw new ModpackProviderException('ATLauncher returned an invalid pack list.');
        }
        $query = mb_strtolower(trim($query));
        $items = collect($packs)
            ->filter(fn ($pack) => is_array($pack))
            ->filter(fn (array $pack) => $query === '' || str_contains(mb_strtolower(
                (string) ($pack['name'] ?? '') . ' ' . (string) ($pack['description'] ?? '')
            ), $query))
            ->map(fn (array $pack) => [
                'id' => $this->requiredString($pack, 'safeName'),
                'slug' => $this->requiredString($pack, 'safeName'),
                'name' => $this->requiredString($pack, 'name'),
                'description' => $this->nullableString($pack, 'description') ?? '',
                'author' => null,
                'icon_url' => null,
                'downloads' => 0,
                'website_url' => $this->safeExternalUrl($pack['websiteURL'] ?? null),
            ])->values()->all();

        return $this->page($items, $page, $pageSize);
    }

    public function versions(string $projectId): array
    {
        $response = $this->get('/pack/' . rawurlencode($projectId));
        $pack = $response['data'] ?? null;
        if (!is_array($pack)) {
            throw new ModpackProviderException('ATLauncher returned invalid pack details.');
        }
        $versions = $pack['versions'] ?? null;
        if (!is_array($versions)) {
            throw new ModpackProviderException('ATLauncher returned an invalid version list.');
        }

        return collect($versions)->filter(fn ($version) => is_array($version))->map(fn (array $version) => [
            'id' => $this->requiredString($version, 'version'),
            'name' => $this->requiredString($version, 'version'),
            'version' => $this->requiredString($version, 'version'),
            'minecraft_version' => $this->nullableString($version, 'minecraft'),
            'loader' => null,
            'published_at' => isset($version['published']) ? date(DATE_ATOM, (int) $version['published']) : null,
            'installable' => true,
        ])->values()->all();
    }

    public function release(string $projectId, string $versionId): array
    {
        $response = $this->get('/pack/' . rawurlencode($projectId) . '/' . rawurlencode($versionId));
        $version = $response['data'] ?? null;
        if (!is_array($version)) {
            throw new ModpackProviderException('ATLauncher returned invalid version details.');
        }
        $minecraft = $this->requiredString($version, 'minecraftVersion');
        $url = $this->nullableString($version, 'serverZipURL');
        if (!$url) {
            throw new ModpackProviderException('This ATLauncher version does not provide a server archive.');
        }
        $packResponse = $this->get('/pack/' . rawurlencode($projectId));
        $pack = $packResponse['data'] ?? null;
        if (!is_array($pack)) {
            throw new ModpackProviderException('ATLauncher returned invalid pack details.');
        }
        $loader = $this->loaderFrom(array_merge(
            array_values(array_filter($version['loaders'] ?? [], 'is_string')),
            [(string) ($version['loader'] ?? ''), (string) ($version['description'] ?? '')]
        ));

        return [
            'provider' => $this->key(),
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'name' => $this->requiredString($pack, 'name'),
            'icon_url' => null,
            'version_id' => $versionId,
            'version_name' => $this->requiredString($version, 'version'),
            'minecraft_version' => $minecraft,
            'loader' => $loader,
            'loader_version' => $this->nullableString($version, 'loaderVersion'),
            'java_version' => $this->javaVersion($minecraft),
            'install_mode' => 'archive',
            'archive' => [
                'url' => $this->safeDownloadUrl($url),
                'filename' => 'atlauncher-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $versionId) . '.zip',
                'size' => 0,
            ],
        ];
    }
}
