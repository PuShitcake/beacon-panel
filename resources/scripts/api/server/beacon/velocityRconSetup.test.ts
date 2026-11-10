import {
    consumeVelocirconSetup,
    isVelocirconSetup,
    selectSetupVersions,
    velocirconSetupLink,
} from './velocityRconSetup';
import type { PluginVersion } from './plugins';

const version = (id: string, number: string, project = 'trusted-project') =>
    ({ id, version_number: number, project_id: project } as PluginVersion);

it('uses fixed navigation state and preserves the instance pathname without a query remount', () => {
    expect(velocirconSetupLink('instance-id')).toEqual({
        pathname: '/server/instance-id/plugins',
        state: { beaconPluginSetup: 'velocircon' },
    });
    expect(isVelocirconSetup(velocirconSetupLink('instance-id').state)).toBe(true);
    for (const invalid of [null, undefined, '?setup=velocircon', [], {}, { beaconPluginSetup: 'other' }]) {
        expect(isVelocirconSetup(invalid)).toBe(false);
    }
});

it('consumes only the one-shot setup marker, without mutating unrelated route state', () => {
    const original = { beaconPluginSetup: 'velocircon', unrelated: 'preserved' };
    const remaining = consumeVelocirconSetup(original);
    expect(remaining).toEqual({ unrelated: 'preserved' });
    expect(isVelocirconSetup(remaining)).toBe(false);
    expect(isVelocirconSetup(original)).toBe(true);
});

it('selects only the exact backend-required version, not the newest release', () => {
    const supported = version('supported', '1.0.7');
    expect(selectSetupVersions([version('newest', '2.0.0'), supported], 'trusted-project', '1.0.7')).toEqual([
        supported,
    ]);
});

it('rejects missing, foreign-project and ambiguous releases without a fallback', () => {
    expect(() => selectSetupVersions([], '', '1.0.7')).toThrow();
    expect(() => selectSetupVersions([], 'trusted-project', '')).toThrow();
    expect(() => selectSetupVersions([version('newest', '2.0.0')], 'trusted-project', '1.0.7')).toThrow();
    expect(() => selectSetupVersions([version('wrong', '1.0.7', 'other')], 'trusted-project', '1.0.7')).toThrow();
    expect(() =>
        selectSetupVersions([version('a', '1.0.7'), version('b', '1.0.7')], 'trusted-project', '1.0.7')
    ).toThrow();
});
