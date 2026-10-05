<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconMinecraftService extends Model
{
    protected $table = 'beacon_minecraft_services';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];
    protected $hidden = ['rcon_password'];
    protected $casts = [
        'server_id' => 'integer',
        'rcon_allocation_id' => 'integer',
        'query_allocation_id' => 'integer',
        'last_operation_id' => 'integer',
        'rcon_password' => 'encrypted',
        'rcon_enabled' => 'boolean',
        'query_enabled' => 'boolean',
    ];

    public static array $validationRules = [
        'server_id' => 'required|integer|unique:beacon_minecraft_services,server_id|exists:servers,id',
        'rcon_allocation_id' => 'nullable|integer|unique:beacon_minecraft_services,rcon_allocation_id|exists:allocations,id',
        'query_allocation_id' => 'nullable|integer|unique:beacon_minecraft_services,query_allocation_id|exists:allocations,id',
        'rcon_password' => 'nullable|string|max:191',
        'rcon_enabled' => 'boolean',
        'query_enabled' => 'boolean',
        'last_operation_id' => 'nullable|integer|exists:beacon_operations,id',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function rconAllocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'rcon_allocation_id');
    }

    public function queryAllocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'query_allocation_id');
    }

    public function lastOperation(): BelongsTo
    {
        return $this->belongsTo(BeaconOperation::class, 'last_operation_id');
    }
}
