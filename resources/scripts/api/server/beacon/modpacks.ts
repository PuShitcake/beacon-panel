import http from '@/api/http';
import { v4 as uuid } from 'uuid';

export type ModpackProviderKey = 'modrinth' | 'curseforge' | 'ftb' | 'atlauncher' | 'technic' | 'voidswrath';

export interface ModpackProviderStatus {
    key: ModpackProviderKey;
    name: string;
    configured: boolean;
    reason: string | null;
}

export interface ModpackProject {
    id: string;
    slug: string | null;
    name: string;
    description: string;
    author: string | null;
    icon_url: string | null;
    downloads: number;
    website_url: string | null;
}

export interface ModpackVersion {
    id: string;
    name: string;
    version: string;
    minecraft_version: string | null;
    loader: string | null;
    published_at: string | null;
    installable: boolean;
}

export interface ModpackInstallation {
    id: number;
    provider: ModpackProviderKey;
    project_id: string;
    project_slug: string | null;
    name: string;
    icon_url: string | null;
    version_id: string;
    version_name: string;
    minecraft_version: string;
    loader: string;
    loader_version: string | null;
    status: string;
    installed_at: string;
}

export interface ModpackOperation {
    uuid: string;
    type: string;
    history_label: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    delete_files: boolean;
    progress: { stage: string; percent: number; message: string } | null;
    error: { code: string; message: string } | null;
    result: Record<string, unknown> | null;
    created_at: string;
    finished_at: string | null;
}

export interface ModpackContext {
    enabled: boolean;
    authorized: boolean;
    providers: ModpackProviderStatus[];
    installation: ModpackInstallation | null;
    active_operation: ModpackOperation | null;
    requires_stopped_server: boolean;
    backup_before_mutation: boolean;
}

export interface ModpackSearchResult {
    page: number;
    page_size: number;
    total: number;
    projects: ModpackProject[];
}

const base = (server: string) => `/api/client/servers/${server}/beacon/modpacks`;
const mutationHeaders = () => ({
    'X-Correlation-ID': uuid(),
    'Idempotency-Key': uuid(),
});

export const getModpackContext = async (server: string): Promise<ModpackContext> => {
    const { data } = await http.get(`${base(server)}/context`);
    return data.data;
};

export const searchModpacks = async (
    server: string,
    provider: ModpackProviderKey,
    query: string,
    page: number,
    pageSize: number
): Promise<ModpackSearchResult> => {
    const normalizedQuery = query.trim();
    const { data } = await http.get(`${base(server)}/search`, {
        params: {
            provider,
            ...(normalizedQuery ? { query: normalizedQuery } : {}),
            page,
            page_size: pageSize,
        },
    });
    return data.data;
};

export const getModpackVersions = async (
    server: string,
    provider: ModpackProviderKey,
    project: string
): Promise<ModpackVersion[]> => {
    const { data } = await http.get(
        `${base(server)}/providers/${provider}/projects/${encodeURIComponent(project)}/versions`
    );
    return data.data;
};

export const getModpackHistory = async (server: string): Promise<ModpackOperation[]> => {
    const { data } = await http.get(`${base(server)}/history`);
    return data.data;
};

export const getModpackOperation = async (server: string, operation: string): Promise<ModpackOperation> => {
    const { data } = await http.get(`${base(server)}/operations/${operation}`);
    return data.data;
};

export const retryModpackOperation = async (server: string, operation: string): Promise<ModpackOperation> => {
    const { data } = await http.post(
        `${base(server)}/operations/${operation}/retry`,
        {},
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const installModpack = async (
    server: string,
    payload: {
        provider: ModpackProviderKey;
        project_id: string;
        version_id: string;
        delete_files: boolean;
        confirmation: string | null;
    }
): Promise<ModpackOperation> => {
    const { data } = await http.post(`${base(server)}/install`, payload, { headers: mutationHeaders() });
    return data.data;
};

export const updateModpack = async (
    server: string,
    installation: number,
    version: string
): Promise<ModpackOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/update`,
        { version_id: version },
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const reinstallModpack = async (server: string, installation: number): Promise<ModpackOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/reinstall`,
        {},
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const restoreModpack = async (server: string, installation: number): Promise<ModpackOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/restore`,
        {},
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const uninstallModpack = async (server: string, installation: number): Promise<ModpackOperation> => {
    const { data } = await http.delete(`${base(server)}/installations/${installation}`, {
        headers: mutationHeaders(),
    });
    return data.data;
};
