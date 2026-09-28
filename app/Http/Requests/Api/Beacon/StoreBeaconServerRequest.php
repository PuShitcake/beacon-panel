<?php

namespace Pterodactyl\Http\Requests\Api\Beacon;

class StoreBeaconServerRequest extends BeaconWriteRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:191',
            'description' => 'nullable|string|max:1024',
            'owner_uuid' => 'required|uuid|exists:users,uuid',
            'application' => 'required|string|max:64',
            'profile' => 'required|string|max:64',
            'version' => 'required|string|max:64',
            'preset' => 'required|string|max:64',
            'deployment' => 'required|array',
            'deployment.locations' => 'required|array|min:1|max:20',
            'deployment.locations.*' => 'integer|min:1|distinct',
            'deployment.dedicated_ip' => 'sometimes|boolean',
            'deployment.port_range' => 'sometimes|array|max:20',
            'deployment.port_range.*' => ['string', 'regex:/^[0-9]{1,5}(-[0-9]{1,5})?$/'],
            'start_on_completion' => 'sometimes|boolean',
            'docker_image' => 'prohibited',
            'image' => 'prohibited',
            'startup' => 'prohibited',
            'environment' => 'prohibited',
            'memory' => 'prohibited',
            'cpu' => 'prohibited',
            'disk' => 'prohibited',
            'limits' => 'prohibited',
            'egg_id' => 'prohibited',
            'nest_id' => 'prohibited',
            'allocation_id' => 'prohibited',
            'install_script' => 'prohibited',
        ];
    }
}
