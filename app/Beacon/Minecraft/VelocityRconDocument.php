<?php

namespace Pterodactyl\Beacon\Minecraft;

use Symfony\Component\Yaml\Yaml;

/** Patch only Velocircon's top-level listener settings, retaining unrelated YAML bytes. */
class VelocityRconDocument
{
    public const LIMIT = 32768;

    private array $values;

    public function __construct(private string $content)
    {
        if (strlen($content) > self::LIMIT || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new \InvalidArgumentException('Unsupported Velocircon configuration input.');
        }
        try {
            $values = Yaml::parse($content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS, 12, 0);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Velocircon configuration must be unambiguous YAML.');
        }
        if (!is_array($values) || array_is_list($values)
            || !is_bool($values['enable'] ?? null)
            || !is_string($values['host'] ?? null)
            || !is_int($values['port'] ?? null) || $values['port'] < 1 || $values['port'] > 65535
            || !is_string($values['password'] ?? null) || strlen($values['password']) > 256
            || preg_match('/[\r\n\0]/', $values['password'])) {
            throw new \InvalidArgumentException('Velocircon listener settings have unsupported types.');
        }
        foreach (['enable', 'host', 'port', 'password'] as $key) {
            if (preg_match_all('/^' . $key . ':[ \t]*([^\r\n]*)\r?$/m', $content, $matches) !== 1) {
                throw new \InvalidArgumentException('Velocircon requires explicit top-level listener settings.');
            }
            // Anchors, aliases, flow maps and multiline scalars must not change other settings.
            try {
                $single = Yaml::parse($key . ': ' . rtrim($matches[1][0], "\r"), Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS, 12, 0);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('Velocircon requires independent single-line listener settings.');
            }
            if (($single[$key] ?? null) !== $values[$key]
                || preg_match('/^[ \t]*[&*!>|\[{]/', $matches[1][0])) {
                throw new \InvalidArgumentException('Velocircon requires independent single-line listener settings.');
            }
        }
        $this->values = $values;
    }

    public function properties(): array
    {
        return [
            'enable-rcon' => $this->values['enable'] ? 'true' : 'false',
            'rcon.port' => (string) $this->values['port'],
            'rcon.password' => $this->values['password'],
            'rcon.host' => $this->values['host'],
        ];
    }

    public function patch(array $values): string
    {
        if (array_diff(array_keys($values), ['enable-rcon', 'rcon.port', 'rcon.password']) !== []) {
            throw new \InvalidArgumentException('Velocity RCON cannot change Query or unrelated settings.');
        }
        $changes = [];
        foreach ($values as $key => $value) {
            $changes[match ($key) {
                'enable-rcon' => 'enable', 'rcon.port' => 'port', 'rcon.password' => 'password',
            }] = match ($key) {
                'enable-rcon' => $value,
                'rcon.port' => $value ?? 25575,
                'rcon.password' => $value ?? '',
            };
        }
        if (($values['enable-rcon'] ?? false) === true) {
            $changes['host'] = '0.0.0.0';
        }
        $result = preg_replace_callback('/^(enable|host|port|password):[^\r\n]*/m', function (array $match) use ($changes) {
            if (!array_key_exists($match[1], $changes)) {
                return $match[0];
            }

            return $match[1] . ': ' . json_encode($changes[$match[1]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }, $this->content);
        new self($result);

        return $result;
    }
}
