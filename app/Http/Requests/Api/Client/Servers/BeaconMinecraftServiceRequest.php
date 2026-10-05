<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconMinecraftServiceRequest extends ClientApiRequest
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
            'service' => ['sometimes', 'required', Rule::in(['rcon', 'query'])],
            'action' => ['sometimes', 'required', Rule::in(['enable', 'disable', 'rotate'])],
        ];
    }
}
