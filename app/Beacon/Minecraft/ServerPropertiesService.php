<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ServerPropertiesService
{
    public const DEFINITIONS = [
        'motd' => ['type' => 'string', 'label' => 'Message of the day'],
        'max-players' => ['type' => 'integer', 'label' => 'Maximum players', 'min' => 1, 'max' => 1000],
        'gamemode' => ['type' => 'select', 'label' => 'Default game mode', 'options' => ['survival', 'creative', 'adventure', 'spectator']],
        'difficulty' => ['type' => 'select', 'label' => 'Difficulty', 'options' => ['peaceful', 'easy', 'normal', 'hard']],
        'hardcore' => ['type' => 'boolean', 'label' => 'Hardcore'],
        'pvp' => ['type' => 'boolean', 'label' => 'Player versus player'],
        'online-mode' => ['type' => 'boolean', 'label' => 'Online authentication'],
        'white-list' => ['type' => 'boolean', 'label' => 'Whitelist'],
        'allow-flight' => ['type' => 'boolean', 'label' => 'Allow flight'],
        'view-distance' => ['type' => 'integer', 'label' => 'View distance', 'min' => 2, 'max' => 32],
        'simulation-distance' => ['type' => 'integer', 'label' => 'Simulation distance', 'min' => 2, 'max' => 32],
        'spawn-protection' => ['type' => 'integer', 'label' => 'Spawn protection', 'min' => 0, 'max' => 1000],
    ];

    public function __construct(private DaemonFileRepository $files)
    {
    }

    public function read(Server $server): array
    {
        $content = $this->content($server);

        return [
            'hash' => hash('sha256', $content),
            'properties' => $this->parse($content),
            'definitions' => self::DEFINITIONS,
        ];
    }

    public function update(Server $server, string $expectedHash, array $properties): array
    {
        $repository = $this->files->setServer($server);
        $content = $this->content($server);
        if (!hash_equals(hash('sha256', $content), $expectedHash)) {
            throw new ConflictHttpException('server.properties changed since it was loaded. Reload and try again.');
        }

        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $updated = array_map(function (string $line) use ($properties, &$seen) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                return $line;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);
            if (!array_key_exists($key, $properties)) {
                return $line;
            }

            $seen[$key] = true;
            $value = $this->serialize($properties[$key]);

            return $key . '=' . $value;
        }, $lines ?: []);

        foreach ($properties as $key => $value) {
            if (!isset($seen[$key])) {
                $updated[] = $key . '=' . $this->serialize($value);
            }
        }

        $newContent = implode(PHP_EOL, $updated);
        $repository->putContent('/server.properties', $newContent);

        return [
            'hash' => hash('sha256', $newContent),
            'properties' => $this->parse($newContent),
            'definitions' => self::DEFINITIONS,
        ];
    }

    private function parse(string $content): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (array_key_exists($key, self::DEFINITIONS)) {
                $result[$key] = $this->cast($value, self::DEFINITIONS[$key]['type']);
            }
        }

        return $result;
    }

    private function content(Server $server): string
    {
        $limit = (int) config('beacon.minecraft_configuration.max_file_bytes');
        $content = $this->files->setServer($server)->getContent('/server.properties', $limit);
        if (strlen($content) > $limit) {
            throw new ConflictHttpException('server.properties exceeds the managed configuration size limit.');
        }

        return $content;
    }

    private function cast(string $value, string $type): bool|int|string
    {
        return match ($type) {
            'boolean' => strtolower(trim($value)) === 'true',
            'integer' => (int) trim($value),
            default => $value,
        };
    }

    private function serialize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
