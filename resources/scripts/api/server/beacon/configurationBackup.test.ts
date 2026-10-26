import http from '@/api/http';
import createServerBackup from '@/api/server/backups/createServerBackup';
import getServerResourceUsage from '@/api/server/getServerResourceUsage';
import { prepareConfigurationBackup } from './configurationBackup';

jest.mock('@/api/http', () => ({ __esModule: true, default: { get: jest.fn() } }));
jest.mock('@/api/server/backups/createServerBackup');
jest.mock('@/api/server/getServerResourceUsage');

const configuration = {
    hash: 'a'.repeat(64),
    runtime_hash: 'b'.repeat(64),
    properties: {},
    definitions: {},
    world_backup_name: 'Beacon world settings fixture',
};
const complete = (overrides = {}) => ({
    attributes: {
        uuid: 'backup-fixture',
        is_successful: true,
        is_locked: true,
        bytes: 100,
        checksum: 'sha256:test',
        completed_at: '2026-01-01',
        created_at: '2026-01-01',
        ...overrides,
    },
});

beforeEach(() => {
    jest.resetAllMocks();
    (getServerResourceUsage as jest.Mock).mockResolvedValue({ status: 'offline' });
    (createServerBackup as jest.Mock).mockResolvedValue({ uuid: 'backup-fixture' });
    (http.get as jest.Mock).mockResolvedValue({ data: complete() });
});

test('creates one native locked backup, waits for completion and never writes configuration itself', async () => {
    const onCreated = jest.fn();
    expect(await prepareConfigurationBackup('server-fixture', configuration, () => true, onCreated)).toBe(
        'backup-fixture'
    );
    expect(createServerBackup).toHaveBeenCalledTimes(1);
    expect(createServerBackup).toHaveBeenCalledWith('server-fixture', {
        name: configuration.world_backup_name,
        ignored: '',
        isLocked: true,
    });
    expect(onCreated).toHaveBeenCalledWith('backup-fixture');
});

test('reuses this form backup after a failed save without creating another', async () => {
    await prepareConfigurationBackup('server-fixture', configuration, () => true, jest.fn(), 'backup-fixture');
    expect(createServerBackup).not.toHaveBeenCalled();
});

test.each([{ is_successful: false }, { is_locked: false }, { bytes: 0 }, { checksum: '' }])(
    'refuses incomplete safety evidence %p',
    async (overrides) => {
        (http.get as jest.Mock).mockResolvedValue({ data: complete(overrides) });
        await expect(
            prepareConfigurationBackup('server-fixture', configuration, () => true, jest.fn())
        ).rejects.toThrow('backup failed or is not locked');
    }
);

test('does not create a backup for a running server or a stale component', async () => {
    (getServerResourceUsage as jest.Mock).mockResolvedValue({ status: 'running' });
    await expect(prepareConfigurationBackup('server-fixture', configuration, () => true, jest.fn())).rejects.toThrow(
        'Stop the server'
    );
    await expect(prepareConfigurationBackup('server-fixture', configuration, () => false, jest.fn())).rejects.toThrow(
        'Configuration changed'
    );
    expect(createServerBackup).not.toHaveBeenCalled();
});

test('never retries a rejected native backup POST', async () => {
    (createServerBackup as jest.Mock).mockRejectedValue(new Error('Backup cooldown'));
    await expect(prepareConfigurationBackup('server-fixture', configuration, () => true, jest.fn())).rejects.toThrow(
        'Backup cooldown'
    );
    expect(createServerBackup).toHaveBeenCalledTimes(1);
    expect(http.get).not.toHaveBeenCalled();
});

test('a pending native backup must complete before returning approval', async () => {
    jest.useFakeTimers({ doNotFake: ['performance'] });
    try {
        (http.get as jest.Mock)
            .mockResolvedValueOnce({ data: complete({ completed_at: null }) })
            .mockResolvedValueOnce({ data: complete() });
        let done = false;
        const promise = prepareConfigurationBackup('server-fixture', configuration, () => true, jest.fn()).then(
            (uuid) => {
                done = true;
                return uuid;
            }
        );
        const flush = async () => {
            for (let i = 0; i < 20; i++) await Promise.resolve();
        };
        await flush();
        expect(done).toBe(false);
        expect(http.get).toHaveBeenCalledTimes(1);
        jest.advanceTimersByTime(5000);
        await flush();
        expect(await promise).toBe('backup-fixture');
        expect(http.get).toHaveBeenCalledTimes(2);
        expect(createServerBackup).toHaveBeenCalledTimes(1);
    } finally {
        jest.useRealTimers();
    }
});

test('navigation during backup creation prevents subsequent preparation', async () => {
    let current = true;
    await expect(
        prepareConfigurationBackup(
            'server-fixture',
            configuration,
            () => current,
            () => {
                current = false;
            }
        )
    ).rejects.toThrow('Configuration changed');
    expect(createServerBackup).toHaveBeenCalledTimes(1);
    expect(http.get).not.toHaveBeenCalled();
});
