<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconModpackInstallation extends Model
{
    protected $table = 'beacon_modpack_installations';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];
    protected $casts = [
        'server_id' => 'integer',
        'operation_id' => 'integer',
        'original_runtime' => 'array',
        'manifest' => 'array',
        'installed_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|integer|exists:servers,id|unique:beacon_modpack_installations,server_id',
        'operation_id' => 'nullable|integer|exists:beacon_operations,id',
        'provider' => 'required|string|max:32',
        'project_id' => 'required|string|max:191',
        'project_slug' => 'nullable|string|max:191',
        'name' => 'required|string|max:191',
        'icon_url' => 'nullable|url|max:2048',
        'version_id' => 'required|string|max:191',
        'version_name' => 'required|string|max:191',
        'minecraft_version' => 'required|string|max:32',
        'loader' => 'required|string|max:32',
        'loader_version' => 'nullable|string|max:64',
        'status' => 'required|string|in:installing,installed,updating,reinstalling,uninstalling,restoring,failed',
        'safety_backup_uuid' => 'required|uuid',
        'original_runtime' => 'required|array',
        'manifest' => 'required|array',
        'installed_at' => 'required|date',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BeaconOperation::class);
    }
}
