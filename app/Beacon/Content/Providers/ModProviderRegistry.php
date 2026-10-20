<?php

namespace Pterodactyl\Beacon\Content\Providers;

use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class ModProviderRegistry
{
    public function __construct(private ContentProvider $modrinth, private CurseForgeModProvider $curseforge)
    {
    }

    public function get(string $key): ContentProvider
    {
        return match ($key) {
            'curseforge' => $this->curseforge,
            'modrinth' => $this->modrinth,
            default => throw new ProviderResponseException('Select CurseForge or Modrinth as the mod provider.'),
        };
    }

    public function describe(): array
    {
        return [
            ['id' => 'curseforge', 'name' => 'CurseForge', 'available' => $this->curseforge->isConfigured()],
            ['id' => 'modrinth', 'name' => 'Modrinth', 'available' => true],
        ];
    }

    public function categories(string $key): array
    {
        $this->get($key); // Reject unknown provider names even on the context endpoint.

        return $key === 'curseforge' ? $this->curseforge->categories()
            : array_map(fn (string $category) => ['value' => $category, 'label' => ucwords(str_replace('-', ' ', $category))], config('beacon.mods.categories', []));
    }
}
