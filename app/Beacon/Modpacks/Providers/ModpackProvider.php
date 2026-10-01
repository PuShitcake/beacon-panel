<?php

namespace Pterodactyl\Beacon\Modpacks\Providers;

interface ModpackProvider
{
    public function key(): string;

    public function name(): string;

    public function isConfigured(): bool;

    public function unavailableReason(): ?string;

    public function search(string $query, int $page, int $pageSize): array;

    public function versions(string $projectId): array;

    public function release(string $projectId, string $versionId): array;
}
