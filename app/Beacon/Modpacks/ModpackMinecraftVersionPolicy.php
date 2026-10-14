<?php

namespace Pterodactyl\Beacon\Modpacks;

use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class ModpackMinecraftVersionPolicy
{
    public function supports(string $version): bool
    {
        if (!preg_match('/^(1|26)\.(\d{1,2})(?:\.(\d{1,2}))?$/D', $version, $parts)) {
            return false;
        }

        return $parts[1] === '26' ? (int) $parts[2] >= 1 : (int) $parts[2] <= 21;
    }

    public function javaVersion(string $version): int
    {
        if (!$this->supports($version)) {
            throw new ModpackProviderException('The selected Minecraft version has no trusted Java mapping.');
        }
        $parts = array_map('intval', explode('.', $version));
        if ($parts[0] === 26) {
            // Minecraft 26.1 introduced the Java 25 requirement and year-based release names.
            return 25;
        }
        $minor = $parts[1];
        $patch = $parts[2] ?? 0;
        if ($minor > 20 || ($minor === 20 && $patch >= 5)) {
            return 21;
        }
        if ($minor >= 18) {
            return 17;
        }

        return $minor === 17 ? 16 : 8;
    }
}
