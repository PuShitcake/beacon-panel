<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware\Api\Client\Server;

use Illuminate\Http\Request;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Illuminate\Routing\Route;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Exceptions\Http\Server\ServerStateConflictException;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

class AuthenticateServerAccessTest extends TestCase
{
    public function testOwnerCanPollBeaconModpackOperationWhileInstallerIsRunning(): void
    {
        [$request] = $this->requestForServer(Server::STATUS_INSTALLING, 'api:beacon.modpacks.operation');

        $response = (new AuthenticateServerAccess())->handle($request, fn (Request $request) => $request);

        $this->assertSame($request, $response);
    }

    public function testOwnerCanReloadModpackContextWhileInstallerIsRunning(): void
    {
        [$request] = $this->requestForServer(Server::STATUS_INSTALLING, 'api:beacon.modpacks.context');

        $response = (new AuthenticateServerAccess())->handle($request, fn (Request $request) => $request);

        $this->assertSame($request, $response);
    }

    public function testNormalServerRouteRemainsBlockedWhileInstallerIsRunning(): void
    {
        [$request] = $this->requestForServer(Server::STATUS_INSTALLING, 'api:client:server.resources');

        $this->expectException(ServerStateConflictException::class);

        (new AuthenticateServerAccess())->handle($request, fn (Request $request) => $request);
    }

    public function testOperationPollingRemainsBlockedForSuspendedServer(): void
    {
        [$request] = $this->requestForServer(Server::STATUS_SUSPENDED, 'api:beacon.modpacks.operation');

        $this->expectException(ServerStateConflictException::class);

        (new AuthenticateServerAccess())->handle($request, fn (Request $request) => $request);
    }

    /** @return array{Request, Server} */
    private function requestForServer(string $status, string $routeName): array
    {
        $user = User::factory()->make(['id' => 10, 'root_admin' => false]);
        $server = Server::factory()->make([
            'id' => 20,
            'owner_id' => $user->id,
            'status' => $status,
        ]);
        $server->setRelation('node', Node::factory()->make(['maintenance_mode' => false]));
        $server->setRelation('transfer', null);

        $request = Request::create('/api/client/servers/test/beacon/modpacks/operations/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $route = (new Route(['GET'], '/api/client/servers/{server}/beacon/modpacks/operations/{operation}', fn () => null))
            ->name($routeName);
        $route->bind($request);
        $route->setParameter('server', $server);
        $request->setRouteResolver(fn () => $route);

        return [$request, $server];
    }
}
