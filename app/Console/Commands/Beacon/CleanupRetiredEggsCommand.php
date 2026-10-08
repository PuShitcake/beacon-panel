<?php

namespace Pterodactyl\Console\Commands\Beacon;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Pterodactyl\Models\BeaconOperation;

class CleanupRetiredEggsCommand extends Command
{
    private const RETIRED_EGGS = [
        'Forge Minecraft',
        'Beacon Modpack Installer',
        'Sponge (SpongeVanilla)',
    ];

    protected $description = 'Backs up and removes retired Beacon Eggs without touching imported reference Eggs.';

    protected $signature = 'p:beacon:eggs:cleanup
        {--apply : Back up, verify restore, and remove the retired Eggs}
        {--restore= : Restore a backup produced by this command}';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '' && $this->option('apply')) {
            $this->error('Use either --apply or --restore, not both.');

            return self::INVALID;
        }
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $nest = Nest::query()->where('name', config('beacon.modpacks.nest.name', 'Beacon'))->first();
        if (!$nest instanceof Nest) {
            $this->error('The Beacon nest does not exist.');

            return self::FAILURE;
        }
        $eggs = Egg::query()
            ->where('nest_id', $nest->id)
            ->whereIn('name', self::RETIRED_EGGS)
            ->orderBy('id')
            ->get();
        if ($eggs->isEmpty()) {
            $this->info('No retired Beacon Eggs are installed.');

            return self::SUCCESS;
        }

        $ids = $eggs->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!$this->safeToRemove($ids)) {
            return self::FAILURE;
        }

        $snapshot = $this->snapshot($ids);
        $this->table(
            ['ID', 'Egg', 'Catalog profiles', 'Variables', 'Mounts'],
            $eggs->map(fn (Egg $egg) => [
                $egg->id,
                $egg->name,
                collect($snapshot['beacon_catalog_profiles'])->where('egg_id', $egg->id)->count(),
                collect($snapshot['egg_variables'])->where('egg_id', $egg->id)->count(),
                collect($snapshot['egg_mount'])->where('egg_id', $egg->id)->count(),
            ])->all()
        );
        if (!$this->option('apply')) {
            $this->comment('Dry run only. Re-run with --apply to create a verified backup and remove these Eggs.');

            return self::SUCCESS;
        }

        $backupPath = storage_path('app/beacon/retired-eggs-' . now()->format('Ymd-His') . '.json');
        File::ensureDirectoryExists(dirname($backupPath));
        File::put($backupPath, json_encode([
            'format' => 1,
            'created_at' => now()->toIso8601String(),
            'nest' => ['id' => $nest->id, 'name' => $nest->name],
            'retired_names' => self::RETIRED_EGGS,
            'tables' => $snapshot,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // Prove this exact backup can restore every affected row before the
        // destructive transaction is allowed to commit.
        DB::transaction(function () use ($snapshot, $ids) {
            $this->deleteSnapshot($snapshot, $ids);
            $this->restoreSnapshot($snapshot);
            if ($this->fingerprint($this->snapshot($ids)) !== $this->fingerprint($snapshot)) {
                throw new \RuntimeException('The retired Egg backup failed its restore verification.');
            }
        }, 5);

        DB::transaction(fn () => $this->deleteSnapshot($snapshot, $ids), 5);

        $this->info('Removed the retired Beacon Eggs.');
        $this->line("Verified rollback backup: {$backupPath}");

        return self::SUCCESS;
    }

    private function safeToRemove(array $eggIds): bool
    {
        $servers = DB::table('servers')->whereIn('egg_id', $eggIds)->count();
        $children = DB::table('eggs')
            ->whereIn('config_from', $eggIds)
            ->orWhereIn('copy_script_from', $eggIds)
            ->count();
        $profileIds = DB::table('beacon_catalog_profiles')->whereIn('egg_id', $eggIds)->pluck('id');
        $metadata = $profileIds->isEmpty()
            ? 0
            : DB::table('beacon_server_metadata')->whereIn('profile_id', $profileIds)->count();
        $operations = DB::table('beacon_operations')
            ->whereIn('status', [BeaconOperation::STATUS_PENDING, BeaconOperation::STATUS_RUNNING])
            ->where('type', 'like', 'modpack.%')
            ->count();

        foreach ([
            'servers still using a retired Egg' => $servers,
            'Eggs inheriting from a retired Egg' => $children,
            'server metadata rows using a retired catalog profile' => $metadata,
            'active modpack operations' => $operations,
        ] as $label => $count) {
            if ($count > 0) {
                $this->error("Refusing cleanup: {$count} {$label}.");

                return false;
            }
        }

        return true;
    }

    private function snapshot(array $eggIds): array
    {
        $profileIds = DB::table('beacon_catalog_profiles')->whereIn('egg_id', $eggIds)->pluck('id')->all();

        return [
            'eggs' => $this->rows('eggs', 'id', $eggIds),
            'egg_variables' => $this->rows('egg_variables', 'egg_id', $eggIds, ['egg_id', 'id']),
            'egg_mount' => $this->rows('egg_mount', 'egg_id', $eggIds, ['egg_id', 'mount_id']),
            'beacon_catalog_profiles' => $this->rows('beacon_catalog_profiles', 'egg_id', $eggIds),
            'beacon_catalog_versions' => $this->rows('beacon_catalog_versions', 'profile_id', $profileIds),
        ];
    }

    private function rows(string $table, string $column, array $ids, array $order = ['id']): array
    {
        if ($ids === []) {
            return [];
        }
        $query = DB::table($table)->whereIn($column, $ids);
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    private function deleteSnapshot(array $snapshot, array $eggIds): void
    {
        $profileIds = array_column($snapshot['beacon_catalog_profiles'], 'id');
        if ($profileIds !== []) {
            DB::table('beacon_catalog_versions')->whereIn('profile_id', $profileIds)->delete();
            DB::table('beacon_catalog_profiles')->whereIn('id', $profileIds)->delete();
        }
        DB::table('egg_mount')->whereIn('egg_id', $eggIds)->delete();
        DB::table('egg_variables')->whereIn('egg_id', $eggIds)->delete();
        DB::table('eggs')->whereIn('id', $eggIds)->delete();
    }

    private function restoreSnapshot(array $snapshot): void
    {
        foreach (['eggs', 'egg_variables', 'egg_mount', 'beacon_catalog_profiles', 'beacon_catalog_versions'] as $table) {
            if ($snapshot[$table] !== []) {
                DB::table($table)->insert($snapshot[$table]);
            }
        }
    }

    private function restore(string $path): int
    {
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $path = $absolute ? $path : base_path($path);
        if (!File::isFile($path)) {
            $this->error('The requested backup file does not exist.');

            return self::FAILURE;
        }
        $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $snapshot = $payload['tables'] ?? null;
        if (($payload['format'] ?? null) !== 1 || !is_array($snapshot) || !is_array($snapshot['eggs'] ?? null)) {
            $this->error('The requested backup has an unsupported format.');

            return self::FAILURE;
        }
        $ids = array_map('intval', array_column($snapshot['eggs'], 'id'));
        if (DB::table('eggs')->whereIn('id', $ids)->exists()) {
            $this->error('One or more backed-up Egg IDs already exist; refusing to overwrite them.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($snapshot, $ids) {
            $this->restoreSnapshot($snapshot);
            if ($this->fingerprint($this->snapshot($ids)) !== $this->fingerprint($snapshot)) {
                throw new \RuntimeException('The restored retired Egg rows do not match the backup.');
            }
        }, 5);

        $this->info('Restored the retired Beacon Eggs from the verified backup.');

        return self::SUCCESS;
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
