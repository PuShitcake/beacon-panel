<?php

namespace Pterodactyl\Beacon\Sftp;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;

class PublicSftpAliasGenerator
{
    public function generate(User $user): string
    {
        if ($user->id < 1) {
            throw new \InvalidArgumentException('A persisted Panel user is required to generate an SFTP alias.');
        }

        return sprintf('@%d.%s', $user->id, Str::lower(Str::random(8)));
    }
}
