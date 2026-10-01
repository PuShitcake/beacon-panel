<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

class BeaconModpackInstallRequest extends BeaconModpackRequest
{
    public function rules(): array
    {
        return [
            'provider' => 'required|string|in:modrinth,curseforge,ftb,atlauncher,technic,voidswrath',
            'project_id' => 'required|string|max:191',
            'version_id' => 'required|string|max:191',
            'delete_files' => 'sometimes|boolean',
            'confirmation' => 'required_if:delete_files,true|nullable|string|max:191',
        ];
    }
}
