<?php

namespace Pterodactyl\Beacon\Addons;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Never extract provider paths. Only validated pack-relative files enter the clean archive. */
class AddonArchive
{
    public const MAX_FILES = 512;

    public function inspect(string $path, ?string $output = null): array
    {
        // Spool payloads to private disk, not PHP memory. Payload sizes have no product cap;
        // entry counts, paths, compression ratios and manifest sizes remain validated.
        $directory = sys_get_temp_dir() . '/beacon-addon-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Private add-on inspection storage is unavailable.');
        }
        try {
            return $this->inspectInto($path, $output, $directory);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function inspectInto(string $path, ?string $output, string $directory): array
    {
        $bytes = filesize($path);
        $this->require(is_int($bytes) && $bytes > 0, 'Choose a non-empty add-on archive.');
        $files = [];
        $total = 0;
        $this->readZip($path, '', 0, $files, $total, $directory);
        $roots = [];
        foreach ($files as $name => $body) {
            if (basename($name) === 'manifest.json') {
                $roots[] = dirname($name) === '.' ? '' : dirname($name) . '/';
            }
        }
        $this->require(count($roots) > 0 && count($roots) <= 8, 'Choose an archive containing 1 to 8 Bedrock behavior/resource packs.');
        $packs = [];
        $allIds = [];
        $clean = $output === null ? null : new \ZipArchive();
        if ($clean !== null && $clean->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not prepare the private add-on archive.');
        }
        try {
            foreach ($roots as $root) {
                $manifestFile = $files[$root . 'manifest.json'];
                $this->require($manifestFile['size'] <= 262144, 'The add-on manifest is too large.');
                $manifest = $this->json(file_get_contents($manifestFile['path']));
                $this->require(($manifest['format_version'] ?? null) === 2, 'Only Bedrock manifest format 2 is supported. Preview/world-template/skin packs are not installed.');
                $header = $manifest['header'] ?? [];
                $id = $this->uuid($header['uuid'] ?? null);
                $version = $this->version($header['version'] ?? null);
                $minimum = $this->version($header['min_engine_version'] ?? null);
                $name = $header['name'] ?? null;
                $this->require(is_string($name) && mb_check_encoding($name, 'UTF-8') && strlen($name) > 0 && strlen($name) <= 191 && !preg_match('/[\x00-\x1f\x7f]/', $name), 'The add-on pack name is invalid.');
                $modules = $manifest['modules'] ?? null;
                $this->require(is_array($modules) && array_is_list($modules) && count($modules) > 0 && count($modules) <= 16, 'The add-on module list is invalid.');
                $types = [];
                foreach ($modules as $module) {
                    $this->require(is_array($module), 'The add-on module is invalid.');
                    $moduleId = $this->uuid($module['uuid'] ?? null);
                    $this->require(!isset($allIds[$moduleId]) && $moduleId !== $id, 'The add-on contains duplicate pack/module UUIDs.');
                    $allIds[$moduleId] = true;
                    $this->version($module['version'] ?? null);
                    $type = $module['type'] ?? null;
                    $this->require(in_array($type, ['data', 'resources', 'script'], true), 'This add-on module type is not supported.');
                    if ($type === 'script') {
                        $this->require(($module['language'] ?? null) === 'javascript' && isset($files[$root . ($module['entry'] ?? '')]), 'The add-on script entry is missing or invalid.');
                    }
                    $types[] = $type;
                }
                $this->require(!isset($allIds[$id]) && !(in_array('resources', $types, true) && count(array_diff($types, ['resources'])) > 0), 'The add-on contains duplicate UUIDs or mixed resource/behavior modules.');
                $allIds[$id] = true;
                $directory = in_array('resources', $types, true) ? 'resource_packs' : 'behavior_packs';
                $dependencies = $manifest['dependencies'] ?? [];
                $this->require(is_array($dependencies) && array_is_list($dependencies) && count($dependencies) <= 32, 'The add-on dependencies are invalid.');
                $required = [];
                $scripts = in_array('script', $types, true);
                foreach ($dependencies as $dependency) {
                    $this->require(is_array($dependency), 'The add-on dependency is invalid.');
                    if (isset($dependency['module_name'])) {
                        $this->require(in_array($dependency['module_name'], ['@minecraft/server', '@minecraft/server-ui', '@minecraft/common'], true)
                            && is_string($dependency['version'] ?? null) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $dependency['version']), 'Beta, network, admin or unknown Script API dependencies are not installed automatically.');
                        $scripts = true;
                    } else {
                        $required[] = ['uuid' => $this->uuid($dependency['uuid'] ?? null), 'version' => $this->version($dependency['version'] ?? null)];
                    }
                }
                $pack = ['uuid' => $id, 'name' => $name, 'version' => $version, 'minimum_engine' => $minimum,
                    'directory' => $directory, 'folder' => 'beacon_' . str_replace('-', '', $id), 'dependencies' => $required, 'scripts' => $scripts, 'files' => []];
                foreach ($files as $file => $body) {
                    if (!str_starts_with($file, $root)) {
                        continue;
                    }
                    $relative = substr($file, strlen($root));
                    // Nested pack roots are ambiguous, never merge them into their parent.
                    foreach ($roots as $other) {
                        $this->require($other === $root || !str_starts_with($other, $root), 'Nested Bedrock pack roots are not supported.');
                    }
                    $this->require(!preg_match('/\.(?:exe|dll|so|jar|php|sh|bat|cmd|ps1|py|wasm)$/iD', $relative), 'Executable files outside the Bedrock Script API are not accepted.');
                    $pack['files'][$relative] = ['size' => $body['size'], 'sha256' => $body['sha256']];
                    if ($clean !== null) {
                        $this->require($clean->addFile($body['path'], $pack['folder'] . '/' . $relative), 'The private add-on archive could not be prepared.');
                    }
                }
                $packs[] = $pack;
            }
            foreach ($packs as $pack) {
                foreach ($pack['dependencies'] as $dependency) {
                    $this->require(collect($packs)->contains(fn ($p) => $p['uuid'] === $dependency['uuid'] && $p['version'] === $dependency['version']), 'A required pack is missing from this archive. Upload a complete .mcaddon bundle including its dependencies.');
                }
            }
            $visiting = [];
            $visited = [];
            $visit = function (string $id) use (&$visit, &$visiting, &$visited, $packs) {
                $this->require(!isset($visiting[$id]), 'The add-on contains a circular pack dependency.');
                if (isset($visited[$id])) {
                    return;
                }
                $visiting[$id] = true;
                $pack = collect($packs)->firstWhere('uuid', $id);
                foreach ($pack['dependencies'] as $dependency) {
                    $visit($dependency['uuid']);
                }
                unset($visiting[$id]);
                $visited[$id] = true;
            };
            foreach ($packs as $pack) {
                $visit($pack['uuid']);
            }
        } finally {
            if ($clean !== null && !$clean->close()) {
                throw new \RuntimeException('The private add-on archive could not be completed. Check available storage.');
            }
        }

        return ['packs' => $packs, 'total_bytes' => $total, 'file_count' => count($files)];
    }

    private function readZip(string $path, string $prefix, int $depth, array &$files, int &$total, string $directory): void
    {
        $zip = new \ZipArchive();
        $this->require($zip->open($path, \ZipArchive::RDONLY) === true, 'Choose a valid .mcpack, .mcaddon or ZIP archive.');
        try {
            $this->require($zip->numFiles > 0 && $zip->numFiles <= self::MAX_FILES, 'The add-on contains too many archive entries.');
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $this->require(AddonPath::archive($name), 'The add-on archive contains an unsafe path.');
                $key = strtolower(rtrim($name, '/'));
                $this->require(!isset($seen[$key]), 'The add-on archive contains duplicate paths.');
                $seen[$key] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                $mode = ($attributes >> 16) & 0170000;
                $this->require(in_array($mode, [0, 0100000, 0040000], true) && ($stat['encryption_method'] ?? 0) === 0, 'Encrypted files, symlinks and special archive entries are not supported.');
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $this->require(is_int($stat['size']) && $stat['size'] >= 0 && $stat['size'] <= PHP_INT_MAX - $total
                    && $stat['size'] <= max(1048576, $stat['comp_size'] * 200), 'The add-on exceeds safe compression limits.');
                if (basename($name) === 'manifest.json') {
                    $this->require($stat['size'] <= 262144, 'The add-on manifest is too large.');
                }
                $body = $this->spool($zip, $i, $stat['size'], $directory);
                $total += $body['size'];
                if (preg_match('/\.(?:mcpack|mcaddon|zip)$/iD', $name)) {
                    $this->require($depth === 0, 'Only one level of bundled .mcpack archives is supported.');
                    try {
                        $this->readZip($body['path'], 'bundle' . $i . '/', 1, $files, $total, $directory);
                    } finally {
                        unlink($body['path']);
                    }
                } else {
                    $this->require(!isset($files[$prefix . $name]) && count($files) < self::MAX_FILES, 'The add-on contains duplicate files or too many files.');
                    $files[$prefix . $name] = $body;
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function spool(\ZipArchive $zip, int $index, int $expected, string $directory): array
    {
        $free = disk_free_space($directory);
        $this->require($free !== false && $free > $expected, 'Not enough temporary disk space to inspect this add-on.');
        $path = $directory . '/' . bin2hex(random_bytes(16));
        $input = $zip->getStreamIndex($index);
        $output = fopen($path, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new \RuntimeException('The add-on entry could not be opened.');
        }
        chmod($path, 0600);
        $hash = hash_init('sha256');
        $crc = hash_init('crc32b');
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                $this->require(is_string($chunk) && ($chunk !== '' || feof($input)), 'The add-on archive entry could not be read.');
                $this->require(strlen($chunk) <= $expected - $size, 'The add-on entry exceeds its declared size.');
                $this->require(fwrite($output, $chunk) === strlen($chunk), 'Not enough temporary disk space to inspect this add-on.');
                $size += strlen($chunk);
                hash_update($hash, $chunk);
                hash_update($crc, $chunk);
            }
            $this->require($size === $expected && hash_final($crc) === sprintf('%08x', $zip->statIndex($index)['crc']), 'The add-on archive entry failed size or CRC verification.');
        } finally {
            fclose($input);
            fclose($output);
        }

        return ['path' => $path, 'size' => $size, 'sha256' => hash_final($hash)];
    }

    public function json(string $body): array
    {
        $this->require(strlen($body) <= 262144, 'The add-on manifest is too large.');
        try {
            $data = json_decode(ltrim($body, "\xef\xbb\xbf"), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConflictHttpException('The add-on manifest is not valid JSON.');
        }
        $this->require(is_array($data), 'The add-on manifest must be a JSON object.');

        return $data;
    }

    public function uuid(mixed $value): string
    {
        $this->require(is_string($value) && Uuid::isValid($value), 'The add-on contains an invalid UUID.');

        return strtolower($value);
    }

    public function version(mixed $value): array
    {
        $this->require(is_array($value) && array_is_list($value) && count($value) === 3
            && count(array_filter($value, fn ($n) => is_int($n) && $n >= 0 && $n <= 1000000)) === 3, 'The add-on contains an invalid version.');

        return $value;
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new ConflictHttpException($message);
        }
    }
}
