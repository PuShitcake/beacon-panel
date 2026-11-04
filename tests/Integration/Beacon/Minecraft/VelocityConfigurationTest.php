<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\VelocityJarIdentity;
use Pterodactyl\Beacon\Minecraft\VelocityPropertyPolicy;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\VelocityConfigurationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** In-memory SQLite only; no shared database, provider or real Wings calls. */
class VelocityConfigurationTest extends TestCase
{
    private $events;
    private VelocityConfigurationService $service;
    private string $body = "# keep\r\nmotd = 'Proxy' # keep\r\nshow-max-players=500\r\nforwarding-secret='PRIVATE_FIXTURE'\r\nbind='0.0.0.0:25565'\r\n[servers]\r\nlobby='localhost:25566'\r\ntry=['lobby']\r\n[advanced]\r\ncompression-level=-1\r\n";
    private string $state = 'offline';
    private ?string $identity = 'fixture-jar';
    private int $writes = 0;
    private string $mode = 'normal';
    private int $reads = 0;
    private ?int $raceAt = null;
    private array $entry = ['name' => 'velocity.toml', 'file' => true, 'symlink' => false, 'size' => 300];

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'velocity_test');
        config()->set('database.connections.velocity_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Storage::fake('local');
        foreach (['nests' => ['name'], 'eggs' => ['name', 'nest_id'], 'egg_variables' => ['egg_id', 'env_variable'],
            'server_variables' => ['server_id', 'variable_id', 'variable_value'], 'server_transfers' => ['server_id', 'successful'],
            'allocations' => ['server_id'], 'servers' => ['egg_id', 'uuid', 'status', 'image', 'startup'],
            'beacon_operations' => ['server_id', 'type', 'status']] as $table => $columns) {
            Schema::create($table, function (Blueprint $schema) use ($columns) {
                $schema->increments('id');
                foreach ($columns as $column) {
                    $schema->text($column)->nullable();
                }
                $schema->timestamps();
            });
        }
        DB::table('nests')->insert(['id' => 1, 'name' => 'Beacon']);
        DB::table('eggs')->insert(['id' => 1, 'name' => 'Velocity', 'nest_id' => 1]);
        DB::table('servers')->insert(['id' => 1, 'egg_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111']);
        DB::table('egg_variables')->insert(['id' => 1, 'egg_id' => 1, 'env_variable' => 'SERVER_JARFILE']);
        DB::table('server_variables')->insert(['server_id' => 1, 'variable_id' => 1, 'variable_value' => 'velocity.jar']);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getDirectory')->with('/')->andReturnUsing(fn () => [
            $this->entry, ['name' => 'velocity.jar', 'file' => true, 'symlink' => false, 'size' => 1000],
        ]);
        $files->allows('putContent')->with('/velocity.toml', \Mockery::type('string'))->andReturnUsing(function ($path, $body) {
            ++$this->writes;
            if ($this->mode !== 'fail') {
                $this->body = $this->mode === 'mismatch' ? "motd='external'" : $body;
            }
            if (in_array($this->mode, ['fail', 'lost-response'], true)) {
                throw new \RuntimeException('PRIVATE_WINGS_DETAIL');
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('read')->with(\Mockery::type(Server::class), '/velocity.toml', 262144)->andReturnUsing(function () {
            ++$this->reads;
            if ($this->raceAt === $this->reads) {
                $this->body = "motd='external'";
            }

            return $this->body;
        });
        $runtime = \Mockery::mock(VelocityJarIdentity::class);
        $runtime->allows('read')->with(\Mockery::type(Server::class), '/velocity.jar', 1000)->andReturnUsing(fn () => $this->identity);
        $power = \Mockery::mock(DaemonServerRepository::class);
        $power->allows('setServer')->andReturnSelf();
        $power->allows('getDetails')->andReturnUsing(fn () => ['state' => $this->state]);
        $this->service = new VelocityConfigurationService($files, $power, $reader, $runtime, new VelocityPropertyPolicy());
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('velocity_test');
        parent::tearDown();
    }

    private function server(): Server
    {
        return Server::query()->findOrFail(1);
    }

    private function read(): array
    {
        return $this->service->read($this->server());
    }

    private function save(array $changes = ['motd' => 'Updated'], ?array $read = null): array
    {
        $read ??= $this->read();

        return $this->service->update($this->server(), $read['hash'], $changes, $read['runtime_hash']);
    }

    public function testReadDiscoversOnlyExistingSafeSettingsAndNeverReturnsSecretsOrAddresses(): void
    {
        $read = $this->read();
        $this->assertSame('velocity.toml', $read['source']);
        $this->assertNull($read['game_version']);
        $this->assertSame(['motd' => 'Proxy', 'show-max-players' => 500, 'advanced.compression-level' => -1], $read['properties']);
        foreach (['PRIVATE_FIXTURE', '0.0.0.0:25565', 'localhost:25566'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($read));
        }
        $this->assertSame(0, $this->writes);
    }

    public function testSaveBacksUpExactlyAndPreservesRoutingSecretsAndUnknownBytes(): void
    {
        $before = $this->body;
        $saved = $this->save(['motd' => 'ทดสอบ "quote" \\', 'advanced.compression-level' => '4']);
        $this->assertSame('ทดสอบ "quote" \\', $saved['properties']['motd']);
        $this->assertSame(4, $saved['properties']['advanced.compression-level']);
        $this->assertStringContainsString("forwarding-secret='PRIVATE_FIXTURE'\r\n", $this->body);
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame($before, Storage::disk('local')->get($files[0]));
        $this->assertSame(1, $this->writes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChanges')]
    public function testProtectedAbsentOrInvalidInputsNeverWriteOrCreateRestorePoints(array $changes): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $this->save($changes);
        } finally {
            $this->assertSame(0, $this->writes);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public static function invalidChanges(): array
    {
        return array_map(fn ($changes) => [$changes], [
            ['forwarding-secret' => 'x'], ['online-mode' => false], ['bind' => 'localhost:1'], ['servers.lobby' => 'x'],
            ['query.port' => 25565], ['announce-forge' => true], ['motd' => "bad\n"], ['motd' => str_repeat('a', 513)],
            ['show-max-players' => true], ['show-max-players' => '1e3'], ['advanced.compression-level' => 10],
        ]);
    }

    public function testNoOpSaveDoesNotWrite(): void
    {
        $this->save(['show-max-players' => '500']);
        $this->assertSame(0, $this->writes);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function testStaleFileHashIsRejected(): void
    {
        $read = $this->read();
        $this->body .= "\n# SFTP edit";
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(read: $read);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public function testChangedJarIdentityIsRejected(): void
    {
        $read = $this->read();
        $this->identity = 'changed-jar';
        $this->expectException(ConflictHttpException::class);
        $this->save(read: $read);
    }

    public function testChangingImageOrStartupInvalidatesTheReadToken(): void
    {
        $read = $this->read();
        DB::table('servers')->where('id', 1)->update(['image' => 'example.invalid/changed-runtime']);
        $this->expectException(ConflictHttpException::class);
        $this->save(read: $read);
    }

    public function testUnsupportedJarAndInstallationStateAreNotEditable(): void
    {
        $this->identity = null;
        $this->assertFalse($this->read()['editable']);
        DB::table('servers')->where('id', 1)->update(['status' => 'installing']);
        $this->assertSame('waiting', $this->read()['state']);
        $this->assertSame(0, $this->writes);
    }

    public function testRestorePointFailurePreventsAnyWingsWrite(): void
    {
        Storage::shouldReceive('disk')->with('local')->andReturn($storage = \Mockery::mock());
        $storage->expects('put')->andReturn(false);
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save();
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public function testRetiredVersionOperationDoesNotBlockSave(): void
    {
        DB::table('beacon_operations')->insert(['server_id' => 1, 'type' => 'version.change', 'status' => 'pending']);
        $this->assertSame('Updated', $this->save()['properties']['motd']);
    }

    public function testSftpRaceImmediatelyBeforeWriteIsNotOverwritten(): void
    {
        $read = $this->read();
        $this->raceAt = 4;
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(read: $read);
        } finally {
            $this->assertSame("motd='external'", $this->body);
            $this->assertSame(0, $this->writes);
        }
    }

    public function testRunningProxyCannotBeMutated(): void
    {
        $this->state = 'running';
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save();
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public function testActiveOperationBlocksMutation(): void
    {
        DB::table('beacon_operations')->insert(['server_id' => 1, 'type' => 'mods.install', 'status' => 'pending']);
        $this->expectException(ConflictHttpException::class);
        $this->save();
    }

    public function testUnsafeAndUnsupportedFilesReturnNormalUnavailableState(): void
    {
        $this->entry['symlink'] = true;
        $this->assertFalse($this->read()['editable']);
        $this->entry['symlink'] = false;
        $this->body = "motd='''unsupported\nmultiline'''";
        $this->assertSame('unavailable', $this->read()['state']);
        $this->assertSame(0, $this->writes);
    }

    public function testLostAcknowledgementSucceedsOnlyWithExactReadback(): void
    {
        $this->mode = 'lost-response';
        $this->assertSame('Updated', $this->save()['properties']['motd']);
    }

    public function testMismatchedReadbackIsNeverOverwrittenByAutomaticRollback(): void
    {
        $this->mode = 'mismatch';
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save();
        } finally {
            $this->assertSame("motd='external'", $this->body);
            $this->assertSame(1, $this->writes);
            $this->assertCount(1, Storage::disk('local')->allFiles());
        }
    }

    public function testTransportFailureIsSanitizedAndDoesNotClaimSuccess(): void
    {
        $before = $this->body;
        $this->mode = 'fail';
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('save did not verify');
        try {
            $this->save();
        } finally {
            $this->assertSame($before, $this->body);
            $this->assertSame(1, $this->writes);
        }
    }
}
