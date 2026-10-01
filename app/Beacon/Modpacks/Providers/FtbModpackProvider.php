<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class FtbModpackProvider extends AbstractModpackProvider
{
    public function key(): string
    {
        return 'ftb';
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $limit = min(100, max($pageSize * $page, 25));
        $data = $this->get('/public/modpack/search/' . $limit . '/detailed', ['term' => $query]);
        $packs = $data['packs'] ?? null;
        if (!is_array($packs)) {
            throw new ModpackProviderException('Feed The Beast returned an invalid search response.');
        }
        $items = collect($packs)->filter(fn ($pack) => is_array($pack))->map(fn (array $pack) => $this->packData($pack))->values()->all();

        return $this->page($items, $page, $pageSize);
    }

    public function versions(string $projectId): array
    {
        $pack = $this->get('/public/modpack/' . rawurlencode($projectId));
        $versions = $pack['versions'] ?? null;
        if (!is_array($versions)) {
            throw new ModpackProviderException('Feed The Beast returned an invalid version list.');
        }

        return collect($versions)->filter(fn ($version) => is_array($version))->map(function (array $version) {
            [$minecraft, $loader, $loaderVersion] = $this->targets($version);

            return [
                'id' => $this->requiredString($version, 'id'),
                'name' => $this->requiredString($version, 'name'),
                'version' => $this->requiredString($version, 'name'),
                'minecraft_version' => $minecraft,
                'loader' => $loader,
                'published_at' => isset($version['updated']) ? date(DATE_ATOM, (int) $version['updated']) : null,
                'installable' => $minecraft !== null && $loader !== null,
                'loader_version' => $loaderVersion,
            ];
        })->values()->all();
    }

    public function release(string $projectId, string $versionId): array
    {
        $version = $this->get('/public/modpack/' . rawurlencode($projectId) . '/' . rawurlencode($versionId));
        $pack = $this->get('/public/modpack/' . rawurlencode($projectId));
        [$minecraft, $loader, $loaderVersion, $javaVersion] = $this->targets($version);
        if (!$minecraft || !$loader) {
            throw new ModpackProviderException('Feed The Beast did not identify a supported loader target.');
        }
        $url = rtrim((string) config('beacon.modpacks.providers.ftb.base_url'), '/')
            . '/public/modpack/' . rawurlencode($projectId) . '/' . rawurlencode($versionId) . '/server/linux';

        return [
            'provider' => $this->key(),
            'project_id' => $projectId,
            'project_slug' => $this->nullableString($pack, 'slug'),
            'name' => $this->requiredString($pack, 'name'),
            'icon_url' => $this->safeImageUrl(data_get($pack, 'art.0.url')),
            'version_id' => $versionId,
            'version_name' => $this->requiredString($version, 'name'),
            'minecraft_version' => $minecraft,
            'loader' => $loader,
            'loader_version' => $loaderVersion,
            'java_version' => $javaVersion ?? $this->javaVersion($minecraft),
            'install_mode' => 'ftb',
            'archive' => [
                'url' => $this->safeDownloadUrl($url),
                'filename' => "serverinstaller_{$projectId}_{$versionId}",
                'size' => 0,
            ],
        ];
    }

    private function packData(array $pack): array
    {
        return [
            'id' => $this->requiredString($pack, 'id'),
            'slug' => null,
            'name' => $this->requiredString($pack, 'name'),
            'description' => $this->nullableString($pack, 'synopsis') ?? '',
            'author' => $this->nullableString($pack, 'authors.0.name'),
            'icon_url' => $this->safeImageUrl(data_get($pack, 'art.0.url')),
            'downloads' => (int) ($pack['installs'] ?? $pack['plays'] ?? 0),
            'website_url' => $this->safeExternalUrl(data_get($pack, 'links.0.link')),
        ];
    }

    private function targets(array $version): array
    {
        $minecraft = $this->nullableString($version, 'targets.minecraft')
            ?? $this->nullableString($version, 'minecraft')
            ?? $this->minecraftVersionFrom(array_values(array_filter($version['targets'] ?? [], 'is_string')));
        $loader = $this->nullableString($version, 'targets.modloader.name');
        $loaderVersion = $this->nullableString($version, 'targets.modloader.version');
        $javaVersion = null;
        if (!$loader && is_array($version['targets'] ?? null)) {
            foreach ($version['targets'] as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $type = strtolower((string) ($target['type'] ?? ''));
                $name = strtolower((string) ($target['name'] ?? ''));
                if ($name === 'minecraft' || $type === 'game') {
                    $minecraft = $this->nullableString($target, 'version') ?? $minecraft;
                } elseif ($type === 'modloader' || $this->loaderFrom([$name, $type])) {
                    $loader = $this->loaderFrom([$name, $type]);
                    $loaderVersion = $this->nullableString($target, 'version');
                } elseif ($name === 'java' || $type === 'runtime') {
                    $runtime = $this->nullableString($target, 'version');
                    if ($runtime && preg_match('/^(\d+)/', $runtime, $matches)) {
                        $javaVersion = (int) $matches[1];
                    }
                }
            }
        }
        $loader = $loader ? $this->loaderFrom([$loader]) : null;

        return [$minecraft, $loader, $loaderVersion, $javaVersion];
    }
}
