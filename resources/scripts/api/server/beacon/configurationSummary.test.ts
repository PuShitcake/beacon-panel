import { MinecraftConfiguration } from './configuration';
import { configurationSummary } from './configurationSource';

const configuration: MinecraftConfiguration = {
    hash: 'fixture',
    source: 'server.properties',
    properties: {},
    definitions: {},
    notes: ['Runtime-specific safety information.'],
};

it.each([
    'Vanilla Minecraft',
    'Paper',
    'Paper + Geyser + Floodgate',
    'Fabric',
    'CurseForge',
    'Vanilla Bedrock',
    'Velocity',
])('keeps %s help to two short lines without changing runtime notes', (runtime) => {
    const current: MinecraftConfiguration = {
        ...configuration,
        runtime_name: runtime,
        source: runtime === 'Velocity' ? 'velocity.toml' : 'server.properties',
        version_source: runtime === 'Vanilla Bedrock' ? 'bedrock_file' : undefined,
    };
    const before = JSON.stringify(current);
    const summary = configurationSummary(current);
    expect(summary).toHaveLength(2);
    expect(summary[0]).toContain('Stop the server before saving.');
    expect(summary[0]).toContain(current.source);
    expect(summary.every((line) => line.length <= 100)).toBe(true);
    expect(summary.join(' ')).not.toContain('Runtime-specific safety information.');
    expect(JSON.stringify(current)).toBe(before);
});

it('keeps the Bedrock ASCII and map warning visible', () => {
    const [, caution] = configurationSummary({ ...configuration, version_source: 'bedrock_file' });
    expect(caution).toContain('Bedrock maps and ASCII text');
    expect(caution).toContain('World changes require a backup.');
});

it('does not describe Velocity as a world or server.properties editor', () => {
    const summary = configurationSummary({ ...configuration, source: 'velocity.toml' }).join(' ');
    expect(summary).toContain('backend routing and player forwarding in Files');
    expect(summary).not.toContain('world');
    expect(summary).not.toContain('server.properties');
});
