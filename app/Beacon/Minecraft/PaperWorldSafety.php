<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Symfony\Component\Yaml\Yaml;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaperWorldSafety extends VanillaWorldSafety
{
    public function __construct(private DaemonFileRepository $files, private VanillaFileReader $reader)
    {
        parent::__construct($files, $reader);
    }

    public function assertSafe(Server $server, array $changes, array $current, ?string $backupUuid): void
    {
        if (!array_intersect_key($changes, array_flip(['level-name', 'level-seed', 'level-type']))) {
            return;
        }
        // Custom launch flags can override the world/container/config properties. Do not
        // claim the root-map safety checks cover a different directory or selected world.
        if (preg_match('/(?:^|\s)[\x22\x27]?(?:--?(?:world|world-dir|world-container|universe|config|bukkit-settings)|-W|-w|-c|-b)(?:[\x22\x27]?\s|=|$)/', $server->startup ?? '')) {
            throw new ConflictHttpException('Custom Paper world or configuration launch options require administrator review before changing world settings.');
        }
        $entries = $this->files->setServer($server)->getDirectory('/');
        $bukkit = collect($entries)->firstWhere('name', 'bukkit.yml');
        if ($bukkit !== null) {
            if (($bukkit['file'] ?? false) !== true || ($bukkit['symlink'] ?? null) !== false || ($bukkit['size'] ?? 65537) > 65536) {
                throw new ConflictHttpException('Review Paper world storage configuration before changing world settings.');
            }
            try {
                $config = Yaml::parse($this->reader->read($server, '/bukkit.yml', 65536));
                if (!is_array($config) || (isset($config['settings']) && !is_array($config['settings']))) {
                    throw new \RuntimeException('Invalid world storage configuration.');
                }
            } catch (\Throwable) {
                throw new ConflictHttpException('Review Paper world storage configuration before changing world settings.');
            }
            $container = $config['settings']['world-container'] ?? '.';
            if (!in_array($container, ['.', './', ''], true)) {
                throw new ConflictHttpException('World selection currently supports Paper worlds in the server root only. No settings were changed.');
            }
        }
        $folder = $changes['level-name'] ?? $current['properties']['level-name'] ?? null;
        if (is_string($folder)) {
            foreach (['_nether', '_the_end'] as $suffix) {
                $entry = collect($entries)->first(fn ($entry) => is_array($entry) && ($entry['name'] ?? null) === $folder . $suffix);
                if ($entry === null) {
                    continue;
                }
                if (isset($changes['level-seed']) || isset($changes['level-type'])) {
                    throw new ConflictHttpException('Choose a new world name without existing Nether or End folders before applying a new seed or generator.');
                }
                if (($entry['file'] ?? true) !== false || ($entry['symlink'] ?? null) !== false) {
                    throw new ConflictHttpException('Matching Paper dimension folders must be regular directories, not files or symlinks.');
                }
            }
        }
        parent::assertSafe($server, $changes, $current, $backupUuid);
    }
}
