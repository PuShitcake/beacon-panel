<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Jobs\Beacon\RemoveContentJob;
use Pterodactyl\Jobs\Beacon\InstallContentJob;
use Pterodactyl\Jobs\Beacon\CreateCatalogServerJob;

class BeaconJobContractTest extends TestCase
{
    public function testJobsExposeLaravelQueueLifecycleMethods(): void
    {
        foreach ([
            CreateCatalogServerJob::class,
            InstallContentJob::class,
            RemoveContentJob::class,
        ] as $jobClass) {
            $job = new $jobClass(1);

            $this->assertTrue(method_exists($jobClass, 'dispatch'));
            $this->assertTrue(method_exists($job, 'release'));
        }
    }
}
