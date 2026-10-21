import http from '@/api/http';
import { setImmediate as realSetImmediate } from 'timers';
import {
    getPluginContext,
    getPluginHistory,
    getPluginInstallations,
    installPlugin,
    updatePlugin,
    reinstallPlugin,
    uninstallPlugin,
} from '@/api/server/beacon/plugins';

jest.mock('@/api/http', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() } }));
jest.mock('uuid', () => ({ v4: () => '11111111-1111-4111-8111-111111111111' }));

describe('plugin refresh and mutation separation', () => {
    beforeEach(() => {
        jest.useFakeTimers({ doNotFake: ['performance'] });
        jest.resetAllMocks();
    });
    afterEach(() => jest.useRealTimers());

    it.each([getPluginContext, getPluginHistory, getPluginInstallations])(
        'retries only the throttled detail GET',
        async (read) => {
            jest.mocked(http.get)
                .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '1' } } })
                .mockResolvedValueOnce({ data: { data: { confirmed: true } } });
            const pending = read('server-uuid');
            await new Promise<void>((resolve) => realSetImmediate(resolve));
            jest.advanceTimersByTime(1000);
            await expect(pending).resolves.toEqual({ confirmed: true });
            expect(http.get).toHaveBeenCalledTimes(2);
            expect(http.post).not.toHaveBeenCalled();
            expect(http.delete).not.toHaveBeenCalled();
        }
    );

    it.each([
        () => installPlugin('server-uuid', '4:16671', 'hangar'),
        () => updatePlugin('server-uuid', 1, '4:16671', 'hangar'),
        () => reinstallPlugin('server-uuid', 1),
        () => uninstallPlugin('server-uuid', 1),
    ])('never automatically replays a failed mutation', async (mutate) => {
        const failure = { response: { status: 429, headers: { 'retry-after': '1' } } };
        jest.mocked(http.post).mockRejectedValue(failure);
        jest.mocked(http.delete).mockRejectedValue(failure);
        await expect(mutate()).rejects.toBe(failure);
        expect(jest.getTimerCount()).toBe(0);
        expect(jest.mocked(http.post).mock.calls.length + jest.mocked(http.delete).mock.calls.length).toBe(1);
        expect(http.get).not.toHaveBeenCalled();
    });
});
