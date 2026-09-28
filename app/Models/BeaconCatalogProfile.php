<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconCatalogProfile extends Model
{
    public const LOADERS = ['vanilla', 'paper', 'fabric', 'forge', 'neoforge'];

    protected $table = 'beacon_catalog_profiles';
    protected $fillable = ['application_id', 'egg_id', 'code', 'name', 'loader', 'content_directory', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];

    public static array $validationRules = [
        'application_id' => 'required|integer|exists:beacon_catalog_applications,id',
        'egg_id' => 'required|integer|exists:eggs,id',
        'code' => 'required|string|max:64',
        'name' => 'required|string|max:191',
        'loader' => 'required|string|in:vanilla,paper,fabric,forge,neoforge',
        'content_directory' => 'nullable|string|in:plugins,mods',
        'enabled' => 'boolean',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(BeaconCatalogApplication::class, 'application_id');
    }

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BeaconCatalogVersion::class, 'profile_id');
    }
}
