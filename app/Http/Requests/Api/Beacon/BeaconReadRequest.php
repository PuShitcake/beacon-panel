<?php

namespace Pterodactyl\Http\Requests\Api\Beacon;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class BeaconReadRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;
    protected int $permission = AdminAcl::READ;
}
