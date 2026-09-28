<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconOperation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $table = 'beacon_operations';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];
    protected $casts = [
        'api_key_id' => 'integer', 'user_id' => 'integer', 'server_id' => 'integer', 'payload' => 'array', 'result' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid|unique:beacon_operations,uuid',
        'api_key_id' => 'nullable|integer|exists:api_keys,id',
        'user_id' => 'nullable|integer|exists:users,id',
        'server_id' => 'nullable|integer|exists:servers,id',
        'actor_key' => 'required|string|max:191',
        'type' => 'required|string|max:64',
        'status' => 'required|string|in:pending,running,succeeded,failed',
        'correlation_id' => 'required|uuid',
        'idempotency_key' => 'nullable|string|max:128',
        'request_hash' => 'nullable|string|size:64',
        'payload' => 'array',
        'error_code' => 'nullable|string|max:64',
        'error_message' => 'nullable|string',
        'result' => 'nullable|array',
        'started_at' => 'nullable|date',
        'finished_at' => 'nullable|date',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
