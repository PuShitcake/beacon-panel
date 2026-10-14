<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class CurseForgeInstalledRuntimeService
{
    private const MANIFEST = 'client.manifest.json';
    private const MAX_MANIFEST_BYTES = 1048576;

    public function __construct(private ModpackMinecraftVersionPolicy $versions)
    {
    }

    public function prepare(DaemonFileRepository $files): void
    {
        $entry = $this->manifestEntry($files);
        if ($entry !== null) {
            $this->assertRegularManifest($entry);
            // The native Egg must produce a fresh manifest; never adopt a prior pack's metadata.
            $files->deleteFiles('/', [self::MANIFEST]);
        }
    }

    public function resolve(Server $server, DaemonFileRepository $files, DaemonServerRepository $servers, array $release): array
    {
        if (($servers->setServer($server)->getDetails()['state'] ?? null) !== 'offline') {
            throw new ModpackProviderException('Stop the server before verifying the installed modpack runtime.');
        }
        $files->setServer($server);
        $entry = $this->manifestEntry($files);
        if ($entry === null) {
            throw new ModpackProviderException('The CurseForge Egg did not produce a fresh client manifest.');
        }
        $this->assertRegularManifest($entry);
        $content = $files->getContent('/' . self::MANIFEST, self::MAX_MANIFEST_BYTES);
        if (strlen($content) > self::MAX_MANIFEST_BYTES) {
            throw new ModpackProviderException('The CurseForge client manifest exceeds the file size limit.');
        }

        return array_replace($release, $this->parse($content));
    }

    public function parse(string $content): array
    {
        if (strlen($content) > self::MAX_MANIFEST_BYTES) {
            throw new ModpackProviderException('The CurseForge client manifest exceeds the file size limit.');
        }
        try {
            $manifest = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ModpackProviderException('The CurseForge client manifest contains invalid JSON.');
        }
        $minecraft = is_array($manifest) ? ($manifest['minecraft'] ?? null) : null;
        $version = is_array($minecraft) ? ($minecraft['version'] ?? null) : null;
        $loaders = is_array($minecraft) ? ($minecraft['modLoaders'] ?? null) : null;
        if (!is_string($version) || !$this->versions->supports($version) || !is_array($loaders) || count($loaders) > 16) {
            throw new ModpackProviderException('The CurseForge client manifest has no supported Minecraft runtime.');
        }
        $primary = array_values(array_filter($loaders, fn ($loader) => is_array($loader) && ($loader['primary'] ?? false) === true));
        if (count($primary) !== 1 || !is_string($primary[0]['id'] ?? null)
            || !preg_match('/^(forge|neoforge|fabric|quilt)-([0-9][A-Za-z0-9.+_-]{0,63})$/D', $primary[0]['id'], $loader)) {
            throw new ModpackProviderException('The CurseForge client manifest has no unambiguous supported primary loader.');
        }

        return [
            'minecraft_version' => $version,
            'loader' => $loader[1],
            'loader_version' => $loader[2],
            'java_version' => $this->versions->javaVersion($version),
            'runtime_source' => 'curseforge_client_manifest',
        ];
    }

    private function manifestEntry(DaemonFileRepository $files): ?array
    {
        foreach ($files->getDirectory('/') as $entry) {
            if (($entry['name'] ?? null) === self::MANIFEST) {
                return $entry;
            }
        }

        return null;
    }

    private function assertRegularManifest(array $entry): void
    {
        if (($entry['file'] ?? false) !== true || ($entry['symlink'] ?? false) !== false
            || !is_numeric($entry['size'] ?? null) || $entry['size'] < 0 || $entry['size'] > self::MAX_MANIFEST_BYTES) {
            throw new ModpackProviderException('The CurseForge client manifest must be a bounded regular file.');
        }
    }
}
