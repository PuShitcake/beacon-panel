import http from '@/api/http';
import { v4 as uuid } from 'uuid';

export interface BeaconContentContext {
    enabled: boolean;
    software?: string;
    software_name?: string;
    unavailable_reason?: string | null;
    profile?: { code: string; name: string; loader: string; content_directory: 'plugins' | 'mods' };
    version?: { version: string; loader_version: string };
    project_type?: 'plugin' | 'mod';
    requires_stopped_server?: boolean;
    backup_before_mutation?: boolean;
}

export interface BeaconProject {
    id: string;
    slug: string | null;
    title: string;
    description: string;
    author: string;
    project_type: string;
    icon_url: string | null;
    downloads: number;
}

export interface BeaconVersion {
    id: string;
    project_id: string;
    name: string;
    version_number: string;
    version_type: string;
    game_versions: string[];
    loaders: string[];
    file: { filename: string; size: number; sha512: string };
}

export interface BeaconInstallation {
    id: number;
    project_id: string;
    version_id: string;
    filename: string;
    loader: string;
    game_version: string;
    size: number;
    status: string;
}

export interface BeaconOperation {
    uuid: string;
    type: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    error: { code: string; message: string } | null;
}

export interface BeaconInstallPlan {
    provider: 'modrinth';
    project_type: 'plugin' | 'mod';
    loader: string;
    game_version: string;
    files: Array<{
        project_id: string;
        version_id: string;
        name: string;
        version_number: string;
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

const base = (server: string) => `/api/client/servers/${server}/beacon/content`;
const mutationHeaders = () => ({
    'X-Correlation-ID': uuid(),
    'Idempotency-Key': uuid(),
});

export const getContentContext = async (server: string): Promise<BeaconContentContext> => {
    const { data } = await http.get(`${base(server)}/context`);
    return data.data;
};

export const getInstallations = async (server: string): Promise<BeaconInstallation[]> => {
    const { data } = await http.get(`${base(server)}/installations`);
    return data.data;
};

export const searchContent = async (server: string, query: string): Promise<BeaconProject[]> => {
    const { data } = await http.get(`${base(server)}/search`, { params: { query } });
    return data.data.projects;
};

export const getProjectVersions = async (server: string, project: string): Promise<BeaconVersion[]> => {
    const { data } = await http.get(`${base(server)}/projects/${encodeURIComponent(project)}/versions`);
    return data.data;
};

export const getInstallPlan = async (server: string, version: string): Promise<BeaconInstallPlan> => {
    const { data } = await http.get(`${base(server)}/plan`, { params: { version_id: version } });
    return data.data;
};

export const installContent = async (server: string, version: string): Promise<BeaconOperation> => {
    const { data } = await http.post(
        `${base(server)}/install`,
        { version_id: version },
        { headers: mutationHeaders() }
    );
    return data.data;
};

export const removeContent = async (server: string, installation: number): Promise<BeaconOperation> => {
    const { data } = await http.delete(`${base(server)}/installations/${installation}`, {
        headers: mutationHeaders(),
    });
    return data.data;
};

export const getContentOperation = async (server: string, operation: string): Promise<BeaconOperation> => {
    const { data } = await http.get(`${base(server)}/operations/${operation}`);
    return data.data;
};
