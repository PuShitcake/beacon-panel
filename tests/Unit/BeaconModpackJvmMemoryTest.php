<?php

namespace Pterodactyl\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Server;
use PHPUnit\Framework\TestCase;
use Pterodactyl\Models\BeaconOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Jobs\Beacon\ProcessModpackOperationJob;
use Pterodactyl\Beacon\Modpacks\ModpackJvmMemoryService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\PrepareModpackOperationService;
use Pterodactyl\Beacon\Modpacks\CurseForgeInstalledRuntimeService;
use Pterodactyl\Beacon\Content\ReconcileManagedModsForModpackService;

class BeaconModpackJvmMemoryTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public static function heapCases(): array
    {
        return [
            'reported 8G pack on 6GiB instance' => ["-Xmx8G\n-Xms8G\n", 6144, "-Xmx5529M\n-Xms128M\n"],
            'low memory allocation' => ["-Xms8G -Xmx8G\n", 64, "-Xms57M -Xmx57M\n"],
            'duplicate and quoted flags' => ["\"-Xms8G\" -Xms8192m '-Xmx8589934592' -Xmx8388608k\n", 1024, "\"-Xms128M\" -Xms128M '-Xmx921M' -Xmx921M\n"],
            'comments and other options preserved' => ["# -Xms8G\r\n-Dtext=\"value -Xms8G # example\"\r\n-Xms8G # initial\r\n-Xmx8G\r\n-XX:+UseG1GC\r\n", 6144, "# -Xms8G\r\n-Dtext=\"value -Xms8G # example\"\r\n-Xms128M # initial\r\n-Xmx5529M\r\n-XX:+UseG1GC\r\n"],
            'missing flags added' => ["# JVM options\n-XX:+UseG1GC\n", 1024, "# JVM options\n-XX:+UseG1GC\n-Xms128M\n-Xmx921M\n"],
            'unlimited allocation preserved' => ["-Xms8G\n-Xmx8G\n", 0, "-Xms8G\n-Xmx8G\n"],
        ];
    }

    #[DataProvider('heapCases')]
    public function testItNormalizesOnlyHeapArgumentsAndIsRetrySafe(string $input, int $memory, string $expected): void
    {
        $service = new ModpackJvmMemoryService();
        $this->assertSame($expected, $service->normalize($input, $memory));
        $this->assertSame($expected, $service->normalize($expected, $memory));
    }

    public function testInstanceStartupKeepsHeadroomAndRemainsDynamicWithoutEditingTheEgg(): void
    {
        $startup = 'java @user_jvm_args.txt -server -Xmx{{SERVER_MEMORY}}M -Dterminal.ansi=true @unix_args.txt';
        $expected = 'java @user_jvm_args.txt -server -Xmx$(({{SERVER_MEMORY}} * 90 / 100))M -Dterminal.ansi=true @unix_args.txt';
        $service = new ModpackJvmMemoryService();

        $this->assertSame($expected, $service->startup($startup, 6144));
        $this->assertSame($expected, $service->startup($expected, 1024));
        $this->assertSame($startup, $service->startup($startup, 0));
    }

    public function testMissingJvmFileDoesNotBlockLegacyJarPacks(): void
    {
        [$server, $files, $states] = $this->repositories([]);
        (new ModpackJvmMemoryService())->apply($server, $files, $states);
        $this->addToAssertionCount(1);
    }

    public function testFileWriteIsVerifiedBeforeSuccess(): void
    {
        [$server, $files, $states] = $this->repositories($this->regularFile());
        $states->expects('setServer')->with($server)->andReturnSelf();
        $states->expects('getDetails')->andReturn(['state' => 'offline']);
        $files->expects('getContent')->twice()->with('/user_jvm_args.txt', 65536)->andReturn("-Xms8G\n-Xmx8G\n", "-Xms128M\n-Xmx5529M\n");
        $files->expects('putContent')->with('/user_jvm_args.txt', "-Xms128M\n-Xmx5529M\n")->andReturn(new Response());

        (new ModpackJvmMemoryService())->apply($server, $files, $states);
        $this->addToAssertionCount(1);
    }

    public function testVerificationFailureIsNotReportedAsSuccess(): void
    {
        [$server, $files, $states] = $this->repositories($this->regularFile());
        $states->expects('setServer')->with($server)->andReturnSelf();
        $states->expects('getDetails')->andReturn(['state' => 'offline']);
        $files->expects('getContent')->twice()->with('/user_jvm_args.txt', 65536)->andReturn("-Xms8G\n-Xmx8G\n", 'unexpected contents');
        $files->expects('putContent')->andReturn(new Response());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be verified');
        (new ModpackJvmMemoryService())->apply($server, $files, $states);
    }

    public function testRunningServerIsNeverModified(): void
    {
        [$server, $files, $states] = $this->repositories($this->regularFile());
        $states->expects('setServer')->with($server)->andReturnSelf();
        $states->expects('getDetails')->andReturn(['state' => 'running']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stop the server');
        (new ModpackJvmMemoryService())->apply($server, $files, $states);
    }

    public function testReinstallNeverReusesAVerificationFlagFromAPreviousAttempt(): void
    {
        $server = \Mockery::mock(Server::class);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('setServer')->with($server)->andReturnSelf();
        $verification = \Mockery::mock(CurseForgeInstalledRuntimeService::class);
        $verification->expects('prepare')->with($files);
        $release = ['minecraft_version' => '1.18.2'];
        $payload = ['release' => $release, 'runtime_verified' => true, 'mutation_started' => true, 'managed_mods_preserved' => []];
        $operation = \Mockery::mock(BeaconOperation::class);
        $operation->shouldReceive('getAttribute')->with('uuid')->andReturn('test-operation');
        $operation->expects('forceFill')->with(\Mockery::on(function ($data) {
            $this->assertFalse($data['payload']['runtime_verified']);
            $this->assertSame('wait_curseforge', $data['payload']['phase']);

            return true;
        }))->andReturnSelf();
        $operation->expects('save')->andReturn(true);
        $runtime = \Mockery::mock(ModpackRuntimeService::class);
        $runtime->expects('switchToCurseForge')->with($server, $release)->andReturn($server);
        $reinstall = \Mockery::mock(ReinstallServerService::class);
        $reinstall->expects('handle')->with($server)->andThrow(new \RuntimeException('Stopped after verifying retry reset'));
        $phase = 'switch_curseforge';
        $arguments = [
            $operation, $server, &$payload, &$phase,
            \Mockery::mock(PrepareModpackOperationService::class), $runtime,
            $reinstall, $files, \Mockery::mock(DeleteBackupService::class),
            \Mockery::mock(ReconcileManagedModsForModpackService::class),
            \Mockery::mock(ModpackJvmMemoryService::class),
            \Mockery::mock(DaemonServerRepository::class), $verification,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stopped after verifying retry reset');
        (new \ReflectionMethod(ProcessModpackOperationJob::class, 'processCurseForgeEgg'))
            ->invokeArgs(new ProcessModpackOperationJob(1), $arguments);
    }

    public function testCurseForgeFinalizationCannotReconcileModsBeforeInstalledRuntimeVerification(): void
    {
        $server = \Mockery::mock(Server::class);
        $server->expects('fresh')->andReturnSelf();
        $files = \Mockery::mock(DaemonFileRepository::class);
        $states = \Mockery::mock(DaemonServerRepository::class);
        $verification = \Mockery::mock(CurseForgeInstalledRuntimeService::class);
        $release = ['minecraft_version' => '1.18.1'];
        $verification->expects('resolve')->with($server, $files, $states, $release)
            ->andThrow(new \RuntimeException('Installed runtime could not be verified'));
        $payload = ['release' => $release];
        $phase = 'finalize_curseforge';
        $arguments = [
            \Mockery::mock(BeaconOperation::class), $server, &$payload, &$phase,
            \Mockery::mock(PrepareModpackOperationService::class),
            \Mockery::mock(ModpackRuntimeService::class),
            \Mockery::mock(ReinstallServerService::class), $files,
            \Mockery::mock(DeleteBackupService::class),
            \Mockery::mock(ReconcileManagedModsForModpackService::class),
            \Mockery::mock(ModpackJvmMemoryService::class), $states, $verification,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Installed runtime could not be verified');
        (new \ReflectionMethod(ProcessModpackOperationJob::class, 'processCurseForgeEgg'))
            ->invokeArgs(new ProcessModpackOperationJob(1), $arguments);
    }

    public function testCorrectedRuntimeIsSyncedBeforeModsAreReconciled(): void
    {
        $server = \Mockery::mock(Server::class);
        $server->expects('fresh')->twice()->andReturnSelf();
        $files = \Mockery::mock(DaemonFileRepository::class);
        $states = \Mockery::mock(DaemonServerRepository::class);
        $verification = \Mockery::mock(CurseForgeInstalledRuntimeService::class);
        $release = ['minecraft_version' => '1.18.1'];
        $corrected = ['minecraft_version' => '1.18.2', 'java_version' => 17];
        $verification->expects('resolve')->with($server, $files, $states, $release)->andReturn($corrected);
        $runtime = \Mockery::mock(ModpackRuntimeService::class);
        $runtime->expects('switchToCurseForge')->with($server, $corrected)
            ->andThrow(new \RuntimeException('Runtime sync failed'));
        $payload = ['release' => $release];
        $phase = 'finalize_curseforge';
        $arguments = [
            \Mockery::mock(BeaconOperation::class), $server, &$payload, &$phase,
            \Mockery::mock(PrepareModpackOperationService::class), $runtime,
            \Mockery::mock(ReinstallServerService::class), $files,
            \Mockery::mock(DeleteBackupService::class),
            \Mockery::mock(ReconcileManagedModsForModpackService::class),
            \Mockery::mock(ModpackJvmMemoryService::class), $states, $verification,
        ];

        try {
            (new \ReflectionMethod(ProcessModpackOperationJob::class, 'processCurseForgeEgg'))
                ->invokeArgs(new ProcessModpackOperationJob(1), $arguments);
            $this->fail('A failed runtime sync must abort finalization.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Runtime sync failed', $exception->getMessage());
            $this->assertArrayNotHasKey('runtime_verified', $payload);
            $this->assertArrayNotHasKey('managed_mod_reconciliation', $payload);
        }
    }

    public function testCurseForgeFinalizationCannotSucceedBeforeJvmMemoryIsVerified(): void
    {
        $server = \Mockery::mock(Server::class);
        $server->expects('fresh')->andReturnSelf();
        $files = \Mockery::mock(DaemonFileRepository::class);
        $states = \Mockery::mock(DaemonServerRepository::class);
        $memory = \Mockery::mock(ModpackJvmMemoryService::class);
        $memory->expects('apply')->with($server, $files, $states)
            ->andThrow(new \RuntimeException('JVM verification failure before completion'));
        $payload = ['managed_mod_reconciliation' => [], 'runtime_verified' => true];
        $phase = 'finalize_curseforge';
        $arguments = [
            \Mockery::mock(BeaconOperation::class), $server, &$payload, &$phase,
            \Mockery::mock(PrepareModpackOperationService::class),
            \Mockery::mock(ModpackRuntimeService::class),
            \Mockery::mock(ReinstallServerService::class), $files,
            \Mockery::mock(DeleteBackupService::class),
            \Mockery::mock(ReconcileManagedModsForModpackService::class), $memory, $states,
            \Mockery::mock(CurseForgeInstalledRuntimeService::class),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JVM verification failure before completion');
        (new \ReflectionMethod(ProcessModpackOperationJob::class, 'processCurseForgeEgg'))
            ->invokeArgs(new ProcessModpackOperationJob(1), $arguments);
    }

    public static function unsafeFiles(): array
    {
        return [
            'symlink' => [['file' => true, 'symlink' => true, 'size' => 100]],
            'directory' => [['file' => false, 'symlink' => false, 'size' => 100]],
            'oversized' => [['file' => true, 'symlink' => false, 'size' => 65537]],
            'unknown metadata' => [[]],
        ];
    }

    #[DataProvider('unsafeFiles')]
    public function testUnsafeFilesAreRejectedBeforeReading(array $entry): void
    {
        [$server, $files, $states] = $this->repositories([array_merge($entry, ['name' => 'user_jvm_args.txt'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bounded regular file');
        (new ModpackJvmMemoryService())->apply($server, $files, $states);
    }

    private function regularFile(): array
    {
        return [['name' => 'user_jvm_args.txt', 'file' => true, 'symlink' => false, 'size' => 100]];
    }

    private function repositories(array $directory): array
    {
        $server = \Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('memory')->andReturn(6144);
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('setServer')->with($server)->andReturnSelf();
        $files->expects('getDirectory')->with('/')->andReturn($directory);

        return [$server, $files, \Mockery::mock(DaemonServerRepository::class)];
    }
}
