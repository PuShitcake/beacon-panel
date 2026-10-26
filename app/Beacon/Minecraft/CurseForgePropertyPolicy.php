<?php

namespace Pterodactyl\Beacon\Minecraft;

/** Common base-game fields only; pack/mod settings never become arbitrary editable fields. */
class CurseForgePropertyPolicy extends VanillaPropertyPolicy
{
    protected const RUNTIME = 'CurseForge modpack';
}
