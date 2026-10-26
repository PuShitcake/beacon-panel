<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BedrockWorldSafety extends VanillaWorldSafety
{
    public function __construct(private DaemonFileRepository $files, private VanillaFileReader $reader)
    {
        parent::__construct($files, $reader);
    }

    public function assertSafe(Server $server, array $changes, array $current, ?string $backupUuid): void
    {
        if (!array_intersect_key($changes, array_flip(['level-name', 'level-seed']))) {
            return;
        }
        $folder = $changes['level-name'] ?? $current['properties']['level-name'] ?? null;
        if (!is_string($folder) || !BedrockPropertyPolicy::safeWorldName($folder)) {
            throw new ConflictHttpException('Choose a safe Bedrock folder name inside worlds/ before changing world settings.');
        }
        $root = $this->files->setServer($server)->getDirectory('/');
        $this->assertNoExclusions($server, $root);
        $worlds = $this->named($root, 'worlds');
        if ($worlds !== null) {
            $this->assertDirectory($worlds);
            $entry = $this->named($this->files->setServer($server)->getDirectory('/worlds'), $folder);
            if ($entry !== null) {
                if (array_key_exists('level-seed', $changes)) {
                    throw new ConflictHttpException('Choose a new unused world folder inside worlds/ to apply a new seed. Existing maps are never regenerated.');
                }
                $this->assertDirectory($entry);
                $map = $this->files->setServer($server)->getDirectory('/worlds/' . $folder);
                $level = $this->named($map, 'level.dat');
                $db = $this->named($map, 'db');
                if (!is_array($level) || ($level['file'] ?? null) !== true || ($level['symlink'] ?? null) !== false
                    || !is_int($level['size'] ?? null) || $level['size'] < 9 || $level['size'] > 16777216 || $db === null) {
                    throw new ConflictHttpException('Select an extracted Bedrock map with regular level.dat and db entries. Java maps are not supported.');
                }
                $this->assertDirectory($db);
                $body = $this->reader->read($server, '/worlds/' . $folder . '/level.dat', 16777216);
                $header = strlen($body) >= 9 ? unpack('Vformat/Vlength', substr($body, 0, 8)) : [];
                if (strlen($body) !== $level['size'] || ($header['format'] ?? 0) < 1 || ($header['format'] ?? 0) > 10
                    || ($header['length'] ?? 0) !== strlen($body) - 8 || ord($body[8]) !== 10) {
                    throw new ConflictHttpException('The selected level.dat is not a supported Bedrock world header. No map conversion is performed.');
                }
                $database = $this->files->setServer($server)->getDirectory('/worlds/' . $folder . '/db');
                if (!$database) {
                    throw new ConflictHttpException('The selected Bedrock world database is empty. Extract the complete map before selecting it.');
                }
                foreach ($database as $file) {
                    if (($file['symlink'] ?? null) !== false || ($file['file'] ?? null) !== true) {
                        throw new ConflictHttpException('Bedrock world database entries must be regular files without symlinks.');
                    }
                }
            }
        }
        $this->assertBackup($server, $current, $backupUuid);
    }

    private function named(array $entries, string $name): ?array
    {
        return collect($entries)->first(fn ($entry) => is_array($entry) && ($entry['name'] ?? null) === $name);
    }

    private function assertDirectory(array $entry): void
    {
        if (($entry['file'] ?? null) !== false || ($entry['symlink'] ?? null) !== false) {
            throw new ConflictHttpException('Bedrock world paths must be regular directories without symlinks.');
        }
    }
}
