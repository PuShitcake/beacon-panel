<?php

namespace Pterodactyl\Beacon\Minecraft;

use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VelocityConfigurationService implements MinecraftConfigurationService
{
    private const LIMIT = 262144;

    public function __construct(
        private DaemonFileRepository $files,
        private DaemonServerRepository $power,
        private VanillaFileReader $reader,
        private VelocityJarIdentity $runtime,
        private VelocityPropertyPolicy $policy,
    ) {
    }

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon') && $server->egg?->name === 'Velocity';
    }

    public function read(Server $server): array
    {
        return $this->inspect($server)['response'];
    }

    public function update(Server $server, string $hash, array $changes, string $runtimeHash, ?string $worldBackupUuid = null): array
    {
        return $server->getConnection()->transaction(function () use ($server, $hash, $changes, $runtimeHash) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            $this->assertIdle($server);
            $before = $this->inspect($server);
            $response = $before['response'];
            if (!$response['editable'] || !hash_equals($response['hash'], $hash) || !hash_equals($response['runtime_hash'], $runtimeHash)) {
                throw new ConflictHttpException('Velocity runtime or velocity.toml changed or is unavailable. Reload Configuration before saving.');
            }
            $changes = $this->policy->validate($changes, $response['definitions']);
            $changes = array_filter($changes, fn ($value, $key) => $response['properties'][$key] !== $value, ARRAY_FILTER_USE_BOTH);
            if (!$changes) {
                return $response;
            }
            $updated = $before['document']->patch($changes);
            // Parse our own output before writing; preserve every unedited byte.
            new VelocityTomlDocument($updated);
            $storage = Storage::disk('local');
            $backup = 'beacon/configuration/' . $server->uuid . '/' . Str::uuid() . '.toml';
            if (!$storage->put($backup, $before['content'], 'private') || $storage->get($backup) !== $before['content']) {
                throw new ConflictHttpException('The configuration restore point could not be verified. No settings were changed.');
            }
            // Rehash JAR and re-read the file immediately before mutation. SFTP and power
            // requests cannot share this database lock; never overwrite unknown readback.
            $current = $this->inspect($server)['response'];
            if (!$current['editable'] || !hash_equals($current['hash'], $hash) || !hash_equals($current['runtime_hash'], $runtimeHash)) {
                throw new ConflictHttpException('Velocity runtime or velocity.toml changed before saving. Reload Configuration.');
            }
            $this->assertIdle($server);
            if ($this->reader->read($server, '/velocity.toml', self::LIMIT) !== $before['content']) {
                throw new ConflictHttpException('velocity.toml changed before saving. Reload Configuration.');
            }
            try {
                $this->files->setServer($server)->putContent('/velocity.toml', $updated);
            } catch (\Throwable) {
                // A lost acknowledgement is only successful when exact readback proves it.
            }
            try {
                $actual = $this->reader->read($server, '/velocity.toml', self::LIMIT);
            } catch (\Throwable) {
                throw new ConflictHttpException('The configuration save could not be verified. A private restore point was retained; ask an administrator to review the file.');
            }
            if ($actual !== $updated) {
                throw new ConflictHttpException('The configuration save did not verify. A private restore point was retained; ask an administrator to review the file.');
            }

            return $this->response($updated, $runtimeHash);
        });
    }

    private function inspect(Server $server): array
    {
        if (!self::supports($server)) {
            throw new ConflictHttpException('This configuration adapter requires Velocity in the Beacon nest.');
        }
        if ($server->status !== null || $server->transfer()->exists()) {
            return ['response' => $this->unavailable('Wait for installation or restore to finish, then refresh Configuration.', 'waiting')];
        }
        $entries = $this->files->setServer($server)->getDirectory('/');
        $entry = collect($entries)->firstWhere('name', 'velocity.toml');
        if ($entry === null) {
            return ['response' => $this->unavailable('Start Velocity once to generate velocity.toml, then stop it before saving settings.', 'waiting')];
        }
        if (!$this->regular($entry, self::LIMIT)) {
            return ['response' => $this->unavailable('velocity.toml must be a non-empty regular file within the size limit. Symlinks are not supported.')];
        }
        $content = $this->reader->read($server, '/velocity.toml', self::LIMIT);
        try {
            $document = new VelocityTomlDocument($content);
        } catch (\InvalidArgumentException) {
            return ['response' => $this->unavailable('This velocity.toml uses unsupported or ambiguous TOML syntax. Review it in Files; no settings have been changed.')];
        }
        $jar = $server->variables()->get()->firstWhere('env_variable', 'SERVER_JARFILE')?->server_value;
        if (!is_string($jar) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.jar$/D', $jar)) {
            return ['response' => $this->unavailable('The Velocity runtime must use a regular root-level JAR.')];
        }
        $jarEntry = collect($entries)->firstWhere('name', $jar);
        if (!$this->regular($jarEntry, VelocityJarIdentity::MAX_BYTES)) {
            return ['response' => $this->unavailable('The Velocity runtime JAR is missing, oversized or unsafe.')];
        }
        $identity = $this->runtime->read($server, '/' . $jar, $jarEntry['size']);
        if ($identity === null) {
            return ['response' => $this->unavailable('The installed JAR could not be identified as a Velocity proxy.')];
        }
        $after = collect($this->files->setServer($server)->getDirectory('/'));
        if ($after->firstWhere('name', $jar) !== $jarEntry || $after->firstWhere('name', 'velocity.toml') !== $entry) {
            throw new ConflictHttpException('Velocity files changed during verification. Reload Configuration.');
        }
        $runtimeHash = hash('sha256', json_encode([$server->uuid, $server->egg_id, $server->startup, $server->image, $jar, $identity], JSON_THROW_ON_ERROR));

        return ['content' => $content, 'document' => $document, 'response' => $this->response($content, $runtimeHash)];
    }

    private function response(string $content, string $runtimeHash): array
    {
        $values = (new VelocityTomlDocument($content))->values();
        $definitions = $this->policy->definitions($values);

        return ['hash' => hash('sha256', $content), 'runtime_hash' => $runtimeHash, 'game_version' => null,
            'runtime_name' => 'Velocity', 'state' => 'ready', 'source' => 'velocity.toml', 'editable' => true,
            'unavailable_reason' => null, 'definitions' => $definitions ?: new \stdClass(),
            'properties' => array_intersect_key($values, $definitions) ?: new \stdClass(),
            'notes' => ['Values come from this instance\'s velocity.toml; absent or unsupported fields are not added.',
                'The installed proxy JAR is fingerprinted for change detection. This does not certify its official provenance or an exact Velocity/Minecraft version.',
                'Proxy bind/ports, backend routing, authentication and player forwarding remain in Files. Forwarding secrets are never returned by this editor.',
                'Save while offline, then start the proxy to apply changes. This editor does not configure or start backend Minecraft servers.']];
    }

    private function unavailable(string $reason, string $state = 'unavailable'): array
    {
        return ['hash' => '', 'runtime_hash' => '', 'game_version' => null, 'runtime_name' => 'Velocity', 'state' => $state,
            'source' => 'velocity.toml', 'editable' => false, 'unavailable_reason' => $reason, 'notes' => [],
            'definitions' => new \stdClass(), 'properties' => new \stdClass()];
    }

    private function regular(mixed $entry, int $limit): bool
    {
        return is_array($entry) && ($entry['file'] ?? false) === true && ($entry['symlink'] ?? false) === false
            && is_int($entry['size'] ?? null) && $entry['size'] > 0 && $entry['size'] <= $limit;
    }

    private function assertIdle(Server $server): void
    {
        if (!self::supports($server) || $server->status !== null || $server->transfer()->exists()
            || BeaconOperation::query()->where('server_id', $server->id)->whereIn('status', ['pending', 'running'])->where('type', 'not like', 'version.%')->exists()) {
            throw new ConflictHttpException('Wait for the active server operation before changing Velocity configuration.');
        }
        if (data_get($this->power->setServer($server)->getDetails(), 'state') !== 'offline') {
            throw new ConflictHttpException('Stop the proxy before changing Velocity configuration.');
        }
    }
}
