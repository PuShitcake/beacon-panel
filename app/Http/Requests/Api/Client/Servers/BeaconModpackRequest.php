<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconModpackRequest extends ClientApiRequest
{
    public function authorize(): bool
    {
        $server = $this->route()->parameter('server');

        return $server instanceof Server
            && ($this->user()->root_admin || $server->owner_id === $this->user()->id);
    }

    public function rules(): array
    {
        return [
            'provider' => 'sometimes|string|in:modrinth,curseforge,ftb,atlauncher,technic,voidswrath',
            'query' => 'sometimes|nullable|string|max:100',
            'page' => 'sometimes|integer|min:1|max:1000',
            'page_size' => 'sometimes|integer|in:10,20,50',
            'version_id' => 'sometimes|string|max:191',
        ];
    }
}
