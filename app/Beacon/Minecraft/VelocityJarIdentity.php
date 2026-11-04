<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Local runtime identity for concurrency, NOT official provenance/version certification. */
class VelocityJarIdentity
{
    public const MAX_BYTES = 134217728;

    // Velocity bundles its dependencies: official 4.3.1 has 25,080 entries.
    // Keep a bounded proxy-specific limit, not the smaller Paperclip limit.
    private const MAX_ENTRIES = 32768;

    public function __construct(private VanillaFileReader $files)
    {
    }

    public function read(Server $server, string $path, int $expectedSize): ?string
    {
        return $this->inspect($server, $path, $expectedSize)['identity'] ?? null;
    }

    /** Manifest-declared proxy version, not Minecraft protocol support or official provenance. */
    public function inspect(Server $server, string $path, int $expectedSize): ?array
    {
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new ConflictHttpException('Velocity runtime verification could not create a private temporary file.');
        }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            $hash = hash_init('sha256');
            $size = 0;
            $this->files->consume($server, $path, self::MAX_BYTES, function (string $chunk) use ($temporary, $hash, &$size) {
                $size += strlen($chunk);
                if ($size > self::MAX_BYTES || fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new ConflictHttpException('The Velocity JAR could not be inspected safely.');
                }
                hash_update($hash, $chunk);
            });
            if ($size !== $expectedSize) {
                throw new ConflictHttpException('The Velocity JAR changed during verification. Reload Configuration.');
            }
            fflush($temporary);
            $opened = @$zip->open(stream_get_meta_data($temporary)['uri'], \ZipArchive::RDONLY) === true;
            if (!$opened || $zip->numFiles > self::MAX_ENTRIES) {
                return null;
            }
            $matches = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                if ($zip->getNameIndex($i) === 'META-INF/MANIFEST.MF') {
                    $matches[] = $i;
                }
            }
            if (count($matches) !== 1) {
                return null;
            }
            $stat = $zip->statIndex($matches[0]);
            if (!$stat || $stat['size'] < 1 || $stat['size'] > 16384 || ($stat['encryption_method'] ?? 0) !== 0) {
                return null;
            }
            $manifest = $zip->getFromIndex($matches[0], 16385);
            if (!is_string($manifest) || strlen($manifest) !== $stat['size']) {
                return null;
            }
            $main = preg_split('/\r?\n\r?\n/', $manifest, 2)[0];
            if (preg_match_all('/^Main-Class:[^\r\n]*\r?$/mi', $main) !== 1
                || !preg_match('/^Main-Class: com\.velocitypowered\.proxy\.Velocity\r?$/mi', $main)) {
                return null;
            }

            $version = null;
            if (preg_match_all('/^Implementation-Version:[^\r\n]*\r?$/mi', $main) === 1
                && preg_match('/^Implementation-Version: ([^\r\n]+)\r?$/mi', $main, $versions)
                && preg_match('/^[0-9]{1,2}\.[0-9]{1,2}(?:\.[0-9]{1,2})?$/D', $versions[1])) {
                $version = $versions[1];
            }

            return ['identity' => hash_final($hash), 'version' => $version];
        } finally {
            if ($opened) {
                $zip->close();
            }
            fclose($temporary);
        }
    }
}
