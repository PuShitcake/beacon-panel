<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Beacon\Versions\McJarsVersionProvider;
use Pterodactyl\Beacon\Versions\VersionRuntimeService;
use Pterodactyl\Jobs\Beacon\ProcessVersionOperationJob;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconVersionRequest;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BeaconVersionController extends ClientApiController
{
    public function __construct(
        private McJarsVersionProvider $versions,
        private VersionRuntimeService $runtime,
        private StartContentOperationService $operations,
    ) {
        parent::__construct();
    }

    public function context(BeaconVersionRequest $request, Server $server): JsonResponse
    {
        $available = $this->eligible($server, false);
        $active = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'version.%')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->latest('id')
            ->first();

        return new JsonResponse(['data' => [
            'enabled' => (bool) config('beacon.versions.enabled'),
            'available' => $available,
            'unavailable_reason' => $available ? null : 'Version Manager supports Vanilla, Paper, and Forge servers in the Beacon nest.',
            'current' => $this->runtime->current($server),
            'software' => [
                ['key' => 'vanilla', 'name' => 'Vanilla'],
                ['key' => 'paper', 'name' => 'Paper'],
                ['key' => 'forge', 'name' => 'Forge'],
            ],
            'active_operation' => $active ? $this->operationData($active) : null,
            'backup_required' => true,
            'managed_modpack' => BeaconModpackInstallation::query()->where('server_id', $server->id)->exists(),
        ]]);
    }

    public function versions(BeaconVersionRequest $request, Server $server, string $software): JsonResponse
    {
        $this->eligible($server);

        return new JsonResponse(['data' => $this->catalog(fn () => $this->versions->versions($software))]);
    }

    public function builds(BeaconVersionRequest $request, Server $server, string $software, string $version): JsonResponse
    {
        $this->eligible($server);

        return new JsonResponse(['data' => $this->catalog(fn () => $this->versions->builds($software, $version))]);
    }

    public function history(BeaconVersionRequest $request, Server $server): JsonResponse
    {
        $operations = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'version.%')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (BeaconOperation $operation) => $this->operationData($operation));

        return new JsonResponse(['data' => $operations]);
    }

    public function operation(BeaconVersionRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $operation = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('uuid', $operationUuid)
            ->where('type', 'like', 'version.%')
            ->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    public function change(BeaconVersionRequest $request, Server $server): JsonResponse
    {
        $this->eligible($server);
        if (BeaconModpackInstallation::query()->where('server_id', $server->id)->exists()) {
            throw new ConflictHttpException('Remove the managed modpack before changing server software or Minecraft version.');
        }
        $validated = $request->validate([
            'software' => ['required', 'string', 'in:vanilla,paper,forge'],
            'version' => ['required', 'string', 'max:32'],
            'build_uuid' => ['required', 'uuid'],
        ]);
        $idempotencyKey = (string) $request->attributes->get('beacon_idempotency_key');
        $actorKey = 'user:' . $request->user()->id;
        $replay = BeaconOperation::query()
            ->where('actor_key', $actorKey)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($replay instanceof BeaconOperation) {
            $this->assertReplayMatches($replay, $server, $validated);

            return new JsonResponse(['data' => $this->operationData($replay)], JsonResponse::HTTP_ACCEPTED);
        }
        $target = $this->catalog(fn () => $this->versions->target(
            $validated['software'],
            $validated['version'],
            $validated['build_uuid']
        ));
        $current = $this->runtime->current($server);
        if ($current['software'] === $target['software']
            && $current['minecraft_version'] === $target['minecraft_version']
            && $this->sameBuild($current['build'], $target)) {
            throw new ConflictHttpException('This server is already using the selected software version and build.');
        }
        if (is_string($current['minecraft_version'])
            && preg_match('/^\d+(?:\.\d+){1,2}$/', $current['minecraft_version'])
            && version_compare($target['minecraft_version'], $current['minecraft_version'], '<')) {
            throw new ConflictHttpException('Minecraft downgrades are blocked because they can corrupt existing worlds.');
        }
        if ((!is_string($current['minecraft_version']) || in_array(strtolower($current['minecraft_version']), ['', 'latest'], true))
            && data_get($this->catalog(fn () => $this->versions->versions($target['software'])), '0.version') !== $target['minecraft_version']) {
            throw new ConflictHttpException('The current runtime is not pinned to an exact version. Choose the latest Minecraft version first to avoid an unsafe downgrade.');
        }

        [$operation, $created] = DB::transaction(function () use ($request, $server, $current, $target, $validated, $idempotencyKey, $actorKey) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $replay = BeaconOperation::query()
                ->where('actor_key', $actorKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($replay instanceof BeaconOperation) {
                $this->assertReplayMatches($replay, $server, $validated);

                return [$replay, false];
            }
            $active = BeaconOperation::query()
                ->where('server_id', $server->id)
                ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
                ->latest('id')
                ->first();
            if ($active instanceof BeaconOperation) {
                throw new ConflictHttpException('Another Beacon server mutation is already running.');
            }

            return $this->operations->handle(
                $request->user(),
                $server,
                'version.change',
                (string) $request->attributes->get('beacon_correlation_id'),
                $idempotencyKey,
                [
                    'current' => $current,
                    'target' => $target,
                    'phase' => 'prepare',
                    'progress' => ['stage' => 'queued', 'percent' => 0, 'message' => 'Waiting for the version worker.'],
                ],
            );
        }, 5);

        if ($created) {
            try {
                ProcessVersionOperationJob::dispatch($operation->id);
            } catch (\Throwable $exception) {
                report($exception);
                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_FAILED,
                    'error_code' => 'version_queue_unavailable',
                    'error_message' => 'The version worker queue is unavailable. No server files were changed.',
                    'finished_at' => now(),
                ])->save();
                throw new ServiceUnavailableHttpException(null, 'The version worker queue is unavailable. No server files were changed.', $exception);
            }
        }

        return new JsonResponse(['data' => $this->operationData($operation)], JsonResponse::HTTP_ACCEPTED);
    }

    private function eligible(Server $server, bool $throw = true): bool
    {
        $server->loadMissing('egg.nest');
        $current = $this->runtime->current($server);
        $eligible = (bool) config('beacon.versions.enabled')
            && $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon')
            && in_array($current['software'], config('beacon.versions.software', []), true);
        if (!$eligible && $throw) {
            throw new ConflictHttpException('Version Manager supports Vanilla, Paper, and Forge servers in the Beacon nest.');
        }

        return $eligible;
    }

    private function sameBuild(?string $current, array $target): bool
    {
        if ($target['software'] === 'vanilla') {
            return true;
        }
        if ($target['software'] === 'paper') {
            return $current === (string) $target['build_number'];
        }

        return is_string($current) && str_contains($current, (string) $target['loader_version']);
    }

    private function assertReplayMatches(BeaconOperation $operation, Server $server, array $selection): void
    {
        if ($operation->server_id !== $server->id
            || $operation->type !== 'version.change'
            || data_get($operation->payload, 'target.software') !== $selection['software']
            || data_get($operation->payload, 'target.minecraft_version') !== $selection['version']
            || data_get($operation->payload, 'target.build_uuid') !== $selection['build_uuid']) {
            throw new ConflictHttpException('The idempotency key was already used with a different request.');
        }
    }

    private function catalog(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage(), $exception);
        } catch (\RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(null, $exception->getMessage(), $exception);
        }
    }

    private function operationData(BeaconOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'type' => $operation->type,
            'status' => $operation->status,
            'current' => data_get($operation->payload, 'current'),
            'target' => data_get($operation->payload, 'target'),
            'progress' => data_get($operation->payload, 'progress'),
            'error' => $operation->error_code ? [
                'code' => $operation->error_code,
                'message' => $operation->error_message,
            ] : null,
            'result' => $operation->result,
            'created_at' => $operation->created_at?->toAtomString(),
            'finished_at' => $operation->finished_at?->toAtomString(),
        ];
    }
}
