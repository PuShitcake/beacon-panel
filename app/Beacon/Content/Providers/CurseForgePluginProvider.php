<?php

namespace Pterodactyl\Beacon\Content\Providers;

/** Bukkit plugin catalog, separate from Forge mods and modpacks. */
class CurseForgePluginProvider extends CurseForgeModProvider
{
    protected const CLASS_ID = 5;
    protected const PROJECT_TYPE = 'plugin';
    protected const LOADERS = ['paper', 'spigot', 'bukkit'];
    protected const FILE_LOADER = null;
    protected const MOD_LOADER_TYPE = null;
}
