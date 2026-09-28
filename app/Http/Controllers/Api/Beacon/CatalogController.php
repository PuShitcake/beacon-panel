<?php

namespace Pterodactyl\Http\Controllers\Api\Beacon;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Beacon\Api\BeaconResponse;
use Pterodactyl\Models\BeaconResourcePreset;
use Pterodactyl\Models\BeaconCatalogApplication;
use Pterodactyl\Http\Requests\Api\Beacon\BeaconReadRequest;

class CatalogController
{
    public function index(BeaconReadRequest $request): JsonResponse
    {
        $applications = BeaconCatalogApplication::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get(['slug', 'name', 'description'])
            ->map(fn (BeaconCatalogApplication $application) => $this->applicationData($application));

        return BeaconResponse::make($request, [
            'applications' => $applications,
            'presets' => $this->presets(),
        ]);
    }

    public function show(BeaconReadRequest $request, BeaconCatalogApplication $application): JsonResponse
    {
        abort_unless($application->enabled, 404);

        return BeaconResponse::make($request, [
            'application' => $this->applicationData($application),
            'presets' => $this->presets(),
        ]);
    }

    private function applicationData(BeaconCatalogApplication $application): array
    {
        $application->load(['profiles' => fn ($query) => $query->where('enabled', true)->orderBy('name'), 'profiles.versions' => function ($query) {
            $query->where('enabled', true)->where('deprecated', false)->orderByDesc('version');
        }]);

        return [
            'slug' => $application->slug,
            'name' => $application->name,
            'description' => $application->description,
            'profiles' => $application->profiles->map(fn ($profile) => [
                'code' => $profile->code,
                'name' => $profile->name,
                'loader' => $profile->loader,
                'content_directory' => $profile->content_directory,
                'versions' => $profile->versions->map(fn ($version) => [
                    'version' => $version->version,
                    'loader_version' => $version->loader_version ?: null,
                ])->values(),
            ])->values(),
        ];
    }

    private function presets(): array
    {
        return BeaconResourcePreset::query()
            ->where('enabled', true)
            ->orderBy('memory')
            ->get(['code', 'name', 'memory', 'disk', 'cpu', 'database_limit', 'allocation_limit', 'backup_limit'])
            ->toArray();
    }
}
