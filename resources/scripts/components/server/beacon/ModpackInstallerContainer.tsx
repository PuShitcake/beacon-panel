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
import Modal from '@/components/elements/Modal';
import { Dialog } from '@/components/elements/dialog';
import {
    ModpackContext,
    ModpackOperation,
    ModpackProject,
    ModpackProviderKey,
    ModpackVersion,
    getModpackContext,
    getModpackHistory,
    getModpackOperation,
    getModpackVersions,
    installModpack,
    reinstallModpack,
    retryModpackOperation,
    restoreModpack,
    searchModpacks,
    uninstallModpack,
    updateModpack,
} from '@/api/server/beacon/modpacks';

type ModalMode = 'install' | 'update';
type InstalledAction = 'reinstall' | 'restore' | 'uninstall';
const LATEST_VERSION = '__latest__';
const OPERATION_POLL_INTERVAL = 5000;
const MAX_CONSECUTIVE_POLL_FAILURES = 6;

interface PollingError {
    response?: {
        status?: number;
        headers?: Record<string, string | undefined>;
    };
}

const isRetryablePollingError = (error: unknown): boolean => {
    const status = (error as PollingError)?.response?.status;

    return status === undefined || status === 429 || status >= 500;
};

const pollingRetryDelay = (error: unknown, failures: number): number => {
    const retryAfter = Number((error as PollingError)?.response?.headers?.['retry-after']);
    if (Number.isFinite(retryAfter) && retryAfter > 0) {
        return Math.min(retryAfter * 1000, 60000);
    }

    return Math.min(OPERATION_POLL_INTERVAL * failures, 30000);
};

const wait = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

const latestInstallableVersion = (versions: ModpackVersion[]): ModpackVersion | undefined =>
    versions.reduce<ModpackVersion | undefined>((latest, candidate) => {
        if (!candidate.installable) return latest;
        if (!latest) return candidate;

        const candidatePublishedAt = candidate.published_at ? Date.parse(candidate.published_at) : Number.NaN;
        const latestPublishedAt = latest.published_at ? Date.parse(latest.published_at) : Number.NaN;

        if (
            !Number.isNaN(candidatePublishedAt) &&
            (Number.isNaN(latestPublishedAt) || candidatePublishedAt > latestPublishedAt)
        ) {
            return candidate;
        }

        return latest;
    }, undefined);

type PendingConfirmation =
    | { type: 'installed-action'; action: InstalledAction }
    | { type: 'retry'; operation: ModpackOperation };

const ModpackInstallerContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!);
    const serverStatus = ServerContext.useStoreState((state) => state.status.value);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [context, setContext] = useState<ModpackContext>();
    const [history, setHistory] = useState<ModpackOperation[]>([]);
    const [provider, setProvider] = useState<ModpackProviderKey>('curseforge');
    const [query, setQuery] = useState('');
    const [pageSize, setPageSize] = useState(20);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [projects, setProjects] = useState<ModpackProject[]>([]);
    const [selectedProject, setSelectedProject] = useState<ModpackProject>();
    const [versions, setVersions] = useState<ModpackVersion[]>([]);
    const [selectedVersion, setSelectedVersion] = useState('');
    const [modalMode, setModalMode] = useState<ModalMode>('install');
    const [modalVisible, setModalVisible] = useState(false);
    const [pendingConfirmation, setPendingConfirmation] = useState<PendingConfirmation | null>(null);
    const [deleteFiles, setDeleteFiles] = useState(false);
    const [confirmation, setConfirmation] = useState('');
    const [active, setActive] = useState<ModpackOperation | null>(null);
    const [loading, setLoading] = useState(true);
    const [working, setWorking] = useState(false);

    const reload = async () => {
        const [nextContext, nextHistory] = await Promise.all([
            getModpackContext(server.uuid),
            getModpackHistory(server.uuid),
        ]);
        setContext(nextContext);
        setHistory(nextHistory);
        setActive(nextContext.active_operation);
        const configured = nextContext.providers.find((item) => item.configured);
        if (configured && !nextContext.providers.find((item) => item.key === provider)?.configured) {
            setProvider(configured.key);
        }
    };

    useEffect(() => {
        clearFlashes('beacon-modpacks');
        reload()
            .catch((error) => clearAndAddHttpError({ key: 'beacon-modpacks', error }))
            .finally(() => setLoading(false));
    }, [server.uuid]);

    useEffect(() => {
        if (!active || working || !['pending', 'running'].includes(active.status)) return;

        let cancelled = false;
        let timer: number | undefined;
        let consecutiveFailures = 0;
        const poll = async () => {
            try {
                const operation = await getModpackOperation(server.uuid, active.uuid);
                if (cancelled) return;
                consecutiveFailures = 0;
                setActive(operation);
                if (!['pending', 'running'].includes(operation.status)) {
                    await reload();
                    if (operation.status === 'failed') {
                        const rollback = operation.result?.rollback as { status?: string } | undefined;
                        const suffix = rollback?.status === 'succeeded' ? ' The safety backup was restored.' : '';
                        addFlash({
                            key: 'beacon-modpacks',
                            type: 'error',
                            message: (operation.error?.message || 'The modpack operation failed.') + suffix,
                        });
                    } else {
                        addFlash({ key: 'beacon-modpacks', type: 'success', message: 'Modpack operation completed.' });
                    }

                    return;
                }

                timer = window.setTimeout(poll, OPERATION_POLL_INTERVAL);
            } catch (error) {
                if (cancelled) return;
                consecutiveFailures++;
                if (!isRetryablePollingError(error) || consecutiveFailures >= MAX_CONSECUTIVE_POLL_FAILURES) {
                    clearAndAddHttpError({ key: 'beacon-modpacks', error });

                    return;
                }

                timer = window.setTimeout(poll, pollingRetryDelay(error, consecutiveFailures));
            }
        };
        timer = window.setTimeout(poll, OPERATION_POLL_INTERVAL);

        return () => {
            cancelled = true;
            if (timer !== undefined) window.clearTimeout(timer);
        };
    }, [active?.uuid, active?.status, working, server.uuid]);

    const awaitOperation = async (initial: ModpackOperation) => {
        let operation = initial;
        let consecutiveFailures = 0;
        let nextPollDelay = OPERATION_POLL_INTERVAL;
        setActive(operation);
        for (let attempt = 0; attempt < 720 && ['pending', 'running'].includes(operation.status); attempt++) {
            await wait(nextPollDelay);
            try {
                operation = await getModpackOperation(server.uuid, operation.uuid);
                consecutiveFailures = 0;
                nextPollDelay = OPERATION_POLL_INTERVAL;
                setActive(operation);
            } catch (error) {
                consecutiveFailures++;
                if (!isRetryablePollingError(error) || consecutiveFailures >= MAX_CONSECUTIVE_POLL_FAILURES) {
                    throw error;
                }
                nextPollDelay = pollingRetryDelay(error, consecutiveFailures);
            }
        }
        await reload();
        if (['pending', 'running'].includes(operation.status)) {
            throw new Error('The modpack operation is still running. Refresh this page to continue monitoring it.');
        }
        if (operation.status !== 'succeeded') {
            const rollback = operation.result?.rollback as { status?: string } | undefined;
            const suffix = rollback?.status === 'succeeded' ? ' The safety backup was restored.' : '';
            throw new Error((operation.error?.message || 'The modpack operation did not complete.') + suffix);
        }
        setActive(null);
    };

    const runSearch = async (nextPage = 1) => {
        setWorking(true);
        clearFlashes('beacon-modpacks');
        try {
            const result = await searchModpacks(server.uuid, provider, query, nextPage, pageSize);
            setProjects(result.projects);
            setPage(result.page);
            setTotal(result.total);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-modpacks', error });
        } finally {
            setWorking(false);
        }
    };

    const openInstall = async (
        project: ModpackProject,
        mode: ModalMode = 'install',
        providerKey: ModpackProviderKey = provider
    ) => {
        setWorking(true);
        clearFlashes('beacon-modpacks');
        try {
            const available = await getModpackVersions(server.uuid, providerKey, project.id);
            setSelectedProject(project);
            setVersions(available);
            setSelectedVersion(latestInstallableVersion(available) ? LATEST_VERSION : '');
            setDeleteFiles(false);
            setConfirmation('');
            setModalMode(mode);
            setModalVisible(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-modpacks', error });
        } finally {
            setWorking(false);
        }
    };

    const openUpdate = async () => {
        if (!context?.installation) return;
        const installed = context.installation;
        setProvider(installed.provider);
        await openInstall(
            {
                id: installed.project_id,
                slug: installed.project_slug,
                name: installed.name,
                description: '',
                author: null,
                icon_url: installed.icon_url,
                downloads: 0,
                website_url: null,
            },
            'update',
            installed.provider
        );
    };

    const submitInstall = async () => {
        const versionId = selectedVersion === LATEST_VERSION ? latestInstallableVersion(versions)?.id : selectedVersion;
        if (!selectedProject || !versionId) return;
        setWorking(true);
        clearFlashes('beacon-modpacks');
        try {
            const operation =
                modalMode === 'install'
                    ? await installModpack(server.uuid, {
                          provider,
                          project_id: selectedProject.id,
                          version_id: versionId,
                          delete_files: deleteFiles,
                          confirmation: deleteFiles ? confirmation : null,
                      })
                    : await updateModpack(server.uuid, context!.installation!.id, versionId);
            setModalVisible(false);
            await awaitOperation(operation);
            addFlash({ key: 'beacon-modpacks', type: 'success', message: 'Modpack operation completed.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-modpacks', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const runInstalledAction = async (action: InstalledAction) => {
        const installation = context?.installation;
        if (!installation) return;
        setWorking(true);
        clearFlashes('beacon-modpacks');
        try {
            const operation =
                action === 'reinstall'
                    ? await reinstallModpack(server.uuid, installation.id)
                    : action === 'restore'
                    ? await restoreModpack(server.uuid, installation.id)
                    : await uninstallModpack(server.uuid, installation.id);
            await awaitOperation(operation);
            addFlash({ key: 'beacon-modpacks', type: 'success', message: 'Modpack operation completed.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-modpacks', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const retryOperation = async (operation: ModpackOperation) => {
        setWorking(true);
        clearFlashes('beacon-modpacks');
        try {
            await awaitOperation(await retryModpackOperation(server.uuid, operation.uuid));
            addFlash({ key: 'beacon-modpacks', type: 'success', message: 'Modpack operation completed.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-modpacks', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const confirmPendingAction = () => {
        if (!pendingConfirmation || working) return;

        const request = pendingConfirmation;
        setPendingConfirmation(null);
        if (request.type === 'retry') {
            void retryOperation(request.operation);
        } else {
            void runInstalledAction(request.action);
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    const selectedProvider = context?.providers.find((item) => item.key === provider);
    const selectedVersionId =
        selectedVersion === LATEST_VERSION ? latestInstallableVersion(versions)?.id : selectedVersion;
    const pageCount = Math.max(1, Math.ceil(total / pageSize));

    return (
        <ServerContentBlock title={'Modpack Installer'}>
            <FlashMessageRender byKey={'beacon-modpacks'} css={tw`mb-4`} />
            <Modal visible={modalVisible} onDismissed={() => setModalVisible(false)} showSpinnerOverlay={working}>
                <h2 css={tw`text-xl font-semibold text-neutral-100 mb-3`}>
                    {modalMode === 'install' ? 'Install modpack' : 'Update modpack'}
                </h2>
                <p css={tw`text-sm text-neutral-300 mb-4`}>
                    Select a server-ready version of <strong>{selectedProject?.name}</strong>.
                </p>
                <label css={tw`block text-xs uppercase text-neutral-300 mb-2`}>Modpack version</label>
                <Select value={selectedVersion} onChange={(event) => setSelectedVersion(event.currentTarget.value)}>
                    {latestInstallableVersion(versions) ? (
                        <option value={LATEST_VERSION}>Latest version</option>
                    ) : (
                        <option value={''}>No compatible versions available</option>
                    )}
                    {versions.map((version) => (
                        <option value={version.id} key={version.id} disabled={!version.installable}>
                            {version.name}
                            {version.minecraft_version ? ` • Minecraft ${version.minecraft_version}` : ''}
                            {version.loader ? ` • ${version.loader}` : ''}
                            {!version.installable ? ' • unavailable' : ''}
                        </option>
                    ))}
                </Select>
                <p css={tw`text-sm text-yellow-200 mt-4`}>
                    Modpack updates can corrupt worlds. Beacon creates a safety backup and rolls back automatically if
                    installation fails.
                </p>
                {modalMode === 'install' && (
                    <div css={tw`bg-neutral-700 rounded p-4 mt-4`}>
                        <label css={tw`flex items-center text-sm text-neutral-100 cursor-pointer`}>
                            <Input
                                type={'checkbox'}
                                checked={deleteFiles}
                                onChange={(event) => setDeleteFiles(event.currentTarget.checked)}
                                css={tw`mr-3`}
                            />
                            Delete all existing server files before installation
                        </label>
                        {deleteFiles && (
                            <div css={tw`mt-3`}>
                                <p css={tw`text-xs text-red-200 mb-2`}>
                                    This is destructive. Type the exact server name: <strong>{server.name}</strong>
                                </p>
                                <Input
                                    value={confirmation}
                                    onChange={(event) => setConfirmation(event.currentTarget.value)}
                                />
                            </div>
                        )}
                    </div>
                )}
                <div css={tw`flex justify-end gap-3 mt-6`}>
                    <Button type={'button'} color={'grey'} onClick={() => setModalVisible(false)}>
                        Cancel
                    </Button>
                    <Button
                        type={'button'}
                        color={'red'}
                        onClick={submitInstall}
                        disabled={!selectedVersionId || (deleteFiles && confirmation !== server.name)}
                    >
                        {modalMode === 'install' ? 'Install modpack' : 'Update modpack'}
                    </Button>
                </div>
            </Modal>
            <Dialog.Confirm
                title={
                    pendingConfirmation?.type === 'retry'
                        ? 'Retry modpack operation?'
                        : pendingConfirmation?.action === 'reinstall'
                        ? 'Reinstall this modpack?'
                        : pendingConfirmation?.action === 'restore'
                        ? 'Restore the previous backup?'
                        : 'Uninstall this modpack?'
                }
                confirm={
                    pendingConfirmation?.type === 'retry'
                        ? 'Retry operation'
                        : pendingConfirmation?.action === 'reinstall'
                        ? 'Reinstall modpack'
                        : pendingConfirmation?.action === 'restore'
                        ? 'Restore backup'
                        : 'Uninstall modpack'
                }
                open={pendingConfirmation !== null}
                onConfirmed={confirmPendingAction}
                onClose={() => setPendingConfirmation(null)}
            >
                <Dialog.Icon
                    position={'container'}
                    className={'!w-8 !h-8 !mr-3'}
                    type={
                        pendingConfirmation?.type === 'retry' || pendingConfirmation?.action === 'reinstall'
                            ? 'warning'
                            : 'danger'
                    }
                />
                <p className={'text-sm text-gray-200 leading-relaxed'}>
                    {pendingConfirmation?.type === 'retry'
                        ? 'Beacon will run a new safety check before retrying this failed operation.'
                        : pendingConfirmation?.action === 'reinstall'
                        ? 'Beacon will reinstall the current modpack while preserving protected server files.'
                        : pendingConfirmation?.action === 'restore'
                        ? 'The server will be restored to its pre-modpack backup. Current files will be replaced.'
                        : serverStatus === 'running' || serverStatus === 'starting'
                        ? 'This server is currently running. Beacon will stop it automatically, uninstall the modpack, restore its pre-modpack state, and start it again.'
                        : serverStatus === 'stopping'
                        ? 'This server is currently stopping. Beacon will wait for it to go offline, uninstall the modpack, restore its pre-modpack state, and leave it offline.'
                        : serverStatus === 'offline'
                        ? 'The installed modpack will be removed and the server will be restored to its pre-modpack state. The server will remain offline.'
                        : 'If this server is running, Beacon will stop it automatically, uninstall the modpack, restore its pre-modpack state, and start it again.'}
                </p>
            </Dialog.Confirm>

            {!context?.enabled ? (
                <ContentBox title={'Modpack Installer disabled'}>
                    <p css={tw`text-sm text-neutral-300`}>Enable BEACON_MODPACKS_ENABLED to use this feature.</p>
                </ContentBox>
            ) : !context.available ? (
                <ContentBox title={'Modpack Installer unavailable'}>
                    <p css={tw`text-sm text-neutral-300`}>
                        {context.unavailable_reason ||
                            'Switch to Forge, Fabric, NeoForge, or Quilt before installing modpacks.'}
                    </p>
                </ContentBox>
            ) : (
                <div css={tw`space-y-6`}>
                    {context.installation && (
                        <ContentBox title={'Most recently installed modpack'} showLoadingOverlay={working}>
                            <div css={tw`flex flex-col md:flex-row md:items-center justify-between gap-4`}>
                                <div css={tw`flex items-center min-w-0`}>
                                    {context.installation.icon_url && (
                                        <img
                                            src={context.installation.icon_url}
                                            alt={''}
                                            referrerPolicy={'no-referrer'}
                                            css={tw`w-12 h-12 rounded mr-4 object-cover`}
                                        />
                                    )}
                                    <div css={tw`min-w-0`}>
                                        <p css={tw`text-neutral-100 font-medium truncate`}>
                                            {context.installation.name}
                                        </p>
                                        <p css={tw`text-xs text-neutral-400`}>
                                            {context.installation.version_name} • Minecraft{' '}
                                            {context.installation.minecraft_version} • {context.installation.loader}
                                        </p>
                                    </div>
                                </div>
                                <div css={tw`flex flex-wrap gap-2`}>
                                    <Button type={'button'} size={'xsmall'} onClick={openUpdate} disabled={working}>
                                        Update
                                    </Button>
                                    <Button
                                        type={'button'}
                                        size={'xsmall'}
                                        color={'grey'}
                                        onClick={() =>
                                            setPendingConfirmation({ type: 'installed-action', action: 'reinstall' })
                                        }
                                        disabled={working}
                                    >
                                        Reinstall
                                    </Button>
                                    <Button
                                        type={'button'}
                                        size={'xsmall'}
                                        color={'grey'}
                                        onClick={() =>
                                            setPendingConfirmation({ type: 'installed-action', action: 'restore' })
                                        }
                                        disabled={working}
                                    >
                                        Restore
                                    </Button>
                                    <Button
                                        type={'button'}
                                        size={'xsmall'}
                                        color={'red'}
                                        onClick={() =>
                                            setPendingConfirmation({ type: 'installed-action', action: 'uninstall' })
                                        }
                                        disabled={working}
                                    >
                                        Uninstall
                                    </Button>
                                </div>
                            </div>
                        </ContentBox>
                    )}

                    {active && ['pending', 'running'].includes(active.status) && (
                        <ContentBox title={'Operation in progress'}>
                            <div css={tw`flex justify-between text-sm text-neutral-200 mb-2`}>
                                <span>{active.progress?.message || 'Waiting for the worker.'}</span>
                                <span>{active.progress?.percent || 0}%</span>
                            </div>
                            <div css={tw`h-2 bg-neutral-700 rounded overflow-hidden`}>
                                <div
                                    css={tw`h-full bg-primary-500 transition-all`}
                                    style={{ width: `${active.progress?.percent || 0}%` }}
                                />
                            </div>
                        </ContentBox>
                    )}

                    <ContentBox title={'Browse modpacks'} showLoadingOverlay={working}>
                        <div css={tw`grid grid-cols-1 md:grid-cols-6 gap-3 mb-4`}>
                            <div css={tw`md:col-span-2`}>
                                <label css={tw`block text-xs uppercase text-neutral-400 mb-1`}>Provider</label>
                                <Select
                                    value={provider}
                                    onChange={(event) => {
                                        setProvider(event.currentTarget.value as ModpackProviderKey);
                                        setProjects([]);
                                        setPage(1);
                                        setTotal(0);
                                    }}
                                >
                                    {context.providers.map((item) => (
                                        <option value={item.key} key={item.key} disabled={!item.configured}>
                                            {item.name}
                                            {!item.configured ? ' (not configured)' : ''}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            <div>
                                <label css={tw`block text-xs uppercase text-neutral-400 mb-1`}>Page size</label>
                                <Select
                                    value={pageSize}
                                    onChange={(event) => setPageSize(Number(event.currentTarget.value))}
                                >
                                    <option value={10}>10</option>
                                    <option value={20}>20</option>
                                    <option value={50}>50</option>
                                </Select>
                            </div>
                            <div css={tw`md:col-span-3`}>
                                <label css={tw`block text-xs uppercase text-neutral-400 mb-1`}>Search query</label>
                                <div css={tw`flex gap-2`}>
                                    <Input
                                        value={query}
                                        onChange={(event) => setQuery(event.currentTarget.value)}
                                        onKeyDown={(event) => event.key === 'Enter' && runSearch(1)}
                                        placeholder={'Leave blank to browse top modpacks'}
                                        maxLength={100}
                                    />
                                    <Button type={'button'} onClick={() => runSearch(1)} disabled={working}>
                                        Search
                                    </Button>
                                </div>
                            </div>
                        </div>
                        {!selectedProvider?.configured && (
                            <p css={tw`text-sm text-yellow-200 mb-4`}>{selectedProvider?.reason}</p>
                        )}
                        {projects.length === 0 ? (
                            <p css={tw`text-sm text-neutral-400`}>
                                Search by name, or leave the query blank to browse top modpacks.
                            </p>
                        ) : (
                            <div css={tw`grid grid-cols-1 lg:grid-cols-3 gap-3`}>
                                {projects.map((project) => (
                                    <button
                                        type={'button'}
                                        key={project.id}
                                        onClick={() => openInstall(project)}
                                        disabled={!!context.installation || !!active}
                                        css={tw`flex text-left bg-neutral-700 hover:bg-neutral-600 disabled:opacity-50 rounded p-3 transition-colors`}
                                    >
                                        {project.icon_url && (
                                            <img
                                                src={project.icon_url}
                                                alt={''}
                                                referrerPolicy={'no-referrer'}
                                                css={tw`w-10 h-10 rounded mr-3 object-cover`}
                                            />
                                        )}
                                        <span css={tw`min-w-0`}>
                                            <span css={tw`block text-sm text-neutral-100 font-medium truncate`}>
                                                {project.name}
                                            </span>
                                            <span css={tw`block text-xs text-neutral-400 mt-1 line-clamp-2`}>
                                                {project.description || 'No description provided.'}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {total > pageSize && (
                            <div css={tw`flex justify-center items-center gap-3 mt-5`}>
                                <Button
                                    type={'button'}
                                    size={'xsmall'}
                                    color={'grey'}
                                    disabled={page <= 1 || working}
                                    onClick={() => runSearch(page - 1)}
                                >
                                    Previous
                                </Button>
                                <span css={tw`text-xs text-neutral-300`}>
                                    Page {page} of {pageCount}
                                </span>
                                <Button
                                    type={'button'}
                                    size={'xsmall'}
                                    color={'grey'}
                                    disabled={page >= pageCount || working}
                                    onClick={() => runSearch(page + 1)}
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </ContentBox>

                    <ContentBox title={'Operation history'}>
                        {history.length === 0 ? (
                            <p css={tw`text-sm text-neutral-400`}>No modpack operations have been recorded.</p>
                        ) : (
                            history.map((operation) => (
                                <div
                                    key={operation.uuid}
                                    css={tw`flex justify-between py-3 border-b border-neutral-600 last:border-0 text-sm`}
                                >
                                    <div>
                                        <p css={tw`text-neutral-100`}>{operation.history_label}</p>
                                        <p css={tw`text-xs text-neutral-400`}>
                                            {operation.error?.message || operation.progress?.message}
                                        </p>
                                    </div>
                                    <div css={tw`flex items-center gap-3`}>
                                        {operation.status === 'failed' && (
                                            <Button
                                                type={'button'}
                                                size={'xsmall'}
                                                color={'grey'}
                                                disabled={working || !!active}
                                                onClick={() => setPendingConfirmation({ type: 'retry', operation })}
                                            >
                                                Retry
                                            </Button>
                                        )}
                                        <span
                                            css={
                                                operation.status === 'succeeded'
                                                    ? tw`text-green-300`
                                                    : operation.status === 'failed'
                                                    ? tw`text-red-300`
                                                    : tw`text-yellow-300`
                                            }
                                        >
                                            {operation.status}
                                        </span>
                                    </div>
                                </div>
                            ))
                        )}
                    </ContentBox>
                </div>
            )}
        </ServerContentBlock>
    );
};

export default ModpackInstallerContainer;
