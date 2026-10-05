import http from '@/api/http';
import { v4 as uuid } from 'uuid';

export type MinecraftNetworkService = 'rcon' | 'query';
export type MinecraftServiceAction = 'enable' | 'disable' | 'rotate';

export interface MinecraftServiceState {
    managed: boolean;
    enabled: boolean;
    status: 'enabled' | 'disabled' | 'attention';
    address: string | null;
    port: number | null;
    password_configured: boolean;
    drift: string | null;
}

export interface MinecraftServiceOperation {
    uuid: string;
    type: string;
    service: MinecraftNetworkService;
    action: MinecraftServiceAction;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    progress: { stage: string; percent: number; message: string } | null;
    result: Record<string, unknown> | null;
    error: { code: string; message: string } | null;
    created_at: string;
    finished_at: string | null;
}

export interface MinecraftServicesContext {
    enabled: boolean;
    authorized: boolean;
    automatic_power: boolean;
    rcon: MinecraftServiceState;
    query: MinecraftServiceState;
    active_operation: MinecraftServiceOperation | null;
}

const base = (server: string) => `/api/client/servers/${server}/beacon/minecraft-services`;
const mutationHeaders = () => ({
    'X-Correlation-ID': uuid(),
    'Idempotency-Key': uuid(),
});

export const getMinecraftServicesContext = async (server: string): Promise<MinecraftServicesContext> => {
    const { data } = await http.get(`${base(server)}/context`);
    return data.data;
};

export const getMinecraftServiceHistory = async (server: string): Promise<MinecraftServiceOperation[]> => {
    const { data } = await http.get(`${base(server)}/history`);
    return data.data;
};

export const getMinecraftServiceOperation = async (
    server: string,
    operation: string
): Promise<MinecraftServiceOperation> => {
    const { data } = await http.get(`${base(server)}/operations/${operation}`);
    return data.data;
};

export const mutateMinecraftService = async (
    server: string,
    service: MinecraftNetworkService,
    action: MinecraftServiceAction
): Promise<MinecraftServiceOperation> => {
    const { data } = await http.post(`${base(server)}/actions`, { service, action }, { headers: mutationHeaders() });
    return data.data;
};
