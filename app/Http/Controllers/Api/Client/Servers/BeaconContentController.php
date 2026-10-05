<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Ramsey\Uuid\Uuid;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconServerMetadata;
use Pterodactyl\Jobs\Beacon\RemoveContentJob;
use Pterodactyl\Jobs\Beacon\InstallContentJob;
use Pterodactyl\Beacon\Content\DependencyPlanner;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Beacon\Content\Providers\ContentProvider;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Beacon\Minecraft\ServerSoftwareCapabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconContentDeleteRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconContentSearchRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconContentInstallRequest;

class BeaconContentController extends ClientApiController
{
    public function __construct(
        private ContentProvider $provider,
        private DependencyPlanner $planner,
        private StartContentOperationService $operations,
        private ServerSoftwareCapabilityService $capabilities,
    ) {
        parent::__construct();
    }

    public function context(BeaconContentSearchRequest $request, Server $server): JsonResponse
    {
        $capabilities = $this->capabilities->resolve($server);
        if (!$capabilities['plugins']) {
            return new JsonResponse(['data' => [
                'enabled' => false,
                'software' => $capabilities['software'],
                'software_name' => $capabilities['name'],
                'unavailable_reason' => $capabilities['plugin_unavailable_reason'],
            ]]);
        }

        $metadata = BeaconServerMetadata::query()
            ->with(['profile', 'version'])
            ->where('server_id', $server->id)
            ->first();
        if (!$metadata instanceof BeaconServerMetadata || !$metadata->profile->content_directory) {
            return new JsonResponse(['data' => [
                'enabled' => false,
                'software' => $capabilities['software'],
                'software_name' => $capabilities['name'],
                'unavailable_reason' => 'Plugin Installer requires a Beacon Paper profile with a managed Minecraft version.',
            ]]);
        }
        if ($metadata->profile->content_directory !== 'plugins') {
            return new JsonResponse(['data' => [
                'enabled' => false,
                'software' => $capabilities['software'],
                'software_name' => $capabilities['name'],
                'unavailable_reason' => 'Plugins are only available for Paper servers.',
            ]]);
        }

        return new JsonResponse(['data' => [
            'enabled' => true,
            'software' => $capabilities['software'],
            'software_name' => $capabilities['name'],
            'profile' => $metadata->profile->only(['code', 'name', 'loader', 'content_directory']),
            'version' => $metadata->version->only(['version', 'loader_version']),
            'project_type' => 'plugin',
            'requires_stopped_server' => (bool) config('beacon.content.require_stopped_server'),
            'backup_before_mutation' => (bool) config('beacon.content.backup_before_mutation'),
        ]]);
    }

    public function index(BeaconContentSearchRequest $request, Server $server): JsonResponse
    {
        $this->metadata($server);
        $installations = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->orderBy('project_id')
            ->get()
            ->map(fn (BeaconContentInstallation $item) => $this->installationData($item));

        return new JsonResponse(['data' => $installations]);
    }

    public function search(BeaconContentSearchRequest $request, Server $server): JsonResponse
    {
        [$metadata, $projectType] = $this->metadata($server);

        return new JsonResponse(['data' => $this->provider->search(
            $request->string('query')->toString(),
            $metadata->version->version,
            $metadata->profile->loader,
            $projectType,
            $request->integer('offset'),
        )]);
    }

    public function versions(BeaconContentSearchRequest $request, Server $server, string $projectId): JsonResponse
    {
        [$metadata] = $this->metadata($server);

        return new JsonResponse(['data' => $this->provider->versions(
            $projectId,
            $metadata->version->version,
            $metadata->profile->loader,
        )]);
    }

    public function plan(BeaconContentSearchRequest $request, Server $server): JsonResponse
    {
        $request->validate(['version_id' => 'required|string|max:64']);
        [$metadata, $projectType] = $this->metadata($server);
        $plan = $this->buildPlan($server, $request->string('version_id')->toString(), $metadata, $projectType);

        return new JsonResponse(['data' => $this->publicPlan($plan)]);
    }

    public function install(BeaconContentInstallRequest $request, Server $server): JsonResponse
    {
        [$metadata, $projectType] = $this->metadata($server);
        $plan = $this->buildPlan($server, $request->string('version_id')->toString(), $metadata, $projectType);

        $payload = [
            'provider' => 'modrinth',
            'project_type' => $projectType,
            'loader' => $metadata->profile->loader,
            'game_version' => $metadata->version->version,
            'destination' => $metadata->profile->content_directory,
            'plan' => $plan,
        ];
        [$operation, $created] = $this->operations->handle(
            $request->user(),
            $server,
            'content.install',
            $this->correlationId($request),
            $this->idempotencyKey($request),
            $payload,
        );
        if ($created) {
            InstallContentJob::dispatch($operation->id);
        }

        return new JsonResponse(['data' => $this->operationData($operation)], $created ? 202 : 200);
    }

