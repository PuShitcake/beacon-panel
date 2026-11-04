import { configurationFileName, configurationTitle } from './configurationSource';
import { configurationChanges, validConfigurationChanges } from './configurationValues';
import { MinecraftConfiguration } from './configuration';

const configuration: MinecraftConfiguration = {
    hash: 'a'.repeat(64),
    source: 'velocity.toml',
    runtime_name: 'Velocity',
    game_version: null,
    definitions: {
        motd: { type: 'string', label: 'MOTD', max_length: 512 },
        'advanced.compression-level': { type: 'integer', label: 'Compression level', min: -1, max: 9 },
    },
    properties: { motd: 'Proxy', 'advanced.compression-level': -1 },
};

it('uses the proxy filename/title without a fabricated Minecraft version', () => {
    expect(configurationFileName(configuration)).toBe('velocity.toml');
    expect(configurationTitle(configuration)).toBe('Velocity — velocity.toml');
    expect(configurationFileName()).toBe('server.properties');
});

it('validates and diffs actual scalar proxy settings using the shared form', () => {
    const changed = { ...configuration, properties: { ...configuration.properties, motd: 'ทดสอบ "quoted"' } };
    expect(configurationChanges(configuration, changed)).toEqual({ motd: 'ทดสอบ "quoted"' });
    expect(validConfigurationChanges(configuration, { 'advanced.compression-level': '9' })).toBe(true);
    expect(validConfigurationChanges(configuration, { 'advanced.compression-level': '10' })).toBe(false);
    expect(validConfigurationChanges(configuration, { motd: 'bad\nline' })).toBe(false);
    expect(validConfigurationChanges(configuration, { 'forwarding-secret': 'hidden' })).toBe(false);
});
