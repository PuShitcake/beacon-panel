import http from '@/api/http';
import { getPluginVersionOptions, getPluginVersions } from '@/api/server/beacon/plugins';

jest.mock('@/api/http', () => ({ __esModule: true, default: { get: jest.fn() } }));
jest.mock('uuid', () => ({ v4: jest.fn(() => 'fixture-request-id') }));
const get = http.get as jest.Mock;

beforeEach(() => jest.clearAllMocks());

it('returns an ordinary unavailable explanation without retrying or installing', async () => {
    const reason = 'No installable version is available. The required file checksum is missing.';
    get.mockResolvedValue({ data: { data: [], unavailable_reason: reason } });
    await expect(getPluginVersionOptions('server', '42025')).resolves.toEqual({
        versions: [],
        unavailableReason: reason,
    });
    expect(get).toHaveBeenCalledTimes(1);
    expect(get).toHaveBeenCalledWith('/api/client/servers/server/beacon/plugins/projects/42025/versions', {
        params: { provider: 'curseforge' },
    });
});

it.each(['curseforge', 'modrinth', 'hangar'] as const)('preserves normal %s version responses', async (provider) => {
    const versions = [{ id: 'release', project_id: 'project' }];
    get.mockResolvedValue({ data: { data: versions } });
    await expect(getPluginVersionOptions('server', 'project', provider)).resolves.toEqual({
        versions,
        unavailableReason: null,
    });
    await expect(getPluginVersions('server', 'project', provider)).resolves.toEqual(versions);
});

it('does not turn real provider errors into an empty version list', async () => {
    const error = { response: { status: 422 } };
    get.mockRejectedValue(error);
    await expect(getPluginVersionOptions('server', 'project')).rejects.toBe(error);
    expect(get).toHaveBeenCalledTimes(1);
});
