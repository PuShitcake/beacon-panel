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
            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('beacon.mods.categories', []))],
            'version_id' => 'sometimes|string|max:64',
            'offset' => 'sometimes|integer|min:0|max:10000',
        ];
    }
}
