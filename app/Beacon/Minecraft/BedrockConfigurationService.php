<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shares only the protected file-write lifecycle; BDS parsing, fields and worlds are separate. */
class BedrockConfigurationService extends VanillaConfigurationService
{
    protected const RUNTIME = 'Vanilla Bedrock';

    public function __construct(DaemonFileRepository $files, DaemonServerRepository $power, VanillaFileReader $reader, BedrockRuntimeReader $versions, BedrockPropertyPolicy $policy)
    {
        parent::__construct($files, $power, $reader, $versions, $policy);
    }

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon') && $server->egg?->name === 'Vanilla Bedrock';
    }

    protected function runtimeFile(Server $server): ?string
    {
        return 'bedrock_server';
    }

    protected function runtimeFileLimit(): int
    {
        return BedrockRuntimeReader::MAX_BYTES;
    }

    protected function runtimeCacheEnabled(): bool
    {
        return false;
    }

    protected function runtimeWaitingReason(Server $server): ?string
    {
        return !$server->installed_at || $server->skip_scripts ? 'Complete the Bedrock installation before using Configuration.' : null;
    }

    protected function identifyRuntime(Server $server, string $path, int $size): ?array
    {
        $server = $server->fresh(['egg.nest']);
        if (!$server || !self::supports($server) || $server->status !== null || $server->transfer()->exists()
            || $server->skip_scripts || !$server->installed_at || $server->startup !== './bedrock_server'
            || !in_array($server->image, array_values($server->egg->docker_images ?? []), true)) {
            return null;
        }
        $selection = $server->variables()->get()->pluck('server_value', 'env_variable')->all();
        $runtime = $this->versions->inspect($server, $path, $size);
        $after = $server->fresh();
        if (!$after || $after->getRawOriginal() !== $server->getRawOriginal()
            || $after->variables()->get()->pluck('server_value', 'env_variable')->all() !== $selection) {
            throw new ConflictHttpException('The Bedrock runtime changed during inspection. Reload Configuration.');
        }
        if ($runtime !== null) {
            $runtime['identity'] = hash('sha256', json_encode([$runtime['identity'], $server->startup, $server->image,
                $server->installed_at->toIso8601String(), $selection], JSON_THROW_ON_ERROR));
        }

        return $runtime;
    }

    protected function document(string $content, string $version): BedrockPropertiesDocument
    {
        return new BedrockPropertiesDocument($content);
    }

    protected function gameVersion(string $version): ?string
    {
        return null;
    }

    protected function versionSource(): string
    {
        return 'bedrock_file';
    }

    protected function assertRuntimeCurrent(Server $server, array $response): void
    {
        $fresh = $this->read($server->fresh());
        if (!$fresh['editable'] || !hash_equals($response['runtime_hash'], $fresh['runtime_hash']) || !hash_equals($response['hash'], $fresh['hash'])) {
            throw new ConflictHttpException('The Bedrock runtime or properties changed before saving. Reload Configuration.');
        }
    }

    protected function worldSafety(): VanillaWorldSafety
    {
        return app(BedrockWorldSafety::class);
    }

    protected function notes(string $version): array
    {
        return [
            'Bedrock settings come from this instance\'s existing file. Runtime metadata and the ELF header are checked for drift; the exact installed version and binary authenticity are not verified or inferred from Startup/latest.',
            'Bedrock worlds live inside worlds/. Select an extracted Bedrock map; Java worlds and Java resource-pack URLs are not supported. Existing worlds are never regenerated or converted.',
            'The unchanged Egg rewrites properties before starting the game. New text values must use safe ASCII. Existing unsupported text is not automatically converted; replace it with ASCII if it changes on start.',
            'Only reviewed existing fields are editable. Network bindings, script-debugger credentials, permissions.json, allowlist.json and world pack files are not exposed or changed.',
        ];
    }

    protected function unrecognizedReason(): string
    {
        return 'The installed Bedrock runtime is missing, unsafe or uses unsupported custom launch settings. Configuration will not guess its layout or version.';
    }
}
