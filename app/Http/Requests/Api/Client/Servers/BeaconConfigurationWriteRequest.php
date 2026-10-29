<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Beacon\Minecraft\MinecraftConfigurationAdapter;

class BeaconConfigurationWriteRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_UPDATE;
    }

    protected function prepareForValidation(): void
    {
        $server = $this->route('server');
        if ($server instanceof Server && MinecraftConfigurationAdapter::serviceClass($server) !== null && $this->isJson()) {
            // Preserve explicit empty/spaced text only for supported text settings. The
            // property policy still validates every value after native request permissions.
            $raw = json_decode($this->getContent(), true);
            $properties = $this->input('properties');
            if (is_array($properties)) {
                foreach (['motd', 'server-name', 'level-name', 'level-seed', 'resource-pack', 'resource-pack-sha1'] as $key) {
                    if (is_string($raw['properties'][$key] ?? null)) {
                        $properties[$key] = $raw['properties'][$key];
                    }
                }
                $this->merge(['properties' => $properties]);
            }
        }
    }

    public function rules(): array
    {
        // There is no legacy writer. Unsupported Eggs fail closed in the controller;
        // reviewed adapters validate keys and values against the current installed file.
        return [
            'hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'runtime_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'world_backup_uuid' => ['sometimes', 'nullable', 'uuid'],
            'properties' => ['required', 'array', 'min:1', 'max:32'],
        ];
    }
}
