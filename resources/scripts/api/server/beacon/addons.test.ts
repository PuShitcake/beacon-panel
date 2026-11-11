import http from '@/api/http';
import { addonContext, addonPreview, addonSearch, installAddon, uninstallAddon } from './addons';

jest.mock('@/api/http', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() } }));
jest.mock('uuid', () => ({ v4: jest.fn(() => 'fixture-id') }));

beforeEach(() => jest.clearAllMocks());

it('bounds context reads without changing the global HTTP timeout', async () => {
    jest.mocked(http.get).mockResolvedValue({ data: { data: { ready: true } } });
    await expect(addonContext('server')).resolves.toEqual({ ready: true });
    expect(http.get).toHaveBeenCalledWith('/api/client/servers/server/beacon/addons/context', { timeout: 120000 });
});

it('forwards only the search query and page, never a URL or game/loader override', async () => {
    jest.mocked(http.get).mockResolvedValue({ data: { data: { projects: [] } } });
    await addonSearch('server', '', 20);
    expect(http.get).toHaveBeenCalledWith('/api/client/servers/server/beacon/addons/search', {
        params: { query: '', offset: 20 },
    });
});

it('previews a fixed CurseForge release without sending download URLs or credentials', async () => {
    jest.mocked(http.post).mockResolvedValue({ data: { data: { archive_id: 'prepared' } } });
    await addonPreview('server', '123:456');
    expect(http.post).toHaveBeenCalledWith(
        '/api/client/servers/server/beacon/addons/preview',
        { provider: 'curseforge', version_id: '123:456' },
        expect.objectContaining({
            headers: expect.objectContaining({ 'Idempotency-Key': 'fixture-id', 'X-Correlation-ID': 'fixture-id' }),
        })
    );
});

it.each([() => installAddon('server', 'prepared', 'Test'), () => uninstallAddon('server', 1)])(
    'never automatically replays a failed mutation',
    async (mutate) => {
        const error = { response: { status: 429 } };
        jest.mocked(http.post).mockRejectedValue(error);
        jest.mocked(http.delete).mockRejectedValue(error);
        await expect(mutate()).rejects.toBe(error);
        expect(jest.mocked(http.post).mock.calls.length + jest.mocked(http.delete).mock.calls.length).toBe(1);
    }
);
