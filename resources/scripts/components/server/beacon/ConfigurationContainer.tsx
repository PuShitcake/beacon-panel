import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/components/FlashMessageRender';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import ContentBox from '@/components/elements/ContentBox';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Label from '@/components/elements/Label';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import Can from '@/components/elements/Can';
import {
    getMinecraftConfiguration,
    MinecraftConfiguration,
    updateMinecraftConfiguration,
} from '@/api/server/beacon/configuration';

const ConfigurationContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [configuration, setConfiguration] = useState<MinecraftConfiguration>();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        clearFlashes('beacon-configuration');
        getMinecraftConfiguration(server)
            .then(setConfiguration)
            .catch((error) => clearAndAddHttpError({ key: 'beacon-configuration', error }))
            .finally(() => setLoading(false));
    }, [server]);

    const setProperty = (key: string, value: string | number | boolean) => {
        setConfiguration((current) =>
            current ? { ...current, properties: { ...current.properties, [key]: value } } : current
        );
    };

    const save = async () => {
        if (!configuration) return;
        setSaving(true);
        clearFlashes('beacon-configuration');
        try {
            setConfiguration(await updateMinecraftConfiguration(server, configuration));
            addFlash({ key: 'beacon-configuration', type: 'success', message: 'Configuration saved.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-configuration', error });
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    return (
        <ServerContentBlock title={'Minecraft Configuration'}>
            <FlashMessageRender byKey={'beacon-configuration'} css={tw`mb-4`} />
            {configuration && (
                <ContentBox title={'server.properties'} showLoadingOverlay={saving}>
                    <p css={tw`text-xs text-yellow-200 mb-5`}>
                        Only Beacon-approved settings are shown. Stop the server before saving changes.
                    </p>
                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-5`}>
                        {Object.entries(configuration.definitions).map(([key, definition]) => {
                            const value = configuration.properties[key];
                            return (
                                <div key={key}>
                                    <Label>{definition.label}</Label>
                                    {definition.type === 'boolean' ? (
                                        <Select
                                            value={String(value ?? false)}
                                            onChange={(event) => setProperty(key, event.currentTarget.value === 'true')}
                                        >
                                            <option value={'true'}>Enabled</option>
                                            <option value={'false'}>Disabled</option>
                                        </Select>
                                    ) : definition.type === 'select' ? (
                                        <Select
                                            value={String(value ?? '')}
                                            onChange={(event) => setProperty(key, event.currentTarget.value)}
                                        >
                                            {definition.options?.map((option) => (
                                                <option value={option} key={option}>
                                                    {option}
                                                </option>
                                            ))}
                                        </Select>
                                    ) : (
                                        <Input
                                            type={definition.type === 'integer' ? 'number' : 'text'}
                                            min={definition.min}
                                            max={definition.max}
                                            value={String(value ?? '')}
                                            onChange={(event) =>
                                                setProperty(
                                                    key,
                                                    definition.type === 'integer'
                                                        ? Number(event.currentTarget.value)
                                                        : event.currentTarget.value
                                                )
                                            }
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <Can action={'file.update'}>
                        <div css={tw`flex justify-end mt-6`}>
                            <Button type={'button'} onClick={save} isLoading={saving}>
                                Save changes
                            </Button>
                        </div>
                    </Can>
                </ContentBox>
            )}
        </ServerContentBlock>
    );
};

export default ConfigurationContainer;
