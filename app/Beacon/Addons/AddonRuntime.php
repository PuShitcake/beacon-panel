<?php

namespace Pterodactyl\Beacon\Addons;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Beacon\Minecraft\VanillaFileReader;
use Pterodactyl\Beacon\Minecraft\BedrockPropertyPolicy;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Beacon\Minecraft\BedrockConfigurationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Version comes from the running BDS status (NetherNet or RakNet), never Startup=latest. */
class AddonRuntime
{
    public function __construct(private DaemonFileRepository $files, private VanillaFileReader $reader)
    {
    }

    public function assertSupported(Server $server): void
    {
        if (!BedrockConfigurationService::supports($server) || $server->status !== null || !$server->installed_at || $server->skip_scripts || $server->transfer()->exists()
            || $server->startup !== './bedrock_server' || !in_array($server->image, array_values($server->egg->docker_images ?? []), true)) {
            throw new ConflictHttpException('Add-ons require an installed Vanilla Bedrock instance in the Beacon nest.');
        }
    }

    public function resolve(Server $server, ?array $known = null): array
    {
        $this->assertSupported($server);
        $files = $this->files->setServer($server);
        $entry = collect($files->getDirectory('/'))->firstWhere('name', 'bedrock_server');
        if (!is_array($entry) || ($entry['file'] ?? null) !== true || ($entry['symlink'] ?? null) !== false
            || !is_int($entry['size'] ?? null) || $entry['size'] < 64 || $entry['size'] > 536870912) {
            throw new ConflictHttpException('A regular Bedrock server binary is required before installing add-ons.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        $header = '';
        $this->reader->consume($server, '/bedrock_server', $entry['size'], function (string $chunk) use ($hash, &$size, &$header) {
            $size += strlen($chunk);
            if (strlen($header) < 6) {
                $header .= substr($chunk, 0, 6 - strlen($header));
            }
            hash_update($hash, $chunk);
        });
        if ($header !== "\x7fELF\x02\x01" || $size !== $entry['size'] || $entry !== collect($files->getDirectory('/'))->firstWhere('name', 'bedrock_server')) {
            throw new ConflictHttpException('The Bedrock binary changed during verification. Refresh Add-ons Installer.');
        }
        $identity = hash_final($hash);
        $variables = $server->variables()->get()->pluck('server_value', 'env_variable')->all();
        ksort($variables);
        $stamp = hash('sha256', json_encode([$identity, $server->egg_id, $server->image, $server->startup, $variables], JSON_THROW_ON_ERROR));
        $key = 'beacon:addons:runtime:' . $server->uuid . ':' . $identity;
        $version = Cache::get($key);
        // A prior verified installation remains removable after Redis expires/restarts.
        if (!is_string($version) && ($known['identity'] ?? null) === $stamp && is_string($known['version'] ?? null)
            && preg_match('/^1\.[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?$/D', $known['version'])) {
            $version = $known['version'];
        }
        if (!is_string($version)) {
            $version = $this->probe($server);
            if ($version !== null) {
                Cache::put($key, $version, 86400);
            }
        }
        if (!is_string($version)) {
            throw new ConflictHttpException('Start Bedrock once and refresh this page so its version can be verified. Panel must reach its game status port (TCP for NetherNet, UDP for legacy RakNet).');
        }

        return ['version' => $version, 'selection' => $this->selection($server), 'identity' => $stamp];
    }

    public function selection(Server $server): string
    {
        $variables = $server->variables()->get()->pluck('server_value', 'env_variable')->all();
        ksort($variables);

        return hash('sha256', json_encode([$server->egg_id, $server->image, $server->startup, $variables], JSON_THROW_ON_ERROR));
    }

    public function worlds(Server $server): array
    {
        $this->assertSupported($server);
        $files = $this->files->setServer($server);
        $this->directory($files->getDirectory('/'), 'worlds');
        $entries = $files->getDirectory('/worlds');
        if (count($entries) > 100) {
            throw new ConflictHttpException('Too many world folders to inspect safely.');
        }
        $worlds = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if (!BedrockPropertyPolicy::safeWorldName($name) || ($entry['file'] ?? null) !== false || ($entry['symlink'] ?? null) !== false) {
                continue;
            }
            $items = $files->getDirectory('/worlds/' . $name);
            $level = collect($items)->firstWhere('name', 'level.dat');
            $db = collect($items)->firstWhere('name', 'db');
            if (($level['file'] ?? null) === true && ($level['symlink'] ?? null) === false && ($level['size'] ?? 0) >= 9
                && ($db['file'] ?? null) === false && ($db['symlink'] ?? null) === false) {
                $worlds[] = $name;
            }
        }

        return $worlds;
    }

    public function assertWorld(Server $server, string $world): void
    {
        if (!BedrockPropertyPolicy::safeWorldName($world) || !in_array($world, $this->worlds($server), true)) {
            throw new ConflictHttpException('Choose an existing extracted Bedrock world. No world will be created or converted.');
        }
    }

    public function assertMinimum(array $manifest, string $version): void
    {
        foreach ($manifest['packs'] as $pack) {
            if (version_compare($version, implode('.', $pack['minimum_engine']), '<')) {
                throw new ConflictHttpException('An add-on requires a newer Bedrock version than this instance.');
            }
        }
    }

    public function directory(array $entries, string $name): void
    {
        $entry = collect($entries)->firstWhere('name', $name);
        if (!is_array($entry) || ($entry['file'] ?? null) !== false || ($entry['symlink'] ?? null) !== false) {
            throw new ConflictHttpException('Add-on paths must be regular directories without symlinks.');
        }
    }

    protected function probe(Server $server): ?string
    {
        // These are two documented status protocols on the same native allocation, not
        // alternate hosts or a guessed version when a provider is unavailable.
        return $this->probeNetherNet($server) ?? $this->probeRakNet($server);
    }

    protected function probeNetherNet(Server $server): ?string
    {
        $allocation = $server->allocation;
        $ip = $allocation?->ip;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !$allocation->port) {
            return null;
        }
        try {
            // Fixed native IP/port/path only. Never send Panel/provider credentials, follow
            // redirects, use cookies or negotiate a game session (POST /v1/join/{id}).
            $response = Http::acceptJson()->withOptions(['stream' => true, 'allow_redirects' => false, 'cookies' => false])
                ->connectTimeout(2)->timeout(4)->get('http://' . $ip . ':' . (int) $allocation->port . '/v1/join');
            $body = $response->toPsrResponse()->getBody();
            try {
                $length = $response->header('Content-Length');
                if (!$response->successful() || strtolower(trim(explode(';', $response->header('Content-Type') ?? '')[0])) !== 'application/json'
                    || ($length !== null && $length !== '' && (!ctype_digit($length) || (float) $length > 16384))) {
                    return null;
                }
                $content = '';
                while (!$body->eof()) {
                    $chunk = $body->read(16385 - strlen($content));
                    $content .= $chunk;
                    if (strlen($content) > 16384 || ($chunk === '' && !$body->eof())) {
                        return null;
                    }
                }
                $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !is_int($data['protocol'] ?? null) || $data['protocol'] < 1 || $data['protocol'] > 65535
                    || !is_string($data['version'] ?? null) || !preg_match('/^1\.[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?$/D', $data['version'])
                    || !is_string($data['name'] ?? null) || !is_string($data['level'] ?? null)
                    || !is_int($data['players'] ?? null) || $data['players'] < 0 || !is_int($data['maxPlayers'] ?? null) || $data['maxPlayers'] < 1
                    || !in_array($data['gameType'] ?? null, [0, 1, 2], true)) {
                    return null;
                }

                return $data['version'];
            } finally {
                $body->close();
            }
        } catch (\Throwable) {
            return null;
        }
    }

    protected function probeRakNet(Server $server): ?string
    {
        $allocation = $server->allocation;
        $ip = $allocation?->ip;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !$allocation->port) {
            return null;
        }
        $socket = @stream_socket_client('udp://' . $ip . ':' . (int) $allocation->port, $errorCode, $errorMessage, 2);
        if (!$socket) {
            return null;
        }
        try {
            stream_set_timeout($socket, 2);
            $nonce = random_bytes(8);
            $magic = hex2bin('00ffff00fefefefefdfdfdfd12345678');
            fwrite($socket, "\x01" . $nonce . $magic . random_bytes(8));
            $reply = fread($socket, 2048);

            return $this->rakNetVersion($reply, $nonce, $magic);
        } finally {
            fclose($socket);
        }
    }

    protected function rakNetVersion(string $reply, string $nonce, string $magic): ?string
    {
        if (strlen($reply) < 35 || $reply[0] !== "\x1c" || substr($reply, 1, 8) !== $nonce || substr($reply, 17, 16) !== $magic
            || unpack('n', substr($reply, 33, 2))[1] !== strlen($reply) - 35) {
            return null;
        }
        $fields = explode(';', substr($reply, 35));
        if (count($fields) === 13 && $fields[12] === '') {
            array_pop($fields);
        }
        // A semicolon inside a MOTD must not shift a fabricated value into the version field.
        if (count($fields) !== 12 || $fields[0] !== 'MCPE' || !ctype_digit($fields[2])
            || !preg_match('/^1\.[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?$/D', $fields[3])
            || !ctype_digit($fields[4]) || !ctype_digit($fields[5]) || !ctype_digit($fields[6]) || !ctype_digit($fields[9])
            || !ctype_digit($fields[10]) || !ctype_digit($fields[11]) || (int) $fields[10] < 1 || (int) $fields[10] > 65535
            || (int) $fields[11] < 1 || (int) $fields[11] > 65535) {
            return null;
        }

        return $fields[3];
    }
}
