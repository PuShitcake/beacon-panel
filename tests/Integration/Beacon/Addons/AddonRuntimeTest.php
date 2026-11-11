<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Allocation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Beacon\Addons\AddonRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AddonRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public function testBedrockNavigationDoesNotEnableJavaInstallersAndOtherNestsStayUnavailable(): void
    {
        $egg = (new Egg())->forceFill(['name' => 'Vanilla Bedrock'])->setRelation('nest', (new Nest())->forceFill(['name' => 'Beacon']));
        $server = (new Server())->setRelation('egg', $egg);
        $caps = app(ServerSoftwareCapabilityService::class)->resolve($server);
        $this->assertTrue($caps['addons']);
        $this->assertFalse($caps['plugins']);
        $this->assertFalse($caps['mods']);
        $egg->setRelation('nest', (new Nest())->forceFill(['name' => 'Minecraft']));
        $this->assertFalse(app(ServerSoftwareCapabilityService::class)->resolve($server)['addons']);
    }

    public function testRuntimeVersionCacheIsBoundToTheWholeBinaryNotOnlyItsHeaderOrSize(): void
    {
        Cache::setDefaultDriver('array');
        Cache::flush();
        $binary = "\x7fELF\x02\x01" . str_repeat('a', 58);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getDirectory')->andReturn([['name' => 'bedrock_server', 'file' => true, 'symlink' => false, 'size' => 64]]);
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('consume')->andReturnUsing(function ($server, $path, $limit, $callback) use (&$binary) { $callback($binary); });
        $runtime = new class ($files, $reader) extends AddonRuntime {
            public int $probes = 0;
            public ?string $answer = '1.21.100';

            public function assertSupported(Server $server): void
            {
            }

            public function selection(Server $server): string
            {
                return 'trusted-selection';
            }

            protected function probe(Server $server): ?string
            {
                ++$this->probes;

                return $this->answer;
            }
        };
        $server = \Mockery::mock(Server::class)->makePartial();
        $server->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111']);
        $relation = \Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $relation->allows('get')->andReturn(collect());
        $server->allows('variables')->andReturn($relation);
        $first = $runtime->resolve($server);
        $this->assertSame($first, $runtime->resolve($server));
        $this->assertSame(1, $runtime->probes);
        $runtime->answer = null;
        Cache::flush();
        $this->assertSame($first, $runtime->resolve($server, $first));
        $binary = "\x7fELF\x02\x01" . str_repeat('b', 58);
        $this->expectException(ConflictHttpException::class);
        $runtime->resolve($server);
    }

    public function testMinimumEngineRejectsNewerPackWithoutChangingStartup(): void
    {
        $runtime = new AddonRuntime(\Mockery::mock(DaemonFileRepository::class), \Mockery::mock(VanillaFileReader::class));
        $runtime->assertMinimum(['packs' => [['minimum_engine' => [1, 20, 0]]]], '1.21.100');
        $this->expectException(ConflictHttpException::class);
        $runtime->assertMinimum(['packs' => [['minimum_engine' => [1, 26, 0]]]], '1.21.100');
    }

    private function statusRuntime(): AddonRuntime
    {
        return new class (\Mockery::mock(DaemonFileRepository::class), \Mockery::mock(VanillaFileReader::class)) extends AddonRuntime {
            public int $legacyCalls = 0;
            public ?string $legacyAnswer = null;

            public function status(Server $server): ?string
            {
                return parent::probe($server);
            }

            protected function probeRakNet(Server $server): ?string
            {
                ++$this->legacyCalls;

                return $this->legacyAnswer;
            }
        };
    }

    private function statusServer(): Server
    {
        return (new Server())->setRelation('allocation', (new Allocation())->forceFill(['ip' => '192.0.2.20', 'port' => 25566]));
    }

    private static function statusData(): array
    {
        return ['name' => 'Fixture', 'protocol' => 2193, 'version' => '1.26.63', 'level' => 'Bedrock level', 'players' => 0, 'maxPlayers' => 10, 'gameType' => 0];
    }

    public function testNetherNetStatusUsesOnlyFixedNativeAllocationWithoutCredentialsOrJoining(): void
    {
        Http::preventStrayRequests();
        $options = [];
        Http::fake(['http://192.0.2.20:25566/v1/join' => function ($request, $received) use (&$options) {
            $options = $received;

            return Http::response(json_encode(self::statusData()), 200, ['Content-Type' => 'application/json']);
        }]);
        $runtime = $this->statusRuntime();
        $this->assertSame('1.26.63', $runtime->status($this->statusServer()));
        $this->assertSame(0, $runtime->legacyCalls);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://192.0.2.20:25566/v1/join'
            && !$request->hasHeader('Authorization') && !$request->hasHeader('x-api-key') && !$request->hasHeader('Cookie'));
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['stream']);
        $this->assertSame(4, $options['timeout']);
        Http::assertSentCount(1);
    }

    public static function invalidStatusResponses(): array
    {
        return [
            [302, self::statusData(), ['Content-Type' => 'application/json', 'Location' => 'http://127.0.0.1/private']],
            [200, self::statusData(), ['Content-Type' => 'text/html']],
            [200, self::statusData(), ['Content-Type' => 'application/json', 'Content-Length' => '999999']],
            [200, str_repeat('x', 16385), ['Content-Type' => 'application/json']],
            [200, 'invalid JSON', ['Content-Type' => 'application/json']],
            [200, array_replace(self::statusData(), ['version' => 'latest']), ['Content-Type' => 'application/json']],
            [200, array_replace(self::statusData(), ['version' => '1.26.63 injected']), ['Content-Type' => 'application/json']],
            [200, array_replace(self::statusData(), ['protocol' => '2193']), ['Content-Type' => 'application/json']],
            [200, array_replace(self::statusData(), ['gameType' => 999]), ['Content-Type' => 'application/json']],
            [200, array_replace(self::statusData(), ['maxPlayers' => -1]), ['Content-Type' => 'application/json']],
        ];
    }

    #[DataProvider('invalidStatusResponses')]
    public function testInvalidStatusNeverGuessesVersionOrFollowsRedirects(int $code, mixed $body, array $headers): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://192.0.2.20:25566/v1/join' => Http::response(is_array($body) ? json_encode($body) : $body, $code, $headers)]);
        $runtime = $this->statusRuntime();
        $this->assertNull($runtime->status($this->statusServer()));
        $this->assertSame(1, $runtime->legacyCalls);
        Http::assertSentCount(1);
    }

    public function testLegacyStatusRemainsAvailableWhenNetherNetEndpointIsNotSupported(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 404)]);
        $runtime = $this->statusRuntime();
        $runtime->legacyAnswer = '1.21.100';
        $this->assertSame('1.21.100', $runtime->status($this->statusServer()));
    }

    public function testRakNetValidatesFramingAndRejectsSemicolonShiftedMotds(): void
    {
        $runtime = new class (\Mockery::mock(DaemonFileRepository::class), \Mockery::mock(VanillaFileReader::class)) extends AddonRuntime {
            public function parse(string $packet, string $nonce, string $magic): ?string
            {
                return $this->rakNetVersion($packet, $nonce, $magic);
            }
        };
        $nonce = str_repeat('n', 8);
        $magic = hex2bin('00ffff00fefefefefdfdfdfd12345678');
        $frame = fn ($status) => "\x1c" . $nonce . str_repeat('g', 8) . $magic . pack('n', strlen($status)) . $status;
        $status = 'MCPE;Fixture;975;1.21.100;0;10;12345;World;Survival;1;19132;19133;';
        $this->assertSame('1.21.100', $runtime->parse($frame($status), $nonce, $magic));
        $this->assertNull($runtime->parse($frame($status), str_repeat('x', 8), $magic));
        $this->assertNull($runtime->parse(substr($frame($status), 0, -1), $nonce, $magic));
        $this->assertNull($runtime->parse($frame(str_replace('Fixture', 'Fixture;999;1.99.99', $status)), $nonce, $magic));
    }
}
