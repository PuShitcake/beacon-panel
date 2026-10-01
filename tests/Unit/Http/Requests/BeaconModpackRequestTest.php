<?php

namespace Pterodactyl\Tests\Unit\Http\Requests;

use Pterodactyl\Models\User;
use Illuminate\Routing\Route;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModpackRequest;

class BeaconModpackRequestTest extends TestCase
{
    public function testOwnerAndRootAdminAreAuthorizedButSubusersAreDenied(): void
    {
        $server = (new Server())->forceFill(['id' => 10, 'owner_id' => 20]);

        $this->assertTrue($this->requestFor((new User())->forceFill(['id' => 20, 'root_admin' => false]), $server)->authorize());
        $this->assertTrue($this->requestFor((new User())->forceFill(['id' => 30, 'root_admin' => true]), $server)->authorize());
        $this->assertFalse($this->requestFor((new User())->forceFill(['id' => 30, 'root_admin' => false]), $server)->authorize());
    }

    public function testSearchQueryMayBeEmptyToBrowsePopularModpacks(): void
    {
        $request = new BeaconModpackRequest();

        $this->assertFalse(validator(['provider' => 'curseforge', 'query' => null], $request->rules())->fails());
        $this->assertFalse(validator(['provider' => 'curseforge'], $request->rules())->fails());
    }

    private function requestFor(User $user, Server $server): BeaconModpackRequest
    {
        $route = \Mockery::mock(Route::class);
        $route->expects('parameter')->with('server')->andReturn($server);
        $request = BeaconModpackRequest::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
