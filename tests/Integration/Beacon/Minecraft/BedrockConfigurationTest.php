<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\BedrockWorldSafety;
use Pterodactyl\Beacon\Minecraft\BedrockRuntimeReader;
use Pterodactyl\Beacon\Minecraft\BedrockPropertyPolicy;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\BedrockConfigurationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Guarded in-memory SQLite only. Never use the destructive application test bootstrap. */
class BedrockConfigurationTest extends TestCase
{
    private $events;
    private BedrockConfigurationService $service;
    private string $content;
    private array $entries;
    private array $directories = [];
    private array $extra = [];
    private string $state = 'offline';
    private string $identity = 'binary-fixture';
    private string $writeMode = 'normal';
    private int $writes = 0;
    private int $reads = 0;
    private int $runtimeReads = 0;
    private ?\Closure $onRuntime = null;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'bedrock_test');
        config()->set('database.connections.bedrock_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Storage::fake('local');
        foreach (['nests' => ['name'], 'eggs' => ['name', 'nest_id', 'docker_images'], 'servers' => ['egg_id', 'uuid', 'status', 'startup', 'image', 'installed_at', 'skip_scripts'],
            'egg_variables' => ['egg_id', 'env_variable'], 'server_variables' => ['server_id', 'variable_id', 'variable_value'], 'server_transfers' => ['server_id', 'successful'],
            'allocations' => ['server_id'], 'beacon_operations' => ['server_id', 'type', 'status']] as $table => $columns) {
            Schema::create($table, function (Blueprint $schema) use ($columns) {
                $schema->increments('id');
                foreach ($columns as $column) {
                    $schema->text($column)->nullable();
                }
                $schema->timestamps();
            });
        }
        Schema::create('backups', function (Blueprint $schema) {
            $schema->increments('id');
            $schema->integer('server_id');
            foreach (['uuid', 'name', 'ignored_files', 'checksum'] as $column) {
                $schema->text($column);
            }
            $schema->boolean('is_successful');
            $schema->boolean('is_locked');
            $schema->integer('bytes');
            $schema->timestamp('completed_at')->nullable();
            $schema->softDeletes();
            $schema->timestamps();
        });
        DB::table('nests')->insert(['id' => 1, 'name' => 'Beacon']);
        DB::table('eggs')->insert(['id' => 1, 'nest_id' => 1, 'name' => 'Vanilla Bedrock', 'docker_images' => json_encode(['BDS' => 'fixture:bedrock'])]);
        DB::table('servers')->insert(['id' => 1, 'egg_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'startup' => './bedrock_server', 'image' => 'fixture:bedrock', 'installed_at' => now(), 'skip_scripts' => 0]);
        DB::table('egg_variables')->insert(['id' => 1, 'egg_id' => 1, 'env_variable' => 'VERSION']);
        DB::table('server_variables')->insert(['server_id' => 1, 'variable_id' => 1, 'variable_value' => 'latest']);
        $this->content = "# keep\r\nserver-name=Bedrock test\r\nmax-players=10\r\ntick-distance=4\r\nlevel-name=Bedrock level\r\nlevel-seed=\r\ntexturepack-required=false\r\nscript-debugger-passcode=PRIVATE_FIXTURE\r\nunknown=keep\r\n";
        $this->entries = [self::file('bedrock_server', 100), self::file('server.properties', strlen($this->content))];
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getDirectory')->andReturnUsing(fn ($path) => $path === '/' ? $this->entries : ($this->directories[$path] ?? []));
        $files->allows('putContent')->andReturnUsing(function ($path, $body) {
            ++$this->writes;
            if ($this->writeMode === 'fail') {
                throw new \RuntimeException('PRIVATE_TRANSPORT');
            }
            $this->content = $this->writeMode === 'mismatch' ? 'external=keep' : $body;
            if ($this->writeMode === 'lost') {
                throw new \RuntimeException('PRIVATE_TRANSPORT');
            }
        });
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('read')->andReturnUsing(function ($server, $path) {
            ++$this->reads;

            return $this->extra[$path] ?? $this->content;
        });
        $versions = \Mockery::mock(BedrockRuntimeReader::class);
        $versions->allows('inspect')->andReturnUsing(function () {
            ++$this->runtimeReads;
            if ($this->onRuntime) {
                ($this->onRuntime)($this->runtimeReads);
            }

            return ['version' => 'bedrock-file', 'identity' => $this->identity];
        });
        $power = \Mockery::mock(DaemonServerRepository::class);
        $power->allows('setServer')->andReturnSelf();
        $power->allows('getDetails')->andReturnUsing(fn () => ['state' => $this->state]);
        $this->service = new BedrockConfigurationService($files, $power, $reader, $versions, new BedrockPropertyPolicy());
        $this->app->instance(BedrockWorldSafety::class, new BedrockWorldSafety($files, $reader));
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('bedrock_test');
        \Mockery::close();
        parent::tearDown();
    }

    private static function file(string $name, int $size): array
    {
        return ['name' => $name, 'file' => true, 'symlink' => false, 'size' => $size];
    }

    private static function directory(string $name): array
    {
        return ['name' => $name, 'file' => false, 'symlink' => false];
    }

    private function server(): Server
    {
        return Server::query()->findOrFail(1);
    }

    private function read(): array
    {
        return $this->service->read($this->server());
    }

    private function save(array $changes, ?array $read = null, ?string $backup = null): array
    {
        $read ??= $this->read();

        return $this->service->update($this->server(), $read['hash'], $changes, $read['runtime_hash'], $backup);
    }

    private function backup(array $read, array $overrides = []): string
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        DB::table('backups')->insert($overrides + ['server_id' => 1, 'uuid' => $uuid, 'name' => $read['world_backup_name'], 'ignored_files' => '[]',
            'is_successful' => true, 'is_locked' => true, 'bytes' => 100, 'checksum' => 'sha256:fixture', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    public function testReadUsesActualKeysWithoutInventingVersionOrLeakingPrivateFields(): void
    {
        $read = $this->read();
        $this->assertTrue($read['editable']);
        $this->assertNull($read['game_version']);
        $this->assertSame('bedrock_file', $read['version_source']);
        $this->assertSame('Vanilla Bedrock', $read['runtime_name']);
        $this->assertArrayNotHasKey('script-debugger-passcode', $read['properties']);
        $this->assertArrayNotHasKey('motd', $read['definitions']);
        $this->assertSame('Bedrock level', $read['properties']['level-name']);
        $this->assertStringNotContainsString('PRIVATE_FIXTURE', json_encode($read));
    }

    public function testMissingPropertiesWaitsWithoutRuntimeOrWrites(): void
    {
        array_pop($this->entries);
        $this->assertSame('waiting', $this->read()['state']);
        $this->assertSame(0, $this->reads);
        $this->assertSame(0, $this->runtimeReads);
        $this->assertSame(0, $this->writes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadyStates')]
    public function testLifecycleWaitsBeforeWingsAccess(array $overrides): void
    {
        DB::table('servers')->where('id', 1)->update($overrides);
        $this->assertSame('waiting', $this->read()['state']);
        $this->assertSame(0, $this->reads);
        $this->assertSame(0, $this->runtimeReads);
    }

    public static function unreadyStates(): array
    {
        return [[['status' => 'installing']], [['status' => 'install_failed']], [['installed_at' => null]], [['skip_scripts' => 1]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRuntime')]
    public function testUnsupportedLaunchStateDoesNotGuess(array $overrides): void
    {
        DB::table('servers')->where('id', 1)->update($overrides);
        $this->assertFalse($this->read()['editable']);
        $this->assertSame(0, $this->runtimeReads);
    }

    public static function unsafeRuntime(): array
    {
        return [[['startup' => './custom']], [['image' => 'untrusted:image']], [['startup' => null]], [['image' => null]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeFiles')]
    public function testUnsafeFilesRemainUnavailable(int $which, array $overrides): void
    {
        $this->entries[$which] = $overrides + $this->entries[$which];
        $this->assertFalse($this->read()['editable']);
        $this->assertSame(0, $this->writes);
    }

    public static function unsafeFiles(): array
    {
        return [[0, ['symlink' => true]], [1, ['symlink' => true]], [0, ['size' => 536870913]], [1, ['size' => 262145]], [1, ['file' => false]]];
    }

    public function testSafeAsciiSaveKeepsUnrelatedBytesAndExactPrivateRestore(): void
    {
        $original = $this->content;
        $result = $this->save(['server-name' => 'Beacon = test', 'max-players' => 24]);
        $this->assertSame('Beacon = test', $result['properties']['server-name']);
        $this->assertSame(str_replace(['server-name=Bedrock test', 'max-players=10'], ['server-name=Beacon = test', 'max-players=24'], $original), $this->content);
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame($original, Storage::disk('local')->get($files[0]));
        $this->assertSame(1, $this->writes);
    }

    public function testNoopWritesNothing(): void
    {
        $this->save(['max-players' => 10]);
        $this->assertSame(0, $this->writes);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function testUnicodeCannotBeAcceptedAndThenSilentlyChangedByNativeEgg(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $this->save(['server-name' => 'ไทย']);
        } finally {
            $this->assertSame(0, $this->writes);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedWrites')]
    public function testDriftActiveOperationsAndPowerCannotWrite(string $change): void
    {
        $read = $this->read();
        match ($change) {
            'hash' => $this->content .= "extra=change\n",
            'runtime' => $this->identity = 'changed',
            'power' => $this->state = 'running',
            'transfer' => DB::table('server_transfers')->insert(['server_id' => 1, 'successful' => null]),
            default => DB::table('beacon_operations')->insert(['server_id' => 1, 'type' => $change, 'status' => 'pending']),
        };
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['max-players' => 24], $read);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public static function blockedWrites(): array
    {
        return array_map(fn ($v) => [$v], ['hash', 'runtime', 'power', 'transfer', 'plugin.install', 'mod.install', 'native_modpack.runtime', 'minecraft-service.enable']);
    }

    public function testLateBinaryDriftIsRejectedBeforeFileWrite(): void
    {
        $read = $this->read();
        $this->onRuntime = function (int $count) {
            if ($count === 3) {
                $this->identity = 'late-replacement';
            }
        };
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['max-players' => 24], $read);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedWrites')]
    public function testFailedReadbackNeverClaimsSuccessOrOverwritesExternalWriter(string $mode): void
    {
        $this->writeMode = $mode;
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['max-players' => 24]);
        } finally {
            $this->assertSame(1, $this->writes);
            $this->assertCount(1, Storage::disk('local')->allFiles());
        }
    }

    public static function failedWrites(): array
    {
        return [['fail'], ['mismatch']];
    }

    public function testLostResponseWithExactReadbackIsSuccess(): void
    {
        $this->writeMode = 'lost';
        $this->assertSame(24, $this->save(['max-players' => 24])['properties']['max-players']);
        $this->assertSame(1, $this->writes);
    }

    public function testNativeReadAndWritePermissionsAndRawTextArePreserved(): void
    {
        $request = \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest::create('/configuration', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['properties' => ['server-name' => '  Thai ไทย  ', 'level-seed' => ''], 'hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64)]));
        $request->setRouteResolver(function () use ($request) {
            $route = (new \Illuminate\Routing\Route('PUT', '/configuration', fn () => null))->bind($request);
            $route->setParameter('server', $this->server());

            return $route;
        });
        (new \Pterodactyl\Http\Middleware\TrimStrings())->handle($request, fn ($r) => (new \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull())->handle($r, fn () => null));
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $this->assertSame('  Thai ไทย  ', $request->input('properties.server-name'));
        $this->assertSame('', $request->input('properties.level-seed'));
        $this->assertSame(\Pterodactyl\Models\Permission::ACTION_FILE_UPDATE, $request->permission());
        $this->assertArrayHasKey('runtime_hash', $request->rules());
        $this->assertSame(\Pterodactyl\Models\Permission::ACTION_FILE_READ_CONTENT, (new \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationReadRequest())->permission());
    }

    public function testMapSelectionRequiresCompletedFullBackup(): void
    {
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['level-name' => 'Fresh Bedrock']);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeBackups')]
    public function testUnsafeBackupCannotAuthorizeWorldMutation(array $override): void
    {
        $read = $this->read();
        $backup = $this->backup($read, $override);
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['level-name' => 'Fresh map'], $read, $backup);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public static function unsafeBackups(): array
    {
        return [[['is_successful' => false]], [['is_locked' => false]], [['bytes' => 0]], [['completed_at' => null]], [['ignored_files' => '["worlds"]']], [['server_id' => 2]], [['name' => 'wrong']], [['created_at' => '2000-01-01 00:00:00']]];
    }

    private function map(): void
    {
        $this->entries[] = self::directory('worlds');
        $this->directories['/worlds'] = [self::directory('Uploaded map'), self::directory('Bedrock level')];
        $body = pack('V2', 10, 4) . "\x0a\0\0\0";
        $this->directories['/worlds/Uploaded map'] = [self::file('level.dat', strlen($body)), self::directory('db')];
        $this->directories['/worlds/Uploaded map/db'] = [self::file('CURRENT', 16)];
        $this->extra['/worlds/Uploaded map/level.dat'] = $body;
    }

    public function testUploadedBedrockMapUsesWorldsSubfolderAndPreservesNames(): void
    {
        $this->map();
        $read = $this->read();
        $this->assertSame('Uploaded map', $this->save(['level-name' => 'Uploaded map'], $read, $this->backup($read))['properties']['level-name']);
        $this->assertSame('Bedrock level', $this->directories['/worlds'][1]['name']);
    }

    public function testNewSeedSelectsUnusedBedrockFolder(): void
    {
        $this->map();
        $read = $this->read();
        $result = $this->save(['level-name' => 'New map', 'level-seed' => '1234567890123456789'], $read, $this->backup($read));
        $this->assertSame('1234567890123456789', $result['properties']['level-seed']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeWorlds')]
    public function testUnsafeWorldsOrExistingSeedAreRejected(string $mode): void
    {
        $this->map();
        $read = $this->read();
        $backup = $this->backup($read);
        if ($mode === 'worlds-link') {
            $this->entries[2]['symlink'] = true;
        } elseif ($mode === 'map-link') {
            $this->directories['/worlds'][0]['symlink'] = true;
        } elseif ($mode === 'level-link') {
            $this->directories['/worlds/Uploaded map'][0]['symlink'] = true;
        } elseif ($mode === 'db-link') {
            $this->directories['/worlds/Uploaded map'][1]['symlink'] = true;
        } elseif ($mode === 'db-child-link') {
            $this->directories['/worlds/Uploaded map/db'][0]['symlink'] = true;
        } elseif ($mode === 'java-map') {
            $this->extra['/worlds/Uploaded map/level.dat'] = str_repeat('J', 12);
        } elseif ($mode === 'no-db') {
            array_pop($this->directories['/worlds/Uploaded map']);
        } elseif ($mode === 'exclusions') {
            $this->entries[] = self::file('.pteroignore', 6);
            $this->extra['/.pteroignore'] = 'worlds';
        }
        $changes = ['level-name' => 'Uploaded map'];
        if ($mode === 'existing-seed') {
            $changes['level-seed'] = '99';
        }
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save($changes, $read, $backup);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public static function unsafeWorlds(): array
    {
        return array_map(fn ($v) => [$v], ['worlds-link', 'map-link', 'level-link', 'db-link', 'db-child-link', 'java-map', 'no-db', 'existing-seed', 'exclusions']);
    }
}
