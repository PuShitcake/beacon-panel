import http from '@/api/http';
import { v4 as uuid } from 'uuid';
import { retryPluginRead } from '@/api/server/beacon/retryPluginRead';

export interface AddonPack {
    uuid: string;
    name: string;
    version: number[];
    minimum_engine?: number[];
    directory: 'behavior_packs' | 'resource_packs';
    scripts: boolean;
}
export interface AddonPreview {
    archive_id: string;
    provider: 'curseforge' | 'upload';
    packs: AddonPack[];
    total_bytes: number;
    file_count: number;
}
export interface AddonInstallation {
    id: number;
    world: string;
    provider: string;
    packs: AddonPack[];
}
export interface AddonOperation {
    uuid: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    progress: { stage: string; percent: number; message: string } | null;
    error: { code: string; message: string } | null;
}
export interface AddonContext {
    enabled: boolean;
    ready: boolean;
    unavailable_reason: string | null;
    version: string | null;
    worlds: string[];
    curseforge_available: boolean;
    installations: AddonInstallation[];
    active_operation: AddonOperation | null;
}
export interface AddonProject {
    id: string;
    title: string;
    description: string;
    downloads: number;
}
export interface AddonVersion {
    id: string;
    name: string;
    game_versions: string[];
}

const base = (server: string) => `/api/client/servers/${server}/beacon/addons`;
const metadata = () => ({ 'X-Correlation-ID': uuid(), 'Idempotency-Key': uuid() });
export const addonContext = (server: string): Promise<AddonContext> =>
    retryPluginRead(async () => (await http.get(`${base(server)}/context`, { timeout: 120000 })).data.data);
export const addonSearch = async (
    server: string,
    query: string,
    offset = 0
): Promise<{ projects: AddonProject[]; offset: number; total: number }> =>
    (await http.get(`${base(server)}/search`, { params: { query, offset } })).data.data;
export const addonVersions = async (server: string, project: string): Promise<AddonVersion[]> =>
    (await http.get(`${base(server)}/projects/${encodeURIComponent(project)}/versions`)).data.data;
export const addonPreview = async (server: string, selection: File | string): Promise<AddonPreview> => {
    if (typeof selection === 'string') {
        return (
            await http.post(
                `${base(server)}/preview`,
                { provider: 'curseforge', version_id: selection },
                { headers: metadata(), timeout: 120000 }
            )
        ).data.data;
    }
    const form = new FormData();
    form.append('provider', 'upload');
    form.append('file', selection);
    return (
        await http.post(`${base(server)}/preview`, form, {
            headers: { ...metadata(), 'Content-Type': 'multipart/form-data' },
            timeout: 120000,
        })
    ).data.data;
};
export const installAddon = async (server: string, archive: string, world: string): Promise<AddonOperation> =>
    (
        await http.post(
            `${base(server)}/install`,
            { archive_id: archive, world, acknowledge: true },
            { headers: metadata(), timeout: 120000 }
        )
    ).data.data;
export const uninstallAddon = async (server: string, installation: number): Promise<AddonOperation> =>
    (await http.delete(`${base(server)}/installations/${installation}`, { headers: metadata(), timeout: 120000 })).data
        .data;
export const addonOperation = async (server: string, operation: string): Promise<AddonOperation> =>
    (await http.get(`${base(server)}/operations/${operation}`)).data.data;
