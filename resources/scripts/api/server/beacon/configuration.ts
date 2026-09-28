import http from '@/api/http';

export interface MinecraftPropertyDefinition {
    type: 'string' | 'integer' | 'boolean' | 'select';
    label: string;
    min?: number;
    max?: number;
    options?: string[];
}

export interface MinecraftConfiguration {
    hash: string;
    properties: Record<string, string | number | boolean>;
    definitions: Record<string, MinecraftPropertyDefinition>;
}

export const getMinecraftConfiguration = async (server: string): Promise<MinecraftConfiguration> => {
    const { data } = await http.get(`/api/client/servers/${server}/beacon/configuration`);
    return data.data;
};

export const updateMinecraftConfiguration = async (
    server: string,
    configuration: MinecraftConfiguration
): Promise<MinecraftConfiguration> => {
    const { data } = await http.put(`/api/client/servers/${server}/beacon/configuration`, {
        hash: configuration.hash,
        properties: configuration.properties,
    });
    return data.data;
};
