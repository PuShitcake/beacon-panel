<?php

namespace Pterodactyl\Beacon\Addons;

use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Immutable, server-scoped, private prepared archives; never public upload paths. */
class AddonStore
{
    public function __construct(private AddonArchive $archives)
    {
    }

    public function directory(Server $server): string
    {
        $directory = storage_path('app/private/beacon/addons/' . $server->uuid);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Private add-on storage is unavailable.');
        }

        return $directory;
    }

    public function prepare(Server $server, string $sourcePath, array $source): array
    {
        $directory = $this->directory($server);
        $lock = fopen($directory . '/.prepare.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Private add-on storage is unavailable.');
        }
        chmod($directory . '/.prepare.lock', 0600);
        try {
            return $this->prepareLocked($server, $directory, $sourcePath, $source);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepareLocked(Server $server, string $directory, string $sourcePath, array $source): array
    {
        $id = hash_file('sha256', $sourcePath);
        $temporary = $directory . '/' . bin2hex(random_bytes(16)) . '.tmp';
        $temporaryMetadata = $temporary . '.json';
        try {
            if (is_file($directory . '/' . $id . '.json')) {
                return $this->load($server, $id);
            }
            $free = disk_free_space($directory);
            if ($free === false || $free <= filesize($sourcePath)) {
                throw new ConflictHttpException('Private add-on storage has insufficient free disk space. Ask an administrator to review available storage.');
            }
            $manifest = $this->archives->inspect($sourcePath, $temporary);
            $cleanHash = hash_file('sha256', $temporary);
            $archive = $directory . '/' . $id . '.zip';
            $metadata = $directory . '/' . $id . '.json';
            $data = ['archive_id' => $id, 'archive_hash' => $cleanHash, 'source' => $source, 'manifest' => $manifest];
            // A content-addressed preview cannot change source metadata after being prepared.
            if (is_file($metadata)) {
                return $this->load($server, $id);
            }
            if (!rename($temporary, $archive)) {
                throw new \RuntimeException('Private add-on storage is unavailable.');
            }
            chmod($archive, 0600);
            if (file_put_contents($temporaryMetadata, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new \RuntimeException('Private add-on storage is unavailable.');
            }
            chmod($temporaryMetadata, 0600);
            if (!rename($temporaryMetadata, $metadata)) {
                throw new \RuntimeException('Private add-on storage is unavailable.');
            }

            return $data;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            if (is_file($temporaryMetadata)) {
                unlink($temporaryMetadata);
            }
        }
    }

    public function load(Server $server, string $id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new ConflictHttpException('Select a prepared add-on preview for this instance.');
        }
        $directory = $this->directory($server);
        $metadata = $directory . '/' . $id . '.json';
        $archive = $directory . '/' . $id . '.zip';
        if (!is_file($metadata) || is_link($metadata) || !is_file($archive) || is_link($archive) || filesize($metadata) > 1048576 || filesize($archive) < 1) {
            throw new ConflictHttpException('The add-on preview is missing or expired. Prepare it again.');
        }
        $data = json_decode(file_get_contents($metadata), true, 64, JSON_THROW_ON_ERROR);
        if (($data['archive_id'] ?? null) !== $id || !hash_equals($data['archive_hash'], hash_file('sha256', $archive))) {
            throw new ConflictHttpException('The prepared add-on archive changed. Prepare it again.');
        }

        return $data;
    }

    public function path(Server $server, string $id): string
    {
        $this->load($server, $id);

        return $this->directory($server) . '/' . $id . '.zip';
    }

    public function publicPreview(array $data): array
    {
        return ['archive_id' => $data['archive_id'], 'provider' => $data['source']['provider'],
            'file_count' => $data['manifest']['file_count'], 'total_bytes' => $data['manifest']['total_bytes'],
            'packs' => array_map(fn ($pack) => array_intersect_key($pack, array_flip(['uuid', 'name', 'version', 'minimum_engine', 'dependencies', 'scripts', 'directory'])), $data['manifest']['packs'])];
    }
}
