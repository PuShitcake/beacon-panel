import { configurationChanges, validConfigurationChanges } from './configurationValues';
import { MinecraftConfiguration } from './configuration';

const configuration: MinecraftConfiguration = {
    hash: 'a'.repeat(64),
    runtime_hash: 'b'.repeat(64),
    properties: { motd: 'Hello', 'max-players': 20, pvp: false },
    definitions: {
        motd: { type: 'string', label: 'MOTD' },
        'max-players': { type: 'integer', label: 'Players', min: 1, max: 1000 },
        pvp: { type: 'boolean', label: 'PvP' },
    },
};

test('sends only explicit changes, without rewriting untouched values or numeric aliases', () => {
    expect(
        configurationChanges(configuration, {
            ...configuration,
            properties: { ...configuration.properties, 'max-players': '20' },
        })
    ).toEqual({});
    expect(
        configurationChanges(configuration, { ...configuration, properties: { ...configuration.properties, motd: '' } })
    ).toEqual({ motd: '' });
});

test.each(['', ' ', '0', '1.5', 'NaN', '1001', true])(
    'rejects invalid numeric input %p without coercing it to zero',
    (value) => {
        expect(validConfigurationChanges(configuration, { 'max-players': value })).toBe(false);
    }
);

test.each([20, '20', '1', 1000])('accepts explicit valid numeric input %p', (value) => {
    expect(validConfigurationChanges(configuration, { 'max-players': value })).toBe(true);
});

test('preserves empty MOTD, false booleans, and rejects secret/unknown/newline fields', () => {
    expect(validConfigurationChanges(configuration, { motd: '', pvp: false })).toBe(true);
    expect(validConfigurationChanges(configuration, { pvp: 'false' })).toBe(false);
    expect(validConfigurationChanges(configuration, { 'rcon.password': 'never' })).toBe(false);
    expect(validConfigurationChanges(configuration, { motd: 'hello\nserver-port=1' })).toBe(false);
});

const worldConfiguration: MinecraftConfiguration = {
    ...configuration,
    definitions: {
        'level-name': { type: 'string', format: 'world-folder', max_length: 64, label: 'World' },
        'level-seed': { type: 'string', max_length: 128, label: 'Seed' },
        'resource-pack': { type: 'string', format: 'resource-url', max_length: 2048, label: 'URL' },
        'resource-pack-sha1': { type: 'string', format: 'sha1', max_length: 40, label: 'Hash' },
    },
};

test.each(['../map', '/map', 'my/map', '.beacon', 'plugins', 'bad name', '', 'CON'])(
    'rejects unsafe world folder %p',
    (value) => {
        expect(validConfigurationChanges(worldConfiguration, { 'level-name': value })).toBe(false);
    }
);

test('accepts imported map folder, exact long seed and clearing optional fields', () => {
    expect(
        validConfigurationChanges(worldConfiguration, {
            'level-name': 'uploaded-map',
            'level-seed': '1234567890123456789',
            'resource-pack': '',
            'resource-pack-sha1': '',
        })
    ).toBe(true);
    expect(
        validConfigurationChanges(worldConfiguration, {
            'resource-pack': 'https://example.com/pack.zip',
            'resource-pack-sha1': 'a'.repeat(40),
        })
    ).toBe(true);
});

test.each([
    'http://example.com/a',
    'https://user:pass@example.com/a',
    'https://127.0.0.1/a',
    'https://127.1/a',
    'https://0x7f.0.0.1/a',
    'https://host.local/a',
    'https://example.com:8000/a',
    'https://example.com/a#fragment',
])('rejects unsafe resource URL %p', (value) => {
    expect(validConfigurationChanges(worldConfiguration, { 'resource-pack': value })).toBe(false);
});
