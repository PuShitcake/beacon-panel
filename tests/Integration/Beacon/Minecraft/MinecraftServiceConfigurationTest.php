<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceConfiguration;

class MinecraftServiceConfigurationTest extends TestCase
{
    public function testItUpdatesOnlyManagedServicePropertiesAndPreservesOtherLines(): void
    {
        $server = Server::factory()->make();
        $original = "# generated\r\nenable-rcon=false\r\nmotd=Keep me\r\n";
        $repository = \Mockery::mock(DaemonFileRepository::class);
        $repository->expects('setServer')->twice()->with($server)->andReturnSelf();
        $repository->expects('getContent')->andReturn($original);
        $repository->expects('putContent')->with('/server.properties', \Mockery::on(function (string $content) {
            return str_contains($content, "enable-rcon=true\r\n")
                && str_contains($content, "rcon.port=25575\r\n")
                && str_contains($content, 'rcon.password=generated-secret')
                && str_contains($content, 'motd=Keep me');
        }));

        $snapshot = (new MinecraftServiceConfiguration($repository))->apply($server, [
            'enable-rcon' => true,
            'rcon.port' => 25575,
            'rcon.password' => 'generated-secret',
        ]);

        $this->assertSame($original, $snapshot);
    }

    public function testItRemovesPortAndPasswordWhenRconIsDisabled(): void
    {
        $server = Server::factory()->make();
        $original = "enable-rcon=true\nrcon.port=25575\nrcon.password=secret\nmotd=Keep me\n";
        $repository = \Mockery::mock(DaemonFileRepository::class);
        $repository->expects('setServer')->twice()->with($server)->andReturnSelf();
        $repository->expects('getContent')->andReturn($original);
        $repository->expects('putContent')->with('/server.properties', \Mockery::on(function (string $content) {
            return str_contains($content, 'enable-rcon=false')
                && !str_contains($content, 'rcon.port=')
                && !str_contains($content, 'rcon.password=')
                && str_contains($content, 'motd=Keep me');
        }));

        (new MinecraftServiceConfiguration($repository))->apply($server, [
            'enable-rcon' => false,
            'rcon.port' => null,
            'rcon.password' => null,
        ]);
    }

    public function testItRejectsPropertiesOutsideItsAllowlist(): void
    {
        $server = Server::factory()->make();
        $repository = \Mockery::mock(DaemonFileRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        (new MinecraftServiceConfiguration($repository))->apply($server, ['server-port' => 25566]);
    }
}
