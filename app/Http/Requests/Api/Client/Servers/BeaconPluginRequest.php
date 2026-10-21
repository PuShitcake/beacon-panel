<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconPluginRequest extends ClientApiRequest
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
            'query' => 'sometimes|nullable|string|max:100',
            'provider' => ['sometimes', 'string', Rule::in(['curseforge', 'modrinth', 'hangar'])],
            'category' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'version_id' => 'sometimes|string|max:64',
            'offset' => 'sometimes|integer|min:0|max:10000',
        ];
    }
}
