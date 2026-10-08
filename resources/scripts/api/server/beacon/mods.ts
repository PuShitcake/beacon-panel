import http from '@/api/http';
import { v4 as uuid } from 'uuid';

export interface ModContext {
    enabled: boolean;
    authorized: boolean;
    software: string;
    software_name: string;
    unavailable_reason: string | null;
    source: 'modpack' | 'catalog' | null;
    loader: 'forge' | 'neoforge' | 'fabric' | 'quilt' | null;
    loader_version: string | null;
    game_version: string | null;
    categories: string[];
    active_operation: ModOperation | null;
    automatic_power: boolean;
    backup_before_mutation: boolean;
}

export interface ModProject {
    id: string;
    slug: string | null;
    title: string;
    description: string;
    author: string;
    icon_url: string | null;
    downloads: number;
    server_side: string | null;
}

export interface ModVersion {
    id: string;
    project_id: string;
    project_name: string;
    name: string;
    version_number: string;
    version_type: string;
    game_versions: string[];
    loaders: string[];
    file: { filename: string; size: number; sha512: string };
}

export interface ModInstallation {
    id: number;
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

export interface ModOperation {
    uuid: string;
    type: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    progress: { stage: string; percent: number; message: string } | null;
    result: Record<string, unknown> | null;
    error: { code: string; message: string } | null;
    created_at: string;
    finished_at: string | null;
}

export interface ModInstallPlan {
    provider: 'modrinth';
    project_type: 'mod';
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
        sha512: string;
        dependency: boolean;
    }>;
    optional_dependencies: Array<{ project_id?: string; version_id?: string }>;
    incompatible_dependencies: Array<{ project_id?: string; version_id?: string }>;
    resolved_dependencies: string[];
    total_bytes: number;
}

const base = (server: string) => `/api/client/servers/${server}/beacon/mods`;
const mutationHeaders = () => ({
    'X-Correlation-ID': uuid(),
    'Idempotency-Key': uuid(),
});

export const getModContext = async (server: string): Promise<ModContext> => {
    const { data } = await http.get(`${base(server)}/context`);
    return data.data;
};

export const getModInstallations = async (server: string): Promise<ModInstallation[]> => {
    const { data } = await http.get(`${base(server)}/installations`);
    return data.data;
};

export const getModHistory = async (server: string): Promise<ModOperation[]> => {
    const { data } = await http.get(`${base(server)}/history`);
    return data.data;
};

export const searchMods = async (
    server: string,
    query: string,
    offset = 0,
    category = ''
): Promise<{ projects: ModProject[]; offset: number; limit: number; total: number }> => {
    const { data } = await http.get(`${base(server)}/search`, {
        params: { query, offset, ...(category ? { category } : {}) },
    });
    return data.data;
};

export const getModVersions = async (server: string, project: string): Promise<ModVersion[]> => {
    const { data } = await http.get(`${base(server)}/projects/${encodeURIComponent(project)}/versions`);
    return data.data;
};

export const getModPlan = async (server: string, version: string): Promise<ModInstallPlan> => {
    const { data } = await http.get(`${base(server)}/plan`, { params: { version_id: version } });
    return data.data;
};

export const installMod = async (server: string, version: string): Promise<ModOperation> => {
    const { data } = await http.post(
        `${base(server)}/install`,
        { version_id: version },
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const updateMod = async (server: string, installation: number, version: string): Promise<ModOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/update`,
        { version_id: version },
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const reinstallMod = async (server: string, installation: number): Promise<ModOperation> => {
    const { data } = await http.post(
        `${base(server)}/installations/${installation}/reinstall`,
        {},
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const uninstallMod = async (server: string, installation: number): Promise<ModOperation> => {
    const { data } = await http.delete(`${base(server)}/installations/${installation}`, {
        headers: mutationHeaders(),
    });
    return data.data;
};

export const getModOperation = async (server: string, operation: string): Promise<ModOperation> => {
    const { data } = await http.get(`${base(server)}/operations/${operation}`);
    return data.data;
};
