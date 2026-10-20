<?php

namespace Pterodactyl\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Pterodactyl\Models\Node;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;

class BeaconModFileHashTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function repository(Response $response): DaemonFileRepository
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response])), 'base_uri' => 'http://wings.test']);
        $repository = \Mockery::mock(DaemonFileRepository::class, [app()])->makePartial();
        $repository->setServer((new Server())->forceFill(['uuid' => 'fixture-server'])->setRelation('node', new Node()));
        $repository->expects('getHttpClient')->andReturn($client);

        return $repository;
    }

    public function testItHashesFileContentsIncrementallyAndClosesTheStream(): void
    {
        $content = str_repeat('mod-fixture', 150000);
        $response = new Response(200, [], $content);
        $result = $this->repository($response)->getFileHashes('/.beacon/stage/mod.jar', strlen($content));
        $this->assertSame(strlen($content), $result['size']);
        $this->assertSame(hash('sha1', $content), $result['sha1']);
        $this->assertSame(hash('sha512', $content), $result['sha512']);
        $this->assertFalse($response->getBody()->isReadable());
    }

    public static function oversizedResponses(): array
    {
        return [[['Content-Length' => '1000']], [[]]];
    }

    #[DataProvider('oversizedResponses')]
    public function testItBoundsReadsWithAndWithoutContentLength(array $headers): void
    {
        $response = new Response(200, $headers, str_repeat('x', 1000));
        try {
            $this->repository($response)->getFileHashes('/mods/mod.jar', 10);
            $this->fail('Oversized contents must not be hashed as a verified file.');
        } catch (FileSizeTooLargeException) {
            $this->assertFalse($response->getBody()->isReadable());
        }
    }

    public function testItDoesNotFollowWingsRedirectsWhileReadingFiles(): void
    {
        $response = new Response(302, ['Location' => 'https://external.invalid/'], 'redirect');
        $this->expectException(\RuntimeException::class);
        $this->repository($response)->getFileHashes('/mods/mod.jar', 100);
    }
}
