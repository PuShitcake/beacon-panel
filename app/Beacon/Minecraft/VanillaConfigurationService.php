<?php

namespace Pterodactyl\Beacon\Minecraft;

use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VanillaConfigurationService
{
    protected const RUNTIME = 'Vanilla';

    private const FILE_LIMIT = 262144;

    public function __construct(
        private DaemonFileRepository $files,
        private DaemonServerRepository $power,
        private VanillaFileReader $reader,
        protected VanillaJarVersionReader $versions,
        private VanillaPropertyPolicy $schema,
    ) {
    }

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon')
            && $server->egg?->name === 'Vanilla Minecraft';
    }

    public function read(Server $server): array
    {
        return $this->inspect($server)['response'];
    }

    public function update(Server $server, string $hash, array $changes, string $runtimeHash, ?string $worldBackupUuid = null): array
    {
        // Other Beacon admissions lock this same native Server row. Do not let queued work
        // enter midway through a properties write, and serialize concurrent configuration saves.
        return $server->getConnection()->transaction(function () use ($server, $hash, $changes, $runtimeHash, $worldBackupUuid) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (!static::supports($server) || $server->status !== null || $server->transfer()->exists()) {
                throw new ConflictHttpException('The ' . static::RUNTIME . ' runtime changed. Reload Configuration.');
            }
            if (BeaconOperation::query()->where('server_id', $server->id)->whereIn('status', ['pending', 'running'])
                ->where('type', 'not like', 'version.%')->exists()) {
                throw new ConflictHttpException('Wait for the active Beacon operation before changing Minecraft configuration.');
            }
            $this->assertOffline($server);
            $inspection = $this->inspect($server, true);
            $response = $inspection['response'];
            if (!$response['editable']) {
                throw new ConflictHttpException($response['unavailable_reason']);
            }
            if (!hash_equals($response['runtime_hash'], $runtimeHash) || !hash_equals($response['hash'], $hash)) {
                throw new ConflictHttpException('The ' . static::RUNTIME . ' runtime or server.properties changed. Reload Configuration before saving.');
            }
            $serialized = $this->schema->validate($changes, $response['definitions']);
            foreach ($serialized as $key => $value) {
                if ($response['properties'][$key] === $this->schema->present([$key => $value], [$key => $response['definitions'][$key]])[$key]) {
                    unset($serialized[$key]);
                }
            }
            if (!$serialized) {
                return $response;
            }
            $effective = $serialized + $inspection['document']->values();
            $packChange = array_intersect_key($serialized, array_flip(['resource-pack', 'require-resource-pack']));
            if ($packChange && ($effective['require-resource-pack'] ?? 'false') === 'true' && ($effective['resource-pack'] ?? '') === '') {
                throw new ConflictHttpException('Set a resource pack URL or disable Require resource pack before saving.');
            }
            if (isset($serialized['resource-pack']) && !array_key_exists('resource-pack-sha1', $changes) && ($effective['resource-pack-sha1'] ?? '') !== '') {
                throw new ConflictHttpException('Update or clear the resource pack SHA-1 when changing its URL.');
            }
            $this->worldSafety()->assertSafe($server, $serialized, $response, $worldBackupUuid);
            $original = $inspection['content'];
            $newContent = $inspection['document']->patch($serialized);
            $backup = 'beacon/configuration/' . $server->uuid . '/' . Str::uuid() . '.properties';
            $storage = Storage::disk('local');
            if (!$storage->put($backup, $original, 'private') || $storage->get($backup) !== $original) {
                throw new ConflictHttpException('The configuration restore point could not be verified. No settings were changed.');
            }
            $this->assertOffline($server);
            // A file manager/SFTP writer does not participate in SQL locks. Recheck immediately
            // before writing, and never blindly overwrite an unexpected readback on failure.
            if ($this->reader->read($server, '/server.properties', self::FILE_LIMIT) !== $original) {
                throw new ConflictHttpException('server.properties changed before saving. Reload Configuration.');
            }
            $this->worldSafety()->assertSafe($server, $serialized, $response, $worldBackupUuid);
            $this->assertRuntimeCurrent($server, $response);
            try {
                $this->files->setServer($server)->putContent('/server.properties', $newContent);
            } catch (\Throwable) {
                // A response may be lost after Wings completed the write. Only exact readback
                // proves success; the following check also covers this ambiguous transport case.
            }
            try {
                $actual = $this->reader->read($server, '/server.properties', self::FILE_LIMIT);
            } catch (\Throwable) {
                throw new ConflictHttpException('The configuration save could not be verified. A private restore point was retained; ask an administrator to review the file before retrying.');
            }
            if ($actual !== $newContent) {
                throw new ConflictHttpException('The configuration save did not verify. A private restore point was retained; ask an administrator to review the file before retrying.');
            }

            return $this->response($actual, $inspection['version'], $response['runtime_hash']);
        });
    }

    private function inspect(Server $server, bool $fresh = false): array
    {
        if (!static::supports($server)) {
            throw new ConflictHttpException('This configuration adapter requires ' . static::RUNTIME . ' Minecraft in the Beacon nest.');
        }
        if ($server->status !== null || $server->transfer()->exists()) {
            return ['response' => $this->unavailable('Wait for the server installation or restore to finish, then refresh Configuration.', 'waiting')];
        }
        if ($reason = $this->runtimeWaitingReason($server)) {
            return ['response' => $this->unavailable($reason, 'waiting')];
        }
        $entries = $this->files->setServer($server)->getDirectory('/');
        $properties = collect($entries)->firstWhere('name', 'server.properties');
        if ($properties === null) {
            return ['response' => $this->unavailable('Start the ' . static::RUNTIME . ' server once to generate server.properties, then stop it before saving settings.', 'waiting')];
        }
        if (!$this->regular($properties, self::FILE_LIMIT)) {
            return ['response' => $this->unavailable('server.properties must be a non-empty regular file within the configuration size limit. Symlinks are not supported.')];
        }
        // The existing file drives discovery. Do not download a JAR, invent a template,
        // or write anything while waiting for Minecraft to generate its own settings.
        $content = $this->reader->read($server, '/server.properties', self::FILE_LIMIT);
        $jar = $this->runtimeFile($server);
        if ($jar === null) {
            return ['response' => $this->unavailable('The configured ' . static::RUNTIME . ' server JAR must be a regular root-level .jar file.')];
        }
        $jarEntry = collect($entries)->firstWhere('name', $jar);
        if (!$this->regular($jarEntry, $this->runtimeFileLimit())) {
            return ['response' => $this->unavailable('The ' . static::RUNTIME . ' runtime identity file is missing or unsafe. Symlinks and oversized files are not supported.')];
        }
        $fingerprint = hash('sha256', json_encode([$server->uuid, $server->egg_id, $jar, $jarEntry], JSON_THROW_ON_ERROR));
        $cacheKey = 'beacon:' . strtolower(static::RUNTIME) . '-jar:v2:' . $fingerprint;
        // Never cache without file timestamps, and always rehash the installed JAR on mutation.
        $cacheable = $this->runtimeCacheEnabled() && is_string($jarEntry['modified_at'] ?? null);
        $runtime = !$fresh && $cacheable ? Cache::get($cacheKey) : null;
        if (!is_array($runtime)) {
            $runtime = $this->identifyRuntime($server, '/' . $jar, $jarEntry['size']);
            $after = collect($this->files->setServer($server)->getDirectory('/'))->firstWhere('name', $jar);
            if ($after !== $jarEntry) {
                throw new ConflictHttpException('The ' . static::RUNTIME . ' runtime file changed during verification. Reload Configuration.');
            }
            if ($cacheable && $runtime !== null) {
                Cache::put($cacheKey, $runtime, 300);
            }
        }
        if ($runtime === null) {
            return ['response' => $this->unavailable($this->unrecognizedReason())];
        }
        $version = $runtime['version'];
        if (!$this->schema->supports($version)) {
            return ['response' => $this->unavailable('This ' . static::RUNTIME . ' release is not in the verified runtime index. Configuration will not guess version-specific validation rules.')];
        }
        $runtimeHash = hash('sha256', $fingerprint . ':' . $runtime['identity']);

        return ['content' => $content, 'version' => $version, 'document' => $this->document($content, $version),
            'response' => $this->response($content, $version, $runtimeHash)];
    }

    private function response(string $content, string $version, string $runtimeHash): array
    {
        $document = $this->document($content, $version);
        $definitions = $this->schema->definitions($version, $document->values());

        return ['hash' => hash('sha256', $content), 'runtime_hash' => $runtimeHash, 'game_version' => $this->gameVersion($version),
            'runtime_name' => static::RUNTIME === 'Vanilla' ? 'Vanilla Minecraft' : static::RUNTIME,
            'version_source' => $this->versionSource(),
            'world_backup_name' => VanillaWorldSafety::backupName(hash('sha256', $content), $runtimeHash),
            'state' => 'ready', 'source' => 'server.properties', 'editable' => true, 'unavailable_reason' => null,
            'notes' => $this->notes($version),
            'definitions' => $definitions, 'properties' => $this->schema->present($document->values(), $definitions)];
    }

    private function unavailable(string $reason, string $state = 'unavailable'): array
    {
        return ['hash' => '', 'runtime_hash' => '', 'game_version' => null, 'editable' => false,
            'state' => $state, 'source' => 'server.properties', 'unavailable_reason' => $reason,
            'notes' => [], 'definitions' => new \stdClass(), 'properties' => new \stdClass()];
    }

    private function regular(mixed $entry, int $limit): bool
    {
        return is_array($entry) && ($entry['file'] ?? false) === true && ($entry['symlink'] ?? false) === false
            && is_int($entry['size'] ?? null) && $entry['size'] > 0 && $entry['size'] <= $limit;
    }

    private function assertOffline(Server $server): void
    {
        if (data_get($this->power->setServer($server)->getDetails(), 'state') !== 'offline') {
            throw new ConflictHttpException('Stop the server before changing Minecraft configuration.');
        }
    }

    protected function identifyRuntime(Server $server, string $path, int $size): ?array
    {
        $version = $this->versions->read($server, $path, $size);

        return $version === null ? null : ['version' => $version, 'identity' => $version];
    }

    protected function document(string $content, string $version): JavaPropertiesDocument|BedrockPropertiesDocument
    {
        return new JavaPropertiesDocument($content, version_compare($version, '1.20', '>='));
    }

    protected function gameVersion(string $version): ?string
    {
        return $version;
    }

    protected function runtimeCacheEnabled(): bool
    {
        return true;
    }

    protected function runtimeFile(Server $server): ?string
    {
        $jar = $server->variables()->get()->firstWhere('env_variable', 'SERVER_JARFILE')?->server_value;

        return is_string($jar) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.jar$/D', $jar) ? $jar : null;
    }

    protected function runtimeFileLimit(): int
    {
        return VanillaJarVersionReader::MAX_BYTES;
    }

    protected function runtimeWaitingReason(Server $server): ?string
    {
        return null;
    }

    protected function versionSource(): string
    {
        return 'official_server_jar';
    }

    protected function assertRuntimeCurrent(Server $server, array $response): void
    {
    }

    protected function worldSafety(): VanillaWorldSafety
    {
        return app(VanillaWorldSafety::class);
    }

    protected function notes(string $version): array
    {
        return version_compare($version, '1.21.9', '>=') ? ['PvP, Nether access and monster spawning are gamerules in this release and are not edited through server.properties.'] : [];
    }

    protected function unrecognizedReason(): string
    {
        return 'This JAR is not in the verified official Vanilla release index. Configuration is read-only for custom JARs, snapshots and unrecognized releases; Beacon will not guess from Startup or latest.';
    }
}
