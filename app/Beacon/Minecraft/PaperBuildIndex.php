<?php

namespace Pterodactyl\Beacon\Minecraft;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Official, bounded checksum metadata only. Never downloads or executes a server artifact. */
class PaperBuildIndex
{
    public function contains(string $version, string $sha256, int $size): bool
    {
        if (!(new PaperPropertyPolicy())->supports($version) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            return false;
        }
        $key = 'beacon:paper-build-checksums:v1:' . $version;
        $index = Cache::get($key);
        if (!is_array($index)) {
            $index = $this->fetch($version);
            Cache::put($key, $index, 300);
        }

        return ($index[$sha256] ?? null) === $size;
    }

    private function fetch(string $version): array
    {
        $body = null;
        try {
            $response = Http::acceptJson()->withUserAgent('BeaconPanel/1.0 (https://github.com/PuShitcake/beacon-panel)')
                ->connectTimeout(5)->timeout(20)->withOptions(['stream' => true, 'allow_redirects' => false, 'decode_content' => false])
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->get('https://fill.papermc.io/v3/projects/paper/versions/' . $version . '/builds');
            $body = $response->toPsrResponse()->getBody();
            if ($response->status() !== 200) {
                throw new \RuntimeException('Metadata unavailable.');
            }
            $json = '';
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                $json .= $chunk;
                if (strlen($json) > 8388608 || ($chunk === '' && !$body->eof())) {
                    throw new \RuntimeException('Metadata limit.');
                }
            }
            $builds = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($builds) || !array_is_list($builds) || count($builds) > 10000) {
                throw new \RuntimeException('Invalid metadata.');
            }
            $index = [];
            foreach ($builds as $build) {
                $file = $build['downloads']['server:default'] ?? null;
                $hash = $file['checksums']['sha256'] ?? null;
                $size = $file['size'] ?? null;
                if (!is_array($file) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || !is_int($size) || $size < 1 || $size > VanillaJarVersionReader::MAX_BYTES) {
                    throw new \RuntimeException('Invalid checksum metadata.');
                }
                if (isset($index[$hash]) && $index[$hash] !== $size) {
                    throw new \RuntimeException('Conflicting checksum metadata.');
                }
                $index[$hash] = $size;
            }

            return $index;
        } catch (\Throwable) {
            throw new ConflictHttpException('Official Paper build verification is temporarily unavailable. No settings were changed. Refresh Configuration later.');
        } finally {
            $body?->close();
        }
    }
}
