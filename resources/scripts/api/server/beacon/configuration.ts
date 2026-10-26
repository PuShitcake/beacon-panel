import http from '@/api/http';

export interface MinecraftPropertyDefinition {
    type: 'string' | 'integer' | 'boolean' | 'select';
    label: string;
    min?: number;
    max?: number;
    options?: string[];
    option_labels?: Record<string, string>;
    max_length?: number;
    format?: 'world-folder' | 'bedrock-world-folder' | 'bedrock-name' | 'bedrock-text' | 'resource-url' | 'sha1';
    description?: string;
    world_setting?: boolean;
}

export interface MinecraftConfiguration {
    runtime_name?: string;
    version_source?: 'official_server_jar' | 'verified_curseforge_manifest' | 'bedrock_file';
    hash: string;
    properties: Record<string, string | number | boolean>;
    definitions: Record<string, MinecraftPropertyDefinition>;
    runtime_hash?: string;
    game_version?: string | null;
    editable?: boolean;
    unavailable_reason?: string | null;
    notes?: string[];
    state?: 'ready' | 'waiting' | 'unavailable';
    source?: 'server.properties';
    world_backup_name?: string;
}

export const getMinecraftConfiguration = async (server: string): Promise<MinecraftConfiguration> => {
    // Vanilla verifies a bounded installed JAR over Wings; the shared 20s UI default
    // is too short on remote nodes. Keep this bounded and scoped to Configuration.
    const { data } = await http.get(`/api/client/servers/${server}/beacon/configuration`, { timeout: 120000 });
    return data.data;
};

export const updateMinecraftConfiguration = async (
    server: string,
    configuration: MinecraftConfiguration,
    changes = configuration.properties,
    worldBackupUuid?: string
): Promise<MinecraftConfiguration> => {
    const { data } = await http.put(
        `/api/client/servers/${server}/beacon/configuration`,
        {
            hash: configuration.hash,
            runtime_hash: configuration.runtime_hash,
            properties: changes,
            ...(worldBackupUuid ? { world_backup_uuid: worldBackupUuid } : {}),
        },
        { timeout: 120000 }
    );
    return data.data;
};
