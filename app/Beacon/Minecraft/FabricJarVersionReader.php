<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Reads the manual installer's launch contract, then hashes the real Mojang server JAR. */
class FabricJarVersionReader extends VanillaJarVersionReader
{
    public function __construct(private VanillaFileReader $files, private DaemonFileRepository $repository)
    {
        parent::__construct($files);
    }

    public function read(Server $server, string $path, int $expectedSize): ?string
    {
        return $this->inspect($server, $path, $expectedSize)['version'] ?? null;
    }

    public function inspect(Server $server, string $path, int $expectedSize): ?array
    {
        // Known launch overrides can change the game, working directory or main entrypoint.
        // Do not execute/expand shell commands, JVM argfiles or manifest-provided URLs.
        $startup = $server->startup ?? '';
        if ($expectedSize < 1 || $expectedSize > 1048576 || !str_starts_with($startup, 'java ')
            || preg_match_all('/(?:^|\s)-jar(?:\s|$)/', $startup) !== 1
            || preg_match('/[;&|`@$\x22\x27\\\\\r\n]|-D(?:fabric\.|user\.dir|java\.class\.path)|(?:^|\s)-(?:cp|classpath|javaagent|agentlib)(?:[:=\s]|$)/i', $startup)
            || !preg_match('/\s-jar\s+(?:\{\{SERVER_JARFILE\}\}|' . preg_quote(ltrim($path, '/'), '/') . ')(?:\s+nogui)?\s*$/D', $startup)) {
            return null;
        }
        $root = $this->repository->setServer($server)->getDirectory('/');
        $properties = $this->entry($root, 'fabric-server-launcher.properties');
        if (!$this->regular($properties, 16384)) {
            return null;
        }
        $raw = $this->files->read($server, '/fabric-server-launcher.properties', 16384);
        if (strlen($raw) !== $properties['size']) {
            throw new ConflictHttpException('The Fabric launch properties changed during verification. Reload Configuration.');
        }
        try {
            $values = (new JavaPropertiesDocument($raw, true))->values();
        } catch (ConflictHttpException) {
            return null;
        }
        $gameJar = $values['serverJar'] ?? null;
        if (!is_string($gameJar) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.jar$/D', $gameJar) || '/' . $gameJar === $path) {
            return null;
        }
        $game = $this->entry($root, $gameJar);
        if (!$this->regular($game, self::MAX_BYTES)) {
            return null;
        }
        $launcher = $this->files->read($server, $path, 1048576);
        if (strlen($launcher) !== $expectedSize) {
            throw new ConflictHttpException('The Fabric launcher changed during verification. Reload Configuration.');
        }
        $libraries = $this->launcherLibraries($launcher);
        if ($libraries === null) {
            return null;
        }
        // Every segment must be a regular directory. In particular, libraries itself must
        // not be a symlink before Wings is asked to inspect any nested library path.
        $directories = ['/' => $root];
        $libraryEntries = [];
        foreach ($libraries as $library) {
            $segments = explode('/', $library);
            $directory = '/';
            foreach ($segments as $i => $segment) {
                if (!isset($directories[$directory])) {
                    if (count($directories) >= 64) {
                        return null;
                    }
                    $directories[$directory] = $this->repository->setServer($server)->getDirectory($directory);
                }
                $entries = $directories[$directory];
                $entry = $this->entry($entries, $segment);
                if ($i === count($segments) - 1) {
                    if (!$this->regular($entry, self::MAX_BYTES)) {
                        return null;
                    }
                    $libraryEntries[$library] = $entry;
                } else {
                    if (($entry['file'] ?? true) !== false || ($entry['symlink'] ?? null) !== false) {
                        return null;
                    }
                    $directory = rtrim($directory, '/') . '/' . $segment;
                }
            }
        }
        $version = parent::read($server, '/' . $gameJar, $game['size']);
        if ($version === null || !(new FabricPropertyPolicy())->supports($version)) {
            return null;
        }
        foreach ($libraries as $library) {
            if (str_starts_with($library, 'libraries/net/fabricmc/intermediary/') && $library !== 'libraries/net/fabricmc/intermediary/' . $version . '/intermediary-' . $version . '.jar') {
                return null;
            }
        }
        $after = $this->repository->setServer($server)->getDirectory('/');
        if ($this->entry($after, $gameJar) !== $game || $this->entry($after, 'fabric-server-launcher.properties') !== $properties
            || $this->files->read($server, '/fabric-server-launcher.properties', 16384) !== $raw) {
            throw new ConflictHttpException('The Fabric game JAR or launch properties changed during verification. Reload Configuration.');
        }

        return ['version' => $version, 'identity' => hash('sha256', json_encode([$version, hash('sha256', $launcher), hash('sha256', $raw), $gameJar, $game, $libraryEntries, $startup], JSON_THROW_ON_ERROR))];
    }

