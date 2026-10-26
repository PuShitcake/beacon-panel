<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\VanillaPropertyPolicy;
use Pterodactyl\Beacon\Minecraft\JavaPropertiesDocument;
use Pterodactyl\Beacon\Minecraft\VanillaJarVersionReader;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VanillaPropertiesTest extends TestCase
{
    public function testOldVanillaDoesNotOfferAViewRadiusThatCrashesItsChunkManager(): void
    {
        // The official 1.2.5 JAR's chunk-manager constructor rejects radii >15.
        $definitions = (new VanillaPropertyPolicy())->definitions('1.2.5', ['view-distance' => '10']);
        $this->assertSame(15, $definitions['view-distance']['max']);
        $schema = new VanillaPropertyPolicy();
        $this->assertSame(15, $schema->definitions('1.7.2', ['view-distance' => '10'])['view-distance']['max']);
        $this->assertSame(20, $schema->definitions('1.7.3', ['view-distance' => '10'])['view-distance']['max']);
        $this->assertSame(32, $schema->definitions('1.8', ['view-distance' => '10'])['view-distance']['max']);
    }

    public function testLosslessParsingEscapesContinuationAndEffectiveDuplicates(): void
    {
        $raw = "# untouched\r\n!comment\r\nmotd : Hello\\u0020World\\\r\n  continued\r\nmax-players 20\r\nmotd=Last\\uD83D\\uDE00\r\nrcon.password=PRIVATE_FIXTURE\\=value\r\nunknown\\ key:value\r\n";
        $document = new JavaPropertiesDocument($raw, true);
        $this->assertSame('Last😀', $document->values()['motd']);
        $this->assertSame('20', $document->values()['max-players']);
        $this->assertSame('value', $document->values()['unknown key']);
        $this->assertSame($raw, $document->patch([]));
        $changed = $document->patch(['motd' => '  สวัสดี😀=yes']);
        $this->assertStringContainsString("motd : Hello\\u0020World\\\r\n  continued\r\n", $changed);
        $this->assertStringContainsString('rcon.password=PRIVATE_FIXTURE\\=value', $changed);
        $this->assertSame('  สวัสดี😀=yes', (new JavaPropertiesDocument($changed, true))->values()['motd']);
        $this->assertSame('  สวัสดี😀=yes', (new JavaPropertiesDocument($changed, false))->values()['motd']);
    }

    public function testLatin1Utf8AndModernInvalidUtf8Fallback(): void
    {
        $this->assertSame('café', (new JavaPropertiesDocument("motd=caf\xe9\n", false))->values()['motd']);
        $this->assertSame('café', (new JavaPropertiesDocument("motd=caf\xe9\n", true))->values()['motd']);
        $this->assertSame('ภาษาไทย', (new JavaPropertiesDocument("motd=ภาษาไทย\n", true))->values()['motd']);
        $this->assertSame('Hello World', (new JavaPropertiesDocument('motd=Hello\u0020World', true))->values()['motd']);
    }

    #[DataProvider('malformed')]
    public function testMalformedInputIsNotSilentlyRewritten(string $content): void
    {
        $this->expectException(ConflictHttpException::class);
        new JavaPropertiesDocument($content, true);
    }

    public static function malformed(): array
    {
        return [['motd=\uXX00'], ['motd=\uD800'], ['motd=\uDC00'], ['motd=unfinished\\']];
    }

    public function testPatchingCannotAddAMissingKey(): void
    {
        $this->expectException(ConflictHttpException::class);
        (new JavaPropertiesDocument('motd=hello', true))->patch(['pvp' => 'true']);
    }

    #[DataProvider('versions')]
    public function testActualGeneratedFilesAreFilteredWithoutSecrets(string $version): void
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/Beacon/Vanilla/' . $version . '.properties');
        $document = new JavaPropertiesDocument($raw, version_compare($version, '1.20', '>='));
        $schema = new VanillaPropertyPolicy();
        $definitions = $schema->definitions($version, $document->values());
        $presented = $schema->present($document->values(), $definitions);
        $evidence = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Beacon/Vanilla/evidence.json'), true, 512, JSON_THROW_ON_ERROR)[$version];
        $index = json_decode(file_get_contents(__DIR__ . '/../../app/Beacon/Minecraft/Vanilla/releases.json'), true, 512, JSON_THROW_ON_ERROR)['releases'][$version];
        $this->assertSame($index['sha1'], $evidence['sha1']);
        $this->assertSame($index['size'], $evidence['size']);
        $this->assertSame($evidence['fixture_sha256'], hash('sha256', $raw));
        foreach ($document->values() as $key => $value) {
            if (preg_match('/password|secret|passcode/i', $key)) {
                $this->assertSame('', $value, 'Generated credentials must be cleared from public fixtures.');
            }
        }
        $this->assertSame($raw, $document->patch([]));
        $this->assertArrayHasKey('motd', $definitions);
        $this->assertArrayNotHasKey('rcon.password', $presented);
        $this->assertArrayNotHasKey('management-server-secret', $presented);
        $this->assertSame(version_compare($version, '1.18', '>='), isset($definitions['simulation-distance']));
        $this->assertSame(version_compare($version, '1.21.9', '<'), isset($definitions['pvp']));
        $schema->validate($presented, $definitions);
        $known = array_fill_keys(array_merge(array_keys($document->values()), $evidence['getter_only_properties']), 'true');
        $withStaleKey = $schema->definitions($version, $known + ['server-ip' => 'private', 'rcon.password' => 'private']);
        $this->assertArrayNotHasKey('server-ip', $withStaleKey);
        $this->assertArrayNotHasKey('rcon.password', $withStaleKey);
        foreach ($definitions as $key => $definition) {
            $change = match ($definition['type']) {
                'integer' => $definition['min'], 'boolean' => !$presented[$key],
                'select' => $definition['options'][0], default => match ($definition['format'] ?? null) {
                    'world-folder' => 'uploaded-map', 'resource-url' => 'https://example.com/pack.zip', 'sha1' => str_repeat('a', 40), default => '  Unicode ภาษาไทย  ',
                },
            };
            $serialized = $schema->validate([$key => $change], $definitions);
            $patched = $document->patch($serialized);
            $values = (new JavaPropertiesDocument($patched, version_compare($version, '1.20', '>=')))->values();
            $expected = $document->values();
            $expected[$key] = $serialized[$key];
            $this->assertSame($expected, $values, $version . ':' . $key);
            if ($definition['type'] === 'integer') {
                $this->assertSame((string) $definition['max'], $schema->validate([$key => $definition['max']], $definitions)[$key]);
                foreach ([$definition['min'] - 1, $definition['max'] + 1] as $invalid) {
                    try {
                        $schema->validate([$key => $invalid], $definitions);
                        $this->fail('An out-of-range value was accepted: ' . $version . ':' . $key);
                    } catch (ValidationException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
            if ($definition['type'] === 'select') {
                foreach ($definition['options'] as $option) {
                    $serialized = $schema->validate([$key => $option], $definitions);
                    $parsed = (new JavaPropertiesDocument($document->patch($serialized), version_compare($version, '1.20', '>=')))->values();
                    $this->assertSame($option, $parsed[$key]);
                }
            }
        }
    }

    public static function versions(): array
    {
        $index = json_decode(file_get_contents(__DIR__ . '/../../app/Beacon/Minecraft/Vanilla/releases.json'), true, 512, JSON_THROW_ON_ERROR);

        return array_map(fn ($version) => [$version], array_keys($index['releases']));
    }

    public function testNoReleaseTemplatesAreRequiredForFieldDiscovery(): void
    {
        $policy = new VanillaPropertyPolicy();
        $this->assertSame([], $policy->definitions('1.20.1', []));
        $actual = ['max-players' => '37', 'private-field' => 'secret', 'motd' => 'Custom'];
        $definitions = $policy->definitions('1.20.1', $actual);
        $this->assertSame(['max-players', 'motd'], array_keys($definitions));
        $this->assertSame(['max-players' => 37, 'motd' => 'Custom'], $policy->present($actual, $definitions));
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Beacon/Minecraft/Vanilla/properties.json');
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Beacon/Minecraft/VanillaPropertiesSchema.php');
    }

    public function testUnreviewedReleaseCannotAdoptFamiliarKeys(): void
    {
        $this->assertSame([], (new VanillaPropertyPolicy())->definitions('99.1', ['motd' => 'Hello', 'pvp' => 'true']));
    }

    #[DataProvider('propertyBoundaries')]
    public function testActualGetterBoundariesRejectStaleKeys(string $version, string $key, bool $supported): void
    {
        $definitions = (new VanillaPropertyPolicy())->definitions($version, [$key => 'true']);
        $this->assertSame($supported, isset($definitions[$key]));
    }

    public static function propertyBoundaries(): array
    {
        return [
            ['1.2.5', 'hardcore', false], ['1.3.1', 'hardcore', true],
            ['1.3.2', 'spawn-protection', false], ['1.4.2', 'spawn-protection', true],
            ['1.5.1', 'force-gamemode', false], ['1.5.2', 'force-gamemode', true],
            ['1.6.2', 'player-idle-timeout', false], ['1.6.4', 'player-idle-timeout', true],
            ['1.12.2', 'enforce-whitelist', false], ['1.13', 'enforce-whitelist', true],
            ['1.15.2', 'enable-status', false], ['1.16', 'enable-status', true],
            ['1.17.1', 'simulation-distance', false], ['1.18', 'simulation-distance', true],
            ['1.21.8', 'pvp', true], ['1.21.9', 'pvp', false],
            ['1.21.9', 'allow-nether', false], ['1.21.9', 'spawn-monsters', false],
        ];
    }

    public function testLegacyModesAndRemovedStaleKeys(): void
    {
        $schema = new VanillaPropertyPolicy();
        $definitions = $schema->definitions('1.12.2', ['gamemode' => '1', 'difficulty' => '2', 'simulation-distance' => '10']);
        $this->assertSame(['0', '1', '2', '3'], $definitions['gamemode']['options']);
        $this->assertSame('creative', $definitions['gamemode']['option_labels']['1']);
        $this->assertArrayNotHasKey('simulation-distance', $definitions);
        $this->assertArrayNotHasKey('enable-status', $schema->definitions('1.12.2', ['enable-status' => 'true']));
        $this->assertSame(['0', '1'], $schema->definitions('1.2.5', ['gamemode' => '1'])['gamemode']['options']);
        $this->assertArrayNotHasKey('pvp', $schema->definitions('1.21.9', ['pvp' => 'true']));
        $this->assertSame([], $schema->definitions('26.3', ['private-unrecognized-field' => 'never return']));
    }

    #[DataProvider('invalidChanges')]
    public function testRejectsInvalidUnknownAndSensitiveSettings(array $changes): void
    {
        $schema = new VanillaPropertyPolicy();
        $definitions = $schema->definitions('1.20.1', ['motd' => '', 'max-players' => '20', 'pvp' => 'true']);
        $this->expectException(ValidationException::class);
        $schema->validate($changes, $definitions);
    }

    public static function invalidChanges(): array
    {
        return [[['max-players' => '']], [['max-players' => '0']], [['max-players' => 1.5]], [['pvp' => 'false']],
            [['rcon.password' => 'never']], [['motd' => "inject\nserver-port=1"]], [['motd' => str_repeat('a', 121)]], [['simulation-distance' => 5]]];
    }

    public function testInvalidValuesRemainInvalidInsteadOfFalseOrZero(): void
    {
        $schema = new VanillaPropertyPolicy();
        $values = ['pvp' => 'invalid', 'max-players' => '', 'motd' => ''];
        $this->assertEquals($values, $schema->present($values, $schema->definitions('1.20.1', $values)));
        $this->assertSame(['motd' => ''], $schema->validate(['motd' => ''], $schema->definitions('1.20.1', $values)));
    }

    public function testOfficialIndexMatchesKnownJarsButNotModifiedBytesOrSize(): void
    {
        $reader = new VanillaJarVersionReader($this->createStub(VanillaFileReader::class));
        $this->assertSame('1.20.1', $reader->identify('84194a2f286ef7c14ed7ce0090dba59902951553', 47791053));
        $this->assertSame('26.3', $reader->identify('33680f5f2ac32864d6d7cf5e56a705fdb3e05f4c', 62294556));
        $this->assertNull($reader->identify(str_repeat('0', 40), 47791053));
        $this->assertNull($reader->identify('84194a2f286ef7c14ed7ce0090dba59902951553', 47791054));
    }

    #[DataProvider('unsafeWorldValues')]
    public function testWorldAndResourceValuesFailClosed(string $key, string $value): void
    {
        $policy = new VanillaPropertyPolicy();
        $definitions = $policy->definitions('1.20.1', [$key => '']);
        $this->expectException(ValidationException::class);
        $policy->validate([$key => $value], $definitions);
    }

    public static function unsafeWorldValues(): array
    {
        return [['level-name', '../outside'], ['level-name', '/world'], ['level-name', 'map/world'], ['level-name', 'map\\world'],
            ['level-name', '.beacon'], ['level-name', 'plugins'], ['level-name', 'world.'], ['level-name', 'CON'],
            ['level-name', 'uploaded map'], ['level-name', ''], ['level-seed', "seed\nserver-port=1"],
            ['resource-pack', 'http://example.com/pack.zip'], ['resource-pack', 'https://user:pass@example.com/a'],
            ['resource-pack', 'https://127.0.0.1/a'], ['resource-pack', 'https://host.local/a'], ['resource-pack', 'file:///a'],
            ['resource-pack', 'https://127.1/a'], ['resource-pack', 'https://0x7f.0.0.1/a'],
            ['resource-pack', 'https://example.com:8080/a'], ['resource-pack', 'https://example.com/a#fragment'],
            ['resource-pack-sha1', 'invalid'], ['level-type', 'unverified:custom']];
    }

    public function testGeneratorChoicesFollowRuntimeAndExistingFile(): void
    {
        $policy = new VanillaPropertyPolicy();
        $legacy = $policy->definitions('1.2.5', ['level-type' => 'DEFAULT']);
        $this->assertSame(['default', 'flat'], $legacy['level-type']['options']);
        $this->assertSame('default', $policy->present(['level-type' => 'DEFAULT'], $legacy)['level-type']);
        $this->assertSame(['default', 'flat', 'largebiomes', 'amplified'], $policy->definitions('1.16.5', ['level-type' => 'default'])['level-type']['options']);
        $this->assertContains('minecraft:normal', $policy->definitions('1.20.1', ['level-type' => 'minecraft:normal'])['level-type']['options']);
        $this->assertArrayNotHasKey('level-seed', $policy->definitions('1.20.1', ['motd' => 'hello']));
        $this->assertSame([], $policy->definitions('1.2.5', ['resource-pack' => '', 'require-resource-pack' => 'false']));
    }
}
