<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class ModrinthPluginProvider extends ModrinthProvider
{
    private bool $velocity = false;

    public function withVelocity(): self
    {
        $provider = clone $this;
        $provider->velocity = true;

        return $provider;
    }

    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        $versions = array_values(array_filter(
            array_map(fn (array $version) => $this->plugin($version), parent::versions($projectId, $gameVersion, $loader)),
            fn (array $version) => in_array($loader, $version['loaders'], true)
        ));

        return $loader === 'velocity' ? array_map(fn (array $version) => [...$version, 'compatibility' => 'provider_metadata'], $versions) : $versions;
    }

    public function version(string $versionId): array
    {
        $version = $this->plugin(parent::version($versionId));
        if ($this->velocity) {
            if (!in_array('velocity', $version['loaders'], true)) {
                throw new ProviderResponseException('The selected Modrinth file is not a Velocity plugin.');
            }
            $version['compatibility'] = 'provider_metadata';
        }

        return $version;
    }

    protected function gameVersionQuery(string $gameVersion, string $loader): array
    {
        return $loader === 'velocity' ? [] : parent::gameVersionQuery($gameVersion, $loader);
    }

    private function plugin(array $version): array
    {
        // Modrinth's global project_type can be mod for projects that also publish plugins.
        // Only the exact release's server loader contract can make it a plugin here.
        if (!array_intersect($version['loaders'], ['paper', 'spigot', 'bukkit', 'velocity'])) {
            throw new ProviderResponseException('This Modrinth file is not a supported server plugin.');
        }
        $version['project_type'] = 'plugin';

        return $version;
    }
}
