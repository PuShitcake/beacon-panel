<?php

namespace Pterodactyl\Beacon\Addons;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Stage and verify before any pack activation. Existing pack/world files are never overwritten. */
class AddonFilesystem
{
    public function __construct(private DaemonFileRepository $files, private VanillaFileReader $reader, private AddonRuntime $runtime, private AddonWorldDocument $documents)
    {
    }

    public function stage(BeaconOperation $operation, Server $server, array $data, string $archive): void
    {
        $files = $this->files->setServer($server);
        $this->ensureDirectory($server, '/', '.beacon');
        $stage = $this->stagePath($operation);
        if (collect($files->getDirectory('/.beacon'))->contains('name', basename($stage))) {
            throw new ConflictHttpException('A previous add-on staging attempt needs manual review. No existing packs were changed.');
        }
        $files->createDirectory(basename($stage), '/.beacon');
        $stream = fopen($archive, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('The prepared add-on archive is unavailable.');
        }
        try {
            // Guzzle streams a resource body instead of loading the full archive into memory.
            $files->getHttpClient()->post('/api/servers/' . $server->uuid . '/files/write', [
                'query' => ['file' => $stage . '/payload.zip'], 'body' => $stream,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $hashes = $files->getFileHashes($stage . '/payload.zip', max(1, filesize($archive)));
        if (!hash_equals($data['archive_hash'], $hashes['sha256'])) {
            throw new ConflictHttpException('The staged add-on archive failed checksum verification.');
        }
        $files->decompressFile($stage, 'payload.zip');
        $files->deleteFiles($stage, ['payload.zip']);
        $this->tree($server, $stage, $data['manifest']['packs'], false);
    }

    /** One small batch per job keeps the queue lease shorter than retry_after. */
    public function verify(BeaconOperation $operation, Server $server, array $packs, bool $remove, int $cursor): array
    {
        $all = [];
        foreach ($packs as $pack) {
            foreach ($pack['files'] as $relative => $expected) {
                $all[] = ['path' => ($remove ? '/' . $pack['directory'] : $this->stagePath($operation)) . '/' . $pack['folder'] . '/' . $relative, 'expected' => $expected];
            }
        }
        foreach (array_slice($all, $cursor, 4) as $item) {
            $parent = dirname($item['path']);
            $entry = collect($this->files->setServer($server)->getDirectory($parent))->firstWhere('name', basename($item['path']));
            if (($entry['file'] ?? null) !== true || ($entry['symlink'] ?? null) !== false || ($entry['size'] ?? null) !== $item['expected']['size']) {
                throw new ConflictHttpException('An add-on file changed or is not a regular file. No pack activation was changed.');
            }
            $hash = $this->files->getFileHashes($item['path'], max(1, $item['expected']['size']));
            if ($hash['size'] !== $item['expected']['size'] || !hash_equals($item['expected']['sha256'], $hash['sha256'])) {
                throw new ConflictHttpException('An add-on file failed checksum verification. No pack activation was changed.');
            }
        }

        return ['cursor' => min(count($all), $cursor + 4), 'count' => count($all)];
    }

    public function tree(Server $server, string $stage, array $packs, bool $remove): string
    {
        $expected = [];
        foreach ($packs as $pack) {
            $base = ($remove ? '/' . $pack['directory'] : $stage) . '/' . $pack['folder'];
            foreach ($pack['files'] as $relative => $file) {
                $expected[$base . '/' . $relative] = $file['size'];
            }
        }
        $observed = [];
        $directories = 0;
        $scan = function (string $directory, int $depth) use (&$scan, &$observed, &$directories, $server, $expected) {
            if ($depth > 12 || ++$directories > 128) {
                throw new ConflictHttpException('The add-on directory tree exceeds its safety limits.');
            }
            $items = $this->files->setServer($server)->getDirectory($directory);
            if (count($items) > AddonArchive::MAX_FILES) {
                throw new ConflictHttpException('The add-on directory contains unexpected files.');
            }
            foreach ($items as $item) {
                $name = $item['name'] ?? '';
                if (!AddonPath::name($name) || ($item['symlink'] ?? null) !== false) {
                    throw new ConflictHttpException('The add-on directory contains an unsafe entry.');
                }
                $path = $directory . '/' . $name;
                if (($item['file'] ?? null) === false) {
                    $scan($path, $depth + 1);
                } elseif (($item['file'] ?? null) === true && array_key_exists($path, $expected) && $item['size'] === $expected[$path]) {
                    $observed[$path] = [$item['size'], $item['modified_at'] ?? null, $item['created_at'] ?? null];
                } else {
                    throw new ConflictHttpException('The add-on directory contains changed or unowned files.');
                }
            }
        };
        foreach ($packs as $pack) {
            $parent = $remove ? '/' . $pack['directory'] : $stage;
            $this->runtime->directory($this->files->setServer($server)->getDirectory($parent), $pack['folder']);
            $scan($parent . '/' . $pack['folder'], 0);
        }
        if (count($observed) !== count($expected)) {
            throw new ConflictHttpException('The add-on directory is incomplete.');
        }
        ksort($observed);

        return hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR));
    }

    public function backupSafe(Server $server): void
    {
        $entries = $this->files->setServer($server)->getDirectory('/');
        $ignore = collect($entries)->firstWhere('name', '.pteroignore');
        if ($ignore !== null) {
            if (($ignore['file'] ?? null) !== true || ($ignore['symlink'] ?? null) !== false || ($ignore['size'] ?? PHP_INT_MAX) > 16384) {
                throw new ConflictHttpException('Review backup exclusions before changing Bedrock add-ons.');
            }
            $body = $this->reader->read($server, '/.pteroignore', 16384);
            foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                if (trim($line) !== '' && !str_starts_with(trim($line), '#')) {
                    throw new ConflictHttpException('Add-on changes require a full backup without .pteroignore exclusions.');
                }
            }
        }
    }

