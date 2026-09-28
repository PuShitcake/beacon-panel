<?php

namespace Pterodactyl\Tests\Integration\Jobs\Beacon;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Jobs\Beacon\RemoveContentJob;
use Pterodactyl\Jobs\Beacon\InstallContentJob;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Content\PrepareContentMutationService;

class ContentMutationJobTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testInstallReportsRollbackFailureAndStopsRetrying(): void
    {
        $server = $this->createServerModel();
        $operation = $this->operation($server, 'content.install', [
            'provider' => 'modrinth',
            'project_type' => 'plugin',
            'loader' => 'paper',
            'game_version' => '1.21.8',
            'destination' => 'plugins',
            'plan' => [
                'resolved_dependencies' => ['dependency-project'],
                'files' => [
                    $this->plannedFile('root-project', 'root-version', 'root.jar', false),
                    $this->plannedFile('dependency-project', 'dependency-version', 'dependency.jar', true),
                ],
            ],
        ]);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->with(
            \Mockery::type(BeaconOperation::class),
            \Mockery::on(fn (Server $candidate) => $candidate->is($server))
        )->andReturnTrue();
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('setServer')->with(\Mockery::on(fn (Server $candidate) => $candidate->is($server)))->andReturnSelf();
        $files->expects('getDirectory')->with('/plugins')->andReturn([]);
        $files->expects('pull')->once()->with('https://cdn.modrinth.com/root.jar', '/plugins', [
            'filename' => 'root.jar',
            'foreground' => true,
        ]);
        $files->expects('pull')->once()->with('https://cdn.modrinth.com/dependency.jar', '/plugins', [
            'filename' => 'dependency.jar',
            'foreground' => true,
        ])->andThrow(new \RuntimeException('dependency download failed'));
        $files->expects('deleteFiles')->with('/plugins', ['root.jar', 'dependency.jar'])->andThrow(new \RuntimeException('rollback failed'));

        (new InstallContentJob($operation->id))->handle($prepare, $files);

        $operation->refresh();
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('content_install_rollback_failed', $operation->error_code);
        $this->assertSame('failed', data_get($operation->result, 'rollback.status'));
        $this->assertSame(['root.jar', 'dependency.jar'], data_get($operation->result, 'rollback.files'));
    }

    public function testRemoveDoesNotReportSuccessWhenTheManifestIsMissingButTheFileExists(): void
    {
        $server = $this->createServerModel();
        $operation = $this->operation($server, 'content.remove', [
            'installation_id' => 999999,
            'project_id' => 'root-project',
            'version_id' => 'root-version',
            'destination' => 'plugins',
            'filename' => 'root.jar',
        ]);
        $prepare = \Mockery::mock(PrepareContentMutationService::class);
        $prepare->expects('handle')->with(
            \Mockery::type(BeaconOperation::class),
            \Mockery::on(fn (Server $candidate) => $candidate->is($server))
        )->andReturnTrue();
        $files = \Mockery::mock(DaemonFileRepository::class);
        $files->expects('setServer')->with(\Mockery::on(fn (Server $candidate) => $candidate->is($server)))->andReturnSelf();
        $files->expects('getDirectory')->with('/plugins')->andReturn([['name' => 'root.jar']]);

        (new RemoveContentJob($operation->id))->handle($prepare, $files);

        $operation->refresh();
        $this->assertSame(BeaconOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('content_remove_failed', $operation->error_code);
        $this->assertStringContainsString('managed file still exists', $operation->error_message);
    }

    private function operation(Server $server, string $type, array $payload): BeaconOperation
    {
        $user = User::factory()->create();

        return BeaconOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'actor_key' => 'user:' . $user->id,
            'type' => $type,
            'status' => BeaconOperation::STATUS_PENDING,
            'correlation_id' => Uuid::uuid4()->toString(),
            'idempotency_key' => 'beacon-test-' . Uuid::uuid4()->toString(),
            'request_hash' => str_repeat('a', 64),
            'payload' => $payload,
        ]);
    }

    private function plannedFile(string $projectId, string $versionId, string $filename, bool $dependency): array
    {
        return [
            'project_id' => $projectId,
            'version_id' => $versionId,
            'url' => "https://cdn.modrinth.com/{$filename}",
            'filename' => $filename,
            'size' => 100,
            'sha512' => str_repeat('a', 128),
            'dependency' => $dependency,
        ];
    }
}
