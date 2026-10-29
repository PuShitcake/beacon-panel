<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\VanillaPropertyPolicy;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\VanillaJarVersionReader;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\VanillaConfigurationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** SQLite memory only; Wings, file storage and installed-JAR verification are mocked. */
class VanillaConfigurationTest extends TestCase
{
    protected const EGG = 'Vanilla Minecraft';
    protected const SERVICE = VanillaConfigurationService::class;
    protected const POLICY = VanillaPropertyPolicy::class;
    protected const VERSIONS = VanillaJarVersionReader::class;
    protected const INSPECT_RUNTIME = false;
    protected const CACHED_READS = true;
    protected const MUTATION_RUNTIME_READS = 2;
    protected const RUNTIME_FILE = 'server.jar';
    protected const RUNTIME_SIZE = 47791053;
    protected const WORLD_SAFETY = \Pterodactyl\Beacon\Minecraft\VanillaWorldSafety::class;
    protected string $buildIdentity = 'fixture-build';
    private $events;
    protected VanillaConfigurationService $service;
    protected string $content;
    protected string $version = '1.20.1';
    protected string $state = 'offline';
    private string $writeMode = 'normal';
    private int $hashReads = 0;
    protected int $writes = 0;
    private int $fileReads = 0;
    protected array $filePaths = [];
    private int $directoryReads = 0;
    private array $readOrder = [];
    protected array $entries;
    protected array $directories = [];
    protected array $extraFiles = [];
    private string $ignore = '';

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'vanilla_test');
        config()->set('database.connections.vanilla_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Cache::setDefaultDriver('array');
        Cache::flush();
        Storage::fake('local');
        foreach (['nests' => ['name'], 'eggs' => ['name', 'nest_id'], 'egg_variables' => ['egg_id', 'env_variable'],
            'server_variables' => ['server_id', 'variable_id', 'variable_value'], 'server_transfers' => ['server_id', 'successful'],
            'allocations' => ['server_id'], 'servers' => ['egg_id', 'uuid', 'status'], 'beacon_operations' => ['server_id', 'type', 'status']] as $table => $columns) {
            Schema::create($table, function (Blueprint $schema) use ($columns) {
                $schema->increments('id');
                foreach ($columns as $column) {
                    $schema->text($column)->nullable();
                }
                $schema->timestamps();
            });
        }
        DB::table('nests')->insert(['id' => 1, 'name' => 'Beacon']);
        DB::table('eggs')->insert(['id' => 1, 'name' => static::EGG, 'nest_id' => 1]);
        DB::table('servers')->insert(['id' => 1, 'egg_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111']);
        DB::table('egg_variables')->insert(['id' => 1, 'egg_id' => 1, 'env_variable' => 'SERVER_JARFILE']);
        DB::table('server_variables')->insert(['server_id' => 1, 'variable_id' => 1, 'variable_value' => 'server.jar']);
        Schema::create('backups', function (Blueprint $schema) {
            $schema->increments('id');
            $schema->integer('server_id');
            foreach (['uuid', 'name', 'ignored_files', 'checksum'] as $field) {
                $schema->text($field);
            }
            $schema->boolean('is_successful');
            $schema->boolean('is_locked');
            $schema->integer('bytes');
            $schema->timestamp('completed_at')->nullable();
            $schema->softDeletes();
            $schema->timestamps();
        });
        $this->content = "# keep\r\nmotd=Hello\\u0020World\r\nmax-players=20\r\npvp=true\r\nrcon.password=PRIVATE_FIXTURE\r\nunknown=keep\r\n";
        $this->entries = [
            ['name' => static::RUNTIME_FILE, 'file' => true, 'symlink' => false, 'size' => static::RUNTIME_SIZE, 'modified_at' => '2026-01-01T00:00:00Z'],
            ['name' => 'server.properties', 'file' => true, 'symlink' => false, 'size' => strlen($this->content)],
        ];
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getDirectory')->andReturnUsing(function ($path) {
            ++$this->directoryReads;

            return $path === '/' ? $this->entries : ($this->directories[$path] ?? []);
        });
        $files->allows('putContent')->andReturnUsing(function ($path, $content) {
            ++$this->writes;
            if ($this->writeMode === 'fail') {
                throw new \RuntimeException('PRIVATE_WINGS_URL');
            }
            $this->content = $this->writeMode === 'mismatch' ? 'external-change=keep' : $content;
            if ($this->writeMode === 'lost-response') {
                throw new \RuntimeException('PRIVATE_WINGS_URL');
            }

            return new Response(204);
        });
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('read')->andReturnUsing(function ($server, $path) {
            ++$this->fileReads;
            $this->filePaths[] = $path;
            $this->readOrder[] = 'properties';

            return $this->extraFiles[$path] ?? ($path === '/.pteroignore' ? $this->ignore : $this->content);
        });
        $versions = \Mockery::mock(static::VERSIONS);
        $this->configureRuntimeReader($versions);
        $versions->allows(static::INSPECT_RUNTIME ? 'inspect' : 'read')->andReturnUsing(function () {
            ++$this->hashReads;
            $this->readOrder[] = 'jar';

            return $this->version === 'unknown' ? null : (static::INSPECT_RUNTIME
                ? ['version' => $this->version, 'identity' => $this->version . ':' . $this->buildIdentity]
                : $this->version);
        });
        $power = \Mockery::mock(DaemonServerRepository::class);
        $power->allows('setServer')->andReturnSelf();
        $power->allows('getDetails')->andReturnUsing(fn () => ['state' => $this->state]);
        $policy = static::POLICY;
        $service = static::SERVICE;
        $safety = static::WORLD_SAFETY;
        $this->service = new $service($files, $power, $reader, $versions, new $policy());
        $this->app->instance($safety, new $safety($files, $reader));
    }

    protected function configureRuntimeReader(\Mockery\MockInterface $reader): void
    {
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::purge('vanilla_test');
        Cache::flush();
        \Mockery::close();
        parent::tearDown();
    }

    protected function server(): Server
    {
        return Server::query()->findOrFail(1);
    }

    public function testRecognizedJarWithoutAReviewedSchemaRemainsReadOnly(): void
    {
        $this->version = '99.1';
        $response = $this->service->read($this->server());
        $this->assertFalse($response['editable']);
        $this->assertStringContainsString('not in the verified runtime index', $response['unavailable_reason']);
        $this->assertSame(0, $this->writes);
    }

    protected function save(array $changes, ?array $read = null, ?string $backupUuid = null): array
    {
        $read ??= $this->service->read($this->server());

        return $this->service->update($this->server(), $read['hash'], $changes, $read['runtime_hash'], $backupUuid);
    }

    public function testMissingFileIsOrdinaryWaitingWithoutJarDownloadOrWrites(): void
    {
        $this->entries = [$this->entries[0]];
        $read = $this->service->read($this->server());
        $this->assertSame('waiting', $read['state']);
        $this->assertSame('server.properties', $read['source']);
        $this->assertSame(0, $this->fileReads);
        $this->assertSame(0, $this->hashReads);
        $this->assertSame(0, $this->writes);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function testInstallationDoesNotAttemptAnyWingsFileAccess(): void
    {
        DB::table('servers')->where('id', 1)->update(['status' => Server::STATUS_INSTALLING]);
        $this->assertSame('waiting', $this->service->read($this->server())['state']);
        $this->assertSame(0, $this->directoryReads);
        $this->assertSame(0, $this->fileReads);
        $this->assertSame(0, $this->hashReads);
        $this->assertSame(0, $this->writes);
    }

    public function testFileDiscoveryRefreshesWithoutInsertingMissingDefaults(): void
    {
        $this->content = "motd=My instance\nunknown=keep\n";
        $first = $this->service->read($this->server());
        $this->assertSame(['motd'], array_keys($first['definitions']));
        $this->assertSame(['motd' => 'My instance'], $first['properties']);
        $this->content .= "max-players=37\n";
        $next = $this->service->read($this->server());
        $this->assertSame(['motd', 'max-players'], array_keys($next['definitions']));
        $this->assertSame(37, $next['properties']['max-players']);
        $this->assertSame(0, $this->writes);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function testExistingFileIsReadBeforeRuntimeValidationAndIsNotATemplate(): void
    {
        $read = $this->service->read($this->server());
        $this->assertSame(['properties', 'jar'], $this->readOrder);
        $this->assertSame('ready', $read['state']);
        $this->assertSame('server.properties', $read['source']);
        $this->assertSame(['motd', 'max-players', 'pvp'], array_keys($read['definitions']));
        $this->assertArrayNotHasKey('allow-flight', $read['properties']);
        $this->assertArrayNotHasKey('rcon.password', $read['definitions']);
        $this->assertSame(0, $this->writes);
    }

    public function testAbsentButApprovedFieldCannotBeInjectedOnSave(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $this->save(['allow-flight' => true]);
        } finally {
            $this->assertSame(0, $this->writes);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function testUnsafePropertiesAreUnavailableNotWaitingAndNeverDownloaded(): void
    {
        foreach ([['symlink' => true], ['size' => 262145], ['size' => 0], ['file' => false]] as $bad) {
            $this->entries[1] = array_merge(['name' => 'server.properties', 'file' => true, 'symlink' => false, 'size' => 100], $bad);
            $this->assertSame('unavailable', $this->service->read($this->server())['state']);
        }
        $this->assertSame(0, $this->fileReads);
        $this->assertSame(0, $this->hashReads);
        $this->assertSame(0, $this->writes);
    }

    public function testReadUsesInstalledVersionAndDoesNotDiscloseRawSecrets(): void
    {
        $read = $this->service->read($this->server());
        $this->assertSame('1.20.1', $read['game_version']);
        $this->assertSame('Hello World', $read['properties']['motd']);
        $this->assertStringNotContainsString('PRIVATE_FIXTURE', json_encode($read));
        $this->service->read($this->server());
        $this->assertSame(static::CACHED_READS ? 1 : 2, $this->hashReads);
    }

    public function testSaveBacksUpVerifiesAndPreservesAllUnchangedBytes(): void
    {
        $old = $this->content;
        $result = $this->save(['motd' => 'สวัสดี']);
        $this->assertSame('สวัสดี', $result['properties']['motd']);
        $this->assertStringContainsString("pvp=true\r\nrcon.password=PRIVATE_FIXTURE\r\nunknown=keep\r\n", $this->content);
        $backups = Storage::disk('local')->allFiles('beacon/configuration');
        $this->assertCount(1, $backups);
        $this->assertSame($old, Storage::disk('local')->get($backups[0]));
        $this->assertSame(static::MUTATION_RUNTIME_READS, $this->hashReads, 'Mutation must reverify, not trust the cached version.');
        $this->assertSame(hash('sha256', $this->content), $result['hash']);
    }

    public function testNoopDoesNotRewriteOrCreateBackup(): void
    {
        $this->save(['motd' => 'Hello World']);
        $this->assertSame(0, $this->writes);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function testStaleFileCannotSave(): void
    {
        $read = $this->service->read($this->server());
        $this->content .= '# another writer';
        $this->expectException(ConflictHttpException::class);
        $this->save(['motd' => 'new'], $read);
    }

    public function testInstalledRuntimeChangeInvalidatesSaveEvenWithUnchangedProperties(): void
    {
        $read = $this->service->read($this->server());
        $this->version = '1.21.9';
        $this->expectException(ConflictHttpException::class);
        $this->save(['motd' => 'new'], $read);
    }

    public function testRunningServerIsRejectedBeforeWriting(): void
    {
        $this->state = 'running';
        $this->expectException(ConflictHttpException::class);
        $this->save(['motd' => 'new']);
    }

    public function testActiveOperationIsRejected(): void
    {
        DB::table('beacon_operations')->insert(['server_id' => 1, 'status' => 'pending', 'type' => 'minecraft-service.enable-rcon']);
        $this->expectException(ConflictHttpException::class);
        $this->save(['motd' => 'new']);
    }

    public function testUnknownJarIsOrdinaryReadOnlyState(): void
    {
        $this->version = 'unknown';
        $read = $this->service->read($this->server());
        $this->assertFalse($read['editable']);
        $this->assertNotEmpty($read['unavailable_reason']);
        $this->assertSame([], (array) $read['properties']);
    }

    public function testSymlinkMissingAndOversizedInputsStayReadOnly(): void
    {
        foreach ([['symlink' => true], ['size' => 134217729], ['file' => false]] as $bad) {
            $this->entries[0] = array_merge($this->entries[0], ['file' => true, 'symlink' => false, 'size' => 10], $bad);
            $this->assertFalse($this->service->read($this->server())['editable']);
        }
        $this->entries = [];
        $this->assertFalse($this->service->read($this->server())['editable']);
    }

    public function testOnlyTheExactBeaconEggIsSupported(): void
    {
        $service = static::SERVICE;
        DB::table('eggs')->where('id', 1)->update(['name' => static::EGG === 'Paper' ? 'Vanilla Minecraft' : 'Paper']);
        $this->assertFalse($service::supports($this->server()));
        DB::table('eggs')->where('id', 1)->update(['name' => static::EGG]);
        DB::table('nests')->update(['name' => 'Minecraft']);
        $this->assertFalse($service::supports($this->server()));
    }

    public function testLostWriteResponseCanSucceedOnlyWithExactReadback(): void
    {
        $this->writeMode = 'lost-response';
        $this->assertSame('verified', $this->save(['motd' => 'verified'])['properties']['motd']);
        $this->assertSame(1, $this->writes);
    }

    public function testFailedWriteRetainsRestorePointWithoutDisclosingOrPretendingSuccess(): void
    {
        $old = $this->content;
        $this->writeMode = 'fail';
        try {
            $this->save(['motd' => 'new']);
            $this->fail('Expected a controlled failure');
        } catch (ConflictHttpException $error) {
            $this->assertStringNotContainsString('PRIVATE_', $error->getMessage());
        }
        $this->assertSame($old, $this->content);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function testReadbackMismatchDoesNotOverwriteAConcurrentWritersFile(): void
    {
        $this->writeMode = 'mismatch';
        try {
            $this->save(['motd' => 'new']);
            $this->fail('Expected readback mismatch');
        } catch (ConflictHttpException) {
            $this->assertSame('external-change=keep', $this->content);
        }
        $this->assertSame(1, $this->writes);
    }

    public function testNativeRequestPreservesEmptyAndSpacedMotdWithoutRelaxingOtherFields(): void
    {
        foreach (['', '  hello  '] as $motd) {
            $request = \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest::create('/configuration', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['properties' => ['motd' => $motd], 'hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64)]));
            $request->setRouteResolver(function () use ($request) {
                $route = (new \Illuminate\Routing\Route('PUT', '/configuration', fn () => null))->bind($request);
                $route->setParameter('server', $this->server());

                return $route;
            });
            (new \Pterodactyl\Http\Middleware\TrimStrings())->handle($request, fn ($r) => (new \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull())->handle($r, fn () => null));
            $prepare = new \ReflectionMethod($request, 'prepareForValidation');
            $prepare->invoke($request);
            $this->assertSame($motd, $request->input('properties.motd'));
            $this->assertArrayHasKey('runtime_hash', $request->rules());
            $this->assertSame(\Pterodactyl\Models\Permission::ACTION_FILE_UPDATE, $request->permission());
        }
    }

    public function testUnsupportedRequestStillRequiresRuntimeHashAndNativeReadPermission(): void
    {
        DB::table('eggs')->update(['name' => 'Unsupported Custom Minecraft']);
        $request = \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest::create('/configuration', 'PUT');
        $request->setRouteResolver(function () use ($request) {
            $route = (new \Illuminate\Routing\Route('PUT', '/configuration', fn () => null))->bind($request);
            $route->setParameter('server', $this->server());

            return $route;
        });
        $this->assertContains('required', $request->rules()['runtime_hash']);
        $this->assertContains('array', $request->rules()['properties']);
        $this->assertSame(\Pterodactyl\Models\Permission::ACTION_FILE_READ_CONTENT, (new \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationReadRequest())->permission());
    }

    protected function worldRead(): array
    {
        $this->content .= "level-name=world\nlevel-seed=\nlevel-type=minecraft\\:normal\nresource-pack=\nresource-pack-sha1=\nrequire-resource-pack=false\n";

        return $this->service->read($this->server());
    }

    protected function worldBackup(array $read, array $override = []): string
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        DB::table('backups')->insert($override + ['server_id' => 1, 'uuid' => $uuid, 'name' => $read['world_backup_name'],
            'ignored_files' => '[]', 'is_successful' => true, 'is_locked' => true, 'bytes' => 100, 'checksum' => 'sha256:test-fixture',
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    public function testWorldChangeRequiresCompletedBackupBeforeAnyWrite(): void
    {
        $read = $this->worldRead();
        try {
            $this->save(['level-name' => 'new-world'], $read);
            $this->fail('World change must require full backup');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $this->writes);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function testUploadedMapCanBeSelectedWithoutRenamingOrDeletingAnything(): void
    {
        $read = $this->worldRead();
        $this->entries[] = ['name' => 'my-map', 'file' => false, 'symlink' => false];
        $this->directories['/my-map'] = [['name' => 'level.dat', 'file' => true, 'symlink' => false, 'size' => 100]];
        $backup = $this->worldBackup($read);
        $result = $this->save(['level-name' => 'my-map'], $read, $backup);
        $this->assertSame('my-map', $result['properties']['level-name']);
        $this->assertSame(1, $this->writes);
        $this->assertSame('my-map', $this->entries[2]['name']);
        $this->assertSame(1, DB::table('backups')->count());
    }

    public function testNewWorldSeedAndGeneratorAreSavedOnlyWithFullBackup(): void
    {
        $read = $this->worldRead();
        $result = $this->save(['level-name' => 'fresh-world', 'level-seed' => '1234567890123456789', 'level-type' => 'minecraft:flat'], $read, $this->worldBackup($read));
        $this->assertSame('1234567890123456789', $result['properties']['level-seed']);
        $this->assertSame('minecraft:flat', $result['properties']['level-type']);
        $this->assertSame(1, $this->writes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeBackups')]
    public function testUnsafeBackupsCannotAuthorizeWorldChange(array $overrides): void
    {
        $read = $this->worldRead();
        $backup = $this->worldBackup($read, $overrides);
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['level-name' => 'fresh-world'], $read, $backup);
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public static function unsafeBackups(): array
    {
        return [[['server_id' => 999]], [['is_successful' => false]], [['is_locked' => false]], [['completed_at' => null]],
            [['bytes' => 0]], [['checksum' => '']], [['ignored_files' => '["world/"]']], [['name' => 'unrelated backup']],
            [['deleted_at' => '2026-01-01']], [['created_at' => '2000-01-01']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeMapEntries')]
    public function testSymlinkFilesAndUnextractedMapsCannotBeSelected(array $entry, array $level): void
    {
        $read = $this->worldRead();
        $this->entries[] = $entry + ['name' => 'uploaded-map', 'file' => false, 'symlink' => false];
        $this->directories['/uploaded-map'] = $level;
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['level-name' => 'uploaded-map'], $read, $this->worldBackup($read));
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public static function unsafeMapEntries(): array
    {
        return [[['symlink' => true], []], [['file' => true], []], [[], []], [[], [['name' => 'level.dat', 'file' => true, 'symlink' => true, 'size' => 100]]]];
    }

    public function testBackupExclusionsBlockWorldSaveButNotOrdinarySettings(): void
    {
        $read = $this->worldRead();
        $this->entries[] = ['name' => '.pteroignore', 'file' => true, 'symlink' => false, 'size' => 10];
        $this->ignore = 'world/';
        try {
            $this->save(['level-name' => 'fresh-world'], $read, $this->worldBackup($read));
            $this->fail('Excluded world is not a full safety backup');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $this->writes);
        }
        $this->assertSame('new message', $this->save(['motd' => 'new message'], $read)['properties']['motd']);
    }

    public function testResourcePackCanBeConfiguredAndClearedWithoutWorldBackup(): void
    {
        $read = $this->worldRead();
        $saved = $this->save(['resource-pack' => 'https://example.com/pack.zip', 'resource-pack-sha1' => str_repeat('a', 40), 'require-resource-pack' => true], $read);
        $this->assertTrue($saved['properties']['require-resource-pack']);
        $cleared = $this->save(['resource-pack' => '', 'resource-pack-sha1' => '', 'require-resource-pack' => false], $saved);
        $this->assertSame('', $cleared['properties']['resource-pack']);
        $this->assertSame(0, DB::table('backups')->count());
    }

    public function testRequiredPackCannotBeEnabledWithoutUrl(): void
    {
        $read = $this->worldRead();
        $this->expectException(ConflictHttpException::class);
        $this->save(['require-resource-pack' => true], $read);
    }

    public function testUnrelatedSaveDoesNotRewriteOrAdoptAnExistingInvalidPackSetting(): void
    {
        $this->worldRead();
        $this->content = str_replace('require-resource-pack=false', 'require-resource-pack=true', $this->content);
        $saved = $this->save(['motd' => 'Only this changes']);
        $this->assertSame('Only this changes', $saved['properties']['motd']);
        $this->assertSame('', $saved['properties']['resource-pack']);
        $this->assertTrue($saved['properties']['require-resource-pack']);
        $this->assertSame(1, $this->writes);
    }

    public function testExistingWorldCannotBeSilentlyRegeneratedBySeedChange(): void
    {
        $read = $this->worldRead();
        $this->entries[] = ['name' => 'world', 'file' => false, 'symlink' => false];
        $this->expectException(ConflictHttpException::class);
        try {
            $this->save(['level-seed' => 'new seed'], $read, $this->worldBackup($read));
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public function testNumericWorldNamesAreComparedExactlyInsteadOfLooseNumericAliases(): void
    {
        $read = $this->worldRead();
        $this->entries[] = ['name' => '01', 'file' => false, 'symlink' => false];
        $saved = $this->save(['level-name' => '1'], $read, $this->worldBackup($read));
        $this->assertSame('1', $saved['properties']['level-name']);
    }

    public function testNativeNormalizationPreservesExplicitEmptyWorldAndPackText(): void
    {
        $body = ['properties' => ['level-seed' => '', 'resource-pack' => '', 'resource-pack-sha1' => '', 'level-name' => ' bad-name ']];
        $request = \Pterodactyl\Http\Requests\Api\Client\Servers\BeaconConfigurationWriteRequest::create('/configuration', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        $request->setRouteResolver(function () use ($request) {
            $route = (new \Illuminate\Routing\Route('PUT', '/configuration', fn () => null))->bind($request);
            $route->setParameter('server', $this->server());

            return $route;
        });
        (new \Pterodactyl\Http\Middleware\TrimStrings())->handle($request, fn ($r) => (new \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull())->handle($r, fn () => null));
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $this->assertSame($body['properties'], $request->input('properties'));
    }
}
