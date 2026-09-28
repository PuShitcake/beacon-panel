<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class BeaconCatalogApplication extends Model
{
    protected $table = 'beacon_catalog_applications';
    protected $fillable = ['slug', 'name', 'description', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];

    public static array $validationRules = [
        'slug' => 'required|string|max:64|unique:beacon_catalog_applications,slug',
        'name' => 'required|string|max:191',
        'description' => 'nullable|string',
        'enabled' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(BeaconCatalogProfile::class, 'application_id');
    }
}
