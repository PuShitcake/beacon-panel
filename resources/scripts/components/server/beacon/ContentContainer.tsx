import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import ContentBox from '@/components/elements/ContentBox';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import Can from '@/components/elements/Can';
import { Dialog } from '@/components/elements/dialog';
import {
    BeaconContentContext,
    BeaconInstallation,
    BeaconInstallPlan,
    BeaconOperation,
    BeaconProject,
    BeaconVersion,
    getContentContext,
    getContentOperation,
    getInstallations,
    getInstallPlan,
    getProjectVersions,
    installContent,
    removeContent,
    searchContent,
} from '@/api/server/beacon/content';

const ContentContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [context, setContext] = useState<BeaconContentContext>();
    const [installations, setInstallations] = useState<BeaconInstallation[]>([]);
    const [projects, setProjects] = useState<BeaconProject[]>([]);
    const [versions, setVersions] = useState<BeaconVersion[]>([]);
    const [query, setQuery] = useState('');
    const [project, setProject] = useState<BeaconProject>();
    const [version, setVersion] = useState('');
    const [plan, setPlan] = useState<BeaconInstallPlan>();
    const [pendingRemoval, setPendingRemoval] = useState<BeaconInstallation | null>(null);
    const [loading, setLoading] = useState(true);
    const [working, setWorking] = useState(false);

    const reloadInstallations = () => getInstallations(server).then(setInstallations);

    useEffect(() => {
        clearFlashes('beacon-content');
        getContentContext(server)
            .then((value) => {
                setContext(value);
                return value.enabled ? reloadInstallations() : undefined;
            })
            .catch((error) => clearAndAddHttpError({ key: 'beacon-content', error }))
            .finally(() => setLoading(false));
    }, [server]);

    const awaitOperation = async (initial: BeaconOperation) => {
        let operation = initial;
        for (let attempt = 0; attempt < 180 && ['pending', 'running'].includes(operation.status); attempt++) {
            await new Promise((resolve) => window.setTimeout(resolve, 2000));
            operation = await getContentOperation(server, operation.uuid);
        }
        if (operation.status !== 'succeeded') {
            throw new Error(operation.error?.message || 'The content operation did not complete.');
        }
        await reloadInstallations();
    };

    const search = async () => {
        setWorking(true);
        clearFlashes('beacon-content');
        try {
            setProjects(await searchContent(server, query));
            setProject(undefined);
            setVersions([]);
            setVersion('');
            setPlan(undefined);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-content', error });
        } finally {
            setWorking(false);
        }
    };

    const chooseProject = async (selected: BeaconProject) => {
        setWorking(true);
        clearFlashes('beacon-content');
        try {
            const available = await getProjectVersions(server, selected.id);
            setProject(selected);
            setVersions(available);
            setVersion(available[0]?.id || '');
            setPlan(undefined);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-content', error });
        } finally {
            setWorking(false);
        }
    };

    const preview = async () => {
        if (!version) return;
        setWorking(true);
        clearFlashes('beacon-content');
        try {
            setPlan(await getInstallPlan(server, version));
        } catch (error) {
            setPlan(undefined);
            clearAndAddHttpError({ key: 'beacon-content', error });
        } finally {
            setWorking(false);
        }
    };

    const install = async () => {
        if (!version) return;
        setWorking(true);
        clearFlashes('beacon-content');
        try {
            await awaitOperation(await installContent(server, version));
            setPlan(undefined);
            addFlash({ key: 'beacon-content', type: 'success', message: 'Plugin installed successfully.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-content', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const remove = async (installation: BeaconInstallation) => {
        setPendingRemoval(null);
        setWorking(true);
        clearFlashes('beacon-content');
        try {
            await awaitOperation(await removeContent(server, installation.id));
            addFlash({ key: 'beacon-content', type: 'success', message: 'Plugin removed successfully.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-content', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    return (
        <ServerContentBlock title={'Plugin Installer'}>
            <FlashMessageRender byKey={'beacon-content'} css={tw`mb-4`} />
            <Dialog.Confirm
                title={'Remove installed content?'}
                confirm={'Remove content'}
                open={pendingRemoval !== null}
                onConfirmed={() => pendingRemoval && void remove(pendingRemoval)}
                onClose={() => setPendingRemoval(null)}
            >
                <Dialog.Icon type={'danger'} position={'container'} className={'!w-8 !h-8 !mr-3'} />
                <p className={'text-sm text-gray-200 leading-relaxed'}>
                    {pendingRemoval
                        ? `${pendingRemoval.filename} will be removed from this server. This action cannot be undone.`
                        : ''}
                </p>
            </Dialog.Confirm>
            {!context?.enabled ? (
                <ContentBox title={'Plugin Installer unavailable'}>
                    <p css={tw`text-sm text-neutral-300`}>
                        {context?.unavailable_reason || 'Plugins are only available for Paper servers.'}
                    </p>
                </ContentBox>
            ) : (
                <div css={tw`space-y-6`}>
                    <ContentBox title={'Plugin manager'} showLoadingOverlay={working}>
                        <p css={tw`text-sm text-neutral-300 mb-4`}>
                            {context.profile?.name} · Minecraft {context.version?.version} · {context.profile?.loader}
                        </p>
                        {(context.requires_stopped_server || context.backup_before_mutation) && (
                            <p css={tw`text-xs text-yellow-200 mb-4`}>
                                Stop the server before making changes.
                                {context.backup_before_mutation
                                    ? ' A safety backup is created before each mutation.'
                                    : ''}
                            </p>
                        )}
                        <div css={tw`flex gap-3`}>
                            <Input
                                value={query}
                                onChange={(event) => setQuery(event.currentTarget.value)}
                                placeholder={'Search Modrinth'}
                                maxLength={100}
                            />
                            <Button type={'button'} onClick={search} disabled={working}>
                                Search
                            </Button>
                        </div>
                        {projects.length > 0 && (
                            <div css={tw`mt-4 space-y-2`}>
                                {projects.map((item) => (
                                    <button
                                        type={'button'}
                                        key={item.id}
                                        onClick={() => chooseProject(item)}
                                        css={tw`block w-full text-left bg-neutral-600 hover:bg-neutral-500 rounded p-3`}
                                    >
                                        <span css={tw`font-medium text-neutral-100`}>{item.title}</span>
                                        <span css={tw`block text-xs text-neutral-300 mt-1`}>{item.description}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {project && (
                            <div css={tw`mt-4 flex gap-3 items-center`}>
                                <Select
                                    value={version}
                                    onChange={(event) => {
                                        setVersion(event.currentTarget.value);
                                        setPlan(undefined);
                                    }}
                                >
                                    {versions.map((item) => (
                                        <option value={item.id} key={item.id}>
                                            {item.name} ({item.version_number})
                                        </option>
                                    ))}
                                </Select>
                                <Button type={'button'} onClick={preview} disabled={!version || working}>
                                    Preview
                                </Button>
                            </div>
                        )}
                        {plan && (
                            <div css={tw`mt-4 bg-neutral-800 rounded p-4`}>
                                <p css={tw`text-sm text-neutral-100 mb-2`}>
                                    {plan.files.length} file{plan.files.length === 1 ? '' : 's'} ·{' '}
                                    {(plan.total_bytes / 1024 / 1024).toFixed(1)} MiB
                                </p>
                                {plan.files.map((file) => (
                                    <p key={file.version_id} css={tw`text-xs text-neutral-300`}>
                                        {file.filename}
                                        {file.dependency ? ' (required dependency)' : ''}
                                    </p>
                                ))}
                                <Can action={'file.create'}>
                                    <Button
                                        type={'button'}
                                        color={'green'}
                                        onClick={install}
                                        disabled={working}
                                        css={tw`mt-4`}
                                    >
                                        Confirm install
                                    </Button>
                                </Can>
                            </div>
                        )}
                    </ContentBox>
                    <ContentBox title={'Installed content'} showLoadingOverlay={working}>
                        {installations.length === 0 ? (
                            <p css={tw`text-sm text-neutral-300`}>No managed content is installed.</p>
                        ) : (
                            installations.map((item) => (
                                <div
                                    key={item.id}
                                    css={tw`flex items-center justify-between py-3 border-b border-neutral-600 last:border-0`}
                                >
                                    <div>
                                        <p css={tw`text-sm text-neutral-100`}>{item.filename}</p>
                                        <p css={tw`text-xs text-neutral-400`}>
                                            {item.game_version} · {item.loader}
                                        </p>
                                    </div>
                                    <Can action={'file.delete'}>
                                        <Button
                                            type={'button'}
                                            color={'red'}
                                            size={'xsmall'}
                                            onClick={() => setPendingRemoval(item)}
                                            disabled={working}
                                        >
                                            Remove
                                        </Button>
                                    </Can>
                                </div>
                            ))
                        )}
                    </ContentBox>
                </div>
            )}
        </ServerContentBlock>
    );
};

export default ContentContainer;
