<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** World selection never renames, removes, extracts or regenerates map files. */
class VanillaWorldSafety
{
    public function __construct(private DaemonFileRepository $files, private VanillaFileReader $reader)
    {
    }

    public static function backupName(string $hash, string $runtimeHash): string
    {
        return 'Beacon world settings ' . hash('sha256', $hash . ':' . $runtimeHash);
    }

    public function assertSafe(Server $server, array $changes, array $current, ?string $backupUuid): void
    {
        $worldChange = array_intersect_key($changes, array_flip(['level-name', 'level-seed', 'level-type']));
        if (!$worldChange) {
            return;
        }
        $entries = $this->files->setServer($server)->getDirectory('/');
        $this->assertNoExclusions($server, $entries);
        $folder = $changes['level-name'] ?? $current['properties']['level-name'] ?? null;
        if ($folder !== null) {
            // Validate even an unchanged folder before trusting it as the selected map.
            (new VanillaPropertyPolicy())->validate(['level-name' => $folder], (new VanillaPropertyPolicy())->definitions($current['game_version'], ['level-name' => $folder]));
            $entry = collect($entries)->first(fn ($entry) => is_array($entry) && ($entry['name'] ?? null) === $folder);
            if ($entry !== null) {
                if (array_key_exists('level-seed', $worldChange) || array_key_exists('level-type', $worldChange)) {
                    throw new ConflictHttpException('Choose a new unused world folder name to apply a new seed or generator. An existing map is never regenerated.');
                }
                if (($entry['file'] ?? true) !== false || ($entry['symlink'] ?? null) !== false) {
                    throw new ConflictHttpException('The world folder must be a regular root directory, not a file or symlink.');
                }
                $level = collect($this->files->setServer($server)->getDirectory('/' . $folder))->firstWhere('name', 'level.dat');
                if (!is_array($level) || ($level['file'] ?? false) !== true || ($level['symlink'] ?? null) !== false || ($level['size'] ?? 0) < 1 || ($level['size'] ?? PHP_INT_MAX) > 16777216) {
                    throw new ConflictHttpException('The uploaded world must contain a regular level.dat directly inside its folder. Extract the map first; no folders were changed.');
                }
            }
        }
        $this->assertBackup($server, $current, $backupUuid);
    }

    protected function assertNoExclusions(Server $server, array $entries): void
    {
        if ($ignore = collect($entries)->firstWhere('name', '.pteroignore')) {
            if (($ignore['file'] ?? false) !== true || ($ignore['symlink'] ?? null) !== false || ($ignore['size'] ?? 16385) > 16384) {
                throw new ConflictHttpException('Review the backup exclusions before changing world settings. No settings were changed.');
            }
            $body = $this->reader->read($server, '/.pteroignore', 16384);
            foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                if (trim($line) !== '' && !str_starts_with(trim($line), '#')) {
                    throw new ConflictHttpException('World settings require a full backup without .pteroignore exclusions. No settings were changed.');
                }
            }
        }
    }

    protected function assertBackup(Server $server, array $current, ?string $backupUuid): void
    {
        $backup = Backup::query()->where('server_id', $server->id)->where('uuid', $backupUuid ?? '')->lockForUpdate()->first();
        if (!$backup || !$backup->is_successful || !$backup->is_locked || !$backup->completed_at || !$backup->checksum || $backup->bytes < 1
            || $backup->ignored_files !== [] || $backup->created_at->lt(now()->subMinutes(30))
            || $backup->name !== self::backupName($current['hash'], $current['runtime_hash'])) {
            throw new ConflictHttpException('A recent completed, locked full safety backup for this configuration is required before changing world settings. No settings were changed.');
        }
    }
}
