import type { PluginVersion } from '@/api/server/beacon/plugins';

export const isVelocirconSetup = (state: unknown): boolean =>
    !!state &&
    typeof state === 'object' &&
    !Array.isArray(state) &&
    (state as { beaconPluginSetup?: unknown }).beaconPluginSetup === 'velocircon';

export const velocirconSetupLink = (server: string) => ({
    pathname: `/server/${encodeURIComponent(server)}/plugins`,
    state: { beaconPluginSetup: 'velocircon' },
});

export const consumeVelocirconSetup = (state: unknown): Record<string, unknown> => {
    const remaining = { ...(state as Record<string, unknown>) };
    delete remaining.beaconPluginSetup;

    return remaining;
};

export const selectSetupVersions = (versions: PluginVersion[], project: string, requiredVersion: string) => {
    if (!project || !requiredVersion) {
        throw new Error('Velocircon setup information is incomplete. No plugin was installed.');
    }
    const matching = versions.filter(
        (version) => version.project_id === project && version.version_number === requiredVersion
    );
    if (matching.length !== 1) {
        throw new Error('The required Velocircon release is unavailable or ambiguous. No plugin was installed.');
    }

    return matching;
};
