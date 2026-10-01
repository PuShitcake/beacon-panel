<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Jobs\Beacon\RemoveContentJob;
use Pterodactyl\Jobs\Beacon\InstallContentJob;
use Pterodactyl\Jobs\Beacon\CreateCatalogServerJob;
use Pterodactyl\Jobs\Beacon\ProcessModpackOperationJob;

class BeaconJobContractTest extends TestCase
{
    public function testJobsExposeLaravelQueueLifecycleMethods(): void
    {
        foreach ([
            CreateCatalogServerJob::class,
            InstallContentJob::class,
            RemoveContentJob::class,
            ProcessModpackOperationJob::class,
        ] as $jobClass) {
            $job = new $jobClass(1);

            $this->assertTrue(method_exists($jobClass, 'dispatch'));
            $this->assertTrue(method_exists($job, 'release'));
        }
    }
}
