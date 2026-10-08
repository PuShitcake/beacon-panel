<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Ramsey\Uuid\Uuid;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Content\DependencyPlanner;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Jobs\Beacon\ProcessModOperationJob;
use Pterodactyl\Beacon\Content\ContentRuntimeResolver;
use Pterodactyl\Beacon\Content\Providers\ContentProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconModRequest;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BeaconModController extends ClientApiController
{
    public function __construct(
        private ContentProvider $provider,
        private DependencyPlanner $planner,
        private ContentRuntimeResolver $runtime,
        private StartContentOperationService $operations,
        private ServerSoftwareCapabilityService $capabilities,
    ) {
        parent::__construct();
    }

    public function context(BeaconModRequest $request, Server $server): JsonResponse
    {
        $runtime = $this->modRuntime($server, false);
        $capabilities = $this->capabilities->resolve($server);
        $active = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'mod.%')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->latest('id')
            ->first();

        return new JsonResponse(['data' => [
            'enabled' => (bool) config('beacon.mods.enabled') && is_array($runtime),
            'authorized' => true,
            'software' => $capabilities['software'],
            'software_name' => $capabilities['name'],
            'unavailable_reason' => is_array($runtime) ? null : $capabilities['mod_unavailable_reason'],
            'source' => $runtime['source'] ?? null,
            'loader' => $runtime['loader'] ?? null,
            'loader_version' => $runtime['loader_version'] ?? null,
            'game_version' => $runtime['game_version'] ?? null,
            'categories' => config('beacon.mods.categories', []),
            'active_operation' => $active ? $this->operationData($active) : null,
            'automatic_power' => true,
            'backup_before_mutation' => (bool) config('beacon.content.backup_before_mutation'),
        ]]);
    }

    public function index(BeaconModRequest $request, Server $server): JsonResponse
    {
        $this->modRuntime($server);

        return new JsonResponse(['data' => BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'mod')
            ->where('destination', 'mods')
            ->where('is_dependency', false)
            ->orderBy('project_name')
            ->get()
            ->map(fn (BeaconContentInstallation $item) => $this->installationData($item))]);
    }

    public function search(BeaconModRequest $request, Server $server): JsonResponse
    {
        $runtime = $this->modRuntime($server);

        return new JsonResponse(['data' => $this->providerCall(fn () => $this->provider->search(
            $request->string('query')->toString(),
            $runtime['game_version'],
            $runtime['loader'],
            'mod',
            $request->integer('offset'),
            $request->filled('category') ? $request->string('category')->toString() : null,
        ))]);
    }

    public function versions(BeaconModRequest $request, Server $server, string $projectId): JsonResponse
    {
        $runtime = $this->modRuntime($server);

        return new JsonResponse(['data' => $this->providerCall(fn () => $this->provider->versions(
            $projectId,
            $runtime['game_version'],
            $runtime['loader'],
        ))]);
    }

    public function plan(BeaconModRequest $request, Server $server): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        $runtime = $this->modRuntime($server);
        $plan = $this->buildPlan($server, $request->string('version_id')->toString(), $runtime);

        return new JsonResponse(['data' => $this->publicPlan($plan)]);
    }

    public function history(BeaconModRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'mod.%')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (BeaconOperation $operation) => $this->operationData($operation))]);
    }

    public function operation(BeaconModRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $operation = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('uuid', $operationUuid)
            ->where('type', 'like', 'mod.%')
            ->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    public function install(BeaconModRequest $request, Server $server): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        $runtime = $this->modRuntime($server);
        $versionId = $request->string('version_id')->toString();

        return $this->start($request, $server, 'install', [
            'action' => 'install',
            'intent' => ['version_id' => $versionId],
            'runtime' => $runtime,
            'plan' => $this->buildPlan($server, $versionId, $runtime),
            'replace_installation_ids' => [],
            'phase' => 'prepare',
        ]);
    }

    public function update(BeaconModRequest $request, Server $server, int $installationId): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        $runtime = $this->modRuntime($server);
        $installation = $this->installation($server, $installationId);
        $replace = $this->replacementInstallations($server, $installation);
        $versionId = $request->string('version_id')->toString();

        return $this->start($request, $server, 'update', [
            'action' => 'update',
            'intent' => ['installation_id' => $installation->id, 'version_id' => $versionId],
            'runtime' => $runtime,
            'plan' => $this->buildPlan($server, $versionId, $runtime, $replace->pluck('id')->all()),
            'installation_id' => $installation->id,
            'replace_installation_ids' => $replace->pluck('id')->all(),
            'phase' => 'prepare',
        ]);
    }

    public function reinstall(BeaconModRequest $request, Server $server, int $installationId): JsonResponse
    {
        $runtime = $this->modRuntime($server);
        $installation = $this->installation($server, $installationId);
        $replace = $this->replacementInstallations($server, $installation);

        return $this->start($request, $server, 'reinstall', [
            'action' => 'reinstall',
            'intent' => ['installation_id' => $installation->id, 'version_id' => $installation->version_id],
            'runtime' => $runtime,
            'plan' => $this->buildPlan($server, $installation->version_id, $runtime, $replace->pluck('id')->all()),
            'installation_id' => $installation->id,
            'replace_installation_ids' => $replace->pluck('id')->all(),
            'phase' => 'prepare',
        ]);
    }

    public function uninstall(BeaconModRequest $request, Server $server, int $installationId): JsonResponse
    {
        $this->modRuntime($server);
        $installation = $this->installation($server, $installationId);
        $remove = $this->replacementInstallations($server, $installation);

        return $this->start($request, $server, 'uninstall', [
            'action' => 'uninstall',
            'intent' => ['installation_id' => $installation->id],
            'installation_id' => $installation->id,
            'remove_installation_ids' => $remove->pluck('id')->all(),
            'phase' => 'prepare',
        ]);
    }

    private function start(BeaconModRequest $request, Server $server, string $action, array $payload): JsonResponse
    {
        if (!(bool) config('beacon.mods.enabled')) {
            throw new ConflictHttpException('The Mod Installer is disabled.');
        }

        [$operation, $created] = DB::transaction(function () use ($request, $server, $action, $payload) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $key = $this->idempotencyKey($request);
            $actorKey = 'user:' . $request->user()->id;
            $active = BeaconOperation::query()
                ->where('server_id', $server->id)
                ->where(function ($query) {
                    $query->where('type', 'like', 'mod.%')
                        ->orWhere('type', 'like', 'modpack.%');
                })
                ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
                ->latest('id')
                ->first();
            if ($active instanceof BeaconOperation && str_starts_with($active->type, 'modpack.')) {
                throw new ConflictHttpException('A modpack operation is already running for this server.');
            }
            if ($active instanceof BeaconOperation
                && ($active->actor_key !== $actorKey || $active->idempotency_key !== $key)) {
                throw new ConflictHttpException('Another mod operation is already running for this server.');
            }
            if ($active instanceof BeaconOperation) {
                if ($active->type !== "mod.{$action}" || data_get($active->payload, 'intent') !== $payload['intent']) {
                    throw new ConflictHttpException('The idempotency key was already used with a different request.');
                }

                return [$active, false];
            }

            return $this->operations->handle(
                $request->user(),
                $server,
                "mod.{$action}",
                $this->correlationId($request),
                $key,
                $payload,
            );
        }, 5);

        if ($created) {
            try {
                ProcessModOperationJob::dispatch($operation->id);
            } catch (\Throwable $exception) {
                report($exception);
                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_FAILED,
                    'error_code' => 'mod_queue_unavailable',
                    'error_message' => 'The mod worker queue is unavailable. No server files were changed.',
                    'finished_at' => now(),
                ])->save();

                throw new ServiceUnavailableHttpException(null, 'The mod worker queue is unavailable. No server files were changed.', $exception);
            }
        }

        return new JsonResponse(['data' => $this->operationData($operation)], JsonResponse::HTTP_ACCEPTED);
    }

    private function buildPlan(Server $server, string $versionId, array $runtime, array $ignoreIds = []): array
    {
        $plan = $this->providerCall(fn () => $this->planner->handle(
            $versionId,
            $runtime['game_version'],
            $runtime['loader'],
            'mod',
        ));
        $installed = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'mod')
            ->whereNotIn('id', $ignoreIds ?: [0])
            ->get();
        $incompatible = collect($plan['incompatible_dependencies']);
        if ($installed->contains(fn (BeaconContentInstallation $item) => $incompatible->contains(
            fn (array $dependency) => ($dependency['project_id'] ?? null) === $item->project_id
                || ($dependency['version_id'] ?? null) === $item->version_id
        ))) {
            throw new ConflictHttpException('An installed mod conflicts with the selected version.');
        }

        $plan['resolved_dependencies'] = collect($plan['files'])
            ->where('dependency', true)
            ->pluck('project_id')
            ->values()
            ->all();
        $plan['files'] = collect($plan['files'])->reject(function (array $file) use ($installed) {
            $existing = $installed->firstWhere('project_id', $file['project_id']);
            if (!$existing instanceof BeaconContentInstallation) {
                return false;
            }
            if (!$file['dependency'] || $existing->version_id !== $file['version_id']) {
                throw new ConflictHttpException('A mod in this plan is already installed with a different version.');
            }

            return true;
        })->values()->all();
        if ($plan['files'] === []) {
            throw new ConflictHttpException('The selected mod is already installed.');
        }
        $plan['total_bytes'] = collect($plan['files'])->sum('size');

        return $plan;
    }

    private function replacementInstallations(Server $server, BeaconContentInstallation $root): \Illuminate\Support\Collection
    {
        $otherDependencies = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'mod')
            ->where('is_dependency', false)
            ->where('id', '!=', $root->id)
            ->get(['dependencies'])
            ->flatMap(fn (BeaconContentInstallation $item) => $item->dependencies ?? [])
            ->unique();
        $orphanProjects = collect($root->dependencies ?? [])
            ->filter('is_string')
            ->reject(fn (string $id) => $otherDependencies->contains($id));

        return BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where(function ($query) use ($root, $orphanProjects) {
                $query->whereKey($root->id);
                if ($orphanProjects->isNotEmpty()) {
                    $query->orWhereIn('project_id', $orphanProjects->all());
                }
            })
            ->get();
    }

    private function installation(Server $server, int $id): BeaconContentInstallation
    {
        return BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'mod')
            ->where('destination', 'mods')
            ->where('is_dependency', false)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function modRuntime(Server $server, bool $required = true): ?array
    {
        $runtime = $this->runtime->resolve($server, false);
        if (!is_array($runtime)) {
            if ($required) {
                $capabilities = $this->capabilities->resolve($server);

                throw new ConflictHttpException($capabilities['mod_unavailable_reason']);
            }

            return null;
        }
        if ($runtime['project_type'] !== 'mod' || $runtime['destination'] !== 'mods') {
            if ($required) {
                $capabilities = $this->capabilities->resolve($server);

                throw new ConflictHttpException($capabilities['mod_unavailable_reason']);
            }

            return null;
        }
        if (strtolower((string) $runtime['loader']) !== 'forge') {
            if ($required) {
                throw new ConflictHttpException('The Mod Installer currently supports Forge servers only.');
            }

            return null;
        }

        return $runtime;
    }

    private function providerCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ProviderResponseException $exception) {
            throw new HttpException(422, $exception->getMessage(), $exception);
        }
    }

    private function publicPlan(array $plan): array
    {
        $plan['files'] = collect($plan['files'])->map(fn (array $file) => collect($file)->except('url')->all())->all();

        return $plan;
    }

    private function installationData(BeaconContentInstallation $item): array
    {
        return $item->only([
            'id', 'provider', 'project_id', 'project_name', 'version_id', 'version_number', 'icon_url',
            'loader', 'game_version', 'filename', 'size', 'dependencies', 'status', 'disabled_path', 'created_at', 'updated_at',
        ]);
    }

    private function operationData(BeaconOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'type' => $operation->type,
            'status' => $operation->status,
            'progress' => data_get($operation->payload, 'progress'),
            'result' => $operation->result,
            'error' => $operation->error_code ? [
                'code' => $operation->error_code,
                'message' => $operation->error_message,
            ] : null,
            'created_at' => $operation->created_at?->toAtomString(),
            'finished_at' => $operation->finished_at?->toAtomString(),
        ];
    }

    private function correlationId(Request $request): string
    {
        $value = $request->attributes->get('beacon_correlation_id');

        return is_string($value) && Uuid::isValid($value) ? $value : Uuid::uuid4()->toString();
    }

    private function idempotencyKey(Request $request): string
    {
        $value = $request->attributes->get('beacon_idempotency_key');
        if (!is_string($value) || $value === '') {
            throw new \LogicException('Mod mutations require an idempotency key.');
        }

        return $value;
    }
}
