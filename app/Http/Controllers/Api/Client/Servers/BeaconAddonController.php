<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Beacon\Addons\AddonStore;
use Pterodactyl\Beacon\Addons\AddonRuntime;
use Pterodactyl\Models\BeaconAddonInstallation;
use Pterodactyl\Jobs\Beacon\ProcessAddonOperationJob;
use Pterodactyl\Beacon\Addons\CurseForgeAddonProvider;
use Pterodactyl\Beacon\Operations\PublicOperationError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Beacon\Minecraft\BedrockConfigurationService;
use Pterodactyl\Beacon\Operations\StartContentOperationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;
use Pterodactyl\Http\Requests\Api\Client\Servers\BeaconAddonRequest;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BeaconAddonController extends ClientApiController
{
    public function __construct(private AddonRuntime $runtime, private AddonStore $store, private CurseForgeAddonProvider $provider, private StartContentOperationService $operations)
    {
        parent::__construct();
    }

    public function context(BeaconAddonRequest $request, Server $server): JsonResponse
    {
        $supported = BedrockConfigurationService::supports($server);
        $runtime = null;
        $reason = $supported ? null : 'Add-ons Installer is available only for Vanilla Bedrock in the Beacon nest.';
        $worlds = [];
        if ($supported) {
            try {
                $known = BeaconAddonInstallation::where('server_id', $server->id)->latest('id')->first();
                $runtime = $this->runtime->resolve($server, data_get($known?->manifest, 'runtime'));
                $worlds = $this->runtime->worlds($server);
                if ($worlds === []) {
                    $reason = 'Start Bedrock once to create its world, then refresh this page.';
                }
            } catch (ConflictHttpException $e) {
                $reason = $e->getMessage();
            }
        }

        return new JsonResponse(['data' => ['enabled' => $supported, 'ready' => $runtime !== null && count($worlds) > 0,
            'unavailable_reason' => $reason, 'version' => $runtime['version'] ?? null, 'worlds' => $worlds,
            'curseforge_available' => $this->provider->available(),
            'installations' => $supported ? BeaconAddonInstallation::where('server_id', $server->id)->get()->map(fn ($i) => $this->installationData($i)) : [],
            'active_operation' => ($active = BeaconOperation::where('server_id', $server->id)->where('type', 'like', 'content.addon.%')->whereIn('status', ['pending', 'running'])->latest('id')->first()) ? $this->operationData($active) : null]]);
    }

    public function search(BeaconAddonRequest $request, Server $server): JsonResponse
    {
        $this->runtime->assertSupported($server);

        return new JsonResponse(['data' => $this->providerCall(fn () => $this->provider->search($request->string('query')->toString(), $request->integer('offset')))]);
    }

    public function versions(BeaconAddonRequest $request, Server $server, string $projectId): JsonResponse
    {
        $this->runtime->assertSupported($server);

        return new JsonResponse(['data' => $this->providerCall(fn () => $this->provider->versions($projectId))]);
    }

    public function preview(BeaconAddonRequest $request, Server $server): JsonResponse
    {
        $this->runtime->assertSupported($server);
        $request->validate(['provider' => 'required|in:upload,curseforge']);
        $temporary = null;
        try {
            if ($request->string('provider')->toString() === 'upload') {
                $request->validate(['file' => 'required|file']);
                $upload = $request->file('file');
                if (!preg_match('/\.(?:mcpack|mcaddon|zip)$/iD', $upload->getClientOriginalName())) {
                    throw new ConflictHttpException('Upload a .mcpack, .mcaddon or ZIP archive.');
                }
                $sourcePath = $upload->getRealPath();
                $source = ['provider' => 'upload'];
            } else {
                $request->validate(['version_id' => 'required|string|max:64']);
                $selected = $this->providerCall(fn () => $this->provider->selected($request->string('version_id')->toString()));
                $temporary = $this->store->directory($server) . '/' . bin2hex(random_bytes(16)) . '.download';
                $this->providerCall(fn () => $this->provider->download($selected, $temporary));
                $sourcePath = $temporary;
                $source = ['provider' => 'curseforge', 'project_id' => explode(':', $selected['id'])[0], 'version_id' => $selected['id']];
            }
            $data = $this->store->prepare($server, $sourcePath, $source);

            return new JsonResponse(['data' => $this->store->publicPreview($data)]);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function install(BeaconAddonRequest $request, Server $server): JsonResponse
    {
        $request->validate(['archive_id' => 'required|string|size:64|regex:/^[a-f0-9]+$/D', 'world' => 'required|string|max:64', 'acknowledge' => 'required|accepted']);
        $data = $this->store->load($server, $request->string('archive_id')->toString());
        $runtime = $this->runtime->resolve($server);
        $this->runtime->assertMinimum($data['manifest'], $runtime['version']);
        $this->runtime->assertWorld($server, $request->string('world')->toString());

        return $this->start($request, $server, ['action' => 'install', 'archive_id' => $data['archive_id'], 'world' => $request->string('world')->toString(), 'runtime' => $runtime, 'phase' => 'prepare']);
    }

    public function uninstall(BeaconAddonRequest $request, Server $server, int $installationId): JsonResponse
    {
        $record = BeaconAddonInstallation::where('server_id', $server->id)->findOrFail($installationId);
        $runtime = $this->runtime->resolve($server, data_get($record->manifest, 'runtime'));

        return $this->start($request, $server, ['action' => 'uninstall', 'archive_id' => $record->archive_id, 'world' => $record->world,
            'installation_id' => $record->id, 'runtime' => $runtime, 'phase' => 'prepare']);
    }

    public function operation(BeaconAddonRequest $request, Server $server, string $operationUuid): JsonResponse
    {
        $operation = BeaconOperation::where('server_id', $server->id)->where('type', 'like', 'content.addon.%')->where('uuid', $operationUuid)->firstOrFail();

        return new JsonResponse(['data' => $this->operationData($operation)]);
    }

    private function start(BeaconAddonRequest $request, Server $server, array $payload): JsonResponse
    {
        if (!config('beacon.content.backup_before_mutation') || $server->backup_limit < 1) {
            throw new ConflictHttpException('A full safety backup and a free backup slot are required before changing add-ons.');
        }
        if (!in_array(config('queue.connections.' . config('queue.default') . '.driver'), ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            throw new ConflictHttpException('An asynchronous worker is required before changing add-ons.');
        }
        [$operation, $created] = DB::transaction(function () use ($request, $server, $payload) {
            $current = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            $this->runtime->assertSupported($current);
            if (!hash_equals($payload['runtime']['selection'], $this->runtime->selection($current))) {
                throw new ConflictHttpException('The Bedrock runtime changed. Refresh Add-ons Installer.');
            }
            $actor = 'user:' . $request->user()->id;
            $key = (string) $request->attributes->get('beacon_idempotency_key');
            $intent = array_intersect_key($payload, array_flip(['action', 'archive_id', 'world', 'installation_id']));
            $previous = BeaconOperation::where('actor_key', $actor)->where('idempotency_key', $key)->first();
            if ($previous) {
                if ($previous->server_id !== $server->id || data_get($previous->payload, 'intent') !== $intent) {
                    throw new ConflictHttpException('The idempotency key was already used with a different request.');
                }

                return [$previous, false];
            }
            if (BeaconOperation::where('server_id', $server->id)->whereIn('status', ['pending', 'running'])->where('type', 'not like', 'version.%')->exists()) {
                throw new ConflictHttpException('Wait for the active Beacon operation before changing add-ons.');
            }
            $payload['intent'] = $intent;

            return $this->operations->handle($request->user(), $server, 'content.addon.' . $payload['action'], $request->attributes->get('beacon_correlation_id'), $key, $payload);
        }, 5);
        if ($created) {
            try {
                \Pterodactyl\Facades\Activity::event('beacon:addon.requested')->actor($request->user())->subject($server)->property('operation', $operation->uuid)->log();
                ProcessAddonOperationJob::dispatch($operation->id);
            } catch (\Throwable $e) {
                PublicOperationError::report($e, 'addon.queue', $operation->id);
                $operation->forceFill(['status' => 'failed', 'error_code' => 'addon_queue_unavailable', 'error_message' => 'The add-on worker queue is unavailable. No server files were changed.', 'finished_at' => now()])->save();
                throw new ServiceUnavailableHttpException(null, 'The add-on worker queue is unavailable. No server files were changed.');
            }
        }

        return new JsonResponse(['data' => $this->operationData($operation)], 202);
    }

    private function operationData(BeaconOperation $operation): array
    {
        return ['uuid' => $operation->uuid, 'status' => $operation->status, 'progress' => data_get($operation->payload, 'progress'),
            'result' => $operation->result, 'error' => $operation->error_code ? ['code' => $operation->error_code, 'message' => $operation->error_message] : null];
    }

    private function installationData(BeaconAddonInstallation $installation): array
    {
        return ['id' => $installation->id, 'world' => $installation->world, 'provider' => $installation->provider,
            'packs' => array_map(fn ($pack) => array_intersect_key($pack, array_flip(['uuid', 'name', 'version', 'directory', 'scripts'])), $installation->manifest['packs'])];
    }

    private function providerCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ProviderResponseException $e) {
            throw new HttpException(422, $e->getMessage(), $e);
        }
    }
}
