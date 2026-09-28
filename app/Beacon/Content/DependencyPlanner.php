<?php

namespace Pterodactyl\Beacon\Content;

use Pterodactyl\Beacon\Content\Providers\ContentProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Beacon\Content\Exceptions\ProviderResponseException;

class DependencyPlanner
{
    private array $planned = [];
    private array $optional = [];
    private array $incompatible = [];
    private int $totalBytes = 0;

    public function __construct(private ContentProvider $provider)
    {
    }

    public function handle(string $versionId, string $gameVersion, string $loader, string $projectType): array
    {
        $this->planned = [];
        $this->optional = [];
        $this->incompatible = [];
        $this->totalBytes = 0;
        $this->visit($versionId, $gameVersion, $loader, 0);

        return [
            'provider' => 'modrinth',
            'project_type' => $projectType,
            'loader' => $loader,
            'game_version' => $gameVersion,
            'files' => array_values($this->planned),
            'optional_dependencies' => array_values($this->optional),
            'incompatible_dependencies' => array_values($this->incompatible),
            'total_bytes' => $this->totalBytes,
        ];
    }

    private function visit(string $versionId, string $gameVersion, string $loader, int $depth): void
    {
        if ($depth > config('beacon.content.max_dependency_depth')) {
            throw new ConflictHttpException('The dependency graph exceeds the configured depth limit.');
        }
        if (isset($this->planned[$versionId])) {
            return;
        }
        if (count($this->planned) >= config('beacon.content.max_dependencies')) {
            throw new ConflictHttpException('The dependency graph exceeds the configured file limit.');
        }

        $version = $this->provider->version($versionId);
        if (!in_array($gameVersion, $version['game_versions'], true) || !in_array($loader, $version['loaders'], true)) {
            throw new ConflictHttpException('A content version is incompatible with the selected game version or loader.');
        }

        $file = $version['file'];
        $this->totalBytes += $file['size'];
        if ($this->totalBytes > config('beacon.content.max_total_bytes')) {
            throw new ConflictHttpException('The installation plan exceeds the configured total size limit.');
        }

        $this->planned[$versionId] = [
            'project_id' => $version['project_id'],
            'version_id' => $version['id'],
            'name' => $version['name'],
            'version_number' => $version['version_number'],
            'url' => $file['url'],
            'filename' => $file['filename'],
            'size' => $file['size'],
            'sha512' => $file['sha512'],
            'dependency' => $depth > 0,
        ];

        foreach ($version['dependencies'] as $dependency) {
            $type = $dependency['dependency_type'] ?? null;
            $dependencyVersion = $dependency['version_id'] ?? null;
            $projectId = $dependency['project_id'] ?? null;

            if ($type === 'incompatible') {
                $this->incompatible[] = array_filter(['version_id' => $dependencyVersion, 'project_id' => $projectId]);
                continue;
            }
            if ($type === 'optional') {
                $this->optional[] = array_filter(['version_id' => $dependencyVersion, 'project_id' => $projectId]);
                continue;
            }
            if ($type !== 'required' && $type !== 'embedded') {
                continue;
            }
            if ($type === 'embedded') {
                continue;
            }

            if (!is_string($dependencyVersion) || $dependencyVersion === '') {
                if (!is_string($projectId) || $projectId === '') {
                    throw new ProviderResponseException('A required dependency has no project or version identifier.');
                }

                $versions = $this->provider->versions($projectId, $gameVersion, $loader);
                $selectedVersion = collect($versions)->first(
                    fn (array $version) => ($version['version_type'] ?? null) === 'release'
                        && is_string($version['id'] ?? null)
                );
                $dependencyVersion = is_array($selectedVersion) ? $selectedVersion['id'] : null;
                if (!is_string($dependencyVersion)) {
                    throw new ConflictHttpException('No stable compatible version exists for a required dependency.');
                }
            }

            $this->visit($dependencyVersion, $gameVersion, $loader, $depth + 1);
        }
    }
}
