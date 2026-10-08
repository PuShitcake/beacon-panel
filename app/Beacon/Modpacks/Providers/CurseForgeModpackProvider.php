<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class CurseForgeModpackProvider extends AbstractModpackProvider
{
    private const MINECRAFT_GAME_ID = 432;
    private const MODPACK_CLASS_ID = 4471;

    public function key(): string
    {
        return 'curseforge';
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured() && is_string(config('beacon.modpacks.providers.curseforge.api_key'))
            && config('beacon.modpacks.providers.curseforge.api_key') !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->isConfigured() ? null : 'CurseForge requires BEACON_CURSEFORGE_API_KEY.';
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        return parent::request()->withHeaders([
            'x-api-key' => (string) config('beacon.modpacks.providers.curseforge.api_key'),
        ]);
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $data = $this->get('/mods/search', [
            'gameId' => self::MINECRAFT_GAME_ID,
            'classId' => self::MODPACK_CLASS_ID,
            'searchFilter' => $query,
            'index' => ($page - 1) * $pageSize,
            'pageSize' => $pageSize,
            'sortField' => 2,
            'sortOrder' => 'desc',
        ]);
        if (!is_array($data['data'] ?? null)) {
            throw new ModpackProviderException('CurseForge returned an invalid modpack search response.');
        }

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int) data_get($data, 'pagination.totalCount', 0),
            'projects' => collect($data['data'])->map(fn (array $pack) => [
                'id' => $this->requiredString($pack, 'id'),
                'slug' => $this->nullableString($pack, 'slug'),
                'name' => $this->requiredString($pack, 'name'),
                'description' => $this->nullableString($pack, 'summary') ?? '',
                'author' => $this->nullableString($pack, 'authors.0.name'),
                'icon_url' => $this->safeImageUrl(data_get($pack, 'logo.thumbnailUrl')),
                'downloads' => (int) ($pack['downloadCount'] ?? 0),
                'website_url' => $this->safeExternalUrl($pack['links']['websiteUrl'] ?? null),
            ])->values()->all(),
        ];
    }

    public function versions(string $projectId): array
    {
        $data = $this->get('/mods/' . rawurlencode($projectId) . '/files', ['pageSize' => 50]);
        if (!is_array($data['data'] ?? null)) {
            throw new ModpackProviderException('CurseForge returned an invalid modpack version response.');
        }

        return collect($data['data'])->map(function (array $file) {
            $targets = array_values(array_filter($file['gameVersions'] ?? [], 'is_string'));
            $minecraft = $this->minecraftVersionFrom($targets);
            $loader = $this->loaderFrom($targets);

            return [
                'id' => $this->requiredString($file, 'id'),
                'name' => $this->requiredString($file, 'displayName'),
                'version' => $this->requiredString($file, 'fileName'),
                'minecraft_version' => $minecraft,
                'loader' => $loader,
                'published_at' => $this->nullableString($file, 'fileDate'),
                'installable' => $minecraft !== null && $loader !== null
                    && (($file['serverPackFileId'] ?? null) !== null || is_string($file['downloadUrl'] ?? null)),
            ];
        })->values()->all();
    }

    public function release(string $projectId, string $versionId): array
    {
        $selection = $this->selection($projectId, $versionId);
        $selected = $this->file($projectId, $versionId);
        $file = isset($selected['serverPackFileId']) && $selected['serverPackFileId']
            ? $this->file($projectId, (string) $selected['serverPackFileId'])
            : $selected;
        $url = $this->nullableString($file, 'downloadUrl');
        if (!$url) {
            throw new ModpackProviderException('The author has disabled third-party downloads for this CurseForge file.');
        }
        $hashes = collect($file['hashes'] ?? []);
        $sha1Hash = $hashes->firstWhere('algo', 1);
        $sha1 = is_array($sha1Hash) ? ($sha1Hash['value'] ?? null) : null;

        return array_merge($selection, [
            'install_mode' => 'archive',
            'archive' => [
                'url' => $this->safeDownloadUrl($url),
                'filename' => $this->requiredString($file, 'fileName'),
                'size' => (int) ($file['fileLength'] ?? 0),
                'sha1' => is_string($sha1) ? strtolower($sha1) : null,
            ],
        ]);
    }

    /**
     * Resolve the trusted project and file metadata needed by the
     * CurseForge Generic Egg without asking Panel to download the archive.
     */
    public function selection(string $projectId, string $versionId): array
    {
        $selected = $this->file($projectId, $versionId);
        $targets = array_values(array_filter($selected['gameVersions'] ?? [], 'is_string'));
        $minecraft = $this->minecraftVersionFrom($targets);
        $loader = $this->loaderFrom($targets);
        if (!$minecraft || !$loader) {
            throw new ModpackProviderException('The selected CurseForge file has no supported Minecraft loader target.');
        }
        $projectResponse = $this->get('/mods/' . rawurlencode($projectId));
        $project = $projectResponse['data'] ?? null;
        if (!is_array($project) || (int) ($project['classId'] ?? 0) !== self::MODPACK_CLASS_ID) {
            throw new ModpackProviderException('The selected CurseForge project is not a modpack.');
        }

        return [
            'provider' => $this->key(),
            'project_id' => $projectId,
            'project_slug' => $this->nullableString($project, 'slug'),
            'name' => $this->requiredString($project, 'name'),
            'icon_url' => $this->safeImageUrl(data_get($project, 'logo.thumbnailUrl')),
            'version_id' => $versionId,
            'version_name' => $this->requiredString($selected, 'displayName'),
            'minecraft_version' => $minecraft,
            'loader' => $loader,
            'loader_version' => null,
            'java_version' => $this->javaVersion($minecraft),
        ];
    }

    private function file(string $projectId, string $fileId): array
    {
        $response = $this->get('/mods/' . rawurlencode($projectId) . '/files/' . rawurlencode($fileId));
        if (!is_array($response['data'] ?? null)) {
            throw new ModpackProviderException('CurseForge returned an invalid file response.');
        }

        return $response['data'];
    }
}
