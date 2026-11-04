<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VelocityTomlDocument;

class VelocityTomlDocumentTest extends TestCase
{
    public function testLosslessPatchPreservesUnknownRoutingSecretsCommentsAndCrlf(): void
    {
        $source = "# untouched\r\nconfig-version = \"2.7\"\r\nmotd = 'Hello # literal' # comment\r\nbind = \"0.0.0.0:25565\"\r\nforwarding-secret-file = \"forwarding.secret\"\r\n[servers]\r\nlobby = \"localhost:25566\"\r\ntry = [\r\n 'lobby', # keep\r\n]\r\n[forced-hosts]\r\n\"play.example.test\" = [\"lobby\"]\r\n[advanced]\r\ncompression-level = -1\r\n";
        $document = new VelocityTomlDocument($source);
        $this->assertSame(['lobby'], $document->values()['servers.try']);
        $this->assertSame(['lobby'], $document->values()['forced-hosts.play.example.test']);
        $updated = $document->patch(['motd' => 'ทดสอบ "quoted" \\ literal', 'advanced.compression-level' => 4]);
        $this->assertSame(str_replace(["'Hello # literal'", 'compression-level = -1'], ['"ทดสอบ \\"quoted\\" \\\\ literal"', 'compression-level = 4'], $source), $updated);
        $this->assertSame('ทดสอบ "quoted" \\ literal', (new VelocityTomlDocument($updated))->values()['motd']);
    }

    public function testEscapedKeyIsCanonicalAndDuplicatesFailClosed(): void
    {
        $this->assertSame(['motd' => 'x'], (new VelocityTomlDocument('"m\\u006ftd" = "x"'))->values());
        $this->expectException(\InvalidArgumentException::class);
        new VelocityTomlDocument("motd='a'\n\"m\\u006ftd\"='b'");
    }

    #[DataProvider('invalid')]
    public function testUnsupportedOrAmbiguousDocumentsFailClosed(string $source): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VelocityTomlDocument($source);
    }

    public static function invalid(): array
    {
        return array_map(fn ($s) => [$s], [
            "motd='''\nadvanced.compression-level=8\n'''", 'motd="unterminated', 'motd="bad\\q"',
            'motd="bad\\uD800"', "motd='a' garbage", 'x={motd="fake"}', 'x=[[1]]', 'x=[1 2]',
            "[advanced]\ncompression-level=1\n[advanced]\ncompression-level=2", 'advanced.compression-level=2',
            '"advanced.compression-level"=2', '["advanced.other"]', '[[advanced]]', 'x=[', "x='a'\nx='b'",
            "advanced='x'\n[advanced]", "[advanced]\nx=1\nroot=2\n[servers]\nx=3\n[advanced]", 'x=012',
            "motd=\"bad\x00\"", 'x=["a",', 'motd="""multi"""',
        ]);
    }
}
