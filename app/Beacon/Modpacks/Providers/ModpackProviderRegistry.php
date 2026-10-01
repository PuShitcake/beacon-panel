<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

use Illuminate\Support\Collection;
use Pterodactyl\Beacon\Modpacks\Exceptions\ModpackProviderException;

class ModpackProviderRegistry
{
    /** @param iterable<ModpackProvider> $providers */
    public function __construct(private iterable $providers)
    {
    }

    public function get(string $key): ModpackProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                if (!$provider->isConfigured()) {
                    throw new ModpackProviderException($provider->unavailableReason() ?? 'This provider is unavailable.');
                }

                return $provider;
            }
        }

        throw new ModpackProviderException('The requested modpack provider is not supported.');
    }

    public function status(): array
    {
        return Collection::make($this->providers)->map(fn (ModpackProvider $provider) => [
            'key' => $provider->key(),
            'name' => $provider->name(),
            'configured' => $provider->isConfigured(),
            'reason' => $provider->unavailableReason(),
        ])->values()->all();
    }
}
