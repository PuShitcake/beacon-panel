import React, { useEffect, useRef, useState } from 'react';
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
import { Dialog } from '@/components/elements/dialog';
import { httpErrorToHuman } from '@/api/http';
import {
    getMinecraftConfiguration,
    MinecraftConfiguration,
    updateMinecraftConfiguration,
} from '@/api/server/beacon/configuration';
import { configurationChanges, validConfigurationChanges } from '@/api/server/beacon/configurationValues';
import { prepareConfigurationBackup } from '@/api/server/beacon/configurationBackup';
import { configurationVersionSourceLabel } from '@/api/server/beacon/configurationSource';
import { usePermissions } from '@/plugins/usePermissions';

const MODPACK_REQUIRED_MESSAGE = 'Install a modpack before using Minecraft Configuration.';

const ConfigurationContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const backupPermissions = usePermissions(['backup.create', 'backup.read', 'backup.delete']);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [configuration, setConfiguration] = useState<MinecraftConfiguration>();
    const [original, setOriginal] = useState<MinecraftConfiguration>();
    const [unavailableReason, setUnavailableReason] = useState<string>();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [confirmWorld, setConfirmWorld] = useState(false);
    const [backupPending, setBackupPending] = useState(false);
    const savingRef = useRef(false);
    const backupRef = useRef<{ hash: string; uuid: string }>();
    const generation = useRef(0);
    const [reload, setReload] = useState(0);

    const handleConfigurationError = (error: unknown) => {
        const message = httpErrorToHuman(error);
        if (message === MODPACK_REQUIRED_MESSAGE) {
            setConfiguration(undefined);
            setUnavailableReason(message);
            clearFlashes('beacon-configuration');
        } else {
            clearAndAddHttpError({ key: 'beacon-configuration', error });
        }
    };

    useEffect(() => {
        const current = ++generation.current;
        setLoading(true);
        setSaving(false);
        savingRef.current = false;
        setConfirmWorld(false);
        setBackupPending(false);
        backupRef.current = undefined;
        clearFlashes('beacon-configuration');
        setConfiguration(undefined);
        setOriginal(undefined);
        setUnavailableReason(undefined);
        getMinecraftConfiguration(server)
            .then((data) => {
                if (generation.current !== current) return;
                setConfiguration(data);
                setOriginal(data);
            })
            .catch((error) => generation.current === current && handleConfigurationError(error))
            .finally(() => generation.current === current && setLoading(false));
        return () => {
            ++generation.current;
        };
    }, [server, reload]);

    const setProperty = (key: string, value: string | number | boolean) => {
        setConfiguration((current) =>
            current
                ? {
                      ...current,
                      properties: {
                          ...current.properties,
                          [key]: value,
                          ...(key === 'resource-pack' && current.definitions['resource-pack-sha1']
                              ? { 'resource-pack-sha1': '' }
                              : {}),
                      },
                  }
                : current
        );
    };

    const save = async (worldConfirmed = false) => {
        if (!configuration || !original || configuration.editable === false || savingRef.current) return;
        const current = generation.current;
        const changes = configurationChanges(original, configuration);
        if ('resource-pack' in changes && configuration.definitions['resource-pack-sha1']) {
            changes['resource-pack-sha1'] = configuration.properties['resource-pack-sha1'];
        }
        if (!Object.keys(changes).length) return;
        if (!validConfigurationChanges(configuration, changes)) {
            addFlash({
                key: 'beacon-configuration',
                type: 'error',
                message:
                    'Choose valid values before saving. Numeric settings cannot be empty; check world names, URLs and hashes.',
            });
            return;
        }
        const changesWorld = Object.keys(changes).some((key) => configuration.definitions[key]?.world_setting);
        if (changesWorld && !backupPermissions.every(Boolean)) {
            addFlash({
                key: 'beacon-configuration',
                type: 'error',
                message:
                    'World changes require permission to create, read and lock backups. Ask the server owner or administrator.',
            });
            return;
        }
        if (changesWorld && !worldConfirmed) {
            setConfirmWorld(true);
            return;
        }
        setConfirmWorld(false);
        savingRef.current = true;
        setSaving(true);
        clearFlashes('beacon-configuration');
        try {
            let backupUuid: string | undefined;
            if (changesWorld) {
                setBackupPending(true);
                backupUuid = await prepareConfigurationBackup(
                    server,
                    configuration,
                    () => generation.current === current,
                    (uuid) => {
                        backupRef.current = { hash: configuration.hash, uuid };
                    },
                    backupRef.current?.hash === configuration.hash ? backupRef.current.uuid : undefined
                );
                if (generation.current !== current) return;
                setBackupPending(false);
            }
            const data = await updateMinecraftConfiguration(server, configuration, changes, backupUuid);
            if (generation.current !== current) return;
            setConfiguration(data);
            setOriginal(data);
            addFlash({ key: 'beacon-configuration', type: 'success', message: 'Configuration saved.' });
        } catch (error) {
            if (generation.current === current) handleConfigurationError(error);
        } finally {
            if (generation.current === current) {
                setSaving(false);
                setBackupPending(false);
                savingRef.current = false;
            }
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    return (
        <ServerContentBlock title={'Minecraft Configuration'}>
            <Dialog.Confirm
                open={confirmWorld}
                title={'Save world settings?'}
                confirm={'Back up and save'}
                onClose={() => setConfirmWorld(false)}
                onConfirmed={() => void save(true)}
            >
                <p className={'text-sm text-gray-200 leading-relaxed'}>
                    Keep this server offline. A completed, locked full backup and a free backup slot are required.
                    Existing world folders will not be renamed or deleted. Changing the folder selects an uploaded map
                    or creates a new world on the next start. Seed and generator settings do not regenerate an existing
                    map. The server will not be started automatically.
                </p>
            </Dialog.Confirm>
            <FlashMessageRender byKey={'beacon-configuration'} css={tw`mb-4`} />
            {backupPending && (
                <p role={'status'} css={tw`text-sm text-yellow-200 mb-4`}>
                    Waiting for the full safety backup. No settings have been changed yet.
                </p>
            )}
            {unavailableReason || configuration?.editable === false ? (
                <ContentBox
                    title={
                        configuration?.state === 'waiting'
                            ? 'Waiting for server.properties'
                            : 'Minecraft Configuration unavailable'
                    }
                >
                    <p css={tw`text-sm text-neutral-300`}>{unavailableReason || configuration?.unavailable_reason}</p>
                    <Button css={tw`mt-4`} onClick={() => setReload((value) => value + 1)}>
                        Refresh configuration
                    </Button>
                </ContentBox>
            ) : configuration ? (
                <ContentBox
                    title={
                        configuration.game_version
                            ? `${configuration.runtime_name || 'Vanilla Minecraft'} ${
                                  configuration.game_version
                              } — server.properties`
                            : `${configuration.runtime_name || 'Minecraft'} — server.properties`
                    }
                    showLoadingOverlay={saving}
                >
                    <p css={tw`text-xs text-yellow-200 mb-5`}>
                        Only Beacon-approved settings are shown. Stop the server before saving changes.
                    </p>
                    {configuration.source === 'server.properties' && (
                        <p css={tw`text-xs text-neutral-300 mb-4`}>
                            Fields and values come from the existing server.properties on this instance. Missing
                            settings are not added and no default values are applied.
                        </p>
                    )}
                    {configurationVersionSourceLabel(configuration) && (
                        <p css={tw`text-xs text-neutral-300 mb-4`}>{configurationVersionSourceLabel(configuration)}</p>
                    )}
                    {configuration.notes?.map((note) => (
                        <p key={note} css={tw`text-sm text-neutral-200 mb-4`}>
                            {note}
                        </p>
                    ))}
                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-5`}>
                        {Object.keys(configuration.definitions).length === 0 && (
                            <p css={tw`text-sm text-neutral-300`}>
                                This file contains no supported editable settings. No defaults have been added.
                            </p>
                        )}
                        {Object.entries(configuration.definitions).map(([key, definition]) => {
                            const value = configuration.properties[key];
                            const validOption =
                                definition.type === 'boolean'
                                    ? typeof value === 'boolean'
                                    : definition.options?.includes(String(value));
                            const id = `configuration-${key}`;
                            return (
                                <div key={key}>
                                    <Label htmlFor={id}>{definition.label}</Label>
                                    {definition.type === 'boolean' ? (
                                        <Select
                                            id={id}
                                            value={validOption ? String(value) : '__unchanged__'}
                                            onChange={(event) => setProperty(key, event.currentTarget.value === 'true')}
                                        >
                                            {!validOption && (
                                                <option value={'__unchanged__'} disabled>
                                                    Existing value is not recognized (kept unchanged)
                                                </option>
                                            )}
                                            <option value={'true'}>Enabled</option>
                                            <option value={'false'}>Disabled</option>
                                        </Select>
                                    ) : definition.type === 'select' ? (
                                        <Select
                                            id={id}
                                            value={validOption ? String(value) : '__unchanged__'}
                                            onChange={(event) => setProperty(key, event.currentTarget.value)}
                                        >
                                            {!validOption && (
                                                <option value={'__unchanged__'} disabled>
                                                    Existing value is not recognized (kept unchanged)
                                                </option>
                                            )}
                                            {definition.options?.map((option) => (
                                                <option value={option} key={option}>
                                                    {definition.option_labels?.[option] ?? option}
                                                </option>
                                            ))}
                                        </Select>
                                    ) : (
                                        <Input
                                            id={id}
                                            type={definition.type === 'integer' ? 'number' : 'text'}
                                            min={definition.min}
                                            max={definition.max}
                                            maxLength={definition.max_length}
                                            value={String(value ?? '')}
                                            onChange={(event) => setProperty(key, event.currentTarget.value)}
                                        />
                                    )}
                                    {definition.description && (
                                        <p css={tw`text-xs text-neutral-300 mt-2`}>{definition.description}</p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <Can action={'file.update'}>
                        <div css={tw`flex justify-end mt-6`}>
                            <Button
                                type={'button'}
                                onClick={() => void save()}
                                isLoading={saving}
                                disabled={
                                    !original || Object.keys(configurationChanges(original, configuration)).length === 0
                                }
                            >
                                Save changes
                            </Button>
                        </div>
                    </Can>
                </ContentBox>
            ) : (
                <Button onClick={() => setReload((value) => value + 1)}>Retry configuration</Button>
            )}
        </ServerContentBlock>
    );
};

export default ConfigurationContainer;
