<?php

namespace Pterodactyl\Beacon\Minecraft;

use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Models\BeaconMinecraftService;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VelocityRconConfiguration
{
    public const PROJECT = 'KkmSfl3v';
    public const VERSION = '1.0.7';
    public const PATH = '/plugins/velocircon/rcon.yml';
    public const SETUP = 'Install Velocircon 1.0.7 from Modrinth using Plugin Installer, start Velocity once to generate its configuration, then refresh Settings.';

    public function __construct(private DaemonFileRepository $files, private VanillaFileReader $reader)
    {
    }

    public function inspect(Server $server, bool $verifyPlugin = true): array
    {
        if ($verifyPlugin) {
            $this->verifyPlugin($server);
        }

        return (new VelocityRconDocument($this->read($server)))->properties();
    }

    public function apply(Server $server, array $values): string
    {
        // Missing plugin bytes must not prevent safely disabling our own configuration.
        if (($values['enable-rcon'] ?? true) !== false) {
            $this->verifyPlugin($server);
        }
        $before = $this->read($server);
        $after = (new VelocityRconDocument($before))->patch($values);
        $store = Storage::disk('local');
        $backup = 'beacon/configuration/' . $server->uuid . '/' . Str::uuid() . '.rcon.enc';
        $encrypted = Crypt::encryptString($before);
        if (!$store->put($backup, $encrypted, 'private') || $store->get($backup) !== $encrypted) {
            throw new ConflictHttpException('A verified private restore point is required before changing Velocity RCON.');
        }
        try {
            $this->write($server, $after, $before);
        } catch (\Throwable $exception) {
            try {
                $current = $this->read($server);
                if ($current === $after) {
                    $this->write($server, $before, $after);
                } elseif ($current !== $before) {
                    throw new \RuntimeException('Concurrent Velocircon configuration change.');
                }
            } catch (\Throwable) {
                throw new \RuntimeException('The Minecraft service change failed and rollback also failed. Manual review is required.');
            }
            throw $exception;
        }

        return $before;
    }

    public function restore(Server $server, string $content): void
    {
        new VelocityRconDocument($content);
        $this->write($server, $content, $this->read($server));
    }

    public function verifyPlugin(Server $server): void
    {
        if ($server->variables()->get()->contains(fn ($v) => str_starts_with(strtoupper($v->env_variable), 'VELOCIRCON__'))) {
            throw new ConflictHttpException('Remove Velocircon environment overrides before managing RCON in Beacon.');
        }
        $installed = BeaconContentInstallation::query()->where('server_id', $server->id)
            ->where('provider', 'modrinth')->where('project_id', self::PROJECT)
            ->where('project_type', 'plugin')->where('loader', 'velocity')->where('destination', 'plugins')
            ->where('status', 'installed')->get();
        if ($installed->count() !== 1 || $installed->first()->version_number !== self::VERSION) {
            throw new ConflictHttpException(self::SETUP);
        }
        $plugin = $installed->first();
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/D', $plugin->filename)
            || $plugin->size < 1 || $plugin->size > 16777216 || !preg_match('/^[a-f0-9]{128}$/D', $plugin->sha512)) {
            throw new ConflictHttpException('The managed Velocircon installation is invalid. Reinstall it using Plugin Installer.');
        }
        $this->directory($server, '/', 'plugins');
        $entry = collect($this->files->setServer($server)->getDirectory('/plugins'))->firstWhere('name', $plugin->filename);
        $this->regular($entry, 16777216);
        $hash = $this->files->setServer($server)->getFileHashes('/plugins/' . $plugin->filename, 16777216);
        if ($entry['size'] !== $plugin->size || !hash_equals($plugin->sha512, $hash['sha512'])) {
            throw new ConflictHttpException('The Velocircon JAR changed outside Beacon. Reinstall it using Plugin Installer.');
        }
    }

    public static function assertPluginMutable(Server $server, BeaconContentInstallation $plugin): void
    {
        if ($plugin->provider === 'modrinth' && $plugin->project_id === self::PROJECT
            && BeaconMinecraftService::query()->where('server_id', $server->id)->where('rcon_enabled', true)->exists()) {
            throw new ConflictHttpException('Disable managed RCON before updating, reinstalling or uninstalling Velocircon.');
        }
    }

    private function read(Server $server): string
    {
        $this->directory($server, '/', 'plugins');
        $this->directory($server, '/plugins', 'velocircon');
        $entry = collect($this->files->setServer($server)->getDirectory('/plugins/velocircon'))->firstWhere('name', 'rcon.yml');
        if ($entry === null) {
            throw new ConflictHttpException(self::SETUP);
        }
        $this->regular($entry, VelocityRconDocument::LIMIT);
        $content = $this->reader->read($server, self::PATH, VelocityRconDocument::LIMIT);
        try {
            new VelocityRconDocument($content);
        } catch (\InvalidArgumentException) {
            throw new ConflictHttpException('Velocircon rcon.yml uses unsupported or ambiguous YAML. Review it in Files before managing RCON.');
        }

        return $content;
    }

    private function write(Server $server, string $after, string $before): void
    {
        if ((app(\Pterodactyl\Repositories\Wings\DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? null) !== 'offline') {
            throw new ConflictHttpException('Stop Velocity before writing its RCON configuration.');
        }
        if ($this->read($server) !== $before) {
            throw new ConflictHttpException('Velocircon configuration changed. Refresh Settings before retrying.');
        }
        try {
            $this->files->setServer($server)->putContent(self::PATH, $after);
        } catch (\Throwable) {
            // A lost acknowledgement is only successful if exact bounded readback proves it.
        }
        if ($this->read($server) !== $after) {
            throw new ConflictHttpException('The Velocircon configuration write could not be verified.');
        }
    }

    private function directory(Server $server, string $parent, string $name): void
    {
        $entry = collect($this->files->setServer($server)->getDirectory($parent))->firstWhere('name', $name);
        if ($entry === null) {
            throw new ConflictHttpException(self::SETUP);
        }
        if (($entry['file'] ?? true) !== false || ($entry['symlink'] ?? true) !== false) {
            throw new ConflictHttpException('Velocircon configuration directories must not be symlinks.');
        }
    }

    private function regular(?array $entry, int $limit): void
    {
        if (!is_array($entry) || ($entry['file'] ?? false) !== true || ($entry['symlink'] ?? true) !== false
            || !is_int($entry['size'] ?? null) || $entry['size'] < 1 || $entry['size'] > $limit) {
            throw new ConflictHttpException('Velocircon requires bounded regular files without symlinks.');
        }
    }
}
