<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconServerMetadata extends Model
{
    protected $table = 'beacon_server_metadata';
    protected $fillable = ['server_id', 'application_id', 'profile_id', 'version_id', 'preset_id'];
    protected $casts = [
        'server_id' => 'integer',
        'application_id' => 'integer',
        'profile_id' => 'integer',
        'version_id' => 'integer',
        'preset_id' => 'integer',
    ];

    public static array $validationRules = [
        'server_id' => 'required|integer|unique:beacon_server_metadata,server_id|exists:servers,id',
        'application_id' => 'required|integer|exists:beacon_catalog_applications,id',
        'profile_id' => 'required|integer|exists:beacon_catalog_profiles,id',
        'version_id' => 'required|integer|exists:beacon_catalog_versions,id',
        'preset_id' => 'required|integer|exists:beacon_resource_presets,id',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(BeaconCatalogApplication::class, 'application_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BeaconCatalogProfile::class, 'profile_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BeaconCatalogVersion::class, 'version_id');
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(BeaconResourcePreset::class, 'preset_id');
    }
}
