<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Beacon\Sftp\PublicSftpAliasService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Settings\StoreSftpAliasRequest;

class SftpAliasController extends ClientApiController
{
    public function __construct(private PublicSftpAliasService $aliases)
    {
        parent::__construct();
    }

    public function __invoke(StoreSftpAliasRequest $request, Server $server): JsonResponse
    {
        $alias = $this->aliases->provision($request->user(), $server);

        return new JsonResponse([
            'data' => [
                'username' => $alias->public_username,
            ],
        ]);
    }
}
