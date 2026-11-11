<?php

namespace Pterodactyl\Tests\Integration\Beacon\Addons;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Addons\AddonArchive;
use Pterodactyl\Beacon\Addons\AddonRuntime;
use Pterodactyl\Beacon\Addons\AddonFilesystem;
use Pterodactyl\Beacon\Addons\AddonWorldDocument;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A regular-file mock filesystem, never calls the real Wings. */
class AddonFilesystemTest extends TestCase
{
    private array $disk;
    private array $links = [];
    private array $temporary = [];
    private AddonFilesystem $service;
    private Server $server;
    private BeaconOperation $operation;
    private array $prepared;
    private string $archive;
    private bool $failWorldWrite = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->disk = ['/worlds' => null, '/worlds/Test' => null, '/worlds/Test/db' => null, '/worlds/Test/db/user-data' => 'preserve-world',
            '/worlds/Test/level.dat' => pack('VV', 10, 1) . "\x0a", '/behavior_packs' => null, '/resource_packs' => null, '/server.properties' => "level-name=Test\n"];
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $files->allows('getHttpClient')->andReturn($client);
        $client->allows('post')->andReturnUsing(function ($url, $options) {
            $this->assertTrue(is_resource($options['body']), 'Archive upload must use a stream, not a full-memory string.');
            $this->disk[$options['query']['file']] = stream_get_contents($options['body']);

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $files->allows('getDirectory')->andReturnUsing(function ($parent) {
            if ($parent !== '/' && (!array_key_exists($parent, $this->disk) || $this->disk[$parent] !== null)) {
                throw new ConflictHttpException('Fixture directory is missing.');
            }
            $entries = [];
            foreach ($this->disk as $path => $body) {
                if (str_replace('\\', '/', dirname($path)) === $parent) {
                    $entries[] = ['name' => basename($path), 'size' => $body === null ? 0 : strlen($body), 'file' => $body !== null, 'symlink' => isset($this->links[$path]), 'modified_at' => 'fixture-timestamp'];
                }
            }

            return $entries;
        });
        $files->allows('createDirectory')->andReturnUsing(function ($name, $parent) {
            $this->disk[rtrim($parent, '/') . '/' . $name] = null;

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $files->allows('putContent')->andReturnUsing(function ($path, $body) {
            $this->disk[$path] = $body;
            if ($this->failWorldWrite && str_ends_with($path, 'world_behavior_packs.json')) {
                $this->failWorldWrite = false;
                throw new \RuntimeException('PRIVATE_LOST_WRITE_RESPONSE');
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $files->allows('getFileHashes')->andReturnUsing(function ($path, $limit) {
            $body = $this->disk[$path];
            if (strlen($body) > $limit) {
                throw new \RuntimeException('Fixture size limit.');
            }

            return ['size' => strlen($body), 'sha256' => hash('sha256', $body)];
        });
        $files->allows('deleteFiles')->andReturnUsing(function ($parent, $names) {
            foreach ($names as $name) {
                unset($this->disk[$parent . '/' . $name]);
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $files->allows('renameFiles')->andReturnUsing(function ($root, $moves) {
            foreach ($moves as $move) {
                $from = '/' . $move['from'];
                $to = '/' . $move['to'];
                foreach (array_keys($this->disk) as $path) {
                    if ($path === $from || str_starts_with($path, $from . '/')) {
                        $this->disk[$to . substr($path, strlen($from))] = $this->disk[$path];
                        unset($this->disk[$path]);
                    }
                }
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $files->allows('decompressFile')->andReturnUsing(function ($root, $name) {
            $path = tempnam(sys_get_temp_dir(), 'beacon-addon-unzip-test-');
            $this->temporary[] = $path;
            file_put_contents($path, $this->disk[$root . '/' . $name]);
            $zip = new \ZipArchive();
            $zip->open($path);
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                $parent = dirname($root . '/' . $name);
                while ($parent !== $root && $parent !== '/') {
                    $this->disk[$parent] = null;
                    $parent = dirname($parent);
                }
                $this->disk[$root . '/' . $name] = $zip->getFromIndex($i);
            }
            $zip->close();

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('read')->andReturnUsing(fn ($server, $path) => $this->disk[$path]);
        $runtime = \Mockery::mock(AddonRuntime::class, [$files, $reader])->makePartial();
        $runtime->allows('assertSupported')->andReturnNull();
        $this->service = new AddonFilesystem($files, $reader, $runtime, new AddonWorldDocument());
        $this->server = (new Server())->forceFill(['id' => 1]);
        $this->operation = (new BeaconOperation())->forceFill(['uuid' => '33333333-3333-4333-8333-333333333333', 'payload' => ['world' => 'Test']]);
        $input = tempnam(sys_get_temp_dir(), 'beacon-addon-input-test-');
        $this->temporary[] = $input;
        $this->archive = tempnam(sys_get_temp_dir(), 'beacon-addon-output-test-');
        $this->temporary[] = $this->archive;
        $zip = new \ZipArchive();
        $zip->open($input, \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(AddonArchiveTest::manifest()));
        $zip->addFromString('functions/test.mcfunction', 'say fixture');
        $zip->close();
        $manifest = (new AddonArchive())->inspect($input, $this->archive);
        $this->prepared = ['archive_hash' => hash_file('sha256', $this->archive), 'manifest' => $manifest];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        \Mockery::close();
        parent::tearDown();
    }

    public function testRealStageVerificationActivationAndUninstallPreserveWorldData(): void
    {
        $packs = $this->prepared['manifest']['packs'];
        $this->service->stage($this->operation, $this->server, $this->prepared, $this->archive);
        $this->assertArrayNotHasKey('/worlds/Test/world_behavior_packs.json', $this->disk);
        $progress = $this->service->verify($this->operation, $this->server, $packs, false, 0);
        $this->assertSame(2, $progress['cursor']);
        $snapshot = $this->service->snapshot($this->operation, $this->server, $packs, false);
        $this->service->commit($this->server, $snapshot);
        $this->assertSame($packs[0]['uuid'], json_decode($this->disk['/worlds/Test/world_behavior_packs.json'], true)[0]['pack_id']);
        $this->assertSame('preserve-world', $this->disk['/worlds/Test/db/user-data']);
        $this->assertSame("level-name=Test\n", $this->disk['/server.properties']);
        $this->service->verify($this->operation, $this->server, $packs, true, 0);
        $this->operation->uuid = '44444444-4444-4444-8444-444444444444';
        $remove = $this->service->snapshot($this->operation, $this->server, $packs, true);
        $this->service->commit($this->server, $remove);
        $this->assertSame([], json_decode($this->disk['/worlds/Test/world_behavior_packs.json'], true));
        $this->assertArrayNotHasKey('/behavior_packs/' . $packs[0]['folder'], $this->disk);
        $this->assertArrayHasKey('/.beacon/addon-' . $this->operation->uuid . '/' . $packs[0]['folder'] . '/manifest.json', $this->disk);
    }

    public function testLostWorldWriteResponseCanBeRolledBackWithoutRemovingUnrelatedFiles(): void
    {
        $packs = $this->prepared['manifest']['packs'];
        $this->service->stage($this->operation, $this->server, $this->prepared, $this->archive);
        $snapshot = $this->service->snapshot($this->operation, $this->server, $packs, false);
        $this->failWorldWrite = true;
        try {
            $this->service->commit($this->server, $snapshot);
            $this->fail('Expected lost response.');
        } catch (\RuntimeException $e) {
            $this->service->rollback($this->server, $snapshot);
        }
        $this->assertArrayNotHasKey('/worlds/Test/world_behavior_packs.json', $this->disk);
        $this->assertArrayNotHasKey('/behavior_packs/' . $packs[0]['folder'], $this->disk);
        $this->assertSame('preserve-world', $this->disk['/worlds/Test/db/user-data']);
    }

    public function testPunctuationPayloadsPassStagingVerificationActivationAndRemoval(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'beacon-addon-named-pack-');
        $this->temporary[] = $input;
        $relative = "textures/_locked/owner's [texture].png";
        $zip = new \ZipArchive();
        $zip->open($input, \ZipArchive::OVERWRITE);
        $zip->addFromString("Verity's [BP]/manifest.json", json_encode(AddonArchiveTest::manifest()));
        $zip->addFromString("Verity's [BP]/" . $relative, 'texture fixture');
        $zip->close();
        $manifest = (new AddonArchive())->inspect($input, $this->archive);
        $prepared = ['archive_hash' => hash_file('sha256', $this->archive), 'manifest' => $manifest];
        $packs = $manifest['packs'];
        $this->service->stage($this->operation, $this->server, $prepared, $this->archive);
        $this->assertSame(2, $this->service->verify($this->operation, $this->server, $packs, false, 0)['cursor']);
        $this->service->commit($this->server, $this->service->snapshot($this->operation, $this->server, $packs, false));
        $path = '/behavior_packs/' . $packs[0]['folder'] . '/' . $relative;
        $this->assertSame('texture fixture', $this->disk[$path]);
        $this->service->verify($this->operation, $this->server, $packs, true, 0);
        $this->operation->uuid = '44444444-4444-4444-8444-444444444444';
        $this->service->commit($this->server, $this->service->snapshot($this->operation, $this->server, $packs, true));
        $this->assertArrayNotHasKey($path, $this->disk);
        $this->assertSame('preserve-world', $this->disk['/worlds/Test/db/user-data']);
    }

    public function testUnexpectedFilesAndSymlinksFailBeforeActivation(): void
    {
        $packs = $this->prepared['manifest']['packs'];
        $this->service->stage($this->operation, $this->server, $this->prepared, $this->archive);
        $this->links[$this->service->stagePath($this->operation) . '/' . $packs[0]['folder'] . '/manifest.json'] = true;
        $this->expectException(ConflictHttpException::class);
        $this->service->tree($this->server, $this->service->stagePath($this->operation), $packs, false);
    }

    public function testUnmanagedPackUuidCollisionIsNeverOverwritten(): void
    {
        $packs = $this->prepared['manifest']['packs'];
        $this->service->stage($this->operation, $this->server, $this->prepared, $this->archive);
        $this->disk["/behavior_packs/Verity's [manual]"] = null;
        $this->disk["/behavior_packs/Verity's [manual]/manifest.json"] = json_encode(AddonArchiveTest::manifest());
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('already installed');
        $this->service->snapshot($this->operation, $this->server, $packs, false);
    }

    public function testBackupExclusionsAreRejected(): void
    {
        $this->disk['/.pteroignore'] = 'worlds/';
        $this->expectException(ConflictHttpException::class);
        $this->service->backupSafe($this->server);
    }
}
