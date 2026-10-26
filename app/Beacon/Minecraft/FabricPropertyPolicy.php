<?php

namespace Pterodactyl\Beacon\Minecraft;

/** Vanilla Java property safety applied only to existing fields of a verified Fabric game. */
class FabricPropertyPolicy extends VanillaPropertyPolicy
{
    protected const RUNTIME = 'Fabric';

    public function supports(string $version): bool
    {
        return parent::supports($version) && version_compare($version, '1.14', '>=');
    }
}
