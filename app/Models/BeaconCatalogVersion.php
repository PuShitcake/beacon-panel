<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconCatalogVersion extends Model
{
    protected $table = 'beacon_catalog_versions';
    protected $fillable = ['profile_id', 'version', 'loader_version', 'docker_image', 'startup', 'environment', 'enabled', 'deprecated'];
    protected $casts = ['environment' => 'array', 'enabled' => 'boolean', 'deprecated' => 'boolean'];

    public static array $validationRules = [
        'profile_id' => 'required|integer|exists:beacon_catalog_profiles,id',
        'version' => 'required|string|max:64',
        'loader_version' => 'string|max:64',
        'docker_image' => 'nullable|string|max:191',
        'startup' => 'nullable|string',
        'environment' => 'array',
        'enabled' => 'boolean',
        'deprecated' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BeaconCatalogProfile::class, 'profile_id');
    }
}
