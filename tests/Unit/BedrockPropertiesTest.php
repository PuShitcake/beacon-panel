<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Beacon\Minecraft\BedrockPropertyPolicy;
use Pterodactyl\Beacon\Minecraft\BedrockPropertiesDocument;
use Pterodactyl\Beacon\Minecraft\BedrockConfigurationService;
use Pterodactyl\Beacon\Minecraft\MinecraftConfigurationAdapter;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeContentRuntimeResolver;

class BedrockPropertiesTest extends TestCase
{
    public function testLiteralUtf8IsNotDecodedOrEscapedLikeJava(): void
    {
        $original = "# keep\r\nserver-name=ไทย \\u0041=literal\r\nlevel-name=Bedrock level\r\nunknown=keep\\\r\n# next\r\n";
        $doc = new BedrockPropertiesDocument($original);
        $this->assertSame('ไทย \\u0041=literal', $doc->values()['server-name']);
        $this->assertSame("# keep\r\nserver-name=ใหม่\r\nlevel-name=Bedrock level\r\nunknown=keep\\\r\n# next\r\n", $doc->patch(['server-name' => 'ใหม่']));
        $this->assertSame($original, $doc->patch([]));
    }

    public function testNoMissingDefaultsOrJavaOrPrivateFieldsAreExposed(): void
    {
        $values = ['server-name' => 'Name', 'tick-distance' => '4', 'level-name' => 'Bedrock level',
            'server-port' => '19132', 'script-debugger-passcode' => 'PRIVATE_FIXTURE', 'motd' => 'Java stale', 'resource-pack' => 'PRIVATE_URL', 'level-type' => 'flat'];
        $policy = new BedrockPropertyPolicy();
        $definitions = $policy->definitions('bedrock-file', $values);
        $this->assertSame(['server-name', 'tick-distance', 'level-name'], array_keys($definitions));
        $this->assertSame(['server-name' => 'Name', 'tick-distance' => 4, 'level-name' => 'Bedrock level'], $policy->present($values, $definitions));
        $this->assertSame([], $policy->definitions('1.20.1', $values));
    }

    public function testMissingKeyCannotBePatched(): void
    {
        $this->expectException(ConflictHttpException::class);
        (new BedrockPropertiesDocument('server-name=Test'))->patch(['online-mode' => 'false']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDocuments')]
    public function testUnsafeDocumentIsRejected(string $body): void
    {
        $this->expectException(ConflictHttpException::class);
        new BedrockPropertiesDocument($body);
    }

    public static function invalidDocuments(): array
    {
        return [["server-name=a\nserver-name=b\n"], ["server-name=\xff"], ["server-name=a\0b"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function testInvalidValuesAreNotCoerced(string $key, mixed $value): void
    {
        $policy = new BedrockPropertyPolicy();
        $defs = $policy->definitions('bedrock-file', [$key => 'old']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $policy->validate([$key => $value], $defs);
    }

    public static function invalidValues(): array
    {
        return [['server-name', 'name;spoof'], ['server-name', "line\nnext"], ['server-name', null],
            ['server-name', 'ไทย'], ['server-name', 'a"b'], ['server-name', 'a\\b'], ['server-name', ' leading'], ['server-name', 'trailing '], ['server-name', '${private}'],
            ['level-seed', 'ไทย'], ['level-seed', 'a"b'], ['level-seed', 'a\\b'], ['level-seed', '${private}'],
            ['level-name', '../map'], ['level-name', 'a/b'], ['level-name', 'a\\b'], ['level-name', '.hidden'],
            ['level-name', 'CON'], ['level-name', 'map '], ['level-name', ' map'], ['level-name', ''],
            ['tick-distance', 3], ['tick-distance', 13], ['tick-distance', 4.5], ['tick-distance', ''],
            ['view-distance', 4], ['view-distance', 97], ['gamemode', 'spectator'], ['gamemode', '1'],
            ['allow-cheats', 'false'], ['server-port', 20000], ['default-player-permission-level', 'operator'],
            ['resource-pack', 'https://example.com/pack.zip'], ['script-debugger-passcode', 'new']];
    }

    public function testDocumentValuesUseBedrockTypesAndSafeNames(): void
    {
        $policy = new BedrockPropertyPolicy();
        $values = ['server-name' => '', 'level-name' => 'Bedrock level', 'tick-distance' => 4, 'allow-cheats' => false, 'level-seed' => '1234567890123456789'];
        $this->assertSame(['server-name' => '', 'level-name' => 'Bedrock level', 'tick-distance' => '4', 'allow-cheats' => 'false', 'level-seed' => '1234567890123456789'], $policy->validate($values, $policy->definitions('bedrock-file', $values)));
        $this->assertSame(['gamemode' => '1'], $policy->present(['gamemode' => '1'], $policy->definitions('bedrock-file', ['gamemode' => '1'])));
    }

    public function testOnlyExactBeaconBedrockIsRoutedAndDoesNotQueryStaleModpackMetadata(): void
    {
        $server = (new Server())->forceFill(['id' => 1]);
        $egg = (new Egg())->forceFill(['name' => 'Vanilla Bedrock']);
        $nest = (new Nest())->forceFill(['name' => 'Beacon']);
        $egg->setRelation('nest', $nest);
        $server->setRelation('egg', $egg);
        $native = \Mockery::mock(NativeCurseForgeContentRuntimeResolver::class);
        $native->shouldNotReceive('resolve');
        $cap = (new ServerSoftwareCapabilityService($native))->resolve($server);
        $this->assertSame('bedrock', $cap['software']);
        $this->assertTrue($cap['configuration']);
        foreach (['plugins', 'mods', 'modpacks'] as $key) {
            $this->assertFalse($cap[$key]);
        }
        $this->assertSame(BedrockConfigurationService::class, MinecraftConfigurationAdapter::serviceClass($server));
        $nest->forceFill(['name' => 'Minecraft']);
        $this->assertNull(MinecraftConfigurationAdapter::serviceClass($server));
        $this->assertFalse((new ServerSoftwareCapabilityService($native))->resolve($server)['configuration']);
        $nest->forceFill(['name' => 'Beacon']);
        $egg->forceFill(['name' => 'Vanilla Bedrock Custom']);
        $this->assertNull(MinecraftConfigurationAdapter::serviceClass($server));
    }
}
