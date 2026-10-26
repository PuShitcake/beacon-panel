<?php

namespace Pterodactyl\Beacon\Minecraft;

/** Reviewed BDS field types only. Never use Java version profiles or insert defaults.
 * Reference: https://learn.microsoft.com/minecraft/creator/documents/bedrockserver/server-properties.
 */
class BedrockPropertyPolicy extends VanillaPropertyPolicy
{
    protected const RUNTIME = 'Bedrock';

    public function supports(string $version): bool
    {
        return $version === 'bedrock-file';
    }

    public function definitions(string $version, array $values): array
    {
        if (!$this->supports($version)) {
            return [];
        }
        $definitions = [
            'server-name' => ['type' => 'string', 'max_length' => 120, 'format' => 'bedrock-name', 'label' => 'Server name (Bedrock MOTD)',
                'description' => 'Use ASCII text without quotes, backslashes, surrounding spaces or ${...} placeholders. The unchanged Egg rewrites these values at startup.'],
            'gamemode' => ['type' => 'select', 'options' => ['survival', 'creative', 'adventure'], 'label' => 'Default game mode'],
            'difficulty' => ['type' => 'select', 'options' => ['peaceful', 'easy', 'normal', 'hard'], 'label' => 'Difficulty'],
            'force-gamemode' => ['type' => 'boolean', 'label' => 'Force default game mode'],
            'allow-cheats' => ['type' => 'boolean', 'label' => 'Allow cheats'],
            'max-players' => ['type' => 'integer', 'min' => 1, 'max' => 1000, 'label' => 'Maximum players'],
            'online-mode' => ['type' => 'boolean', 'label' => 'Xbox Live authentication'],
            'allow-list' => ['type' => 'boolean', 'label' => 'Require allowlist'],
            'view-distance' => ['type' => 'integer', 'min' => 5, 'max' => 96, 'label' => 'View distance', 'description' => 'Beacon safety limit: 5 to 96 chunks. Larger values can significantly increase resource use.'],
            'tick-distance' => ['type' => 'integer', 'min' => 4, 'max' => 12, 'label' => 'Tick distance'],
            'player-idle-timeout' => ['type' => 'integer', 'min' => 0, 'max' => 10080, 'label' => 'Idle kick timeout (minutes; 0 disables)'],
            'texturepack-required' => ['type' => 'boolean', 'label' => 'Require Bedrock world resource packs', 'description' => 'Uses Bedrock packs already configured in the selected world. Java resource-pack URLs are not supported.'],
            'level-name' => ['type' => 'string', 'max_length' => 64, 'format' => 'bedrock-world-folder', 'label' => 'World folder name', 'world_setting' => true,
                'description' => 'Exact folder inside worlds/, including spaces (max 64). Select an extracted Bedrock map or an unused name. No folder is moved, converted or deleted.'],
            'level-seed' => ['type' => 'string', 'format' => 'bedrock-text', 'max_length' => 128, 'label' => 'Seed for a new world', 'world_setting' => true,
                'description' => 'ASCII text or a numeric seed, without quotes, backslashes, surrounding spaces or ${...}. Requires an unused folder inside worlds/. Empty selects a random seed; existing maps are never regenerated.'],
        ];

        return array_intersect_key($definitions, $values);
    }

    public function present(array $values, array $definitions): array
    {
        $result = [];
        foreach ($definitions as $key => $definition) {
            $value = $values[$key];
            $result[$key] = match ($definition['type']) {
                'boolean' => match (trim($value)) {
                    'true' => true, 'false' => false, default => $value,
                },
                'integer' => preg_match('/^-?[0-9]{1,9}$/D', trim($value)) ? (int) trim($value) : $value,
                default => $value,
            };
        }

        return $result;
    }

    public function validate(array $changes, array $definitions): array
    {
        $result = parent::validate($changes, $definitions);
        foreach ($result as $key => $value) {
            if ((in_array($key, ['server-name', 'level-seed'], true) && !self::safeText($value))
                || ($key === 'server-name' && str_contains($value, ';'))
                || ($key === 'level-name' && !self::safeWorldName($value))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['properties' => 'Use safe ASCII text for Bedrock names and seeds. Quotes, backslashes, surrounding spaces and placeholders are not supported by this Egg.']);
            }
        }

        return $result;
    }

    public static function safeWorldName(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 _-]{0,62}[A-Za-z0-9_-])?$/D', $value) === 1
            && !preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/iD', $value);
    }

    public static function safeText(string $value): bool
    {
        return preg_match('/^[\x20-\x7e]*$/D', $value) === 1 && trim($value) === $value
            && !str_contains($value, '"') && !str_contains($value, '\\') && !str_contains($value, '${');
    }
}
