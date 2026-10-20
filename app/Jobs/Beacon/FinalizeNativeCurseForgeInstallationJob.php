<?php

namespace Pterodactyl\Jobs\Beacon;

use Pterodactyl\Models\BeaconOperation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Beacon\Modpacks\CurseForgeInstalledRuntimeService;
use Pterodactyl\Beacon\Modpacks\NativeCurseForgeInstallationService;

class FinalizeNativeCurseForgeInstallationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 900;
    public int $timeout = 60;

    public function __construct(public int $operationId)
    {
        $this->queue = 'standard';
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('native-modpack:' . $this->operationId))->releaseAfter(10)->expireAfter(75)];
    }

    public function handle(
        NativeCurseForgeInstallationService $lifecycle,
        CurseForgeInstalledRuntimeService $installedRuntime,
        DaemonFileRepository $files,
        DaemonServerRepository $wings,
        DaemonPowerRepository $power,
    ): void {
        $operation = BeaconOperation::query()->where('type', NativeCurseForgeInstallationService::TYPE)->find($this->operationId);
        if (!$operation) {
            return;
        }
        try {
            if (!$lifecycle->process($operation, $installedRuntime, $files, $wings, $power)) {
                $this->release(10);
            }
        } catch (\Throwable $exception) {
            $lifecycle->fail($operation, $exception, 'worker');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $operation = BeaconOperation::query()->where('type', NativeCurseForgeInstallationService::TYPE)->find($this->operationId);
        if ($operation) {
            app(NativeCurseForgeInstallationService::class)->fail($operation, $exception, 'worker_exhausted');
        }
    }
}
