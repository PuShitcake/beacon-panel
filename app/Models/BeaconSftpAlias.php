<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeaconSftpAlias extends Model
{
    public const PUBLIC_USERNAME_REGEX = '/^@([1-9][0-9]*)\.[a-z0-9]{8}$/D';

    protected $table = 'beacon_sftp_aliases';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];
    protected $casts = [
        'user_id' => 'integer',
        'server_id' => 'integer',
    ];

    public static array $validationRules = [
        'user_id' => 'required|integer|exists:users,id',
        'server_id' => 'required|integer|exists:servers,id',
        'public_username' => ['required', 'regex:' . self::PUBLIC_USERNAME_REGEX, 'unique:beacon_sftp_aliases,public_username'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
