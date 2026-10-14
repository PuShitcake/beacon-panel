<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class ModpackJvmMemoryService
{
    private const MAX_FILE_BYTES = 65536;

    public function startup(string $startup, int $memory): string
    {
        if ($memory <= 0) {
            return $startup;
        }

        // Keep the allocation placeholder dynamic when an administrator resizes the instance.
        return str_replace('-Xmx{{SERVER_MEMORY}}M', '-Xmx$(({{SERVER_MEMORY}} * 90 / 100))M', $startup);
    }

    public function normalize(string $content, int $memory): string
    {
        if ($memory <= 0) {
            return $content;
        }

        $maximum = max(1, intdiv($memory * 9, 10));
        $initial = min(128, $maximum);
        $seen = ['s' => false, 'x' => false];
        // Treat quoted arguments and comments as complete tokens so other JVM settings survive.
        $pattern = <<<'REGEX'
~#[^\r\n]*|(?:[^\s#"'\\]+|\\.|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')+~
REGEX;
        $updated = preg_replace_callback($pattern, function (array $match) use ($maximum, $initial, &$seen): string {
            $token = $match[0];
            $quote = '';
            if (strlen($token) >= 2 && in_array($token[0], ['"', "'"], true) && $token[-1] === $token[0]) {
                $quote = $token[0];
                $token = substr($token, 1, -1);
            }
            if (!preg_match('/^-Xm([sx])\d+[kKmMgG]?$/D', $token, $heap)) {
                return $match[0];
            }
            $seen[$heap[1]] = true;

            return $quote . '-Xm' . $heap[1] . ($heap[1] === 's' ? $initial : $maximum) . 'M' . $quote;
        }, $content);
        if (!is_string($updated)) {
            throw new \RuntimeException('The modpack JVM arguments could not be normalized.');
        }
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        foreach (['s' => $initial, 'x' => $maximum] as $flag => $value) {
            if (!$seen[$flag]) {
                $updated = rtrim($updated, "\r\n") . $newline . "-Xm{$flag}{$value}M" . $newline;
            }
        }

        return $updated;
    }

    public function apply(Server $server, DaemonFileRepository $files, DaemonServerRepository $servers): void
    {
        if ($server->memory <= 0) {
            return;
        }
        $files->setServer($server);
        $entry = collect($files->getDirectory('/'))->first(fn (array $entry) => ($entry['name'] ?? null) === 'user_jvm_args.txt');
        if ($entry === null) {
            return;
        }
        if (($entry['file'] ?? false) !== true || ($entry['symlink'] ?? false) !== false
            || !is_numeric($entry['size'] ?? null) || $entry['size'] > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('The modpack JVM arguments must be a bounded regular file.');
        }
        if (($servers->setServer($server)->getDetails()['state'] ?? null) !== 'offline') {
            throw new \RuntimeException('Stop the server before applying modpack JVM memory settings.');
        }
        $original = $files->getContent('/user_jvm_args.txt', self::MAX_FILE_BYTES);
        if (strlen($original) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('The modpack JVM arguments exceed the file size limit.');
        }
        $updated = $this->normalize($original, $server->memory);
        if ($updated === $original) {
            return;
        }
        $files->putContent('/user_jvm_args.txt', $updated);
        if ($files->getContent('/user_jvm_args.txt', self::MAX_FILE_BYTES) !== $updated) {
            throw new \RuntimeException('The modpack JVM memory settings could not be verified.');
        }
    }
}
