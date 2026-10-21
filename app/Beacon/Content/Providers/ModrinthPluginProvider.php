<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class ModrinthPluginProvider extends ModrinthProvider
{
    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        return array_map(fn (array $version) => $this->plugin($version), parent::versions($projectId, $gameVersion, $loader));
    }

    public function version(string $versionId): array
    {
        return $this->plugin(parent::version($versionId));
    }

    private function plugin(array $version): array
    {
        // Modrinth's global project_type can be mod for projects that also publish plugins.
        // Only the exact release's server loader contract can make it a plugin here.
        if (!array_intersect($version['loaders'], ['paper', 'spigot', 'bukkit'])) {
            throw new ProviderResponseException('This Modrinth file is not a supported server plugin.');
        }
        $version['project_type'] = 'plugin';

        return $version;
    }
}
