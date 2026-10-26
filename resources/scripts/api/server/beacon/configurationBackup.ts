import http from '@/api/http';
import createServerBackup from '@/api/server/backups/createServerBackup';
import getServerResourceUsage from '@/api/server/getServerResourceUsage';
import { rawDataToServerBackup } from '@/api/transformers';
import { MinecraftConfiguration } from './configuration';

export const prepareConfigurationBackup = async (
    server: string,
    configuration: MinecraftConfiguration,
    current: () => boolean,
    onCreated: (uuid: string) => void,
    existing?: string
): Promise<string> => {
    const assertOffline = async () => {
        if (!current()) throw new Error('Configuration changed. Reload before saving.');
        if ((await getServerResourceUsage(server)).status !== 'offline') {
            throw new Error('Stop the server and keep it offline until the world settings have been saved.');
        }
        if (!current()) throw new Error('Configuration changed. Reload before saving.');
    };
    if (!configuration.world_backup_name) throw new Error('Reload Configuration before changing world settings.');
    await assertOffline();
    // Native backup creation enforces backup permissions, quota and cooldown. Never retry
    // its POST automatically and never delete an existing backup to make room.
    let uuid = existing;
    if (!uuid) {
        const backup = await createServerBackup(server, {
            name: configuration.world_backup_name,
            ignored: '',
            isLocked: true,
        });
        uuid = backup.uuid;
        onCreated(uuid);
    }
    for (let attempt = 0; attempt < 180; attempt++) {
        await assertOffline();
        const { data } = await http.get(`/api/client/servers/${server}/backups/${uuid}`);
        const backup = rawDataToServerBackup(data);
        if (backup.completedAt) {
            if (!backup.isSuccessful || !backup.isLocked || !backup.checksum || backup.bytes < 1) {
                throw new Error('The full safety backup failed or is not locked. No world settings were changed.');
            }
            await assertOffline();
            return uuid;
        }
        await new Promise((resolve) => setTimeout(resolve, 5000));
    }
    throw new Error('The safety backup is still pending. No settings were changed; check Backups before retrying.');
};
