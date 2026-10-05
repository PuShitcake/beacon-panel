<?php

namespace Pterodactyl\Tests\Unit;

use Carbon\CarbonImmutable;
use Pterodactyl\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Pterodactyl\Models\BeaconOperation;
use Database\Seeders\BeaconModpackNestSeeder;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Jobs\Beacon\ProcessModpackOperationJob;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconModpackController;

class BeaconModpackInstallerContractTest extends TestCase
{
    public function testInstallerScriptContainsRequiredArchiveAndDownloadGuards(): void
    {
        $this->assertSame(
            'debian:bookworm-slim',
            (new \ReflectionClass(BeaconModpackNestSeeder::class))->getConstant('INSTALLER_IMAGE')
        );

        $method = new \ReflectionMethod(BeaconModpackNestSeeder::class, 'installScript');
        $script = $method->invoke(new BeaconModpackNestSeeder());

        $this->assertIsString($script);
        $this->assertStringContainsString('[[ "${URL}" == https://* ]]', $script);
        $this->assertStringContainsString("--proto-redir '=https'", $script);
        $this->assertStringContainsString('archive contains an unsafe path', $script);
        $this->assertStringContainsString('archive contains a symbolic link', $script);
        $this->assertStringContainsString('archive expands beyond the safety limit', $script);
        $this->assertStringContainsString('archive contains too many entries', $script);
        $this->assertStringContainsString('https://cdn.modrinth.com/', $script);
        $this->assertStringContainsString('A Modrinth file hash did not match.', $script);
        $this->assertStringContainsString('trap \'fail "The installer stopped unexpectedly at line ${LINENO}."\' ERR', $script);
        $this->assertStringContainsString('rm -rf /var/lib/apt/lists/*', $script);
        $this->assertStringContainsString('apt-get update -o Acquire::Retries=3', $script);
        $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $script);
        $this->assertStringContainsString('Installer dependency setup failed.', $script);
        $this->assertStringContainsString('! -f "${SERVER_ROOT}/unix_args.txt" && ! -f "${SERVER_ROOT}/server.jar"', $script);
        $this->assertStringContainsString('printf \'eula=true\\n\' > "${SERVER_ROOT}/eula.txt"', $script);
    }

    public function testUpdatesMoveAndRestoreUserWorldAndConfigurationFiles(): void
    {
        $response = \Mockery::mock(ResponseInterface::class);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('getDirectory')->with('/')->andReturn([
            ['name' => '.beacon'],
            ['name' => 'world'],
            ['name' => 'config'],
            ['name' => 'server.properties'],
            ['name' => 'mods'],
        ]);
        $files->expects('createDirectory')->with('preserved-operation-uuid', '/.beacon')->andReturn($response);
        $files->expects('renameFiles')->with('/', [
            ['from' => 'world', 'to' => '.beacon/preserved-operation-uuid/world'],
            ['from' => 'config', 'to' => '.beacon/preserved-operation-uuid/config'],
            ['from' => 'server.properties', 'to' => '.beacon/preserved-operation-uuid/server.properties'],
        ])->andReturn($response);
        $files->expects('deleteFiles')->with('/', ['mods'])->andReturn($response);

        $clean = new \ReflectionMethod(ProcessModpackOperationJob::class, 'cleanFilesIfRequested');
        $job = new ProcessModpackOperationJob(1);
        $preserved = $clean->invoke($job, $files, ['action' => 'update'], 'operation-uuid');
        $this->assertSame(['world', 'config', 'server.properties'], $preserved);

        $files->expects('getDirectory')->with('/')->andReturn([
            ['name' => '.beacon'],
            ['name' => 'world'],
            ['name' => 'config'],
        ]);
        $files->expects('deleteFiles')->with('/', ['world', 'config'])->andReturn($response);
        $files->expects('renameFiles')->with('/', [
            ['from' => '.beacon/preserved-operation-uuid/world', 'to' => 'world'],
            ['from' => '.beacon/preserved-operation-uuid/config', 'to' => 'config'],
            ['from' => '.beacon/preserved-operation-uuid/server.properties', 'to' => 'server.properties'],
        ])->andReturn($response);
        $files->expects('deleteFiles')->with('/.beacon', ['preserved-operation-uuid'])->andReturn($response);

        $restore = new \ReflectionMethod(ProcessModpackOperationJob::class, 'restorePreservedFiles');
        $restore->invoke($job, $files, ['preserved_paths' => $preserved], 'operation-uuid');
    }

    public function testRuntimeSelectsLoaderLaunchJarForBeaconRuntimeEggs(): void
    {
        $service = (new \ReflectionClass(ModpackRuntimeService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModpackRuntimeService::class, 'serverJarFile');

        $this->assertSame('fabric-server-launch.jar', $method->invoke($service, ['loader' => 'fabric'], 'server.jar'));
        $this->assertSame('quilt-server-launch.jar', $method->invoke($service, ['loader' => 'quilt'], 'server.jar'));
        $this->assertSame('server.jar', $method->invoke($service, ['loader' => 'vanilla'], 'server.jar'));
    }

    public function testPowerTransitionTimeoutUsesConfiguredDeadline(): void
    {
        config()->set('beacon.modpacks.power_transition_timeout_seconds', 120);
        CarbonImmutable::setTestNow('2026-09-29T12:00:00+07:00');

        try {
            $method = new \ReflectionMethod(ProcessModpackOperationJob::class, 'powerTransitionTimedOut');
            $job = new ProcessModpackOperationJob(1);

            $this->assertFalse($method->invoke($job, [
                'power_transition_started_at' => '2026-09-29T11:58:01+07:00',
            ]));
            $this->assertTrue($method->invoke($job, [
                'power_transition_started_at' => '2026-09-29T11:57:59+07:00',
            ]));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testOperationHistoryReportsWhenInstallDeletedExistingFiles(): void
    {
        $controller = (new \ReflectionClass(BeaconModpackController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BeaconModpackController::class, 'operationData');

        $operation = new BeaconOperation();
        $operation->forceFill([
            'type' => 'modpack.install',
            'status' => BeaconOperation::STATUS_SUCCEEDED,
            'payload' => ['delete_files' => true],
        ]);

        $data = $method->invoke($controller, $operation);
        $this->assertTrue($data['delete_files']);
        $this->assertSame('install (existing files deleted)', $data['history_label']);

        $operation->payload = ['intent' => ['delete_files' => true]];
        $data = $method->invoke($controller, $operation);
        $this->assertTrue($data['delete_files']);
        $this->assertSame('install (existing files deleted)', $data['history_label']);

        $operation->payload = [];
        $data = $method->invoke($controller, $operation);
        $this->assertFalse($data['delete_files']);
        $this->assertSame('install', $data['history_label']);
    }

    public function testModpackReleaseMustDeclareAndMatchTheSelectedSoftwareLoader(): void
    {
        $controller = (new \ReflectionClass(BeaconModpackController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BeaconModpackController::class, 'assertReleaseCompatible');
        $capabilities = ['name' => 'Forge', 'modpack_loaders' => ['forge']];

        $method->invoke($controller, $capabilities, ['loader' => 'forge']);
        $this->addToAssertionCount(1);

        foreach ([['loader' => 'fabric'], ['loader' => null]] as $release) {
            try {
                $method->invoke($controller, $capabilities, $release);
                $this->fail('An incompatible or missing modpack loader was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
