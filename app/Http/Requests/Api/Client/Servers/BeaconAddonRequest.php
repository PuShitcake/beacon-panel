<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconAddonRequest extends ClientApiRequest
{
    public function authorize(): bool
    {
        $server = $this->route()->parameter('server');

        return $server instanceof Server && ($this->user()->root_admin || $server->owner_id === $this->user()->id);
    }

    public function rules(): array
    {
        return ['query' => 'sometimes|nullable|string|max:100', 'offset' => 'sometimes|integer|min:0|max:9980',
            'provider' => 'sometimes|string|in:curseforge,upload', 'version_id' => 'sometimes|string|max:64',
            'world' => 'sometimes|string|max:64', 'archive_id' => 'sometimes|string|size:64|regex:/^[a-f0-9]+$/D',
            'file' => 'sometimes|file', 'acknowledge' => 'sometimes|boolean'];
    }
}
