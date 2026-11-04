<?php

namespace Pterodactyl\Beacon\Minecraft;

use Illuminate\Validation\ValidationException;

class VelocityPropertyPolicy
{
    public function definitions(array $values): array
    {
        $definitions = [
            'motd' => ['type' => 'string', 'label' => 'MOTD', 'max_length' => 512, 'description' => 'Velocity MiniMessage text shown in the server list.'],
            'show-max-players' => ['type' => 'integer', 'label' => 'Displayed maximum players', 'min' => 1, 'max' => 1000000, 'description' => 'Server-list display only. This does not enforce a player limit.'],
            'announce-forge' => ['type' => 'boolean', 'label' => 'Announce Forge support'],
            'kick-existing-players' => ['type' => 'boolean', 'label' => 'Kick duplicate player sessions'],
            'ping-passthrough' => ['type' => 'select', 'label' => 'Ping passthrough', 'options' => ['disabled', 'mods', 'description', 'all']],
            'enable-player-address-logging' => ['type' => 'boolean', 'label' => 'Log player IP addresses'],
            'advanced.compression-threshold' => ['type' => 'integer', 'label' => 'Compression threshold (bytes)', 'min' => -1, 'max' => 1048576],
            'advanced.compression-level' => ['type' => 'integer', 'label' => 'Compression level', 'min' => -1, 'max' => 9],
            'advanced.login-ratelimit' => ['type' => 'integer', 'label' => 'Login rate limit (milliseconds)', 'min' => 0, 'max' => 3600000],
            'advanced.connection-timeout' => ['type' => 'integer', 'label' => 'Connection timeout (milliseconds)', 'min' => 1000, 'max' => 300000],
            'advanced.read-timeout' => ['type' => 'integer', 'label' => 'Read timeout (milliseconds)', 'min' => 1000, 'max' => 300000],
            'advanced.show-ping-requests' => ['type' => 'boolean', 'label' => 'Log ping requests'],
            'advanced.log-command-executions' => ['type' => 'boolean', 'label' => 'Log commands'],
            'advanced.log-player-connections' => ['type' => 'boolean', 'label' => 'Log player connections'],
        ];

        return array_filter($definitions, fn ($definition, $key) => array_key_exists($key, $values)
            && $this->valid($values[$key], $definition), ARRAY_FILTER_USE_BOTH);
    }

    public function validate(array $changes, array $definitions): array
    {
        if (!$changes || count($changes) > 32) {
            throw ValidationException::withMessages(['properties' => 'Choose between 1 and 32 supported settings.']);
        }
        foreach ($changes as $key => $value) {
            if (!isset($definitions[$key]) || !$this->valid($value, $definitions[$key], true)) {
                // Never reflect untrusted keys/values or a forwarding secret in errors.
                throw ValidationException::withMessages(['properties' => 'Choose valid values for existing editable Velocity settings only.']);
            }
            if ($definitions[$key]['type'] === 'integer') {
                $changes[$key] = (int) $value;
            }
        }

        return $changes;
    }

    private function valid(mixed $value, array $definition, bool $input = false): bool
    {
        return match ($definition['type']) {
            'boolean' => is_bool($value),
            'integer' => (is_int($value) || ($input && is_string($value) && preg_match('/^-?\d{1,9}$/D', $value)))
                && (int) $value >= $definition['min'] && (int) $value <= $definition['max'],
            'select' => is_string($value) && in_array($value, $definition['options'], true),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $definition['max_length']
                && !preg_match('/[\x00-\x1f\x7f]/', $value),
            default => false,
        };
    }
}
