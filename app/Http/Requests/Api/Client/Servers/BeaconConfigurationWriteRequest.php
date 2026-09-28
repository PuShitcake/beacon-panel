<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconConfigurationWriteRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_UPDATE;
    }

    public function rules(): array
    {
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
