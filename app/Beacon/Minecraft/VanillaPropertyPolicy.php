<?php

namespace Pterodactyl\Beacon\Minecraft;

use Illuminate\Validation\ValidationException;

/** Field types and safety limits only; the installed file supplies keys and values. */
class VanillaPropertyPolicy
{
    protected const RUNTIME = 'Vanilla';
    // Semantic boundaries prevent stale keys from pretending to be supported settings.
    // These are not release templates and never supply missing fields or default values.
    private const INTRODUCED = [
        'hardcore' => '1.3.1',
        'spawn-protection' => '1.4.2',
        'force-gamemode' => '1.5.2',
        'player-idle-timeout' => '1.6.4',
        'enforce-whitelist' => '1.13',
        'enable-status' => '1.16',
        'simulation-distance' => '1.18',
        'resource-pack' => '1.6.1',
        'resource-pack-sha1' => '1.8',
        'require-resource-pack' => '1.17',
    ];

    public function definitions(string $version, array $values): array
    {
        if (!$this->supports($version)) {
            return [];
        }
        $definitions = ServerPropertiesService::DEFINITIONS + [
            'allow-nether' => ['type' => 'boolean', 'label' => 'Allow Nether'],
            'spawn-monsters' => ['type' => 'boolean', 'label' => 'Spawn monsters'],
            'enforce-whitelist' => ['type' => 'boolean', 'label' => 'Enforce whitelist'],
            'force-gamemode' => ['type' => 'boolean', 'label' => 'Force default game mode'],
            'player-idle-timeout' => ['type' => 'integer', 'label' => 'Idle kick timeout (minutes; 0 disables)', 'min' => 0, 'max' => 10080],
            'enable-status' => ['type' => 'boolean', 'label' => 'Enable server list status'],
            'level-name' => ['type' => 'string', 'format' => 'world-folder', 'max_length' => 64, 'label' => 'World folder name', 'world_setting' => true,
                'description' => 'Exact root folder name of an uploaded map, or a new unused name. Use letters, numbers, - or _ (max 64). Existing folders are never renamed or deleted.'],
            'level-seed' => ['type' => 'string', 'max_length' => 128, 'label' => 'Seed for a new world', 'world_setting' => true,
                'description' => 'For a new unused world folder only. Leave empty for a random seed. This never regenerates an existing map.'],
            'level-type' => ['type' => 'select', 'label' => 'Generator for a new world', 'world_setting' => true,
                'description' => 'Applies to newly generated worlds, not terrain already saved in an existing map.'],
            'resource-pack' => ['type' => 'string', 'format' => 'resource-url', 'max_length' => 2048, 'label' => 'Resource pack URL',
                'description' => 'Public HTTPS download link, sent to players. Never include private tokens. Changing it clears SHA-1 in this form: enter the new hash before saving. Beacon does not fetch this URL.'],
            'resource-pack-sha1' => ['type' => 'string', 'format' => 'sha1', 'max_length' => 40, 'label' => 'Resource pack SHA-1',
                'description' => '40 hexadecimal characters for the exact pack, or empty to clear it.'],
            'require-resource-pack' => ['type' => 'boolean', 'label' => 'Require resource pack',
                'description' => 'Players who decline a required pack cannot join.'],
        ];
        $generators = ['default' => 'Normal', 'flat' => 'Superflat'];
        if (version_compare($version, '1.3.1', '>=')) {
            $generators['largebiomes'] = 'Large biomes';
        }
        if (version_compare($version, '1.7.2', '>=')) {
            $generators['amplified'] = 'Amplified';
        }
        if (version_compare($version, '1.19', '>=')) {
            $generators = ['minecraft:normal' => 'Normal', 'minecraft:flat' => 'Superflat', 'minecraft:large_biomes' => 'Large biomes', 'minecraft:amplified' => 'Amplified'];
        }
        $definitions['level-type']['options'] = array_keys($generators);
        $definitions['level-type']['option_labels'] = $generators;
        // Conservative Vanilla bounds, rather than Paper's more permissive distance policy.
        $definitions['view-distance']['min'] = 3;
        $definitions['view-distance']['max'] = version_compare($version, '1.7.3', '<') ? 15
            : (version_compare($version, '1.8', '<') ? 20 : 32);
        $definitions['simulation-distance']['min'] = 3;
        if (version_compare($version, '1.14', '<')) {
            foreach (['gamemode', 'difficulty'] as $key) {
                $labels = $definitions[$key]['options'];
                $definitions[$key]['options'] = ['0', '1', '2', '3'];
                $definitions[$key]['option_labels'] = array_combine(['0', '1', '2', '3'], $labels);
            }
            if (version_compare($version, '1.8', '<')) {
                array_pop($definitions['gamemode']['options']);
                unset($definitions['gamemode']['option_labels']['3']);
            }
            if (version_compare($version, '1.3.1', '<')) {
                $definitions['gamemode']['options'] = ['0', '1'];
                unset($definitions['gamemode']['option_labels']['2']);
            }
        }

        $result = [];
        foreach ($values as $key => $_value) {
            if (!isset($definitions[$key])) {
                continue;
            }
            if (isset(self::INTRODUCED[$key]) && version_compare($version, self::INTRODUCED[$key], '<')) {
                continue;
            }
            if (in_array($key, ['pvp', 'allow-nether', 'spawn-monsters'], true) && version_compare($version, '1.21.9', '>=')) {
                continue;
            }
            $result[$key] = $definitions[$key];
        }

        return $result;
    }

