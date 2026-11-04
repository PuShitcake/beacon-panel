<?php

namespace Pterodactyl\Beacon\Content\Providers;

/** Bukkit plugin catalog, separate from Forge mods and modpacks. */
class CurseForgePluginProvider extends CurseForgeModProvider
{
    protected const CLASS_ID = 5;
    protected const PROJECT_TYPE = 'plugin';
    protected const LOADERS = ['paper', 'spigot', 'bukkit'];
    protected const FILE_LOADER = null;
    protected const MOD_LOADER_TYPE = null;

    private bool $velocity = false;

    public function withVelocity(): self
    {
        $provider = clone $this;
        $provider->velocity = true;

        return $provider;
    }

    public function search(string $query, string $gameVersion, string $loader, string $projectType, int $offset = 0, ?string $category = null): array
    {
        if (!$this->velocity || $loader !== 'velocity' || $projectType !== 'plugin') {
            return parent::search($query, $gameVersion, $loader, $projectType, $offset, $category);
        }

        // This catalog has no reliable Velocity file facet. Search results are candidates,
        // not a platform certification. No JAR descriptor inspection is performed.
        return parent::search(trim($query) === '' ? 'velocity' : $query, '', 'paper', $projectType, $offset, $category);
    }

    public function versions(string $projectId, string $gameVersion, string $loader): array
    {
        if (!$this->velocity || $loader !== 'velocity') {
            return parent::versions($projectId, $gameVersion, $loader);
        }

        return array_map(function (array $version) {
            $version['id'] .= ':velocity';
            $version['loaders'] = ['velocity'];
            $version['compatibility'] = 'provider_metadata';

            return $version;
        }, parent::versions($projectId, '', 'paper'));
    }

    public function version(string $versionId): array
    {
        if (!$this->velocity) {
            return parent::version($versionId);
        }
        // Required dependencies are resolved through this request-local provider too.
        if (!str_ends_with($versionId, ':velocity')) {
            throw new \Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException('Select a Velocity file from the CurseForge version list.');
        }
        $raw = substr($versionId, 0, -9);
        $version = parent::version($raw);
        $version['id'] .= ':velocity';
        $version['loaders'] = ['velocity'];
        $version['compatibility'] = 'provider_metadata';

        return $version;
    }
}
