import http from '@/api/http';
import { v4 as uuid } from 'uuid';

export type VersionSoftware = 'vanilla' | 'paper' | 'forge';

export interface VersionTarget {
    software: VersionSoftware;
    minecraft_version: string;
    build_uuid: string;
    build_name: string;
    build_number: number;
    loader_version: string | null;
    java_version: number;
}

export interface CurrentVersion {
    software: string;
    software_name: string;
    minecraft_version: string | null;
    build: string | null;
    image: string;
}

export interface VersionOperation {
    uuid: string;
    type: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    current: CurrentVersion | null;
    target: VersionTarget | null;
    progress: { stage: string; percent: number; message: string } | null;
    error: { code: string; message: string } | null;
    result: Record<string, unknown> | null;
    created_at: string;
    finished_at: string | null;
}

export interface VersionContext {
    enabled: boolean;
    available: boolean;
    unavailable_reason: string | null;
    current: CurrentVersion;
    software: { key: VersionSoftware; name: string }[];
    active_operation: VersionOperation | null;
    backup_required: boolean;
    managed_modpack: boolean;
}

export interface MinecraftVersion {
    version: string;
    java: number;
    latest_build: string;
    latest_build_uuid: string;
}

export interface MinecraftBuild {
    uuid: string;
    name: string;
    build_number: number;
    project_version: string | null;
    created_at: string | null;
}

const base = (server: string) => `/api/client/servers/${server}/beacon/versions`;
const mutationHeaders = () => ({ 'X-Correlation-ID': uuid(), 'Idempotency-Key': uuid() });

export const getVersionContext = async (server: string): Promise<VersionContext> => {
    const { data } = await http.get(`${base(server)}/context`);
    return data.data;
};

export const getMinecraftVersions = async (server: string, software: VersionSoftware): Promise<MinecraftVersion[]> => {
    const { data } = await http.get(`${base(server)}/software/${software}`);
    return data.data;
};

export const getMinecraftBuilds = async (
    server: string,
    software: VersionSoftware,
    version: string
): Promise<MinecraftBuild[]> => {
    const { data } = await http.get(`${base(server)}/software/${software}/${encodeURIComponent(version)}`);
    return data.data;
};

export const getVersionHistory = async (server: string): Promise<VersionOperation[]> => {
    const { data } = await http.get(`${base(server)}/history`);
    return data.data;
};

export const getVersionOperation = async (server: string, operation: string): Promise<VersionOperation> => {
    const { data } = await http.get(`${base(server)}/operations/${operation}`);
    return data.data;
};

export const changeMinecraftVersion = async (
    server: string,
    software: VersionSoftware,
    version: string,
    build: string
): Promise<VersionOperation> => {
    const { data } = await http.post(
        `${base(server)}/change`,
        { software, version, build_uuid: build },
        { headers: mutationHeaders() }
    );
    return data.data;
};
