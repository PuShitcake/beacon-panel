<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Beacon\Minecraft\MinecraftConfigurationAdapter;

class BeaconConfigurationWriteRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_UPDATE;
    }

    protected function prepareForValidation(): void
    {
        $server = $this->route('server');
        if ($server instanceof Server && MinecraftConfigurationAdapter::serviceClass($server) !== null && $this->isJson()) {
            // Preserve explicit empty/spaced text only for supported text settings. The
            // property policy still validates every value after native request permissions.
            $raw = json_decode($this->getContent(), true);
            $properties = $this->input('properties');
            if (is_array($properties)) {
                foreach (['motd', 'server-name', 'level-name', 'level-seed', 'resource-pack', 'resource-pack-sha1'] as $key) {
                    if (is_string($raw['properties'][$key] ?? null)) {
                        $properties[$key] = $raw['properties'][$key];
                    }
                }
                $this->merge(['properties' => $properties]);
            }
        }
    }

    public function rules(): array
    {
        $server = $this->route('server');
        if ($server instanceof Server && MinecraftConfigurationAdapter::serviceClass($server) !== null) {
            // The selected runtime adapter and current file determine the dynamic allowlist
            // after permission and stopped-state enforcement. Bedrock never uses Java fields.
            return [
                'hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                'runtime_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                'world_backup_uuid' => ['sometimes', 'nullable', 'uuid'],
                'properties' => ['required', 'array', 'min:1', 'max:32'],
            ];
        }

        return [
            'hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'properties' => 'required|array:motd,max-players,gamemode,difficulty,hardcore,pvp,online-mode,white-list,allow-flight,view-distance,simulation-distance,spawn-protection',
            'properties.motd' => ['sometimes', 'string', 'max:120', 'not_regex:/[\r\n]/'],
            'properties.max-players' => 'sometimes|integer|min:1|max:1000',
            'properties.gamemode' => ['sometimes', Rule::in(['survival', 'creative', 'adventure', 'spectator'])],
            'properties.difficulty' => ['sometimes', Rule::in(['peaceful', 'easy', 'normal', 'hard'])],
            'properties.hardcore' => 'sometimes|boolean',
            'properties.pvp' => 'sometimes|boolean',
            'properties.online-mode' => 'sometimes|boolean',
            'properties.white-list' => 'sometimes|boolean',
            'properties.allow-flight' => 'sometimes|boolean',
            'properties.view-distance' => 'sometimes|integer|min:2|max:32',
            'properties.simulation-distance' => 'sometimes|integer|min:2|max:32',
            'properties.spawn-protection' => 'sometimes|integer|min:0|max:1000',
        ];
    }
}
