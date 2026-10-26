<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Identify the installed official JAR, including old releases and Startup=latest. */
class VanillaJarVersionReader
{
    public const MAX_BYTES = 134217728;

    public function __construct(private VanillaFileReader $reader)
    {
    }

    public function read(Server $server, string $path, int $expectedSize): ?string
    {
        $hash = hash_init('sha1');
        $size = 0;
        $this->reader->consume($server, $path, self::MAX_BYTES, function (string $chunk) use ($hash, &$size) {
            hash_update($hash, $chunk);
            $size += strlen($chunk);
        });
        if ($size !== $expectedSize) {
            throw new ConflictHttpException('The Vanilla JAR changed while its version was being inspected. Reload Configuration.');
        }

        return $this->identify(hash_final($hash), $size);
    }

    public function identify(string $sha1, int $size): ?string
    {
        $index = json_decode(file_get_contents(__DIR__ . '/Vanilla/releases.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($index['releases'] as $version => $release) {
            if ($release['size'] === $size && hash_equals($release['sha1'], $sha1)) {
                return (string) $version;
            }
        }

        return null;
    }
}
