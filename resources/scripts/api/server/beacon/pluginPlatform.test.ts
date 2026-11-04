import {
    DEFAULT_PLUGIN_PROVIDER,
    pluginRuntimeLabel,
    velocityProviderNotice,
} from '@/api/server/beacon/pluginPlatform';

describe('plugin platform identity', () => {
    it('uses CurseForge as the unconditional initial selection for every server', () => {
        expect(DEFAULT_PLUGIN_PROVIDER).toBe('curseforge');
    });

    it('never labels a proxy version as Minecraft', () => {
        expect(pluginRuntimeLabel({ loader: 'velocity', game_version: null, platform_version: '4.3.1' })).toBe(
            'Velocity 4.3.1'
        );
        expect(pluginRuntimeLabel({ loader: 'paper', game_version: '1.21.1' })).toBe('Minecraft 1.21.1 - paper');
        expect(pluginRuntimeLabel({ loader: 'velocity', game_version: '1.21.1' })).toBe('Velocity version unavailable');
    });

    it('distinguishes candidate files and platform declarations from exact proxy-version support', () => {
        expect(velocityProviderNotice('velocity', 'curseforge')).toContain('results are candidates');
        expect(velocityProviderNotice('velocity', 'modrinth')).toContain('Velocity-tagged');
        expect(velocityProviderNotice('velocity', 'modrinth')).toContain('not JAR inspection');
        expect(velocityProviderNotice('velocity', 'curseforge')).toContain('does not inspect the JAR');
        expect(velocityProviderNotice('velocity', 'hangar')).toBeNull();
        expect(velocityProviderNotice('paper', 'curseforge')).toBeNull();
    });
});
