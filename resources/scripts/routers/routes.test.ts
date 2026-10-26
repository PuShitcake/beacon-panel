import routes from './routes';

// Keep route metadata regression tests independent of page components and their side effects.
jest.mock('@/components/server/console/ServerConsoleContainer', () => () => null);
jest.mock('@/components/server/databases/DatabasesContainer', () => () => null);
jest.mock('@/components/server/schedules/ScheduleContainer', () => () => null);
jest.mock('@/components/server/users/UsersContainer', () => () => null);
jest.mock('@/components/server/backups/BackupContainer', () => () => null);
jest.mock('@/components/server/network/NetworkContainer', () => () => null);
jest.mock('@/components/server/startup/StartupContainer', () => () => null);
jest.mock('@/components/server/files/FileManagerContainer', () => () => null);
jest.mock('@/components/server/settings/SettingsContainer', () => () => null);
jest.mock('@/components/dashboard/AccountOverviewContainer', () => () => null);
jest.mock('@/components/dashboard/AccountApiContainer', () => () => null);
jest.mock('@/components/dashboard/ssh/AccountSSHContainer', () => () => null);
jest.mock('@/components/dashboard/activity/ActivityLogContainer', () => () => null);
jest.mock('@/components/server/ServerActivityLogContainer', () => () => null);
jest.mock('@/components/server/beacon/PluginInstallerContainer', () => () => null);
jest.mock('@/components/server/beacon/ConfigurationContainer', () => () => null);
jest.mock('@/components/server/beacon/ModInstallerContainer', () => () => null);

it('gates Plugin Installer navigation using the backend plugins capability', () => {
    expect(routes.server.find((route) => route.path === '/plugins')).toMatchObject({
        name: 'Plugin Installer',
        beaconCapability: 'plugins',
        ownerOnly: true,
    });
});

it('gates Mod Installer navigation using the backend mods capability', () => {
    expect(routes.server.find((route) => route.path === '/mods')).toMatchObject({
        name: 'Mod Installer',
        beaconCapability: 'mods',
        ownerOnly: true,
    });
});

it('preserves the existing Configuration navigation capability', () => {
    expect(routes.server.find((route) => route.path === '/configuration')?.beaconCapability).toBe('configuration');
});
