import { MinecraftConfiguration } from './configuration';

export const configurationVersionSourceLabel = (configuration: MinecraftConfiguration): string | undefined => {
    if (!configuration.game_version) return undefined;
    switch (configuration.version_source) {
        case 'official_server_jar':
            return 'Version identified from the installed official server JAR, not the Startup version selector.';
        case 'verified_curseforge_manifest':
            return 'Version identified from the verified installed CurseForge manifest, not the Startup IDs or Java image.';
        default:
            return undefined;
    }
};
