<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VelocityRconDocument;

class VelocityRconDocumentTest extends TestCase
{
    private const YAML = "# retain\nenable: false\nhost: '127.0.0.1'\nport: 25575\npassword: 'PASSWORD'\ncolors: true\npermissions:\n  regex:\n    enable: true\n    regex: '^(?!stop$).*' # retain\n";

    public function testPatchPreservesPermissionsAndOtherLinesAndIsIdempotent(): void
    {
        $values = ['enable-rcon' => true, 'rcon.port' => 25610, 'rcon.password' => 'TEST_ONLY_SAFE'];
        $after = (new VelocityRconDocument(self::YAML))->patch($values);
        $this->assertStringContainsString("colors: true\npermissions:\n  regex:\n    enable: true\n    regex: '^(?!stop$).*' # retain\n", $after);
        $this->assertStringStartsWith("# retain\n", $after);
        $this->assertSame($after, (new VelocityRconDocument($after))->patch($values));
        $this->assertSame(['enable-rcon' => 'true', 'rcon.port' => '25610', 'rcon.password' => 'TEST_ONLY_SAFE', 'rcon.host' => '0.0.0.0'], (new VelocityRconDocument($after))->properties());
    }

    public function testDisableClearsTheSecretWithoutDeletingRequiredPluginKeys(): void
    {
        $after = (new VelocityRconDocument(self::YAML))->patch(['enable-rcon' => false, 'rcon.port' => null, 'rcon.password' => null]);
        $this->assertSame('false', (new VelocityRconDocument($after))->properties()['enable-rcon']);
        $this->assertSame('', (new VelocityRconDocument($after))->properties()['rcon.password']);
        $this->assertStringNotContainsString('PASSWORD', $after);
    }

    public function testCrlfAndQuotedSpecialCharactersRoundTrip(): void
    {
        $content = str_replace("\n", "\r\n", self::YAML);
        $after = (new VelocityRconDocument($content))->patch(['rcon.password' => 'test:#value"only']);
        $this->assertSame('test:#value"only', (new VelocityRconDocument($after))->properties()['rcon.password']);
        $this->assertSame(substr_count($after, "\n"), substr_count($after, "\r\n"));
    }

    #[DataProvider('invalidInputs')]
    public function testUnsafeOrAmbiguousInputIsRejected(string $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VelocityRconDocument($content);
    }

    public static function invalidInputs(): iterable
    {
        yield 'duplicate' => [self::YAML . "enable: true\n"];
        yield 'string boolean' => [str_replace('enable: false', 'enable: "false"', self::YAML)];
        yield 'string port' => [str_replace('port: 25575', 'port: "25575"', self::YAML)];
        yield 'invalid port' => [str_replace('port: 25575', 'port: 70000', self::YAML)];
        yield 'null password' => [str_replace("password: 'PASSWORD'", 'password: null', self::YAML)];
        yield 'anchor' => [str_replace("password: 'PASSWORD'", 'password: &p test', self::YAML) . "other: *p\n"];
        yield 'multiline' => [str_replace("password: 'PASSWORD'", "password: |\n  test", self::YAML)];
        yield 'null byte' => [self::YAML . "\0"];
        yield 'oversize' => [self::YAML . str_repeat('#', 32769)];
        yield 'invalid utf8' => [self::YAML . "\xff"];
        yield 'flow mapping' => ['{enable: false, host: localhost, port: 25575, password: test}'];
        yield 'quoted managed key' => [str_replace('enable:', '"enable":', self::YAML)];
    }

    public function testQueryCannotBeWrittenToThePluginConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VelocityRconDocument(self::YAML))->patch(['enable-query' => true]);
    }
}
