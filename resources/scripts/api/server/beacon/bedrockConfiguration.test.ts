import { MinecraftConfiguration } from './configuration';
import { validConfigurationChanges } from './configurationValues';
import { configurationVersionSourceLabel } from './configurationSource';

const configuration: MinecraftConfiguration = {
    hash: 'fixture',
    properties: {},
    definitions: {
        'level-name': { type: 'string', format: 'bedrock-world-folder', label: 'World folder name', max_length: 64 },
        'server-name': { type: 'string', format: 'bedrock-name', label: 'Server name', max_length: 120 },
        'level-seed': { type: 'string', format: 'bedrock-text', label: 'Seed' },
        'tick-distance': { type: 'integer', label: 'Tick distance', min: 4, max: 12 },
    },
};

test.each(['Bedrock level', 'new-map', '1', '01'])('accepts safe Bedrock world folder %s', (folder) => {
    expect(validConfigurationChanges(configuration, { 'level-name': folder })).toBe(true);
});

test.each(['../map', 'worlds/map', 'map\\sub', '.hidden', 'map ', ' map', 'CON', 'COM9', ''])(
    'rejects unsafe Bedrock folder %s',
    (folder) => {
        expect(validConfigurationChanges(configuration, { 'level-name': folder })).toBe(false);
    }
);

test('does not confuse Java and Bedrock fields or number types', () => {
    expect(validConfigurationChanges(configuration, { 'server-name': 'Beacon = name' })).toBe(true);
    expect(validConfigurationChanges(configuration, { 'server-name': 'ไทย' })).toBe(false);
    expect(validConfigurationChanges(configuration, { 'server-name': 'name;spoof' })).toBe(false);
    expect(validConfigurationChanges(configuration, { 'tick-distance': 4 })).toBe(true);
    expect(validConfigurationChanges(configuration, { 'tick-distance': 3 })).toBe(false);
    expect(validConfigurationChanges(configuration, { 'tick-distance': '' })).toBe(false);
    expect(validConfigurationChanges(configuration, { motd: 'Java' })).toBe(false);
});

test.each(['ไทย', 'a"b', 'a\\b', ' leading', 'trailing ', '${private}', ''])(
    'validates Bedrock text before a mutation: %s',
    (text) => {
        expect(validConfigurationChanges(configuration, { 'level-seed': text })).toBe(text === '');
    }
);

test('does not invent official-JAR or installed-version provenance for Bedrock', () => {
    expect(
        configurationVersionSourceLabel({ ...configuration, version_source: 'bedrock_file', game_version: null })
    ).toBeUndefined();
    expect(
        configurationVersionSourceLabel({ ...configuration, version_source: 'bedrock_file', game_version: 'latest' })
    ).toBeUndefined();
});
