<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MinecraftServiceConfiguration
{
    private const MANAGED_KEYS = [
        'enable-rcon', 'rcon.port', 'rcon.password',
        'enable-query', 'query.port',
    ];

    public function __construct(private DaemonFileRepository $files)
    {
    }

    public static function driver(Server $server): ?string
    {
        $server->loadMissing('egg.nest');
        if ($server->egg?->nest?->name !== config('beacon.modpacks.nest.name', 'Beacon')) {
            return null;
        }

        return match (strtolower(trim($server->egg->name))) {
            'velocity' => 'velocircon',
            'vanilla minecraft', 'paper', 'paper + geyser + floodgate', 'fabric', 'curseforge generic',
            'forge minecraft', 'neoforge', 'neoforge minecraft', 'quilt', 'quilt minecraft',
            'bukkit', 'craftbukkit', 'spigot', 'purpur' => 'java',
            default => null,
        };
    }

    public static function usesBedrockConsole(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon')
            && strtolower(trim($server->egg->name)) === 'vanilla bedrock';
    }

    public function assertSupported(Server $server, string $service): void
    {
        $driver = self::driver($server);
        if ($driver === null || !in_array($service, ['rcon', 'query'], true)) {
            throw new ConflictHttpException('RCON & Query Manager requires supported Minecraft software in the Beacon nest.');
        }
        if ($driver === 'velocircon' && $service !== 'rcon') {
            throw new ConflictHttpException('Velocity Query management is not enabled in Beacon. Use velocity.toml for Query settings.');
        }
    }

    /** @return array<string, string> */
    public function inspect(Server $server, bool $verifyPlugin = true): array
    {
        $this->assertSupported($server, 'rcon');
        if (self::driver($server) === 'velocircon') {
            return app(VelocityRconConfiguration::class)->inspect($server, $verifyPlugin);
        }

        return $this->parse($this->read($server));
    }

    /**
     * A null value removes that managed key from the file.
     *
     * @param array<string, bool|int|string|null> $values
     */
    public function apply(Server $server, array $values): string
    {
        $invalid = array_diff(array_keys($values), self::MANAGED_KEYS);
        if ($invalid !== []) {
            throw new \InvalidArgumentException('An unsupported Minecraft service property was requested.');
        }
        $this->assertSupported($server, 'rcon');
        if (self::driver($server) === 'velocircon') {
            return app(VelocityRconConfiguration::class)->apply($server, $values);
        }

        $original = $this->read($server);
        $lineEnding = str_contains($original, "\r\n") ? "\r\n" : "\n";
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', $original) ?: [];
        $updated = array_map(function (string $line) use ($values, &$seen) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                return $line;
            }

            [$rawKey] = explode('=', $line, 2);
            $key = trim($rawKey);
            if (!array_key_exists($key, $values)) {
                return $line;
            }

            $seen[$key] = true;

            if ($values[$key] === null) {
                return null;
            }

            return $key . '=' . $this->serialize($values[$key]);
        }, $lines);

        $updated = array_values(array_filter($updated, fn (?string $line) => $line !== null));

        foreach ($values as $key => $value) {
            if (!isset($seen[$key]) && $value !== null) {
                $updated[] = $key . '=' . $this->serialize($value);
            }
        }

        $content = implode($lineEnding, $updated);
        $this->write($server, $content);

        return $original;
    }

    public function restore(Server $server, string $content): void
    {
        $this->assertSupported($server, 'rcon');
        if (self::driver($server) === 'velocircon') {
            app(VelocityRconConfiguration::class)->restore($server, $content);

            return;
        }
        $this->write($server, $content);
    }

    private function read(Server $server): string
    {
        $limit = (int) config('beacon.minecraft_configuration.max_file_bytes', 262144);
        $content = $this->files->setServer($server)->getContent('/server.properties', $limit);
        if (strlen($content) > $limit) {
            throw new ConflictHttpException('server.properties exceeds the managed configuration size limit.');
        }

        return $content;
    }

    private function write(Server $server, string $content): void
    {
        $limit = (int) config('beacon.minecraft_configuration.max_file_bytes', 262144);
        if (strlen($content) > $limit) {
            throw new ConflictHttpException('The updated server.properties exceeds the managed configuration size limit.');
        }

        $this->files->setServer($server)->putContent('/server.properties', $content);
    }

    /** @return array<string, string> */
    private function parse(string $content): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (in_array($key, self::MANAGED_KEYS, true)) {
                $result[$key] = trim($value);
            }
        }

        return $result;
    }

    private function serialize(bool|int|string $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
