<?php

namespace Pterodactyl\Tests\Integration\Beacon\Minecraft;

use Pterodactyl\Beacon\Minecraft\PaperGeyserConfigurationService;

/** Repeat the Paper/Vanilla writer, backup, permissions and storage contract for this Egg. */
class PaperGeyserConfigurationTest extends PaperConfigurationTest
{
    protected const EGG = 'Paper + Geyser + Floodgate';
    protected const SERVICE = PaperGeyserConfigurationService::class;

    public function testCrossplayNotesDoNotClaimBedrockSettingsAreManaged(): void
    {
        $read = $this->service->read($this->server());
        $notes = implode(' ', $read['notes']);
        $this->assertStringContainsString('Bedrock ports and network allocations are not read or changed', $notes);
        $this->assertStringContainsString('does not verify Bedrock connectivity', $notes);
        $this->assertStringContainsString('Java packs are not converted automatically', $notes);
    }

    public function testPluginFilesAndSecretsCannotBecomePropertiesFields(): void
    {
        $this->entries[] = ['name' => 'plugins', 'file' => false, 'symlink' => false];
        $this->extraFiles['/plugins/floodgate/key.pem'] = 'PRIVATE_CROSSPLAY_FIXTURE';
        $this->extraFiles['/plugins/Geyser-Spigot/config.yml'] = 'PRIVATE_GEYSER_FIXTURE';
        $this->content .= "floodgate-key=PRIVATE_CROSSPLAY_FIXTURE\nauth-type=floodgate\nbedrock-port=19132\n";
        $read = $this->service->read($this->server());
        $this->assertStringNotContainsString('PRIVATE_CROSSPLAY_FIXTURE', json_encode($read));
        $this->assertStringNotContainsString('PRIVATE_GEYSER_FIXTURE', json_encode($read));
        $this->assertArrayNotHasKey('auth-type', $read['definitions']);
        $this->assertArrayNotHasKey('bedrock-port', $read['definitions']);
        $before = $this->extraFiles;
        $this->save(['motd' => 'Java only'], $read);
        $this->assertSame($before, $this->extraFiles);
        $this->assertSame(['/server.properties'], array_values(array_unique($this->filePaths)));
        $this->assertStringContainsString("auth-type=floodgate\nbedrock-port=19132", $this->content);
    }

    public function testCrossplayKeysCannotBypassTheJavaPropertiesAllowlist(): void
    {
        $this->content .= "auth-type=floodgate\n";
        $read = $this->service->read($this->server());
        try {
            $this->save(['auth-type' => 'offline'], $read);
            $this->fail('Expected an unsupported properties field to be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame(0, $this->writes);
        }
    }
}
