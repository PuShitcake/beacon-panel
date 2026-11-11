<?php

namespace Pterodactyl\Models;

class BeaconAddonInstallation extends Model
{
    protected $table = 'beacon_addon_installations';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];
    protected $casts = ['server_id' => 'integer', 'operation_id' => 'integer', 'manifest' => 'array'];

    public static array $validationRules = [
        'server_id' => 'required|integer|exists:servers,id',
        'operation_id' => 'nullable|integer|exists:beacon_operations,id',
        'world' => 'required|string|max:64',
        'provider' => 'required|in:upload,curseforge',
        'project_id' => 'nullable|string|max:64',
        'version_id' => 'nullable|string|max:64',
        'archive_id' => 'required|string|size:64',
        'manifest' => 'required|array',
    ];
}
