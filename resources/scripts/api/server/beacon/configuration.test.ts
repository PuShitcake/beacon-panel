import http from '@/api/http';
import { getMinecraftConfiguration, updateMinecraftConfiguration } from './configuration';

jest.mock('@/api/http', () => ({ __esModule: true, default: { get: jest.fn(), put: jest.fn() } }));

test('configuration alone gets a bounded timeout long enough for installed-JAR verification', async () => {
    const configuration = {
        hash: 'a'.repeat(64),
        runtime_hash: 'b'.repeat(64),
        definitions: {},
        properties: { motd: 'old' },
    };
    (http.get as jest.Mock).mockResolvedValue({ data: { data: configuration } });
    (http.put as jest.Mock).mockResolvedValue({ data: { data: configuration } });
    expect(await getMinecraftConfiguration('fixture')).toEqual(configuration);
    expect(http.get).toHaveBeenCalledWith('/api/client/servers/fixture/beacon/configuration', { timeout: 120000 });
    await updateMinecraftConfiguration('fixture', configuration, { motd: 'new' });
    expect(http.put).toHaveBeenCalledTimes(1);
    expect(http.put).toHaveBeenCalledWith(
        '/api/client/servers/fixture/beacon/configuration',
        {
            hash: configuration.hash,
            runtime_hash: configuration.runtime_hash,
            properties: { motd: 'new' },
        },
        { timeout: 120000 }
    );
});
