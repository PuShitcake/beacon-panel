<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BeaconVersionRequest extends ClientApiRequest
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
            'software' => ['sometimes', 'required', Rule::in(config('beacon.versions.software', []))],
            'version' => ['sometimes', 'required', 'string', 'max:32'],
            'build_uuid' => ['sometimes', 'required', 'uuid'],
        ];
    }
}
