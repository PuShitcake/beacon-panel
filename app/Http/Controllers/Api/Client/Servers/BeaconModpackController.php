<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Modpacks\ModpackRuntimeService;
use Pterodactyl\Jobs\Beacon\ProcessModpackOperationJob;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Modpacks\Providers\ModpackProviderRegistry;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModpackRequest;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModpackInstallRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModpackMutationRequest;

class BeaconModpackController extends ClientApiController
{
    public function __construct(
        private ModpackProviderRegistry $providers,
        private ModpackRuntimeService $runtime,
        private StartContentOperationService $operations,
        private ServerSoftwareCapabilityService $capabilities,
    ) {
        parent::__construct();
    }

    public function context(BeaconModpackRequest $request, Server $server): JsonResponse
    {
        $capabilities = $this->capabilities->resolve($server);
        $installation = BeaconModpackInstallation::query()->where('server_id', $server->id)->first();
        $active = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'modpack.%')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->latest('id')
            ->first();

        return new JsonResponse(['data' => [
            'enabled' => (bool) config('beacon.modpacks.enabled'),
            'available' => $capabilities['modpacks'],
            'authorized' => true,
            'software' => $capabilities['software'],
            'software_name' => $capabilities['name'],
            'unavailable_reason' => $capabilities['modpack_unavailable_reason'],
            'providers' => $this->providers->status(),
            'installation' => $installation ? $this->installationData($installation) : null,
            'active_operation' => $active ? $this->operationData($active) : null,
            'requires_stopped_server' => true,
            'backup_before_mutation' => true,
        ]]);
    }

    public function search(BeaconModpackRequest $request, Server $server): JsonResponse
    {
        $this->assertModpackAvailable($server);

        return new JsonResponse(['data' => $this->provider($request->string('provider')->toString())->search(
            $request->string('query')->toString(),
            $request->integer('page', 1),
            $request->integer('page_size', 20),
        )]);
    }

    public function versions(BeaconModpackRequest $request, Server $server, string $provider, string $projectId): JsonResponse
    {
        $this->assertModpackAvailable($server);

        return new JsonResponse(['data' => $this->provider($provider)->versions($projectId)]);
    }

    public function history(BeaconModpackRequest $request, Server $server): JsonResponse
    {
        $operations = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'modpack.%')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (BeaconOperation $operation) => $this->operationData($operation));

        return new JsonResponse(['data' => $operations]);
    }

    public function operation(BeaconModpackRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $operation = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('uuid', $operationUuid)
            ->where('type', 'like', 'modpack.%')
            ->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    public function retry(BeaconModpackMutationRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $capabilities = $this->assertModpackAvailable($server);

        $previous = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('uuid', $operationUuid)
            ->where('type', 'like', 'modpack.%')
            ->firstOrFail();
        if ($previous->status !== BeaconOperation::STATUS_FAILED) {
            throw new ConflictHttpException('Only failed modpack operations can be retried.');
        }
        if (data_get($previous->result, 'rollback.status') === 'failed') {
            throw new ConflictHttpException('Automatic rollback failed. Review and recover the server before retrying.');
        }

        $payload = $previous->payload;
        $action = (string) ($payload['action'] ?? '');
        if (!in_array($action, ['install', 'update', 'reinstall', 'restore', 'uninstall'], true)) {
            throw new ConflictHttpException('This operation cannot be retried.');
        }
        if ($action === 'install' && BeaconModpackInstallation::query()->where('server_id', $server->id)->exists()) {
            throw new ConflictHttpException('This server already has a managed modpack.');
        }
        if ($action !== 'install') {
            $this->installation($server, (int) ($payload['installation_id'] ?? 0));
        }
        if (isset($payload['release']) && is_array($payload['release'])) {
            $this->runtime->validateTargets($payload['release']);
            $this->assertReleaseCompatible($capabilities, $payload['release']);
        }

        unset($payload['mutation_started'], $payload['failure_message'], $payload['progress']);
        $payload['phase'] = in_array($action, ['restore', 'uninstall'], true) ? 'prepare_restore' : 'prepare';

        return $this->start($request, $server, $action, $payload);
    }

    public function install(BeaconModpackInstallRequest $request, Server $server): JsonResponse
    {
        $capabilities = $this->assertModpackAvailable($server);
        if (BeaconModpackInstallation::query()->where('server_id', $server->id)->exists()) {
            throw new ConflictHttpException('This server already has a managed modpack. Use update, reinstall, or uninstall.');
        }
        if ($request->boolean('delete_files') && !hash_equals($server->name, $request->string('confirmation')->toString())) {
            throw new HttpException(422, 'Type the exact server name to confirm deleting its files.');
        }
        $provider = $this->provider($request->string('provider')->toString());
        $release = $this->providerCall(fn () => $provider->release(
            $request->string('project_id')->toString(),
            $request->string('version_id')->toString(),
        ));
        $this->runtime->validateTargets($release);
        $this->assertReleaseCompatible($capabilities, $release);

        return $this->start($request, $server, 'install', [
            'action' => 'install',
            'intent' => [
                'provider' => $request->string('provider')->toString(),
                'project_id' => $request->string('project_id')->toString(),
                'version_id' => $request->string('version_id')->toString(),
                'delete_files' => $request->boolean('delete_files'),
            ],
            'delete_files' => $request->boolean('delete_files'),
            'release' => $release,
            'phase' => 'prepare',
        ]);
    }

    public function update(BeaconModpackMutationRequest $request, Server $server, int $installationId): JsonResponse
    {
        $capabilities = $this->assertModpackAvailable($server);
        $installation = $this->installation($server, $installationId);
        $versionId = $request->string('version_id')->toString();
        if ($versionId === '') {
            throw new HttpException(422, 'Choose the modpack version to install.');
        }
        $provider = $this->provider($installation->provider);
        $release = $this->providerCall(fn () => $provider->release($installation->project_id, $versionId));
        $this->runtime->validateTargets($release);
        $this->assertReleaseCompatible($capabilities, $release);

        return $this->start($request, $server, 'update', [
            'action' => 'update',
            'intent' => ['installation_id' => $installation->id, 'version_id' => $versionId],
            'installation_id' => $installation->id,
            'release' => $release,
            'phase' => 'prepare',
        ]);
    }

    public function reinstall(BeaconModpackMutationRequest $request, Server $server, int $installationId): JsonResponse
    {
        $capabilities = $this->assertModpackAvailable($server);
        $installation = $this->installation($server, $installationId);
        $provider = $this->provider($installation->provider);
        $release = $this->providerCall(fn () => $provider->release($installation->project_id, $installation->version_id));
        $this->runtime->validateTargets($release);
        $this->assertReleaseCompatible($capabilities, $release);

        return $this->start($request, $server, 'reinstall', [
            'action' => 'reinstall',
            'intent' => ['installation_id' => $installation->id],
            'installation_id' => $installation->id,
            'release' => $release,
            'phase' => 'prepare',
        ]);
    }

    public function uninstall(BeaconModpackMutationRequest $request, Server $server, int $installationId): JsonResponse
    {
        $this->assertModpackAvailable($server);
        $installation = $this->installation($server, $installationId);

        return $this->start($request, $server, 'uninstall', [
            'action' => 'uninstall',
            'intent' => ['installation_id' => $installation->id],
            'installation_id' => $installation->id,
            'phase' => 'prepare_restore',
        ]);
    }

    public function restore(BeaconModpackMutationRequest $request, Server $server, int $installationId): JsonResponse
    {
        $this->assertModpackAvailable($server);
        $installation = $this->installation($server, $installationId);

        return $this->start($request, $server, 'restore', [
            'action' => 'restore',
            'intent' => ['installation_id' => $installation->id],
            'installation_id' => $installation->id,
            'phase' => 'prepare_restore',
        ]);
    }

    private function start(BeaconModpackRequest $request, Server $server, string $action, array $payload): JsonResponse
    {
        $this->assertModpackAvailable($server);
        [$operation, $created] = DB::transaction(function () use ($request, $server, $action, $payload) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = $this->idempotencyKey($request);
            $actorKey = 'user:' . $request->user()->id;
            $active = BeaconOperation::query()
                ->where('server_id', $server->id)
                ->where(function ($query) {
                    $query->where('type', 'like', 'modpack.%')
                        ->orWhere('type', 'like', 'mod.%')
                        ->orWhere('type', 'like', 'version.%');
                })
                ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
                ->latest('id')
                ->first();
            if ($active instanceof BeaconOperation && str_starts_with($active->type, 'mod.')) {
                throw new ConflictHttpException('A mod operation is already running for this server.');
            }
            if ($active instanceof BeaconOperation
                && ($active->actor_key !== $actorKey || $active->idempotency_key !== $idempotencyKey)) {
                throw new ConflictHttpException('Another modpack operation is already running for this server.');
            }
            if ($active instanceof BeaconOperation) {
                if ($active->type !== "modpack.{$action}"
                    || data_get($active->payload, 'intent') !== ($payload['intent'] ?? null)) {
                    throw new ConflictHttpException('The idempotency key was already used with a different request.');
                }

                return [$active, false];
            }

            return $this->operations->handle(
                $request->user(),
                $server,
                "modpack.{$action}",
                $this->correlationId($request),
                $idempotencyKey,
                $payload,
            );
        }, 5);
        if ($created) {
            try {
                ProcessModpackOperationJob::dispatch($operation->id);
            } catch (\Throwable $exception) {
                report($exception);
                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_FAILED,
                    'error_code' => 'modpack_queue_unavailable',
                    'error_message' => 'The modpack worker queue is unavailable. No server files were changed.',
                    'finished_at' => now(),
                ])->save();

                throw new ServiceUnavailableHttpException(null, 'The modpack worker queue is unavailable. No server files were changed.', $exception);
            }
        }

        return new JsonResponse(['data' => $this->operationData($operation)], JsonResponse::HTTP_ACCEPTED);
    }

    private function provider(string $key): \Pterodactyl\Beacon\Modpacks\Providers\ModpackProvider
    {
        return $this->providerCall(fn () => $this->providers->get($key));
    }

    private function assertModpackAvailable(Server $server): array
    {
        if (!(bool) config('beacon.modpacks.enabled')) {
            throw new ConflictHttpException('The Modpack Installer is disabled.');
        }

        $capabilities = $this->capabilities->resolve($server);
        if (!$capabilities['modpacks']) {
            throw new ConflictHttpException($capabilities['modpack_unavailable_reason']);
        }

        return $capabilities;
    }

    private function assertReleaseCompatible(array $capabilities, array $release): void
    {
        $loader = strtolower((string) data_get($release, 'loader'));
        if ($loader === '') {
            throw new ConflictHttpException('The selected modpack does not declare a supported Minecraft mod loader.');
        }
        if (in_array($loader, $capabilities['modpack_loaders'], true)) {
            return;
        }

        throw new ConflictHttpException("This server is currently using {$capabilities['name']}. Choose a {$capabilities['name']} compatible modpack.");
    }

    private function providerCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ModpackProviderException $exception) {
            throw new HttpException(422, $exception->getMessage(), $exception);
        }
    }

    private function installation(Server $server, int $id): BeaconModpackInstallation
    {
        return BeaconModpackInstallation::query()->where('server_id', $server->id)->whereKey($id)->firstOrFail();
    }

    private function installationData(BeaconModpackInstallation $installation): array
    {
        return $installation->only([
            'id', 'provider', 'project_id', 'project_slug', 'name', 'icon_url', 'version_id', 'version_name',
            'minecraft_version', 'loader', 'loader_version', 'status', 'installed_at',
        ]);
    }

    private function operationData(BeaconOperation $operation): array
    {
        $deleteFiles = (bool) data_get(
            $operation->payload,
            'delete_files',
            data_get($operation->payload, 'intent.delete_files', false)
        );
        $type = str_replace('modpack.', '', $operation->type);

        return [
            'uuid' => $operation->uuid,
            'type' => $operation->type,
            'history_label' => $type === 'install' && $deleteFiles ? "$type (existing files deleted)" : $type,
            'status' => $operation->status,
            'delete_files' => $deleteFiles,
            'progress' => data_get($operation->payload, 'progress'),
            'error' => $operation->error_code ? [
                'code' => $operation->error_code,
                'message' => $operation->error_message,
            ] : null,
            'result' => $operation->result,
            'created_at' => $operation->created_at,
            'finished_at' => $operation->finished_at,
        ];
    }

    private function correlationId(BeaconModpackRequest $request): string
    {
        return (string) $request->attributes->get('beacon_correlation_id');
    }

    private function idempotencyKey(BeaconModpackRequest $request): string
    {
        return (string) $request->attributes->get('beacon_idempotency_key');
    }
}
