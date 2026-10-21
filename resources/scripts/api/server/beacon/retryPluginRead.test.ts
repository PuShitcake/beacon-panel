import { retryPluginRead } from '@/api/server/beacon/retryPluginRead';
import { setImmediate as realSetImmediate } from 'timers';

const advance = async (milliseconds: number) => {
    await new Promise<void>((resolve) => realSetImmediate(resolve));
    jest.advanceTimersByTime(milliseconds);
    await new Promise<void>((resolve) => realSetImmediate(resolve));
};

describe('plugin detail read retries', () => {
    beforeEach(() => jest.useFakeTimers({ doNotFake: ['performance'] }));
    afterEach(() => jest.useRealTimers());

    it('returns a successful read without waiting', async () => {
        const read = jest.fn().mockResolvedValue({ status: 'succeeded' });
        await expect(retryPluginRead(read)).resolves.toEqual({ status: 'succeeded' });
        expect(read).toHaveBeenCalledTimes(1);
        expect(jest.getTimerCount()).toBe(0);
    });

    it.each([429, 500, 502, 503, 504])('recovers from HTTP %s without changing the result', async (status) => {
        const read = jest.fn().mockRejectedValueOnce({ response: { status } }).mockResolvedValue('confirmed');
        const pending = retryPluginRead(read);
        await advance(4999);
        expect(read).toHaveBeenCalledTimes(1);
        await advance(1);
        await expect(pending).resolves.toBe('confirmed');
        expect(read).toHaveBeenCalledTimes(2);
    });

    it('honors Retry-After seconds instead of polling the content limiter early', async () => {
        const read = jest
            .fn()
            .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '60' } } })
            .mockResolvedValue('fresh');
        const pending = retryPluginRead(read);
        await advance(59999);
        expect(read).toHaveBeenCalledTimes(1);
        await advance(1);
        await expect(pending).resolves.toBe('fresh');
    });

    it('honors Retry-After HTTP dates', async () => {
        jest.setSystemTime(new Date('2026-01-01T00:00:00Z'));
        const read = jest
            .fn()
            .mockRejectedValueOnce({
                response: { status: 503, headers: { 'Retry-After': 'Thu, 01 Jan 2026 00:00:20 GMT' } },
            })
            .mockResolvedValue(true);
        const pending = retryPluginRead(read);
        await advance(19999);
        expect(read).toHaveBeenCalledTimes(1);
        await advance(1);
        await expect(pending).resolves.toBe(true);
    });

    it('uses bounded backoff when Retry-After is malformed', async () => {
        const read = jest
            .fn()
            .mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': 'invalid' } } })
            .mockResolvedValue(true);
        const pending = retryPluginRead(read);
        await advance(5000);
        await expect(pending).resolves.toBe(true);
    });

    it('does not retry earlier than an unsupported long server cooldown', async () => {
        const failure = { response: { status: 429, headers: { 'retry-after': '600' } } };
        const read = jest.fn().mockRejectedValue(failure);
        await expect(retryPluginRead(read)).rejects.toBe(failure);
        expect(read).toHaveBeenCalledTimes(1);
        expect(jest.getTimerCount()).toBe(0);
    });

    it('retries only a bounded number of times when the server stays unavailable', async () => {
        const failure = { response: { status: 429, headers: { 'retry-after': '1' } } };
        const read = jest.fn().mockRejectedValue(failure);
        const assertion = expect(retryPluginRead(read)).rejects.toBe(failure);
        for (let attempt = 0; attempt < 3; attempt++) await advance(1000);
        await assertion;
        expect(read).toHaveBeenCalledTimes(4);
        expect(jest.getTimerCount()).toBe(0);
    });

    it.each([400, 401, 403, 404, 409, 422])('does not retry HTTP %s', async (status) => {
        const failure = { response: { status } };
        const read = jest.fn().mockRejectedValue(failure);
        await expect(retryPluginRead(read)).rejects.toBe(failure);
        expect(read).toHaveBeenCalledTimes(1);
    });

    it('retries an Axios network failure but does not hide programming errors', async () => {
        const read = jest.fn().mockRejectedValueOnce({ isAxiosError: true }).mockResolvedValue(true);
        const pending = retryPluginRead(read);
        await advance(5000);
        await expect(pending).resolves.toBe(true);
        const failure = new Error('synthetic programming failure');
        const broken = jest.fn().mockRejectedValue(failure);
        await expect(retryPluginRead(broken)).rejects.toBe(failure);
        expect(broken).toHaveBeenCalledTimes(1);
    });
});
