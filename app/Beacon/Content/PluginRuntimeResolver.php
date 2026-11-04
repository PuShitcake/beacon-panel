<?php

namespace Pterodactyl\Beacon\Content;

use Pterodactyl\Models\Server;
use Pterodactyl\Beacon\Minecraft\VelocityJarIdentity;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PluginRuntimeResolver
{
    public function __construct(private DaemonFileRepository $files)
    {
    }

    public function platform(Server $server): ?string
    {
        $server->loadMissing('egg.nest');
        if ($server->egg?->nest?->name !== config('beacon.modpacks.nest.name', 'Beacon')) {
            return null;
        }

        return \Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService::pluginPlatformFromEggName($server->egg->name);
    }

    public function resolve(Server $server): array
    {
        $platform = $this->platform($server);
        if ($platform === null) {
            throw new ConflictHttpException('This server does not support managed plugins. Use a compatible Bukkit/Paper or Velocity Egg in the Beacon nest.');
        }
        if ($server->status !== null || $server->transfer()->exists() || !$server->installed_at) {
            throw new ConflictHttpException('Finish installing this server before managing plugins.');
        }
        $variables = $server->variables()->get()->pluck('server_value', 'env_variable')->all();
        ksort($variables);
        if ($platform === 'velocity') {
            return $this->velocity($server, $variables);
        }
        $game = null;
        // Paper writes its actual version after boot. Never guess a version from "latest".
        $repository = $this->files->setServer($server);
        $entry = collect($repository->getDirectory('/'))->firstWhere('name', 'version_history.json');
        if (is_array($entry)) {
            if (($entry['file'] ?? false) !== true || ($entry['symlink'] ?? false) !== false || ($entry['size'] ?? 65537) > 65536) {
                throw new ConflictHttpException('The server version history must be a bounded regular file.');
            }
            $history = json_decode($repository->getContent('/version_history.json', 65536), true);
            $current = $history['currentVersion'] ?? null;
            if (is_string($current) && preg_match('/(?:MC: |^)((?:1\.[0-9]{1,2}(?:\.[0-9]{1,2})?|2[6-9]\.[0-9]{1,2}(?:\.[0-9]{1,2})?))(?:\)|-|$)/D', $current, $match)) {
                $game = $match[1];
            }
            if ($game === null) {
                throw new ConflictHttpException('The installed Minecraft version could not be verified. Start the server once before managing plugins.');
            }
        } else {
            $configured = $variables['MINECRAFT_VERSION'] ?? $variables['VERSION'] ?? null;
            if (is_string($configured) && preg_match('/^(?:1\.[0-9]{1,2}(?:\.[0-9]{1,2})?|2[6-9]\.[0-9]{1,2}(?:\.[0-9]{1,2})?)$/D', $configured)) {
                $game = $configured;
            }
        }
        if ($game === null) {
            throw new ConflictHttpException('Start the server once so Beacon can identify its installed Minecraft version before installing plugins.');
        }

        return ['source' => 'native_plugin', 'project_type' => 'plugin', 'loader' => $platform,
            'game_version' => $game, 'destination' => 'plugins', 'egg_id' => $server->egg_id,
            'selection_hash' => hash('sha256', json_encode([$variables, $server->startup, $server->image], JSON_THROW_ON_ERROR))];
    }

    public function assertOperationRuntime(Server $server, array $expected): void
    {
        if ($this->resolve($server) !== $expected) {
            throw new ConflictHttpException('The server runtime changed. No plugin files were changed. Reload the Plugin Installer.');
        }
    }

    private function velocity(Server $server, array $variables): array
    {
        $jar = $variables['SERVER_JARFILE'] ?? null;
        if (!is_string($jar) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.jar$/D', $jar)) {
            throw new ConflictHttpException('Velocity plugins require a regular root-level proxy JAR.');
        }
        $repository = $this->files->setServer($server);
        $entry = collect($repository->getDirectory('/'))->firstWhere('name', $jar);
        if (!is_array($entry) || ($entry['file'] ?? false) !== true || ($entry['symlink'] ?? false) !== false
            || !is_int($entry['size'] ?? null) || $entry['size'] < 1 || $entry['size'] > VelocityJarIdentity::MAX_BYTES) {
            throw new ConflictHttpException('Finish installing a valid Velocity proxy JAR before managing plugins.');
        }
        $identity = app(VelocityJarIdentity::class)->inspect($server, '/' . $jar, $entry['size']);
        if (!is_string($identity['version'] ?? null)) {
            throw new ConflictHttpException('The installed Velocity version could not be verified. Stable proxy version metadata is required before managing plugins.');
        }
        if ($entry !== collect($repository->getDirectory('/'))->firstWhere('name', $jar)) {
            throw new ConflictHttpException('The Velocity JAR changed during verification. Reload the Plugin Installer.');
        }

        // The legacy compatibility-version storage column is paired with loader=velocity.
        // Public responses label it as the proxy version, never as a Minecraft version.
        return ['source' => 'native_plugin', 'project_type' => 'plugin', 'loader' => 'velocity',
            'game_version' => $identity['version'], 'platform_version' => $identity['version'],
            'destination' => 'plugins', 'egg_id' => $server->egg_id,
            'selection_hash' => hash('sha256', json_encode([$variables, $server->startup, $server->image, $identity['identity']], JSON_THROW_ON_ERROR))];
    }
}
