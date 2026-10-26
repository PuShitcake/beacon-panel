<?php

namespace Pterodactyl\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use Pterodactyl\Beacon\Minecraft\BedrockRuntimeReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BedrockRuntimeReaderTest extends TestCase
{
    private function inspect(string $body, int $length, int $status = 200, ?int $headerLength = null): ?array
    {
        $stream = Utils::streamFor($body);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, ['Content-Length' => (string) ($headerLength ?? $length)], $stream)]))]);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getHttpClient')->andReturn($client);
        try {
            return (new BedrockRuntimeReader($files))->inspect((new Server())->forceFill(['uuid' => 'fixture']), '/bedrock_server', $length);
        } finally {
            $this->assertFalse($stream->isReadable(), 'Binary stream must close on success and failure');
        }
    }

    public function testLargeBodyIsNeverReadBeyondHeaderOrInterpretedAsAVersion(): void
    {
        $header = "\x7fELF\x02\x01" . str_repeat('x', 58);
        $position = 0;
        $stream = new \GuzzleHttp\Psr7\PumpStream(function ($length) use (&$position, $header) {
            $this->assertLessThanOrEqual(64 - $position, $length);
            $chunk = substr($header, $position, $length);
            $position += strlen($chunk);

            return $chunk;
        });
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getHttpClient')->andReturn(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Length' => '256133808'], $stream)]))]));
        $runtime = (new BedrockRuntimeReader($files))->inspect((new Server())->forceFill(['uuid' => 'fixture']), '/bedrock_server', 256133808);
        $this->assertSame(['version' => 'bedrock-file', 'identity' => hash('sha256', $header . ':256133808')], $runtime);
        $this->assertSame(64, $position);
    }

    public function testSizeDriftCannotPass(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->inspect(str_repeat('x', 64), 100, 200, 99);
    }

    public function testRedirectCannotPass(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->inspect(str_repeat('x', 64), 100, 302);
    }

    public function testWrongBinaryAndShortHeaderAreUnavailable(): void
    {
        $this->assertNull($this->inspect(str_repeat('x', 64), 100));
        $this->assertNull($this->inspect("\x7fELF\x02\x01", 100));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeInputs')]
    public function testUnsafeInputsNeverReachWings(string $path, int $size): void
    {
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->shouldNotReceive('getHttpClient');
        $this->assertNull((new BedrockRuntimeReader($files))->inspect(new Server(), $path, $size));
    }

    public static function unsafeInputs(): array
    {
        return [['/custom', 100], ['/../bedrock_server', 100], ['/bedrock_server', 0], ['/bedrock_server', 536870913]];
    }
}
