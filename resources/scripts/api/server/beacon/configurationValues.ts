import { MinecraftConfiguration } from './configuration';

export const configurationChanges = (original: MinecraftConfiguration, edited: MinecraftConfiguration) =>
    Object.fromEntries(
        Object.entries(edited.properties).filter(([key, value]) => String(value) !== String(original.properties[key]))
    );

export const validConfigurationChanges = (
    configuration: MinecraftConfiguration,
    changes: MinecraftConfiguration['properties']
) =>
    Object.entries(changes).every(([key, value]) => {
        const definition = configuration.definitions[key];
        if (!definition) return false;
        switch (definition.type) {
            case 'integer':
                return (
                    /^-?\d{1,9}$/.test(String(value)) &&
                    Number(value) >= definition.min! &&
                    Number(value) <= definition.max!
                );
            case 'boolean':
                return typeof value === 'boolean';
            case 'select':
                return definition.options?.includes(String(value)) === true;
            default:
                // Intentionally reject control characters before Java Properties serialization.
                // eslint-disable-next-line no-control-regex
                if (
                    typeof value !== 'string' ||
                    Array.from(value).length > (definition.max_length ?? 120) ||
                    // eslint-disable-next-line no-control-regex
                    /[\x00-\x1f\x7f]/.test(value)
                )
                    return false;
                if (definition.format === 'world-folder') {
                    return (
                        /^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test(value) &&
                        ![
                            'con',
                            'prn',
                            'aux',
                            'nul',
                            'com1',
                            'lpt1',
                            'libraries',
                            'versions',
                            'mods',
                            'plugins',
                            'logs',
                            'config',
                            'backups',
                            'resourcepacks',
                            'datapacks',
                            'cache',
                        ].includes(value.toLowerCase())
                    );
                }
                if (definition.format === 'bedrock-name' || definition.format === 'bedrock-text') {
                    return (
                        /^[\x20-\x7e]*$/.test(value) &&
                        value.trim() === value &&
                        !/["\\]/.test(value) &&
                        !value.includes('${') &&
                        (definition.format !== 'bedrock-name' || !value.includes(';'))
                    );
                }
                if (definition.format === 'bedrock-world-folder') {
                    return (
                        /^[A-Za-z0-9](?:[A-Za-z0-9 _-]{0,62}[A-Za-z0-9_-])?$/.test(value) &&
                        !/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i.test(value)
                    );
                }
                if (definition.format === 'sha1') return value === '' || /^[a-f\d]{40}$/i.test(value);
                if (definition.format === 'resource-url' && value !== '') {
                    try {
                        const url = new URL(value);
                        return (
                            url.protocol === 'https:' &&
                            !url.username &&
                            !url.password &&
                            !url.hash &&
                            !url.port &&
                            url.hostname.includes('.') &&
                            !/^[\d.]+$/.test(url.hostname) &&
                            !url.hostname.includes(':') &&
                            !/(^|\.)(localhost|local|internal|invalid|test)$/i.test(url.hostname) &&
                            !/[\s\\]/.test(value)
                        );
                    } catch {
                        return false;
                    }
                }
                return true;
        }
    });
