<?php

namespace Pterodactyl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pterodactyl\Beacon\Minecraft\RconAuthenticationProbe;

class RconAuthenticationProbeTest extends TestCase
{
    public function testAuthenticationPacketUsesTheCorrectProtocolWithoutACommand(): void
    {
        $packet = RconAuthenticationProbe::packet('TEST_ONLY');
        $this->assertSame(['length' => 19, 'id' => RconAuthenticationProbe::REQUEST_ID, 'type' => 3], unpack('Vlength/Vid/Vtype', substr($packet, 0, 12)));
        $this->assertSame("TEST_ONLY\0\0", substr($packet, 12));
    }

    public function testOnlyTheMatchingAuthenticationResponseIsAccepted(): void
    {
        $response = fn ($id, $type) => pack('VVV', 10, $id, $type) . "\0\0";
        $this->assertTrue(RconAuthenticationProbe::accepted($response(RconAuthenticationProbe::REQUEST_ID, 2)));
        $this->assertFalse(RconAuthenticationProbe::accepted($response(0xFFFFFFFF, 2)));
        $this->assertFalse(RconAuthenticationProbe::accepted($response(999, 2)));
        $this->assertNull(RconAuthenticationProbe::accepted($response(RconAuthenticationProbe::REQUEST_ID, 0)));
        $this->assertFalse(RconAuthenticationProbe::accepted($response(1, 4)));
        $this->assertFalse(RconAuthenticationProbe::accepted("\0\0"));
        $this->assertFalse(RconAuthenticationProbe::accepted(pack('VVV', 9999, 17311, 2) . "\0\0"));
    }

    public function testUntrustedOrUnusableAddressesAreRejectedBeforeConnecting(): void
    {
        $probe = new RconAuthenticationProbe();
        $this->assertFalse($probe->authenticate('example.com', 25575, 'TEST_ONLY'));
        $this->assertFalse($probe->authenticate('0.0.0.0', 25575, 'TEST_ONLY'));
        $this->assertFalse($probe->authenticate('127.0.0.1', 80, 'TEST_ONLY'));
        $this->assertFalse($probe->authenticate('127.0.0.1', 25575, ''));
    }
}
