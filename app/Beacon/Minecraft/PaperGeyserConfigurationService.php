<?php

namespace Pterodactyl\Beacon\Minecraft;

use Pterodactyl\Models\Server;

/** Java properties of the exact combined Egg; never reads or edits crossplay secrets. */
class PaperGeyserConfigurationService extends PaperConfigurationService
{
    protected const RUNTIME = 'Paper + Geyser + Floodgate';

    public static function supports(Server $server): bool
    {
        $server->loadMissing('egg.nest');

        return $server->egg?->nest?->name === config('beacon.modpacks.nest.name', 'Beacon') && $server->egg?->name === 'Paper + Geyser + Floodgate';
    }

    protected function notes(string $version): array
    {
        return array_merge(parent::notes($version), [
            'This page edits the Java server.properties only. Geyser and Floodgate configuration, authentication keys, Bedrock ports and network allocations are not read or changed.',
            'Geyser can have separate Bedrock MOTD, player-count and authentication settings. Saving Java settings here does not verify Bedrock connectivity or change Geyser authentication.',
            'Resource pack settings here are for Java clients. Geyser needs Bedrock-format packs configured separately; Java packs are not converted automatically.',
        ]);
    }
}
