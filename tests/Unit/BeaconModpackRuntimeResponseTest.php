<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\BeaconModpackInstallation;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconModpackController;

class BeaconModpackRuntimeResponseTest extends TestCase
{
    public function testVerifiedRuntimeAddsOnlySafeFieldsToThePublicInstallation(): void
    {
        $installation = (new BeaconModpackInstallation())->forceFill([
            'minecraft_version' => '1.18.2',
            'loader' => 'forge',
            'manifest' => [
                'java_version' => 17,
                'runtime_source' => 'curseforge_client_manifest',
                'private' => 'must-not-be-returned',
            ],
            'original_runtime' => ['private' => 'must-not-be-returned'],
            'safety_backup_uuid' => 'must-not-be-returned',
        ]);
        $controller = (new \ReflectionClass(BeaconModpackController::class))->newInstanceWithoutConstructor();
        $data = (new \ReflectionMethod(BeaconModpackController::class, 'installationData'))->invoke($controller, $installation);

        $this->assertSame(17, $data['java_version']);
        $this->assertTrue($data['runtime_verified']);
        $this->assertSame('1.18.2', $data['minecraft_version']);
        $this->assertArrayNotHasKey('manifest', $data);
        $this->assertArrayNotHasKey('original_runtime', $data);
        $this->assertArrayNotHasKey('safety_backup_uuid', $data);
        $this->assertStringNotContainsString('must-not-be-returned', json_encode($data));
    }

    public function testHistoricalInstallationsAreNotFalselyReportedAsVerified(): void
    {
        $installation = (new BeaconModpackInstallation())->forceFill(['manifest' => []]);
        $controller = (new \ReflectionClass(BeaconModpackController::class))->newInstanceWithoutConstructor();
        $data = (new \ReflectionMethod(BeaconModpackController::class, 'installationData'))->invoke($controller, $installation);

        $this->assertNull($data['java_version']);
        $this->assertFalse($data['runtime_verified']);
    }
}
