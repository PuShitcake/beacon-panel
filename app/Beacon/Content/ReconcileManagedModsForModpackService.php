<?php

namespace Pterodactyl\Beacon\Content;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\BeaconContentInstallation;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

class ReconcileManagedModsForModpackService
{
    public function preserve(
        Server $server,
        DaemonFileRepository $files,
        string $operationUuid,
        string $action,
    ): array {
        if (!in_array($action, ['install', 'update', 'reinstall'], true)) {
            return [];
        }

        $installations = BeaconContentInstallation::query()
            ->where('server_id', $server->id)
            ->where('project_type', 'mod')
            ->where('destination', 'mods')
            ->whereIn('status', ['installed', 'disabled'])
            ->get();
        if ($installations->isEmpty()) {
            return [];
        }

        $directory = "mod-addons-{$operationUuid}";
        $directoryExists = collect($files->getDirectory('/.beacon'))
            ->contains(fn (array $entry) => ($entry['name'] ?? null) === $directory);
        if ($directoryExists) {
            $preserved = collect($files->getDirectory('/.beacon/' . $directory))->pluck('name')->filter('is_string');
            $missing = $installations->pluck('filename')->first(fn (string $name) => !$preserved->contains($name));
            if (is_string($missing)) {
                throw new \RuntimeException("The preserved Beacon mod '{$missing}' is missing. Manual file review is required.");
            }
        } else {
            $missing = $installations->first(fn (BeaconContentInstallation $item) => !$this->pathExists($files, $this->managedPath($item)));
            if ($missing instanceof BeaconContentInstallation) {
                throw new \RuntimeException("The Beacon-managed mod '{$missing->filename}' is missing. The modpack was not changed.");
            }
            $files->createDirectory($directory, '/.beacon');
            $files->renameFiles('/', $installations->map(fn (BeaconContentInstallation $item) => [
                'from' => $this->managedPath($item),
                'to' => ".beacon/{$directory}/{$item->filename}",
            ])->all());
        }

        return $installations->map(fn (BeaconContentInstallation $item) => [
            'id' => $item->id,
            'filename' => $item->filename,
            'game_version' => $item->game_version,
            'loader' => $item->loader,
        ])->all();
    }

    public function restore(
        DaemonFileRepository $files,
        string $operationUuid,
        array $release,
        array $preserved,
    ): array {
        if ($preserved === []) {
            return [];
        }

        $this->ensureModsDirectory($files);
        $existing = collect($files->getDirectory('/mods'))->pluck('name')->filter('is_string');
        $directory = "mod-addons-{$operationUuid}";
        $changes = [];
        foreach ($preserved as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || !is_string($item['filename'] ?? null)) {
                throw new \RuntimeException('The preserved Beacon mod manifest is invalid.');
            }
            $compatible = ($item['game_version'] ?? null) === ($release['minecraft_version'] ?? null)
                && ($item['loader'] ?? null) === ($release['loader'] ?? null)
                && !$existing->contains($item['filename']);
            $target = $compatible
                ? 'mods/' . $item['filename']
                : 'mods/.beacon-disabled-' . $item['id'] . '-' . $operationUuid . '-' . $item['filename'] . '.disabled';
            $files->renameFiles('/', [[
                'from' => ".beacon/{$directory}/{$item['filename']}",
                'to' => $target,
            ]]);
            $existing->push(basename($target));
            $changes[] = [
                'id' => $item['id'],
                'status' => $compatible ? 'installed' : 'disabled',
                'disabled_path' => $compatible ? null : $target,
            ];
        }
        $files->deleteFiles('/.beacon', [$directory]);

        return $changes;
    }

    private function ensureModsDirectory(DaemonFileRepository $files): void
    {
        $exists = collect($files->getDirectory('/'))->contains(fn (array $entry) => ($entry['name'] ?? null) === 'mods');
        if (!$exists) {
            $files->createDirectory('mods', '/');
        }
    }

    private function managedPath(BeaconContentInstallation $installation): string
    {
        $path = $installation->status === 'disabled' ? $installation->disabled_path : null;
        if (is_string($path) && preg_match('#^mods/[A-Za-z0-9._+-]+\.disabled$#', $path)) {
            return $path;
        }

        return 'mods/' . $installation->filename;
    }

    private function pathExists(DaemonFileRepository $files, string $path): bool
    {
        $directory = '/' . trim(str_replace('\\', '/', dirname($path)), './');
        $directory = $directory === '/' ? '/' : rtrim($directory, '/');
        $name = basename($path);

        return collect($files->getDirectory($directory))
            ->contains(fn (array $entry) => ($entry['name'] ?? null) === $name);
    }
}
