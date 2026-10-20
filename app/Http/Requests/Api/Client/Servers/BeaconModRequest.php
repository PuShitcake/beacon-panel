<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconModRequest extends ClientApiRequest
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
            'provider' => ['sometimes', 'string', Rule::in(['curseforge', 'modrinth'])],
            'category' => $this->input('provider', 'curseforge') === 'modrinth'
                ? ['sometimes', 'nullable', 'string', Rule::in(config('beacon.mods.categories', []))]
                : ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'version_id' => 'sometimes|string|max:64',
            'offset' => 'sometimes|integer|min:0|max:10000',
        ];
    }
}
