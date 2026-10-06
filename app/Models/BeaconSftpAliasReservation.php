<?php

namespace Pterodactyl\Models;

class BeaconSftpAliasReservation extends Model
{
    protected $table = 'beacon_sftp_alias_reservations';
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    public static array $validationRules = [
        'public_username' => ['required', 'regex:' . BeaconSftpAlias::PUBLIC_USERNAME_REGEX, 'unique:beacon_sftp_alias_reservations,public_username'],
    ];
}
