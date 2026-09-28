<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconContentSearchRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'query' => 'sometimes|string|max:100',
            'project_id' => 'sometimes|string|max:64',
            'version_id' => 'sometimes|string|max:64',
            'offset' => 'sometimes|integer|min:0|max:10000',
        ];
    }
}
