<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

class BeaconModpackMutationRequest extends BeaconModpackRequest
{
    public function rules(): array
    {
        return [
            'version_id' => 'sometimes|string|max:191',
        ];
    }
}
