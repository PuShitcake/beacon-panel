<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\BeaconMinecraftService;
use Pterodactyl\Services\Allocations\AssignmentService;

class MinecraftServiceAllocationService
{
    public function __construct(private AssignmentService $allocations)
    {
    }

    public function claim(Server $server, BeaconMinecraftService $settings, string $service): Allocation
    {
        $column = $this->column($service);

        return DB::transaction(function () use ($server, $settings, $service, $column) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $settings = BeaconMinecraftService::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $existingId = $settings->getAttribute($column);
            if (is_int($existingId)) {
                $existing = Allocation::query()->lockForUpdate()->find($existingId);
                if ($existing instanceof Allocation && $existing->server_id === $server->id && $existing->id !== $server->allocation_id) {
                    return $existing;
                }

                throw new DisplayException("The Beacon {$service} allocation record is inconsistent. No port was changed.");
            }

            $allocation = $server->node->allocations()
                ->where('ip', $server->allocation->ip)
                ->whereNull('server_id')
                ->lockForUpdate()
                ->inRandomOrder()
                ->first();
            if (!$allocation instanceof Allocation) {
                $allocation = $this->create($server);
            }
            $allocation->forceFill([
                'server_id' => $server->id,
                'notes' => $this->note($service),
            ])->save();
            $settings->forceFill([$column => $allocation->id])->save();

            return $allocation;
        }, 5);
    }

    public function release(Server $server, BeaconMinecraftService $settings, string $service): ?Allocation
    {
        $column = $this->column($service);

        return DB::transaction(function () use ($server, $settings, $service, $column) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $settings = BeaconMinecraftService::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $allocationId = $settings->getAttribute($column);
            if (!is_int($allocationId)) {
                return null;
            }

            $allocation = Allocation::query()->lockForUpdate()->find($allocationId);
            if (!$allocation instanceof Allocation) {
                $settings->forceFill([$column => null])->save();

                return null;
            }
            if ($allocation->server_id !== $server->id || $allocation->id === $server->allocation_id) {
                throw new DisplayException("Beacon refused to release an unowned or primary {$service} allocation.");
            }

            $allocation->forceFill([
                'server_id' => null,
                'notes' => $allocation->notes === $this->note($service) ? null : $allocation->notes,
            ])->save();
            $settings->forceFill([$column => null])->save();

            return $allocation;
        }, 5);
    }

    public function restore(Server $server, BeaconMinecraftService $settings, string $service, Allocation $allocation): void
    {
        $column = $this->column($service);
        DB::transaction(function () use ($server, $settings, $service, $allocation, $column) {
            $locked = Allocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($locked->server_id !== null && $locked->server_id !== $server->id) {
                throw new DisplayException('The previous allocation was claimed by another server during rollback.');
            }
            $locked->forceFill(['server_id' => $server->id, 'notes' => $this->note($service)])->save();
            BeaconMinecraftService::query()->whereKey($settings->id)->update([$column => $locked->id]);
        }, 5);
    }

    private function column(string $service): string
    {
        if (!in_array($service, ['rcon', 'query'], true)) {
            throw new \InvalidArgumentException('Unsupported Minecraft network service.');
        }

        return $service . '_allocation_id';
    }

    private function create(Server $server): Allocation
    {
        $start = (int) config('beacon.minecraft_services.port_range_start');
        $end = (int) config('beacon.minecraft_services.port_range_end');
        if ($start < 1024 || $end > 65535 || $start > $end) {
            throw new DisplayException('The Beacon Minecraft service port range is invalid.');
        }

        $used = $server->node->allocations()
            ->where('ip', $server->allocation->ip)
            ->whereBetween('port', [$start, $end])
            ->lockForUpdate()
            ->pluck('port')
            ->all();
        $available = array_values(array_diff(range($start, $end), $used));
        if ($available === []) {
            throw new DisplayException('No free port remains in the Beacon Minecraft service range.');
        }
        $port = $available[random_int(0, count($available) - 1)];
        $this->allocations->handle($server->node, [
            'allocation_ip' => $server->allocation->ip,
            'allocation_ports' => [$port],
        ]);

        return $server->node->allocations()
            ->where('ip', $server->allocation->ip)
            ->where('port', $port)
            ->whereNull('server_id')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function note(string $service): string
    {
        return 'Beacon managed ' . strtoupper($service);
    }
}
