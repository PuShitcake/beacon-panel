<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Modpacks\ModpackMinecraftVersionPolicy;
use Pterodactyl\Beacon\Modpacks\CurseForgeInstalledRuntimeService;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeContentRuntimeResolver;

/** Installed manifest provenance, not a claim that every mod/loader binary is authentic. */
class CurseForgeConfigurationRuntimeReader extends VanillaJarVersionReader
{
    public const FILE = 'client.manifest.json';
    public const LIMIT = 1048576;
    public const WAITING = 'Install a modpack from Startup and wait for runtime verification before using Minecraft Configuration.';

    public function __construct(
        private VanillaFileReader $reader,
        private NativeCurseForgeContentRuntimeResolver $native,
        private CurseForgeInstalledRuntimeService $manifest,
    ) {
        parent::__construct($reader);
    }

    public function unavailableReason(Server $server): ?string
    {
        return $this->context($server) === null ? self::WAITING : null;
    }

    public function inspect(Server $server, string $path, int $size): ?array
    {
        $context = $this->context($server);
        if ($context === null || $path !== '/' . self::FILE || $size < 1 || $size > self::LIMIT) {
            return null;
        }
        $content = $this->reader->read($server, $path, self::LIMIT);
        if (strlen($content) !== $size) {
            return null;
        }
        try {
            $runtime = $this->manifest->parse($content);
        } catch (ModpackProviderException) {
            return null;
        }
        foreach (['minecraft_version', 'loader', 'loader_version', 'java_version'] as $key) {
            if ($runtime[$key] !== $context['runtime'][$key]) {
                return null;
            }
        }
        // Re-read SQL provenance/selection after Wings I/O. Never adopt a newer pending/failed
        // operation, an older success, or a manually changed selection as the installed pack.
        if ($this->context($server) !== $context) {
            return null;
        }

        return ['version' => $runtime['minecraft_version'], 'identity' => hash('sha256', json_encode([
            $context, hash('sha256', $content), $server->image, $server->startup,
        ], JSON_THROW_ON_ERROR))];
    }

    private function context(Server $server): ?array
    {
        $server = $server->fresh(['egg.nest']);
        if (!$server || !CurseForgeConfigurationService::supports($server) || $server->skip_scripts
            || $server->status !== null || !$server->installed_at || $server->transfer()->exists()) {
            return null;
        }
        $native = $this->native->resolve($server);
        if ($native !== null) {
            if ($native['runtime'] === null) {
                return null;
            }
            $projection = $native['runtime'];
            $runtime = ['minecraft_version' => $projection['game_version'], 'loader' => $projection['loader'],
                'loader_version' => $projection['loader_version'],
                'java_version' => (new ModpackMinecraftVersionPolicy())->javaVersion($projection['game_version'])];
            $identity = ['native', $projection['native_operation_id']];
        } else {
            // Keep verified retired-picker instances readable without deleting their metadata
            // or rollback backup. No native history means this is an independent legacy path.
            $legacy = BeaconModpackInstallation::query()->where('server_id', $server->id)->first();
            if (!$legacy || $legacy->provider !== 'curseforge' || $legacy->status !== 'installed'
                || !$legacy->installed_at || !is_array($legacy->manifest)
                || ($legacy->manifest['runtime_source'] ?? null) !== 'curseforge_client_manifest') {
                return null;
            }
            $runtime = array_intersect_key($legacy->manifest, array_flip(['minecraft_version', 'loader', 'loader_version', 'java_version']));
            if (count($runtime) !== 4 || $runtime['minecraft_version'] !== $legacy->minecraft_version
                || $runtime['loader'] !== $legacy->loader || $runtime['loader_version'] !== $legacy->loader_version) {
                return null;
            }
            $selection = $server->variables()->get()->pluck('server_value', 'env_variable');
            if ($selection->get('PROJECT_ID') !== $legacy->project_id || $selection->get('VERSION') !== $legacy->version_id) {
                return null;
            }
            $identity = ['legacy', $legacy->id, $legacy->operation_id, $legacy->installed_at->toIso8601String()];
        }
        $policy = new ModpackMinecraftVersionPolicy();
        if (!is_string($runtime['minecraft_version'] ?? null) || !$policy->supports($runtime['minecraft_version'])
            || $runtime['java_version'] !== $policy->javaVersion($runtime['minecraft_version'])
            || !in_array($runtime['loader'] ?? null, ['forge', 'fabric', 'neoforge', 'quilt'], true)
            || !is_string($runtime['loader_version'] ?? null)
            || !preg_match('/^[0-9][A-Za-z0-9.+_-]{0,63}$/D', $runtime['loader_version'])) {
            return null;
        }
        // A custom launch command can select another world/config/runtime. Do not guess its
        // semantics or edit it. Native image finalization leaves this Egg command unchanged.
        $image = config('beacon.modpacks.java_images.' . $runtime['java_version']);
        if (!is_string($image) || $image === '' || !is_string($server->startup)
            || $server->startup === '' || $server->startup !== $server->egg->startup || $server->image !== $image) {
            return null;
        }

        return ['runtime' => $runtime, 'installation' => $identity, 'image' => $server->image,
            'startup' => $server->startup, 'installed_at' => $server->installed_at->toIso8601String()];
    }
}
