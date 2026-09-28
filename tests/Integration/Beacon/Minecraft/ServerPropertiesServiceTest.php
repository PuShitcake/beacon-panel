<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\ServerPropertiesService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ServerPropertiesServiceTest extends IntegrationTestCase
{
    public function testItOnlyUpdatesAllowlistedPropertiesAndPreservesUnknownLines(): void
    {
        $server = Server::factory()->make();
        $original = "# generated\nmotd=Old name\nmax-players=20\nunknown-setting=keep\n";
        $repository = \Mockery::mock(DaemonFileRepository::class);
        $repository->expects('setServer')->twice()->with($server)->andReturnSelf();
        $repository->expects('getContent')->andReturn($original);
        $repository->expects('putContent')->with('/server.properties', \Mockery::on(function (string $content) {
            return str_contains($content, 'motd=Beacon Server')
                && str_contains($content, 'max-players=50')
                && str_contains($content, 'unknown-setting=keep');
        }));

        $result = (new ServerPropertiesService($repository))->update($server, hash('sha256', $original), [
            'motd' => 'Beacon Server',
            'max-players' => 50,
        ]);

        $this->assertSame('Beacon Server', $result['properties']['motd']);
        $this->assertSame(50, $result['properties']['max-players']);
    }

    public function testItRejectsAStaleHash(): void
    {
        $server = Server::factory()->make();
        $repository = \Mockery::mock(DaemonFileRepository::class);
        $repository->expects('setServer')->twice()->with($server)->andReturnSelf();
        $repository->expects('getContent')->andReturn('motd=current');

        $this->expectException(ConflictHttpException::class);
        (new ServerPropertiesService($repository))->update($server, str_repeat('a', 64), ['motd' => 'new']);
    }
}