    public function snapshot(BeaconOperation $operation, Server $server, array $packs, bool $remove): array
    {
        $world = $operation->payload['world'];
        $this->runtime->assertWorld($server, $world);
        $this->assertPackCollisions($server, $packs, $remove);
        if ($remove) {
            foreach ($this->runtime->worlds($server) as $other) {
                if ($other === $world) {
                    continue;
                }
                foreach (['behavior_packs', 'resource_packs'] as $directory) {
                    $bindings = $this->documents->parse($this->readWorldFile($server, $other, 'world_' . $directory . '.json'));
                    if (array_intersect(array_column($bindings, 'pack_id'), array_column($packs, 'uuid'))) {
                        throw new ConflictHttpException('Another world uses this add-on. Remove that reference before uninstalling it.');
                    }
                }
            }
        }
        $snapshot = ['files' => [], 'moves' => []];
        foreach (['behavior_packs', 'resource_packs'] as $directory) {
            if (!collect($packs)->contains('directory', $directory)) {
                continue;
            }
            $name = 'world_' . $directory . '.json';
            $before = $this->readWorldFile($server, $world, $name);
            $snapshot['files'][] = ['path' => '/worlds/' . $world . '/' . $name, 'before' => $before,
                'after' => $this->documents->change($before, $packs, $directory, $remove)];
        }
        $stage = $this->stagePath($operation);
        $this->ensureDirectory($server, '/', '.beacon');
        if ($remove) {
            $this->ensureDirectory($server, '/.beacon', basename($stage));
        }
        foreach ($packs as $pack) {
            $this->ensureDirectory($server, '/', $pack['directory']);
            $source = ($remove ? '/' . $pack['directory'] : $stage) . '/' . $pack['folder'];
            $destination = ($remove ? $stage : '/' . $pack['directory']) . '/' . $pack['folder'];
            if (collect($this->files->setServer($server)->getDirectory(dirname($destination)))->contains('name', basename($destination))) {
                throw new ConflictHttpException('An add-on destination already exists. No files were overwritten.');
            }
            $snapshot['moves'][] = ['from' => ltrim($source, '/'), 'to' => ltrim($destination, '/')];
        }

        return $snapshot;
    }

    public function commit(Server $server, array $snapshot): void
    {
        foreach ($snapshot['files'] as $file) {
            $actual = $this->readWorldFile($server, basename(dirname($file['path'])), basename($file['path']));
            if ($actual !== $file['before']) {
                throw new ConflictHttpException('The world pack list changed while this operation was preparing.');
            }
        }
        foreach ($snapshot['moves'] as $move) {
            $this->files->setServer($server)->renameFiles('/', [$move]);
        }
        foreach ($snapshot['files'] as $file) {
            $this->files->setServer($server)->putContent($file['path'], $file['after']);
            if ($this->reader->read($server, $file['path'], 262144) !== $file['after']) {
                throw new ConflictHttpException('The world pack list could not be verified after writing.');
            }
        }
    }