    public function supports(string $version): bool
    {
        static $index;
        $index ??= json_decode(file_get_contents(__DIR__ . '/Vanilla/releases.json'), true, 512, JSON_THROW_ON_ERROR);

        return isset($index['releases'][$version]);
    }

    public function present(array $values, array $definitions): array
    {
        $result = [];
        foreach ($definitions as $key => $definition) {
            $value = $values[$key];
            $trimmed = trim($value);
            if ($key === 'level-type') {
                // Legacy Vanilla compares these built-in names case-insensitively.
                $value = $trimmed = strtolower($value);
            }
            $result[$key] = match ($definition['type']) {
                'boolean' => match ($trimmed) {
                    'true' => true, 'false' => false, default => $value,
                },
                'integer' => preg_match('/^-?[0-9]{1,9}$/D', $trimmed) ? (int) $trimmed : $value,
                default => $value,
            };
            if ($definition['type'] === 'select' && !isset($definition['option_labels']) && in_array($trimmed, ['0', '1', '2', '3'], true)) {
                $result[$key] = $definition['options'][(int) $trimmed];
            }
        }

        return $result;
    }

    public function validate(array $changes, array $definitions): array
    {
        $result = [];
        foreach ($changes as $key => $value) {
            $definition = $definitions[$key] ?? null;
            $valid = $definition !== null;
            if ($valid) {
                $valid = match ($definition['type']) {
                    'boolean' => is_bool($value),
                    'integer' => (is_int($value) || (is_string($value) && preg_match('/^-?[0-9]{1,9}$/D', $value)))
                        && (int) $value >= $definition['min'] && (int) $value <= $definition['max'],
                    'select' => is_string($value) && in_array($value, $definition['options'], true),
                    'string' => is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= ($definition['max_length'] ?? 120)
                        && !preg_match('/[\x00-\x1f\x7f]/', $value) && $this->validFormat($value, $definition['format'] ?? null),
                    default => false,
                };
            }
            if (!$valid) {
                // Never reflect attacker-controlled keys or raw file values in public errors.
                throw ValidationException::withMessages(['properties' => 'Choose a valid value for an existing, supported ' . static::RUNTIME . ' setting.']);
            }
            $result[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $result;
    }

    private function validFormat(string $value, ?string $format): bool
    {
        return match ($format) {
            'world-folder' => preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value) === 1
                && !in_array(strtolower($value), ['con', 'prn', 'aux', 'nul', 'com1', 'lpt1', 'libraries', 'versions', 'mods', 'plugins', 'logs', 'config', 'backups', 'resourcepacks', 'datapacks', 'cache'], true),
            'sha1' => $value === '' || preg_match('/^[a-fA-F0-9]{40}$/D', $value) === 1,
            'resource-url' => $value === '' || $this->validResourceUrl($value),
            default => true,
        };
    }

    private function validResourceUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL) || preg_match('/[\s\\\\]/', $value)) {
            return false;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');

        return ($url['scheme'] ?? '') === 'https' && !isset($url['user']) && !isset($url['pass']) && !isset($url['fragment'])
            && (!isset($url['port']) || $url['port'] === 443) && str_contains($host, '.')
            && preg_match('/\.[a-z][a-z0-9-]*$/D', $host)
            && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            && !preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test)$/D', $host);
    }
}
