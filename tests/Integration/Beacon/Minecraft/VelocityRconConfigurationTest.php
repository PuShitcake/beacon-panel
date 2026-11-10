<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Minecraft\VelocityRconConfiguration;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Isolated SQLite and mocked files only. Never reads a configured shared database. */
class VelocityRconConfigurationTest extends TestCase
{
    private $events;
    private VelocityRconConfiguration $service;
    private string $body = "enable: false\r\nhost: 0.0.0.0\r\nport: 25575\r\npassword: TEST_ONLY_ORIGINAL\r\npermissions:\r\n  regex:\r\n    enable: true\r\n";
    private string $jar = 'fixture-jar';
    private string $state = 'offline';
    private string $mode = 'normal';
    private int $writes = 0;
    private bool $symlink = false;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'velocity_rcon_test');
        config()->set('database.connections.velocity_rcon_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Storage::fake('local');
        foreach (['egg_variables' => ['egg_id', 'env_variable'], 'server_variables' => ['server_id', 'variable_id', 'variable_value'],
            'beacon_content_installations' => ['server_id', 'provider', 'project_id', 'project_type', 'loader', 'destination', 'status', 'version_number', 'filename', 'size', 'sha512'],
            'beacon_minecraft_services' => ['server_id', 'rcon_enabled']] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->increments('id');
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        DB::table('beacon_content_installations')->insert(['id' => 1, 'server_id' => 1, 'provider' => 'modrinth', 'project_id' => VelocityRconConfiguration::PROJECT,
            'project_type' => 'plugin', 'loader' => 'velocity', 'destination' => 'plugins', 'status' => 'installed', 'version_number' => '1.0.7',
            'filename' => 'velocircon.jar', 'size' => strlen($this->jar), 'sha512' => hash('sha512', $this->jar)]);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->allows('setServer')->andReturnSelf();
        $files->allows('getDirectory')->andReturnUsing(fn ($path) => match ($path) {
            '/' => [['name' => 'plugins', 'file' => false, 'symlink' => $this->symlink]],
            '/plugins' => [['name' => 'velocircon', 'file' => false, 'symlink' => false], ['name' => 'velocircon.jar', 'file' => true, 'symlink' => false, 'size' => strlen($this->jar)]],
            '/plugins/velocircon' => [['name' => 'rcon.yml', 'file' => true, 'symlink' => false, 'size' => strlen($this->body)]],
        });
        $files->allows('getFileHashes')->andReturnUsing(fn () => ['sha512' => hash('sha512', $this->jar)]);
        $files->allows('putContent')->with(VelocityRconConfiguration::PATH, \Mockery::type('string'))->andReturnUsing(function ($path, $body) {
            ++$this->writes;
            if ($this->mode !== 'fail') {
                $this->body = $this->mode === 'mismatch' ? str_replace('TEST_ONLY_NEW', 'OUTSIDE_EDIT', $body) : $body;
            }
            if (in_array($this->mode, ['fail', 'lost-ack'], true)) {
                throw new \RuntimeException('PRIVATE_FIXTURE_DETAIL');
            }

            return new \GuzzleHttp\Psr7\Response(204);
        });
        $reader = \Mockery::mock(VanillaFileReader::class);
        $reader->allows('read')->andReturnUsing(fn () => $this->body);
        $power = \Mockery::mock(DaemonServerRepository::class);
        $power->allows('setServer')->andReturnSelf();
        $power->allows('getDetails')->andReturnUsing(fn () => ['state' => $this->state]);
        app()->instance(DaemonServerRepository::class, $power);
        $this->service = new VelocityRconConfiguration($files, $reader);
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        DB::disconnect('velocity_rcon_test');
        parent::tearDown();
    }

    private function server(): Server
    {
        return (new Server())->forceFill(['id' => 1, 'egg_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111']);
    }

    private function enable(): string
    {
        return $this->service->apply($this->server(), ['enable-rcon' => true, 'rcon.port' => 25610, 'rcon.password' => 'TEST_ONLY_NEW']);
    }

    public function testEnableReadbackAndEncryptedRestorePointPreservePermissionSettings(): void
    {
        $before = $this->body;
        $this->assertSame($before, $this->enable());
        $this->assertSame('true', $this->service->inspect($this->server())['enable-rcon']);
        $this->assertStringContainsString("permissions:\r\n  regex:\r\n    enable: true", $this->body);
        $backups = Storage::disk('local')->allFiles('beacon/configuration');
        $this->assertCount(1, $backups);
        $ciphertext = Storage::disk('local')->get($backups[0]);
        $this->assertStringNotContainsString('TEST_ONLY_ORIGINAL', $ciphertext);
        $this->assertSame($before, Crypt::decryptString($ciphertext));
        $this->service->restore($this->server(), $before);
        $this->assertSame($before, $this->body);
    }

    public function testLostWriteAcknowledgementRequiresAnExactReadback(): void
    {
        $this->mode = 'lost-ack';
        $this->enable();
        $this->assertSame(1, $this->writes);
        $this->assertSame('TEST_ONLY_NEW', $this->service->inspect($this->server())['rcon.password']);
    }

    public function testFailedWriteDoesNotReportSuccessOrChangeOriginalFile(): void
    {
        $before = $this->body;
        $this->mode = 'fail';
        try {
            $this->enable();
            $this->fail('Write failure must not succeed.');
        } catch (ConflictHttpException) {
            $this->assertSame($before, $this->body);
        }
    }

    public function testUnknownConcurrentReadbackIsPreservedForManualReview(): void
    {
        $this->mode = 'mismatch';
        try {
            $this->enable();
            $this->fail('Mismatched readback must not succeed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('rollback also failed', $exception->getMessage());
            $this->assertStringContainsString('OUTSIDE_EDIT', $this->body);
            $this->assertSame(1, $this->writes);
        }
    }

    public function testMissingPluginIsAnActionableConflictWithoutWrites(): void
    {
        DB::table('beacon_content_installations')->delete();
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Install Velocircon');
        $this->enable();
    }

    public function testChangedPluginBytesAreRejected(): void
    {
        $this->jar = 'changed bytes';
        $this->expectException(ConflictHttpException::class);
        $this->enable();
    }

    public function testUnverifiedPluginVersionCannotChangeConfiguration(): void
    {
        DB::table('beacon_content_installations')->update(['version_number' => '0.0.0']);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Install Velocircon');
        $this->enable();
    }

    public function testMissingPluginDoesNotPreventClearingOurOwnSecretAndDisabling(): void
    {
        $this->enable();
        DB::table('beacon_content_installations')->delete();
        $this->service->apply($this->server(), ['enable-rcon' => false, 'rcon.port' => null, 'rcon.password' => null]);
        $properties = $this->service->inspect($this->server(), false);
        $this->assertSame('false', $properties['enable-rcon']);
        $this->assertSame('', $properties['rcon.password']);
    }

    public function testEnvironmentOverridesAreRejected(): void
    {
        DB::table('egg_variables')->insert(['id' => 1, 'egg_id' => 1, 'env_variable' => 'VELOCIRCON__PASSWORD']);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('environment overrides');
        $this->enable();
    }

    public function testSymlinkedParentDirectoriesAreRejected(): void
    {
        $this->symlink = true;
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('symlinks');
        $this->enable();
    }

    public function testRunningServerCannotBeWritten(): void
    {
        $this->state = 'running';
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Stop Velocity');
        $this->enable();
    }

    public function testManagedPluginCannotBeRemovedUntilRconIsDisabled(): void
    {
        DB::table('beacon_minecraft_services')->insert(['server_id' => 1, 'rcon_enabled' => 1]);
        $this->expectException(ConflictHttpException::class);
        VelocityRconConfiguration::assertPluginMutable($this->server(), BeaconContentInstallation::findOrFail(1));
    }

    public function testDisabledRconDoesNotBlockNormalPluginManagement(): void
    {
        DB::table('beacon_minecraft_services')->insert(['server_id' => 1, 'rcon_enabled' => 0]);
        VelocityRconConfiguration::assertPluginMutable($this->server(), BeaconContentInstallation::findOrFail(1));
        $this->assertSame(0, $this->writes);
    }
}
