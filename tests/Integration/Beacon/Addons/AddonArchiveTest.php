<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Beacon\Addons\AddonArchive;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Beacon\Addons\AddonWorldDocument;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AddonArchiveTest extends TestCase
{
    private array $temporary = [];

    public static function manifest(string $id = '11111111-1111-4111-8111-111111111111', string $type = 'data'): array
    {
        return ['format_version' => 2, 'header' => ['uuid' => $id, 'name' => 'Fixture add-on', 'version' => [1, 0, 0], 'min_engine_version' => [1, 20, 0]],
            'modules' => [['uuid' => '22222222-2222-4222-8222-222222222222', 'version' => [1, 0, 0], 'type' => $type]]];
    }

    private function zip(array $files): string
    {
        $file = tempnam(sys_get_temp_dir(), 'beacon-addon-test-');
        $this->temporary[] = $file;
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testBehaviorAndResourcePacksAreInspectedWithoutExecutingContent(): void
    {
        $bp = self::manifest();
        $rp = self::manifest('33333333-3333-4333-8333-333333333333', 'resources');
        $rp['modules'][0]['uuid'] = '44444444-4444-4444-8444-444444444444';
        $bp['dependencies'] = [['uuid' => $rp['header']['uuid'], 'version' => [1, 0, 0]]];
        $input = $this->zip(['BP/manifest.json' => json_encode($bp), 'BP/functions/test.mcfunction' => 'say fixture', 'RP/manifest.json' => json_encode($rp), 'RP/textures/test.png' => 'not executed']);
        $output = tempnam(sys_get_temp_dir(), 'beacon-addon-clean-');
        $this->temporary[] = $output;
        $plan = (new AddonArchive())->inspect($input, $output);
        $this->assertSame(4, $plan['file_count']);
        $this->assertSame(['behavior_packs', 'resource_packs'], array_column($plan['packs'], 'directory'));
        $this->assertSame(hash('sha256', 'say fixture'), $plan['packs'][0]['files']['functions/test.mcfunction']['sha256']);
        $zip = new \ZipArchive();
        $zip->open($output);
        $this->assertFalse($zip->locateName('BP/manifest.json'));
        $this->assertNotFalse($zip->locateName($plan['packs'][0]['folder'] . '/manifest.json'));
        $zip->close();
    }

    public function testMcaddonCanContainOneLevelOfMcpackArchives(): void
    {
        $pack = $this->zip(['manifest.json' => json_encode(self::manifest())]);
        $bundle = $this->zip(['fixture.mcpack' => file_get_contents($pack)]);
        $this->assertCount(1, (new AddonArchive())->inspect($bundle)['packs']);
    }

    public function testNamedPackRootsAndPayloadsWithOrdinaryPunctuationArePreservedSafely(): void
    {
        $root = "ThatMob's Verity 4.0.0 [BP]/";
        $relative = "textures/_locked/owner's [texture].png";
        $input = $this->zip([$root . 'manifest.json' => json_encode(self::manifest()), $root . $relative => 'texture fixture']);
        $output = tempnam(sys_get_temp_dir(), 'beacon-punctuation-clean-');
        $this->temporary[] = $output;
        $plan = (new AddonArchive())->inspect($input, $output);
        $this->assertSame(hash('sha256', 'texture fixture'), $plan['packs'][0]['files'][$relative]['sha256']);
        $zip = new \ZipArchive();
        $zip->open($output);
        $this->assertFalse($zip->locateName($root . 'manifest.json'));
        $this->assertSame('texture fixture', $zip->getFromName($plan['packs'][0]['folder'] . '/' . $relative));
        $zip->close();
    }

    public function testPunctuationDoesNotBypassCaseCollisionProtection(): void
    {
        $root = "Verity's [BP]/";
        $input = $this->zip([$root . 'manifest.json' => json_encode(self::manifest()), $root . "Owner's [texture].png" => 'first', $root . "owner's [texture].png" => 'second']);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('duplicate paths');
        (new AddonArchive())->inspect($input);
    }

    public function testPunctuationDoesNotBypassSymlinkProtection(): void
    {
        $name = "Verity's [BP]/owner's [link].json";
        $input = $this->zip(["Verity's [BP]/manifest.json" => json_encode(self::manifest()), $name => '/outside']);
        $zip = new \ZipArchive();
        $zip->open($input);
        $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('symlinks');
        (new AddonArchive())->inspect($input);
    }

    public function testPunctuationDoesNotBypassExecutableProtection(): void
    {
        $input = $this->zip(["Verity's [BP]/manifest.json" => json_encode(self::manifest()), "Verity's [BP]/owner's [file].php" => '<?php fixture']);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Executable files');
        (new AddonArchive())->inspect($input);
    }

    public function testManySmallFilesUseTheirActualSizeNotThePerFileMaximum(): void
    {
        $files = ['manifest.json' => json_encode(self::manifest())];
        for ($index = 0; $index < 100; ++$index) {
            $files['functions/test' . $index . '.mcfunction'] = 'say fixture';
        }
        $plan = (new AddonArchive())->inspect($this->zip($files));
        $this->assertSame(101, $plan['file_count']);
        $this->assertLessThan(4096, $plan['total_bytes']);
    }

    public function testLargeArchivesAndIndividualPayloadsAreStreamedWithoutTheOldByteCaps(): void
    {
        $payload = tempnam(sys_get_temp_dir(), 'beacon-large-payload-');
        $this->temporary[] = $payload;
        $handle = fopen($payload, 'wb');
        $chunk = random_bytes(1048576);
        for ($index = 0; $index < 40; ++$index) {
            fwrite($handle, $chunk);
        }
        fclose($handle);
        unset($chunk);
        $input = $this->zip(['manifest.json' => json_encode(self::manifest())]);
        $zip = new \ZipArchive();
        $zip->open($input);
        $zip->addFile($payload, 'textures/large.bin');
        $zip->setCompressionName('textures/large.bin', \ZipArchive::CM_STORE);
        $zip->close();
        $this->assertGreaterThan(33554432, filesize($input));
        $output = tempnam(sys_get_temp_dir(), 'beacon-large-clean-');
        $this->temporary[] = $output;
        $plan = (new AddonArchive())->inspect($input, $output);
        $file = $plan['packs'][0]['files']['textures/large.bin'];
        $this->assertSame(41943040, $file['size']);
        $this->assertSame(hash_file('sha256', $payload), $file['sha256']);
        $zip->open($output);
        $this->assertSame(41943040, $zip->statName($plan['packs'][0]['folder'] . '/textures/large.bin')['size']);
        $zip->close();
    }

    public function testCompressionBombsRemainRejectedWithoutAProductSizeCap(): void
    {
        $input = $this->zip(['manifest.json' => json_encode(self::manifest()), 'textures/bomb.bin' => str_repeat('a', 2097152)]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('compression limits');
        (new AddonArchive())->inspect($input);
    }

    public function testManifestSizeProtectionRemainsIndependentOfPayloadSizes(): void
    {
        $input = $this->zip(['manifest.json' => str_repeat(' ', 262145)]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('manifest is too large');
        (new AddonArchive())->inspect($input);
    }

    public static function unsafePaths(): array
    {
        return [['../manifest.json'], ['/manifest.json'], ['C:/manifest.json'], ['pack/../../manifest.json'], ['pack\\manifest.json'], ['pack//manifest.json'],
            ["Verity's [BP]/../manifest.json"], ["Verity's [BP]/.. /manifest.json"], ["Verity's [BP]/textures/../../manifest.json"]];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafeArchivePaths(string $path): void
    {
        $input = $this->zip([$path => '{}']);
        $this->expectException(ConflictHttpException::class);
        (new AddonArchive())->inspect($input);
    }

    public static function invalidManifests(): array
    {
        return [['format_version', 3], ['header.uuid', 'not-a-uuid'], ['header.version', '1.0.0'], ['header.min_engine_version', [-1, 0, 0]],
            ['modules.0.type', 'world_template'], ['modules.0.uuid', '11111111-1111-4111-8111-111111111111'],
            ['dependencies', [['uuid' => '55555555-5555-4555-8555-555555555555', 'version' => [1, 0, 0]]]],
            ['dependencies', [['module_name' => '@minecraft/server-net', 'version' => '1.0.0']]],
            ['dependencies', [['module_name' => '@minecraft/server', 'version' => '2.0.0-beta']]]];
    }

    #[DataProvider('invalidManifests')]
    public function testRejectsUnknownFormatsUnsafeDependenciesAndVersions(string $field, mixed $value): void
    {
        $manifest = self::manifest();
        data_set($manifest, $field, $value);
        $input = $this->zip(['manifest.json' => json_encode($manifest)]);
        $this->expectException(ConflictHttpException::class);
        (new AddonArchive())->inspect($input);
    }

    public function testRejectsSymlinkArchiveEntries(): void
    {
        $input = $this->zip(['manifest.json' => json_encode(self::manifest()), 'linked.json' => '/etc/passwd']);
        $zip = new \ZipArchive();
        $zip->open($input);
        $zip->setExternalAttributesName('linked.json', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(ConflictHttpException::class);
        (new AddonArchive())->inspect($input);
    }

    public function testRejectsCaseCollisionsAndNonBedrockExecutables(): void
    {
        $input = $this->zip(['manifest.json' => json_encode(self::manifest()), 'other.php' => '<?php PRIVATE']);
        $this->expectException(ConflictHttpException::class);
        (new AddonArchive())->inspect($input);
    }

    public function testWorldChangesPreserveExistingEntriesAndNeverGuessRemovalVersions(): void
    {
        $document = new AddonWorldDocument();
        $existing = [['pack_id' => '33333333-3333-4333-8333-333333333333', 'version' => [1, 0, 0], 'custom' => 'preserved']];
        $pack = ['uuid' => self::manifest()['header']['uuid'], 'version' => [1, 0, 0], 'directory' => 'behavior_packs'];
        $next = $document->change(json_encode($existing), [$pack], 'behavior_packs', false);
        $this->assertSame($existing[0], json_decode($next, true)[0]);
        $restored = $document->change($next, [$pack], 'behavior_packs', true);
        $this->assertSame($existing, json_decode($restored, true));
        $pack['version'] = [2, 0, 0];
        $this->expectException(ConflictHttpException::class);
        $document->change($next, [$pack], 'behavior_packs', true);
    }

    public function testDuplicateWorldReferencesAreRejected(): void
    {
        $item = ['pack_id' => self::manifest()['header']['uuid'], 'version' => [1, 0, 0]];
        $this->expectException(ConflictHttpException::class);
        (new AddonWorldDocument())->parse(json_encode([$item, $item]));
    }
}
