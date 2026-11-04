import type { PluginProvider } from '@/api/server/beacon/plugins';

export const DEFAULT_PLUGIN_PROVIDER: PluginProvider = 'curseforge';

export const velocityProviderNotice = (loader: string | null, provider: PluginProvider): string | null => {
    if (loader !== 'velocity' || provider === 'hangar') return null;

    return provider === 'curseforge'
        ? 'CurseForge results are candidates. Beacon does not inspect the JAR to confirm it is a Velocity plugin. Choose a Velocity build and check the author requirements.'
        : 'Only Velocity-tagged releases are listed. Beacon uses provider metadata, not JAR inspection. Check the author requirements for your Velocity version.';
};

export const pluginRuntimeLabel = (runtime: {
    loader: string | null;
    game_version: string | null;
    platform_version?: string | null;
}): string =>
    runtime.loader === 'velocity'
        ? `Velocity ${runtime.platform_version || 'version unavailable'}`
        : `Minecraft ${runtime.game_version || 'version unavailable'} - ${runtime.loader || 'unknown'}`;
