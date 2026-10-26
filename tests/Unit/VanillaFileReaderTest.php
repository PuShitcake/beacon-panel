<?php

namespace Pterodactyl\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\VanillaJarVersionReader;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VanillaFileReaderTest extends TestCase
{
    private function reader(Response $response): VanillaFileReader
    {
        $client = new Client(['handler' => new MockHandler([$response])]);
        $repository = \Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getHttpClient')->andReturn($client);

        return new VanillaFileReader($repository);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public function testBoundedStreamingClosesBodyAndDoesNotBufferBeyondLimit(): void
    {
        $body = Utils::streamFor('motd=ok');
        $reader = $this->reader(new Response(200, ['Content-Length' => '7'], $body));
        $this->assertSame('motd=ok', $reader->read(new Server(), '/server.properties', 7));
        $this->assertFalse($body->isReadable());
    }

    #[DataProvider('unsafeResponses')]
    public function testRejectsRedirectOversizeAndStreamingOverflow(int $status, array $headers, string $content): void
    {
        $body = Utils::streamFor($content);
        try {
            $this->reader(new Response($status, $headers, $body))->read(new Server(), '/server.properties', 7);
            $this->fail('Expected bounded failure');
        } catch (ConflictHttpException) {
            $this->assertFalse($body->isReadable());
        }
    }

    public static function unsafeResponses(): array
    {
        return [[302, ['Location' => 'https://never-fetch.invalid'], ''], [200, ['Content-Length' => '9999999'], ''], [200, [], '12345678']];
    }

    public function testJarSizeMismatchFailsInsteadOfAdoptingPartialHash(): void
    {
        $reader = new VanillaJarVersionReader($this->reader(new Response(200, [], 'partial')));
        $this->expectException(ConflictHttpException::class);
        $reader->read(new Server(), '/server.jar', 20);
    }
}
