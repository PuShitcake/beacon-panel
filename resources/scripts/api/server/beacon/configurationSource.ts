import { MinecraftConfiguration } from './configuration';

export const configurationFileName = (configuration?: MinecraftConfiguration): string =>
    configuration?.source === 'velocity.toml' ? 'velocity.toml' : 'server.properties';

export const configurationTitle = (configuration: MinecraftConfiguration): string =>
    `${configuration.runtime_name || 'Minecraft'}${
        configuration.game_version ? ` ${configuration.game_version}` : ''
    } — ${configurationFileName(configuration)}`;

export const configurationSummary = (configuration: MinecraftConfiguration): [string, string] => [
    `Edit supported ${configurationFileName(configuration)} settings. Stop the server before saving.`,
    configuration.source === 'velocity.toml'
        ? 'Manage ports, backend routing and player forwarding in Files.'
        : configuration.version_source === 'bedrock_file'
        ? 'Use Bedrock maps and ASCII text. World changes require a backup.'
        : 'World changes require a backup and do not regenerate existing maps.',
];

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