    public function remove(BeaconContentDeleteRequest $request, Server $server, int $installationId): JsonResponse
    {
        $this->metadata($server);
        $installation = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->whereKey($installationId)
            ->firstOrFail();
        $isRequired = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->get(['id', 'dependencies'])
            ->contains(
                fn (BeaconContentInstallation $item) => $item->id !== $installation->id && in_array($installation->project_id, $item->dependencies ?? [], true)
            );
        if ($isRequired) {
            throw new ConflictHttpException('This project is required by other installed content.');
        }
        $payload = [
            'installation_id' => $installation->id,
            'project_id' => $installation->project_id,
            'version_id' => $installation->version_id,
            'destination' => $installation->destination,
            'filename' => $installation->filename,
        ];
        [$operation, $created] = $this->operations->handle(
            $request->user(),
            $server,
            'content.remove',
            $this->correlationId($request),
            $this->idempotencyKey($request),
            $payload,
        );
        if ($created) {
            RemoveContentJob::dispatch($operation->id);
        }

        return new JsonResponse(['data' => $this->operationData($operation)], $created ? 202 : 200);
    }

    public function operation(BeaconContentSearchRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $operation = BeaconOperation::query()
            ->where('server_id', $server->id)
            ->where('user_id', $request->user()->id)
            ->where('uuid', $operationUuid)
            ->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    private function metadata(Server $server): array
    {
        $capabilities = $this->capabilities->resolve($server);
        if (!$capabilities['plugins']) {
            throw new ConflictHttpException($capabilities['plugin_unavailable_reason']);
        }

        $metadata = BeaconServerMetadata::query()
            ->with(['profile', 'version'])
            ->where('server_id', $server->id)
            ->first();
        if (!$metadata instanceof BeaconServerMetadata || $metadata->profile->content_directory !== 'plugins') {
            throw new ConflictHttpException('Plugin Installer requires a Beacon Paper profile with a managed Minecraft version.');
        }

        return [$metadata, 'plugin'];
    }

    private function buildPlan(Server $server, string $versionId, BeaconServerMetadata $metadata, string $projectType): array
    {
        $plan = $this->planner->handle(
            $versionId,
            $metadata->version->version,
            $metadata->profile->loader,
            $projectType,
        );

        $installed = BeaconContentInstallation::query()->where('server_id', $server->id)->get();
        $incompatible = collect($plan['incompatible_dependencies']);
        if ($installed->contains(function (BeaconContentInstallation $item) use ($incompatible) {
            return $incompatible->contains(
                fn (array $dependency) => ($dependency['project_id'] ?? null) === $item->project_id
                || ($dependency['version_id'] ?? null) === $item->version_id
            );
        })) {
            throw new ConflictHttpException('Installed content conflicts with the selected version.');
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
                throw new ConflictHttpException('One or more projects in this plan are already installed with a different version.');
            }

            return true;
        })->values()->all();
        if ($plan['files'] === []) {
            throw new ConflictHttpException('The selected project is already installed.');
        }
        $plan['total_bytes'] = collect($plan['files'])->sum('size');

        return $plan;
    }

    private function publicPlan(array $plan): array
    {
        $plan['files'] = collect($plan['files'])->map(fn (array $file) => collect($file)->except('url')->all())->all();

        return $plan;
    }

    private function correlationId(Request $request): string
    {
        $value = $request->attributes->get('beacon_correlation_id');

        return is_string($value) && Uuid::isValid($value) ? $value : Uuid::uuid4()->toString();
    }

    private function idempotencyKey(Request $request): string
    {
        $value = $request->attributes->get('beacon_idempotency_key');
        if (!is_string($value)) {
            throw new \LogicException('Content mutations require an idempotency key.');
        }

        return $value;
    }

    private function installationData(BeaconContentInstallation $item): array
    {
        return $item->only([
            'id', 'provider', 'project_id', 'version_id', 'project_type', 'loader', 'game_version',
            'destination', 'filename', 'sha512', 'size', 'dependencies', 'status', 'created_at', 'updated_at',
        ]);
    }

    private function operationData(BeaconOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'type' => $operation->type,
            'status' => $operation->status,
            'result' => $operation->result,
            'error' => $operation->error_code ? [
                'code' => $operation->error_code,
                'message' => $operation->error_message,
            ] : null,
            'created_at' => $operation->created_at?->toAtomString(),
            'started_at' => $operation->started_at?->toAtomString(),
            'finished_at' => $operation->finished_at?->toAtomString(),
        ];
    }
}
