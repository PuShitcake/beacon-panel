<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BeaconMinecraftServicesContractTest extends TestCase
{
    public function testPublicApiNeverReturnsTheRconPassword(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/Client/Servers/BeaconMinecraftServiceController.php');
        $job = file_get_contents(__DIR__ . '/../../app/Jobs/Beacon/ProcessMinecraftServiceOperationJob.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString("'password_configured'", $controller);
        $this->assertStringNotContainsString("'rcon_password' =>", $controller);
        $this->assertIsString($job);
        $this->assertStringNotContainsString("'password' => \$password", $job);
    }

    public function testMutationsRequireBeaconRequestMetadataAndOwnerOnlyRequest(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api-client.php');
        $request = file_get_contents(__DIR__ . '/../../app/Http/Requests/Api/Client/Servers/BeaconMinecraftServiceRequest.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString("'prefix' => '/beacon/minecraft-services'", $routes);
        $this->assertStringContainsString('RequireBeaconRequestMetadata::class', $routes);
        $this->assertIsString($request);
        $this->assertStringContainsString('$this->user()->root_admin', $request);
        $this->assertStringContainsString('$server->owner_id === $this->user()->id', $request);
    }

    public function testServicePortsAreSystemManagedAndProtectedFromNativeNetworkMutations(): void
    {
        $allocator = file_get_contents(__DIR__ . '/../../app/Beacon/Minecraft/MinecraftServiceAllocationService.php');
        $network = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/Client/Servers/NetworkAllocationController.php');

        $this->assertIsString($allocator);
        $this->assertStringContainsString('AssignmentService', $allocator);
        $this->assertStringContainsString('beacon.minecraft_services.port_range_start', $allocator);
        $this->assertStringNotContainsString('allocation_limit', $allocator);
        $this->assertIsString($network);
        $this->assertGreaterThanOrEqual(2, substr_count($network, '$this->ensureNotBeaconManaged($allocation);'));
        $this->assertStringContainsString('rcon_allocation_id', $network);
        $this->assertStringContainsString('query_allocation_id', $network);
    }
}
