<?php

namespace Pterodactyl\Tests\Unit;

use Pterodactyl\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Beacon\Minecraft\VelocityPropertyPolicy;

class VelocityPropertyPolicyTest extends TestCase
{
    public function testOnlyPresentWellTypedAllowlistedFieldsAreDiscovered(): void
    {
        $policy = new VelocityPropertyPolicy();
        $definitions = $policy->definitions(['motd' => 'hello', 'announce-forge' => true, 'ping-passthrough' => 'mods',
            'advanced.log-player-connections' => false, 'advanced.read-timeout' => 30000, 'show-max-players' => 'wrong',
            'forwarding-secret' => 'hidden', 'query.enabled' => true]);
        $this->assertSame(['motd', 'announce-forge', 'ping-passthrough', 'advanced.read-timeout', 'advanced.log-player-connections'], array_keys($definitions));
        $this->assertSame(
            ['motd' => '', 'announce-forge' => false, 'ping-passthrough' => 'all', 'advanced.read-timeout' => 5000],
            $policy->validate(['motd' => '', 'announce-forge' => false, 'ping-passthrough' => 'all', 'advanced.read-timeout' => '5000'], $definitions)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidChangesAreRejectedWithoutReflectingUserInput(array $changes): void
    {
        $policy = new VelocityPropertyPolicy();
        $definitions = $policy->definitions(['announce-forge' => true, 'ping-passthrough' => 'mods']);
        try {
            $policy->validate($changes, $definitions);
            $this->fail('Unsafe value accepted');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('PRIVATE', json_encode($e->errors()));
        }
    }

    public static function invalid(): array
    {
        return [[['announce-forge' => 'true']], [['ping-passthrough' => 'PRIVATE_UNKNOWN']], [['forwarding-secret' => 'PRIVATE_SECRET']], [[]]];
    }
}
