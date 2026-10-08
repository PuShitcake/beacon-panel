<?php

namespace Pterodactyl\Beacon\Content\Providers;

interface ContentProvider
{
    public function search(
        string $query,
        string $gameVersion,
        string $loader,
        string $projectType,
        int $offset = 0,
        ?string $category = null,
    ): array;

    public function versions(string $projectId, string $gameVersion, string $loader): array;

    public function version(string $versionId): array;
}
