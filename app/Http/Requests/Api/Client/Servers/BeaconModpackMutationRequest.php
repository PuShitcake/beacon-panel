<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

class BeaconModpackMutationRequest extends BeaconModpackRequest
{
    public function rules(): array
    {
        return [
            'provider' => 'sometimes|string|in:curseforge',
            'project_id' => 'sometimes|string|max:191',
            'version_id' => 'sometimes|string|max:191',
        ];
    }
}
