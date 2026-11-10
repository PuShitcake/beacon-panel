<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\MinecraftServiceConfiguration;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MinecraftServiceRuntimeTest extends TestCase
{
    private function server(string $name, string $nest = 'Beacon'): Server
    {
        $egg = (new Egg())->forceFill(['name' => $name])->setRelation('nest', (new Nest())->forceFill(['name' => $nest]));

        return (new Server())->setRelation('egg', $egg);
    }

    public function testAllPreviouslyTestedJavaEggsKeepTheirExistingDriver(): void
    {
        foreach (['Vanilla Minecraft', 'Paper', 'Paper + Geyser + Floodgate', 'Fabric', 'CurseForge Generic'] as $name) {
            $this->assertSame('java', MinecraftServiceConfiguration::driver($this->server($name)));
        }
        $this->assertSame('velocircon', MinecraftServiceConfiguration::driver($this->server('Velocity')));
    }

    public function testBedrockAndUnknownOrOtherNestServersCannotGainJavaServiceSettings(): void
    {
        foreach ([$this->server('Vanilla Bedrock'), $this->server('Unknown'), $this->server('Velocity', 'Minecraft'), $this->server('Paper', 'Minecraft')] as $server) {
            $this->assertNull(MinecraftServiceConfiguration::driver($server));
        }
    }

    public function testVelocityQueryCannotFallThroughToServerProperties(): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->shouldNotReceive('getContent');
        $this->expectException(ConflictHttpException::class);
        (new MinecraftServiceConfiguration($files))->assertSupported($this->server('Velocity'), 'query');
    }
}