    public function rollback(Server $server, array $snapshot): void
    {
        foreach ($snapshot['files'] as $file) {
            $actual = $this->readWorldFile($server, basename(dirname($file['path'])), basename($file['path']));
            if ($actual === $file['before']) {
                continue;
            }
            if ($actual !== $file['after']) {
                throw new ConflictHttpException('The world pack list changed externally. Restore the safety backup manually.');
            }
            if ($file['before'] === null) {
                $this->files->setServer($server)->deleteFiles(dirname($file['path']), [basename($file['path'])]);
            } else {
                $this->files->setServer($server)->putContent($file['path'], $file['before']);
            }
            if ($this->readWorldFile($server, basename(dirname($file['path'])), basename($file['path'])) !== $file['before']) {
                throw new ConflictHttpException('The previous world pack list could not be verified. Restore its safety backup manually.');
            }
        }
        foreach (array_reverse($snapshot['moves']) as $move) {
            $from = collect($this->files->setServer($server)->getDirectory('/' . dirname($move['from'])))->contains('name', basename($move['from']));
            $to = collect($this->files->setServer($server)->getDirectory('/' . dirname($move['to'])))->contains('name', basename($move['to']));
            if ($from && $to) {
                throw new ConflictHttpException('An add-on folder changed externally. Review its safety backup before restoring it.');
            } elseif (!$from && $to) {
                $this->files->setServer($server)->renameFiles('/', [['from' => $move['to'], 'to' => $move['from']]]);
            } elseif (!$from) {
                throw new ConflictHttpException('An add-on folder needs manual recovery from its safety backup.');
            }
        }
    }

    public function readWorldFile(Server $server, string $world, string $name): ?string
    {
        $this->runtime->assertWorld($server, $world);
        $entry = collect($this->files->setServer($server)->getDirectory('/worlds/' . $world))->firstWhere('name', $name);
        if ($entry === null) {
            return null;
        }
        if (($entry['file'] ?? null) !== true || ($entry['symlink'] ?? null) !== false || ($entry['size'] ?? PHP_INT_MAX) > 262144) {
            throw new ConflictHttpException('World pack lists must be bounded regular files.');
        }

        return $this->reader->read($server, '/worlds/' . $world . '/' . $name, 262144);
    }

    private function assertPackCollisions(Server $server, array $packs, bool $remove): void
    {
        $ids = array_column($packs, 'uuid');
        foreach (['behavior_packs', 'resource_packs'] as $directory) {
            $this->ensureDirectory($server, '/', $directory);
            $entries = $this->files->setServer($server)->getDirectory('/' . $directory);
            if (count($entries) > 256) {
                throw new ConflictHttpException('Too many existing pack folders to inspect safely.');
            }
            foreach ($entries as $entry) {
                if (($entry['file'] ?? null) === true) {
                    continue;
                }
                $name = $entry['name'] ?? '';
                if (($entry['symlink'] ?? null) !== false || !AddonPath::name($name)) {
                    throw new ConflictHttpException('Review unsafe existing Bedrock pack paths first.');
                }
                if ($remove && collect($packs)->contains(fn ($p) => $p['directory'] === $directory && $p['folder'] === $name)) {
                    continue;
                }
                $items = $this->files->setServer($server)->getDirectory('/' . $directory . '/' . $name);
                $manifest = collect($items)->firstWhere('name', 'manifest.json');
                if ($manifest === null) {
                    continue;
                }
                if (($manifest['file'] ?? null) !== true || ($manifest['symlink'] ?? null) !== false || ($manifest['size'] ?? PHP_INT_MAX) > 262144) {
                    throw new ConflictHttpException('An existing pack manifest is unsafe.');
                }
                $data = (new AddonArchive())->json($this->reader->read($server, '/' . $directory . '/' . $name . '/manifest.json', 262144));
                if (in_array(strtolower(data_get($data, 'header.uuid', '')), $ids, true)) {
                    throw new ConflictHttpException('A pack UUID in this add-on is already installed. Unmanaged packs are never overwritten.');
                }
            }
        }
    }

    private function ensureDirectory(Server $server, string $parent, string $name): void
    {
        $entries = $this->files->setServer($server)->getDirectory($parent);
        if (!collect($entries)->contains('name', $name)) {
            $this->files->createDirectory($name, $parent);
            $entries = $this->files->getDirectory($parent);
        }
        $this->runtime->directory($entries, $name);
    }

    public function stagePath(BeaconOperation $operation): string
    {
        return '/.beacon/addon-' . $operation->uuid;
    }
}
