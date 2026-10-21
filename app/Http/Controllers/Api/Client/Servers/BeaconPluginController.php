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
use Pterodactyl\Beacon\Content\PluginRuntimeResolver;
use Pterodactyl\Jobs\Beacon\ProcessPluginOperationJob;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Beacon\Content\Providers\PluginProviderRegistry;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconPluginRequest;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BeaconPluginController extends ClientApiController
{
    public function __construct(
        private PluginProviderRegistry $providers,
        private DependencyPlanner $planner,
        private PluginRuntimeResolver $runtime,
        private StartContentOperationService $operations,
        private ServerSoftwareCapabilityService $capabilities,
    ) {
        parent::__construct();
    }

    public function context(BeaconPluginRequest $request, Server $server): JsonResponse
    {
        $runtime = null;
        $runtimeError = null;
        try {
            $runtime = $this->runtime->resolve($server);
        } catch (ConflictHttpException $exception) {
            $runtimeError = $exception->getMessage();
        }
        $capabilities = $this->capabilities->resolve($server);
        $provider = $this->providerKey($request);
        $categories = [];
        $providerError = null;
        try {
            $categories = $this->providers->categories($provider);
        } catch (ProviderResponseException $exception) {
            $providerError = $exception->getMessage();
        }
        if ($provider === 'hangar' && $runtime && $runtime['loader'] !== 'paper') {
            $providerError = 'Hangar supports Paper-compatible plugins for this installer. Select another provider for this server.';
        }
        $active = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'plugin.%')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->latest('id')
            ->first();

        return new JsonResponse(['data' => [
            'enabled' => (bool) config('beacon.plugins.enabled') && is_array($runtime),
            'authorized' => true,
            'software' => $capabilities['software'],
            'software_name' => $capabilities['name'],
            'unavailable_reason' => $runtimeError,
            'source' => $runtime['source'] ?? null,
            'loader' => $runtime['loader'] ?? null,
            'loader_version' => $runtime['loader_version'] ?? null,
            'game_version' => $runtime['game_version'] ?? null,
            'provider' => $provider,
            'providers' => $this->providers->describe(),
            'provider_available' => $providerError === null,
            'provider_unavailable_reason' => $providerError,
            'categories' => $categories,
            'active_operation' => $active ? $this->operationData($active) : null,
            'automatic_power' => true,
            'backup_before_mutation' => (bool) config('beacon.content.backup_before_mutation'),
        ]]);
    }

    public function index(BeaconPluginRequest $request, Server $server): JsonResponse
    {
        $this->pluginRuntime($server);

        return new JsonResponse(['data' => BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'plugin')
            ->where('destination', 'plugins')
            ->where('is_dependency', false)
            ->orderBy('project_name')
            ->get()
            ->map(fn (BeaconContentInstallation $item) => $this->installationData($item))]);
    }

    public function search(BeaconPluginRequest $request, Server $server): JsonResponse
    {
        $runtime = $this->pluginRuntime($server);
        if ($request->filled('category') && !in_array($request->string('category')->toString(), array_column($this->providers->categories($this->providerKey($request)), 'value'), true)) {
            throw new HttpException(422, 'The selected plugin category is not supported by this provider.');
        }

        return new JsonResponse(['data' => $this->providerCall(fn () => $this->providers->get($this->providerKey($request))->search(
            $request->string('query')->toString(),
            $runtime['game_version'],
            $runtime['loader'],
            'plugin',
            $request->integer('offset'),
            $request->filled('category') ? $request->string('category')->toString() : null,
        ))]);
    }

    public function versions(BeaconPluginRequest $request, Server $server, string $projectId): JsonResponse
    {
        $runtime = $this->pluginRuntime($server);

        $versions = $this->providerCall(fn () => $this->providers->get($this->providerKey($request))->versions(
            $projectId,
            $runtime['game_version'],
            $runtime['loader'],
        ));

        return new JsonResponse(['data' => array_map(function (array $version) {
            unset($version['file']['url']);

            return $version;
        }, $versions)]);
    }

    public function plan(BeaconPluginRequest $request, Server $server): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        $runtime = $this->pluginRuntime($server);
        $plan = $this->buildPlan($server, $request->string('version_id')->toString(), $runtime, $this->providerKey($request));

        return new JsonResponse(['data' => $this->publicPlan($plan)]);
    }

    public function history(BeaconPluginRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('type', 'like', 'plugin.%')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (BeaconOperation $operation) => $this->operationData($operation))]);
    }

    public function operation(BeaconPluginRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $operation = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('uuid', $operationUuid)
            ->where('type', 'like', 'plugin.%')
            ->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    public function install(BeaconPluginRequest $request, Server $server): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        $runtime = $this->pluginRuntime($server);
        $versionId = $request->string('version_id')->toString();
        $provider = $this->providerKey($request);

        return $this->start($request, $server, 'install', [
            'action' => 'install',
            'provider' => $provider,
            'intent' => ['provider' => $provider, 'version_id' => $versionId],
            'runtime' => $runtime,
            'plan' => $this->buildPlan($server, $versionId, $runtime, $provider),
            'replace_installation_ids' => [],
            'phase' => 'prepare',
        ]);
    }

    public function update(BeaconPluginRequest $request, Server $server, int $installationId): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        $runtime = $this->pluginRuntime($server);
        $installation = $this->installation($server, $installationId);
        $replace = $this->replacementInstallations($server, $installation);
        $versionId = $request->string('version_id')->toString();

        return $this->start($request, $server, 'update', [
            'action' => 'update',
            'provider' => $installation->provider,
            'intent' => ['provider' => $installation->provider, 'installation_id' => $installation->id, 'version_id' => $versionId],
            'runtime' => $runtime,
            'plan' => $this->buildPlan($server, $versionId, $runtime, $installation->provider, $replace->pluck('id')->all(), $installation->project_id),
            'installation_id' => $installation->id,
            'replace_installation_ids' => $replace->pluck('id')->all(),
            'phase' => 'prepare',
        ]);
    }

    public function reinstall(BeaconPluginRequest $request, Server $server, int $installationId): JsonResponse
    {
        $runtime = $this->pluginRuntime($server);
        $installation = $this->installation($server, $installationId);
        $replace = $this->replacementInstallations($server, $installation);

        return $this->start($request, $server, 'reinstall', [
            'action' => 'reinstall',
            'provider' => $installation->provider,
            'intent' => ['provider' => $installation->provider, 'installation_id' => $installation->id, 'version_id' => $installation->version_id],
            'runtime' => $runtime,
            'plan' => $this->buildPlan($server, $installation->version_id, $runtime, $installation->provider, $replace->pluck('id')->all(), $installation->project_id),
            'installation_id' => $installation->id,
            'replace_installation_ids' => $replace->pluck('id')->all(),
            'phase' => 'prepare',
        ]);
    }

    public function uninstall(BeaconPluginRequest $request, Server $server, int $installationId): JsonResponse
    {
        $runtime = $this->pluginRuntime($server);
        $installation = $this->installation($server, $installationId);
        $remove = $this->replacementInstallations($server, $installation);

        return $this->start($request, $server, 'uninstall', [
            'action' => 'uninstall',
            'provider' => $installation->provider,
            'runtime' => $runtime,
            'intent' => ['installation_id' => $installation->id],
            'installation_id' => $installation->id,
            'remove_installation_ids' => $remove->pluck('id')->all(),
            'phase' => 'prepare',
        ]);
    }

    private function start(BeaconPluginRequest $request, Server $server, string $action, array $payload): JsonResponse
    {
        if (!(bool) config('beacon.plugins.enabled')) {
            throw new ConflictHttpException('The Plugin Installer is disabled.');
        }
        if (!config('beacon.content.backup_before_mutation')) {
            throw new ConflictHttpException('Enable managed-content safety backups before changing plugins.');
        }
        if (!in_array(config('queue.connections.' . config('queue.default') . '.driver'), ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            throw new ConflictHttpException('An asynchronous queue worker is required before changing plugins.');
        }

        [$operation, $created] = DB::transaction(function () use ($request, $server, $action, $payload) {
            $current = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $this->runtime->assertOperationRuntime($current, $payload['runtime']);
            $key = $this->idempotencyKey($request);
            $actorKey = 'user:' . $request->user()->id;
            $active = BeaconOperation::query()
                ->where('server_id', $server->id)
                ->where(function ($query) {
                    $query->where('type', 'like', 'plugin.%')
                        ->orWhere('type', 'like', 'minecraft-service.%')
                        ->orWhere('type', 'like', 'mod.%')
                        ->orWhere('type', 'native_modpack.runtime')
                        ->orWhere('type', 'like', 'content.%')
                        ->orWhere('type', 'like', 'modpack.%');
                })
                ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
                ->latest('id')
                ->first();
            if ($active instanceof BeaconOperation && ($active->type === 'native_modpack.runtime' || str_starts_with($active->type, 'modpack.'))) {
                throw new ConflictHttpException('A modpack operation is already running for this server.');
            }
            if ($active instanceof BeaconOperation
                && ($active->actor_key !== $actorKey || $active->idempotency_key !== $key)) {
                throw new ConflictHttpException('Another plugin operation is already running for this server.');
            }
            if ($active instanceof BeaconOperation) {
                if ($active->type !== "plugin.{$action}" || data_get($active->payload, 'intent') !== $payload['intent']) {
                    throw new ConflictHttpException('The idempotency key was already used with a different request.');
                }

                return [$active, false];
            }

            return $this->operations->handle(
                $request->user(),
                $server,
                "plugin.{$action}",
                $this->correlationId($request),
                $key,
                $payload,
            );
        }, 5);

        if ($created) {
            try {
                ProcessPluginOperationJob::dispatch($operation->id);
            } catch (\Throwable $exception) {
                \Pterodactyl\Beacon\Operations\PublicOperationError::report($exception, 'plugin.queue', $operation->id);
                $operation->forceFill([
                    'status' => BeaconOperation::STATUS_FAILED,
                    'error_code' => 'plugin_queue_unavailable',
                    'error_message' => 'The plugin worker queue is unavailable. No server files were changed.',
                    'finished_at' => now(),
                ])->save();

                throw new ServiceUnavailableHttpException(null, 'The plugin worker queue is unavailable. No server files were changed.', $exception);
            }
        }

        return new JsonResponse(['data' => $this->operationData($operation)], JsonResponse::HTTP_ACCEPTED);
    }

    private function buildPlan(Server $server, string $versionId, array $runtime, string $provider, array $ignoreIds = [], ?string $expectedProject = null): array
    {
        $plan = $this->providerCall(fn () => $this->planner->handle(
            $versionId,
            $runtime['game_version'],
            $runtime['loader'],
            'plugin',
            $provider,
            $this->providers->get($provider),
        ));
        if ($expectedProject !== null && !collect($plan['files'])->contains(fn (array $file) => !$file['dependency'] && $file['project_id'] === $expectedProject)) {
            throw new ConflictHttpException('Select a version belonging to this installed plugin and its original provider.');
        }
        $installed = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('provider', $provider)
            ->where('project_type', 'plugin')
            ->whereNotIn('id', $ignoreIds ?: [0])
            ->get();
        $incompatible = collect($plan['incompatible_dependencies']);
        if ($installed->contains(fn (BeaconContentInstallation $item) => $incompatible->contains(
            fn (array $dependency) => ($dependency['project_id'] ?? null) === $item->project_id
                || ($dependency['version_id'] ?? null) === $item->version_id
        ))) {
            throw new ConflictHttpException('An installed plugin conflicts with the selected version.');
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
                throw new ConflictHttpException('A plugin in this plan is already installed with a different version.');
            }

            return true;
        })->values()->all();
        if ($plan['files'] === []) {
            throw new ConflictHttpException('The selected plugin is already installed.');
        }
        $plan['total_bytes'] = collect($plan['files'])->sum('size');

        return $plan;
    }

    private function replacementInstallations(Server $server, BeaconContentInstallation $root): \Illuminate\Support\Collection
    {
        $otherDependencies = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('provider', $root->provider)
            ->where('project_type', 'plugin')
            ->where('is_dependency', false)
            ->where('id', '!=', $root->id)
            ->get(['dependencies'])
            ->flatMap(fn (BeaconContentInstallation $item) => $item->dependencies ?? [])
            ->unique();
        $orphanProjects = collect($root->dependencies ?? [])
            ->filter(fn ($value) => is_string($value))
            ->reject(fn (string $id) => $otherDependencies->contains($id));

        return BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('provider', $root->provider)
            ->where('project_type', 'plugin')
            ->where('destination', 'plugins')
            ->where(function ($query) use ($root, $orphanProjects) {
                $query->whereKey($root->id);
                if ($orphanProjects->isNotEmpty()) {
                    $query->orWhere(fn ($dependency) => $dependency->whereIn('project_id', $orphanProjects->all())->where('is_dependency', true));
                }
            })
            ->get();
    }

    private function installation(Server $server, int $id): BeaconContentInstallation
    {
        return BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'plugin')
            ->where('destination', 'plugins')
            ->where('is_dependency', false)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function pluginRuntime(Server $server, bool $required = true): ?array
    {
        try {
            return $this->runtime->resolve($server);
        } catch (ConflictHttpException $exception) {
            if ($required) {
                throw $exception;
            }

            return null;
        }
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

    private function providerKey(Request $request): string
    {
        $provider = $request->input('provider', 'curseforge');
        if (!is_string($provider) || !in_array($provider, ['curseforge', 'modrinth', 'hangar'], true)) {
            throw new HttpException(422, 'Select CurseForge, Modrinth, or Hangar as the plugin provider.');
        }

        return $provider;
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
