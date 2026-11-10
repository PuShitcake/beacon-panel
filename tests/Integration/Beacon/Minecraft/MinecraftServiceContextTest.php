<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\BeaconOperation;
use Pterodactyl\Models\BeaconMinecraftService;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BeaconMinecraftServiceController;

/** Public service projection only: no database or Wings access. */
class MinecraftServiceContextTest extends TestCase
{
    private function project(string $change = '', ?string $unavailable = null): array
    {
        config()->set('beacon.modpacks.nest.name', 'Beacon');
        $server = (new Server())->forceFill(['id' => 1, 'allocation_id' => 1]);
        $egg = (new Egg())->forceFill(['name' => 'Velocity']);
        $egg->setRelation('nest', (new Nest())->forceFill(['name' => 'Beacon']));
        $server->setRelation('egg', $egg);
        $allocation = (new Allocation())->forceFill(['id' => 2, 'server_id' => $change === 'unowned' ? 2 : 1, 'ip' => '127.0.0.1', 'port' => 25610]);
        $settings = new BeaconMinecraftService();
        $settings->forceFill(['rcon_enabled' => true, 'rcon_password' => 'TEST_ONLY_PUBLIC_CONTEXT']);
        $settings->setRelation('rconAllocation', $allocation);
        $settings->setRelation('lastOperation', $change === 'failed' ? (new BeaconOperation())->forceFill(['status' => 'failed']) : null);
        $properties = ['enable-rcon' => 'true', 'rcon.port' => '25610', 'rcon.password' => 'TEST_ONLY_PUBLIC_CONTEXT', 'rcon.host' => '0.0.0.0'];
        if ($change === 'password') {
            $properties['rcon.password'] = 'OUTSIDE_EDIT';
        } elseif ($change === 'host') {
            $properties['rcon.host'] = '127.0.0.1';
        }
        $controller = (new \ReflectionClass(BeaconMinecraftServiceController::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($controller, 'serviceData'))->invoke($controller, $server, $settings, $properties, 'rcon', $unavailable);
    }

    public function testHealthyProjectionMasksThePassword(): void
    {
        $data = $this->project();
        $this->assertSame('enabled', $data['status']);
        $this->assertTrue($data['can_disable']);
        $this->assertTrue($data['password_configured']);
        $this->assertStringNotContainsString('TEST_ONLY_PUBLIC_CONTEXT', json_encode($data));
    }

    public function testMissingPluginAllowsOnlyOwnedDisableRecovery(): void
    {
        $data = $this->project('', 'Plugin unavailable');
        $this->assertSame('attention', $data['status']);
        $this->assertFalse($data['enabled']);
        $this->assertTrue($data['can_disable']);
        $this->assertFalse($data['password_configured']);
    }

    public function testFailedListenerNeverLooksHealthyButCanBeDisabled(): void
    {
        $data = $this->project('failed');
        $this->assertSame('attention', $data['status']);
        $this->assertFalse($data['enabled']);
        $this->assertTrue($data['can_disable']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('drift')]
    public function testForeignConfigurationCannotBeDisabled(string $change): void
    {
        $data = $this->project($change);
        $this->assertSame('attention', $data['status']);
        $this->assertFalse($data['can_disable']);
        $this->assertFalse($data['enabled']);
    }

    public static function drift(): array
    {
        return [['unowned'], ['password'], ['host']];
    }
}