    private function launcherLibraries(string $body): ?array
    {
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new ConflictHttpException('Fabric verification could not create a private temporary file. No settings were changed.');
        }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if (fwrite($temporary, $body) !== strlen($body) || !fflush($temporary)) {
                throw new ConflictHttpException('The Fabric launcher could not be inspected safely. No settings were changed.');
            }
            $opened = @$zip->open(stream_get_meta_data($temporary)['uri'], \ZipArchive::RDONLY) === true;
            if (!$opened || $zip->numFiles !== 2) {
                return null;
            }
            $contents = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if (!in_array($name, ['META-INF/MANIFEST.MF', 'fabric-server-launch.properties'], true) || isset($contents[$name])
                    || $stat['size'] < 1 || $stat['size'] > 16384 || ($stat['encryption_method'] ?? 0) !== 0) {
                    return null;
                }
                $entry = $zip->getFromIndex($i, 16385);
                if (!is_string($entry) || strlen($entry) !== $stat['size']) {
                    return null;
                }
                $contents[$name] = $entry;
            }
            $manifest = $contents['META-INF/MANIFEST.MF'] ?? '';
            $manifest = preg_replace('/\r?\n /', '', $manifest);
            $attributes = [];
            foreach (preg_split('/\r\n|\n|\r/', trim($manifest)) as $line) {
                if (!preg_match('/^(Manifest-Version|Main-Class|Class-Path): (.+)$/iD', $line, $match) || isset($attributes[strtolower($match[1])])) {
                    return null;
                }
                $attributes[strtolower($match[1])] = $match[2];
            }
            if (($attributes['manifest-version'] ?? null) !== '1.0' || !in_array($attributes['main-class'] ?? '', ['net.fabricmc.loader.impl.launch.server.FabricServerLauncher', 'net.fabricmc.loader.launch.server.FabricServerLauncher'], true)) {
                return null;
            }
            try {
                $embedded = (new JavaPropertiesDocument($contents['fabric-server-launch.properties'] ?? '', true))->values();
            } catch (ConflictHttpException) {
                return null;
            }
            if (count($embedded) !== 1 || !in_array($embedded['launch.mainClass'] ?? '', ['net.fabricmc.loader.impl.launch.knot.KnotServer', 'net.fabricmc.loader.launch.knot.KnotServer'], true)) {
                return null;
            }
            $classpath = $attributes['class-path'] ?? '';
            $libraries = explode(' ', $classpath);
            if (strlen($classpath) > 8192 || count($libraries) > 32 || count(array_unique($libraries)) !== count($libraries)) {
                return null;
            }
            $loaders = 0;
            foreach ($libraries as $library) {
                // Maven build metadata uses literal '+', e.g. sponge-mixin versions.
                // It is not form decoding: percent escapes, URLs and dot traversal stay out.
                if (!preg_match('~^libraries/(?:[A-Za-z0-9_-][A-Za-z0-9._+-]*/){1,12}[A-Za-z0-9_-][A-Za-z0-9._+-]*\.jar$~D', $library)) {
                    return null;
                }
                if (preg_match('~^libraries/net/fabricmc/fabric-loader/(0\.[0-9]+\.[0-9]+)/fabric-loader-\1\.jar$~D', $library, $match)) {
                    if (version_compare($match[1], '0.12.5', '<=')) {
                        return null;
                    }
                    ++$loaders;
                }
            }

            return $loaders === 1 ? $libraries : null;
        } finally {
            if ($opened) {
                $zip->close();
            }
            fclose($temporary);
        }
    }

    private function entry(array $entries, string $name): ?array
    {
        return collect($entries)->first(fn ($entry) => is_array($entry) && ($entry['name'] ?? null) === $name);
    }

    private function regular(?array $entry, int $limit): bool
    {
        return ($entry['file'] ?? false) === true && ($entry['symlink'] ?? null) === false && is_int($entry['size'] ?? null) && $entry['size'] > 0 && $entry['size'] <= $limit;
    }
}
