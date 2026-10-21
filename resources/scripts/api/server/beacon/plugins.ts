import http from '@/api/http';
import { v4 as uuid } from 'uuid';
import { retryPluginRead } from '@/api/server/beacon/retryPluginRead';

export type PluginProvider = 'curseforge' | 'modrinth' | 'hangar';

export interface PluginContext {
    enabled: boolean;
    authorized: boolean;
    software: string;
    software_name: string;
    unavailable_reason: string | null;
    source: 'native_plugin' | null;
    loader: 'paper' | 'spigot' | 'bukkit' | null;
    loader_version: string | null;
    game_version: string | null;
    provider: PluginProvider;
    providers: Array<{ id: PluginProvider; name: string; available: boolean }>;
    provider_available: boolean;
    provider_unavailable_reason: string | null;
    categories: Array<{ value: string; label: string }>;
    active_operation: PluginOperation | null;
    automatic_power: boolean;
    backup_before_mutation: boolean;
}

export interface PluginProject {
    id: string;
    slug: string | null;
    title: string;
    description: string;
    author: string;
    icon_url: string | null;
    downloads: number;
    server_side: string | null;
}

export interface PluginVersion {
    id: string;
    project_id: string;
    project_name: string;
    name: string;
    version_number: string;
    version_type: string;
    game_versions: string[];
    loaders: string[];
    file: { filename: string; size: number; sha512: string | null; sha1?: string };
}

export interface PluginInstallation {
    id: number;
    provider: PluginProvider;
    project_id: string;
    project_name: string | null;
    version_id: string;
    version_number: string | null;
    icon_url: string | null;
    filename: string;
    loader: string;
    game_version: string;
    size: number;
    dependencies: string[];
    status: string;
    disabled_path: string | null;
}

export interface PluginOperation {
    uuid: string;
    type: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    progress: { stage: string; percent: number; message: string } | null;
    result: Record<string, unknown> | null;
    error: { code: string; message: string } | null;
    created_at: string;
    finished_at: string | null;
}

export interface PluginInstallPlan {
    provider: PluginProvider;
    project_type: 'plugin';
    loader: string;
    game_version: string;
    files: Array<{
        project_id: string;
        project_name: string;
        version_id: string;
        version_number: string;
        icon_url: string | null;
        filename: string;
        size: number;
        sha512: string | null;
        sha1?: string;
        dependency: boolean;
    }>;
    optional_dependencies: Array<{ project_id?: string; version_id?: string }>;
    incompatible_dependencies: Array<{ project_id?: string; version_id?: string }>;
    resolved_dependencies: string[];
    total_bytes: number;
}

const base = (server: string) => `/api/client/servers/${server}/beacon/plugins`;
const mutationHeaders = () => ({
    'X-Correlation-ID': uuid(),
    'Idempotency-Key': uuid(),
});

export const getPluginContext = async (
    server: string,
    provider: PluginProvider = 'curseforge'
): Promise<PluginContext> => {
    return retryPluginRead(async () => {
        const { data } = await http.get(`${base(server)}/context`, { params: { provider } });
        return data.data;
    });
};

export const getPluginInstallations = async (server: string): Promise<PluginInstallation[]> => {
    return retryPluginRead(async () => {
        const { data } = await http.get(`${base(server)}/installations`);
        return data.data;
    });
};

export const getPluginHistory = async (server: string): Promise<PluginOperation[]> => {
    return retryPluginRead(async () => {
        const { data } = await http.get(`${base(server)}/history`);
        return data.data;
    });
};

export const searchPlugins = async (
    server: string,
    query: string,
    offset = 0,
    category = '',
    provider: PluginProvider = 'curseforge'
): Promise<{ projects: PluginProject[]; offset: number; limit: number; total: number }> => {
    const { data } = await http.get(`${base(server)}/search`, {
        params: { provider, query, offset, ...(category ? { category } : {}) },
    });
    return data.data;
};

export const getPluginVersions = async (
    server: string,
    project: string,
    provider: PluginProvider = 'curseforge'
): Promise<PluginVersion[]> => {
    const { data } = await http.get(`${base(server)}/projects/${encodeURIComponent(project)}/versions`, {
        params: { provider },
    });
    return data.data;
};

export const getPluginPlan = async (
    server: string,
    version: string,
    provider: PluginProvider = 'curseforge'
): Promise<PluginInstallPlan> => {
    const { data } = await http.get(`${base(server)}/plan`, { params: { provider, version_id: version } });
    return data.data;
};

export const installPlugin = async (
    server: string,
    version: string,
    provider: PluginProvider = 'curseforge'
): Promise<PluginOperation> => {
    const { data } = await http.post(
        `${base(server)}/install`,
        { provider, version_id: version },
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const updatePlugin = async (
    server: string,
    installation: number,
    version: string,
    provider: PluginProvider = 'curseforge'
): Promise<PluginOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/update`,
        { provider, version_id: version },
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const reinstallPlugin = async (server: string, installation: number): Promise<PluginOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/reinstall`,
        {},
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const uninstallPlugin = async (server: string, installation: number): Promise<PluginOperation> => {
    const { data } = await http.delete(`${base(server)}/installations/${installation}`, {
        headers: mutationHeaders(),
    });
    return data.data;
};

export const getPluginOperation = async (server: string, operation: string): Promise<PluginOperation> => {
    const { data } = await http.get(`${base(server)}/operations/${operation}`);
    return data.data;
};
