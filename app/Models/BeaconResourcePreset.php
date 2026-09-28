<?php

namespace Pterodactyl\Models;

class BeaconResourcePreset extends Model
{
    protected $table = 'beacon_resource_presets';
    protected $fillable = ['code', 'name', 'memory', 'swap', 'disk', 'io', 'cpu', 'threads', 'database_limit', 'allocation_limit', 'backup_limit', 'enabled'];
    protected $casts = [
        'memory' => 'integer', 'swap' => 'integer', 'disk' => 'integer', 'io' => 'integer', 'cpu' => 'integer',
        'database_limit' => 'integer', 'allocation_limit' => 'integer', 'backup_limit' => 'integer', 'enabled' => 'boolean',
    ];

    public static array $validationRules = [
        'code' => 'required|string|max:64|unique:beacon_resource_presets,code',
        'name' => 'required|string|max:191',
        'memory' => 'required|integer|min:128',
        'swap' => 'required|integer|min:-1',
        'disk' => 'required|integer|min:512',
        'io' => 'required|integer|between:10,1000',
        'cpu' => 'required|integer|min:0',
        'threads' => 'nullable|regex:/^[0-9-,]+$/',
        'database_limit' => 'required|integer|min:0',
        'allocation_limit' => 'required|integer|min:0',
        'backup_limit' => 'required|integer|min:0',
        'enabled' => 'boolean',
    ];
}
