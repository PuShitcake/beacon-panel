<?php

namespace Pterodactyl\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\VelocityJarIdentity;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VelocityJarIdentityTest extends TestCase
{
    private function jar(string $manifest, int $entries = 1): string
    {
        $path = tempnam(sys_get_temp_dir(), 'velocity-test-');
        $zip = new \ZipArchive();
        try {
            $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            $zip->addFromString('META-INF/MANIFEST.MF', $manifest);
            for ($i = 1; $i < $entries; ++$i) {
                $zip->addFromString('fixture/' . $i, '');
            }
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function inspect(string $body, ?int $size = null, int $status = 200): ?string
    {
        $stream = Utils::streamFor($body);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, ['Content-Length' => (string) strlen($body)], $stream)]))]);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getHttpClient')->andReturn($client);
        try {
            return (new VelocityJarIdentity(new VanillaFileReader($files)))->read((new Server())->forceFill(['uuid' => 'fixture']), '/velocity.jar', $size ?? strlen($body));
        } finally {
            $this->assertFalse($stream->isReadable());
        }
    }

    public function testIdentityHashesEntireActualJarAndClosesStream(): void
    {
        $body = $this->jar("Manifest-Version: 1.0\r\nMain-Class: com.velocitypowered.proxy.Velocity\r\n\r\n");
        $this->assertSame(hash('sha256', $body), $this->inspect($body));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('manifestVersions')]
    public function testInspectsOnlyAnUnambiguousStableManifestProxyVersion(string $metadata, ?string $expected): void
    {
        $body = $this->jar("Main-Class: com.velocitypowered.proxy.Velocity\r\n" . $metadata);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getHttpClient')->andReturn($client);
        $actual = (new VelocityJarIdentity(new VanillaFileReader($files)))->inspect((new Server())->forceFill(['uuid' => 'fixture']), '/velocity.jar', strlen($body));
        $this->assertSame(hash('sha256', $body), $actual['identity']);
        $this->assertSame($expected, $actual['version']);
    }

    public static function manifestVersions(): array
    {
        return [["Implementation-Version: 4.3.1\r\n", '4.3.1'], ["Implementation-Version: 3.4\n", '3.4'],
            ['', null], ["Implementation-Version: latest\n", null], ["Implementation-Version: 4.3.1-SNAPSHOT\n", null],
            ["Implementation-Version: 4.3.1\nImplementation-Version: 4.3.0\n", null],
            ["Implementation-Version: 4.3.1\nimplementation-version: \n", null],
            ["\r\nName: another-entry\r\nImplementation-Version: 4.3.1\r\n", null]];
    }

    public function testDifferentOrCorruptJarsAreNotVelocity(): void
    {
        $this->assertNull($this->inspect($this->jar("Main-Class: example.Other\n")));
        $this->assertNull($this->inspect('not a JAR'));
        $this->assertNull($this->inspect($this->jar(str_repeat('x', 16385))));
    }

    public function testSizeDriftIsRejected(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->inspect('changed', 1000);
    }

    public function testBundledProxyWith25080EntriesIsRecognizedWithoutExtractingDependencies(): void
    {
        $body = $this->jar("Main-Class: com.velocitypowered.proxy.Velocity\r\n", 25080);
        $this->assertSame(hash('sha256', $body), $this->inspect($body));
    }

    public function testExcessiveArchiveEntryCountStillFailsClosed(): void
    {
        $this->assertNull($this->inspect($this->jar("Main-Class: com.velocitypowered.proxy.Velocity\r\n", 32769)));
    }

    public function testRedirectIsRejected(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->inspect('redirect', null, 302);
    }
}
