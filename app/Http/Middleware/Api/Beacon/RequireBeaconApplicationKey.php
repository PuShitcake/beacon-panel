<?php

namespace Pterodactyl\Http\Middleware\Api\Beacon;

use Illuminate\Http\Request;
use Pterodactyl\Models\ApiKey;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequireBeaconApplicationKey
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $token = $request->user()?->currentAccessToken();
        if (!$token instanceof ApiKey || $token->key_type !== ApiKey::TYPE_APPLICATION) {
            throw new AccessDeniedHttpException('Beacon API access requires an Application API key.');
        }

        return $next($request);
    }
}
