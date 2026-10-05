<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconContentInstallation extends Model
{
    protected $table = 'beacon_content_installations';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];
    protected $casts = [
        'server_id' => 'integer',
        'operation_id' => 'integer',
        'size' => 'integer',
        'dependencies' => 'array',
        'is_dependency' => 'boolean',
    ];

    public static array $validationRules = [
        'server_id' => 'required|integer|exists:servers,id',
        'operation_id' => 'nullable|integer|exists:beacon_operations,id',
        'provider' => 'required|string|in:modrinth',
        'project_id' => 'required|string|max:64',
        'project_name' => 'nullable|string|max:191',
        'version_id' => 'required|string|max:64',
        'version_number' => 'nullable|string|max:191',
        'icon_url' => 'nullable|url|max:2048',
        'project_type' => 'required|string|in:plugin,mod,modpack',
        'loader' => 'required|string|max:32',
        'game_version' => 'required|string|max:64',
        'destination' => 'required|string|in:plugins,mods',
        'filename' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/'],
        'sha512' => ['required', 'string', 'size:128', 'regex:/^[a-f0-9]+$/'],
        'size' => 'required|integer|min:1',
        'dependencies' => 'array',
        'is_dependency' => 'boolean',
        'disabled_path' => 'nullable|string|max:255',
        'status' => 'required|string|in:planned,installing,installed,disabled,failed,removing',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BeaconOperation::class, 'operation_id');
    }
}
