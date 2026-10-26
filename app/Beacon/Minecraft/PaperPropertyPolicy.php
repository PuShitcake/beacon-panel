<?php

namespace Pterodactyl\Beacon\Minecraft;

/** Reuses Java field safety, not Vanilla defaults or a generated properties template. */
class PaperPropertyPolicy extends VanillaPropertyPolicy
{
    protected const RUNTIME = 'Paper';

    public function definitions(string $version, array $values): array
    {
        $definitions = parent::definitions($version, $values);
        if (isset($definitions['level-name'])) {
            $definitions['level-name']['description'] .= ' Keep the matching Nether and End folders when uploading a Paper map; Beacon never converts dimensions.';
        }

        return $definitions;
    }

    public function supports(string $version): bool
    {
        // Runtime encoding and basic Java property boundaries are reviewed for these releases.
        // Only a matching official Paper build checksum can authorize this policy at runtime.
        return parent::supports($version) && version_compare($version, '1.16.5', '>=');
    }
}
