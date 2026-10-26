import { MinecraftConfiguration } from './configuration';
import { configurationVersionSourceLabel } from './configurationSource';

const configuration: MinecraftConfiguration = {
    hash: 'fixture',
    properties: {},
    definitions: {},
    game_version: '1.20.1',
};

it('describes the verified CurseForge manifest without claiming an official JAR hash check', () => {
    const label = configurationVersionSourceLabel({ ...configuration, version_source: 'verified_curseforge_manifest' });
    expect(label).toContain('verified installed CurseForge manifest');
    expect(label).not.toContain('official server JAR');
});

it('keeps the actual JAR source for Vanilla, Paper and manual Fabric adapters', () => {
    expect(configurationVersionSourceLabel({ ...configuration, version_source: 'official_server_jar' })).toContain(
        'installed official server JAR'
    );
});

it('does not invent provenance from a name, an old response or an unrecognized source', () => {
    expect(configurationVersionSourceLabel({ ...configuration, runtime_name: 'Vanilla Minecraft' })).toBeUndefined();
    expect(
        configurationVersionSourceLabel({
            ...configuration,
            version_source: 'unknown',
        } as unknown as MinecraftConfiguration)
    ).toBeUndefined();
});

it('does not show a version claim in the waiting state', () => {
    expect(
        configurationVersionSourceLabel({
            ...configuration,
            game_version: null,
            version_source: 'verified_curseforge_manifest',
        })
    ).toBeUndefined();
});
