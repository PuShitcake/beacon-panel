<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;

/** Read-only projection of the latest verified native install, never a guessed runtime. */
class NativeCurseForgeContentRuntimeResolver
{
    public const UNAVAILABLE = 'Install or reinstall a Forge modpack from Startup and wait for runtime verification before installing mods.';

    public function resolve(Server $server): ?array
    {
        $server->loadMissing('egg.nest');
        if ($server->egg?->nest?->name !== config('beacon.modpacks.nest.name', 'Beacon')
            || $server->egg?->name !== config('beacon.modpacks.nest.curseforge_egg', 'CurseForge Generic')) {
            return null;
        }
        // Do not filter for success: a newer failed/pending reinstall invalidates old results.
        $operation = BeaconOperation::query()->where('server_id', $server->id)
            ->where('type', NativeCurseForgeInstallationService::TYPE)->latest('id')->first();
        if (!$operation) {
            return null; // Preserve the independent legacy managed-installation path.
        }
        $unavailable = ['runtime' => null, 'reason' => self::UNAVAILABLE];
        if ($server->skip_scripts || $server->status !== null || !$server->installed_at
            || $server->transfer()->exists() || $operation->status !== BeaconOperation::STATUS_SUCCEEDED
            || !$operation->finished_at || ($operation->payload['phase'] ?? null) !== 'verify'
            || ($operation->payload['egg_id'] ?? null) !== $server->egg_id) {
            return $unavailable;
        }
        $variables = $server->variables()->get()->pluck('server_value', 'env_variable')->all();
        $selection = ['PROJECT_ID' => $variables['PROJECT_ID'] ?? '', 'VERSION' => $variables['VERSION'] ?? 'latest'];
        $hash = $operation->payload['selection_hash'] ?? null;
        if (!is_string($selection['PROJECT_ID']) || !preg_match('/^(?:[1-9][0-9]{0,18}|zip)$/D', $selection['PROJECT_ID'])
            || !is_string($selection['VERSION']) || !preg_match('/^(?:[1-9][0-9]{0,18}|latest)$/D', $selection['VERSION'])) {
            return $unavailable;
        }
        if (!is_string($hash) || !hash_equals($hash, hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR)))) {
            return ['runtime' => null, 'reason' => 'The Startup modpack selection changed. Restore the previous Project ID and File ID to manage existing add-on mods. Uninstall those mods before reinstalling or changing the modpack.'];
        }
        $result = $operation->result ?? [];
        $game = $result['minecraft_version'] ?? null;
        $loader = $result['loader'] ?? null;
        $loaderVersion = $result['loader_version'] ?? null;
        $policy = new ModpackMinecraftVersionPolicy();
        if (($result['runtime_source'] ?? null) !== 'curseforge_client_manifest'
            || !is_string($game) || !$policy->supports($game)
            || ($result['java_version'] ?? null) !== $policy->javaVersion($game)
            || !in_array($loader, ['forge', 'fabric', 'neoforge', 'quilt'], true)
            || !is_string($loaderVersion) || !preg_match('/^[0-9][A-Za-z0-9.+_-]{0,63}$/D', $loaderVersion)) {
            return $unavailable;
        }

        return ['reason' => null, 'runtime' => [
            'source' => 'native_modpack',
            'native_operation_id' => $operation->id,
            'project_type' => 'mod',
            'loader' => $loader,
            'loader_version' => $loaderVersion,
            'game_version' => $game,
            'destination' => 'mods',
            'profile' => ['code' => $loader, 'name' => ucfirst($loader), 'loader' => $loader, 'content_directory' => 'mods'],
        ]];
    }
}
