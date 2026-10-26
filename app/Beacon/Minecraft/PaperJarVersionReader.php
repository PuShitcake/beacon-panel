<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Embedded metadata is only a lookup hint; the full official SHA-256 must match. */
class PaperJarVersionReader extends VanillaJarVersionReader
{
    public function __construct(private VanillaFileReader $files, private PaperBuildIndex $index)
    {
        parent::__construct($files);
    }

    public function read(Server $server, string $path, int $expectedSize): ?string
    {
        return $this->inspect($server, $path, $expectedSize)['version'] ?? null;
    }

    public function inspect(Server $server, string $path, int $expectedSize): ?array
    {
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new ConflictHttpException('Paper verification could not create a private temporary file. No settings were changed.');
        }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            $hash = hash_init('sha256');
            $size = 0;
            $this->files->consume($server, $path, self::MAX_BYTES, function (string $chunk) use ($temporary, $hash, &$size) {
                $size += strlen($chunk);
                if ($size > self::MAX_BYTES || fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new ConflictHttpException('The Paper JAR could not be inspected safely. No settings were changed.');
                }
                hash_update($hash, $chunk);
            });
            if ($size !== $expectedSize) {
                throw new ConflictHttpException('The Paper JAR changed during verification. Reload Configuration.');
            }
            fflush($temporary);
            $sha256 = hash_final($hash);
            $opened = @$zip->open(stream_get_meta_data($temporary)['uri'], \ZipArchive::RDONLY) === true;
            if (!$opened || $zip->numFiles > 16384) {
                return null;
            }
            $version = $this->candidate($zip);
            if ($version === null || !(new PaperPropertyPolicy())->supports($version)
                || !$this->index->contains($version, $sha256, $size)) {
                return null;
            }

            return ['version' => $version, 'identity' => $sha256];
        } finally {
            if ($opened) {
                $zip->close();
            }
            fclose($temporary);
        }
    }

    private function candidate(\ZipArchive $zip): ?string
    {
        if ($zip->locateName('version.json') !== false) {
            // A malformed modern manifest must not fall through to the legacy format.
            $body = $this->entry($zip, 'version.json');
            if ($body === null) {
                return null;
            }
            try {
                $metadata = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }

            return is_array($metadata) && is_string($metadata['id'] ?? null) && ($metadata['stable'] ?? false) === true ? $metadata['id'] : null;
        }
        // Legacy Paperclip has patch.properties instead of version.json. Read its version
        // ONLY; sourceUrl, hashes and all other instructions in this file are never used.
        $body = $this->entry($zip, 'patch.properties');
        if ($body === null) {
            return null;
        }
        try {
            $version = (new JavaPropertiesDocument($body, false))->values()['version'] ?? null;
        } catch (ConflictHttpException) {
            return null;
        }

        return is_string($version) && version_compare($version, '1.18', '<') ? $version : null;
    }

    private function entry(\ZipArchive $zip, string $name): ?string
    {
        $matches = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            if ($zip->getNameIndex($i) === $name) {
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
        $body = $zip->getFromIndex($matches[0], 16385);

        return is_string($body) && strlen($body) === $stat['size'] ? $body : null;
    }
}
