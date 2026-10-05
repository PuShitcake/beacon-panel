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
    ModContext,
    ModInstallation,
    ModInstallPlan,
    ModOperation,
    ModProject,
    ModVersion,
    getModContext,
    getModHistory,
    getModInstallations,
    getModOperation,
    getModPlan,
    getModVersions,
    installMod,
    reinstallMod,
    searchMods,
    uninstallMod,
    updateMod,
} from '@/api/server/beacon/mods';

type ModalMode = 'install' | 'update';
type PendingAction = { action: 'reinstall' | 'uninstall'; installation: ModInstallation };
const POLL_INTERVAL = 5000;
const wait = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

interface PollError {
    response?: { status?: number; headers?: Record<string, string | undefined> };
}

const retryablePollError = (error: unknown) => {
    const status = (error as PollError)?.response?.status;
    return status === undefined || status === 429 || status >= 500;
};

const ModInstallerContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!);
    const serverStatus = ServerContext.useStoreState((state) => state.status.value);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [context, setContext] = useState<ModContext>();
    const [installations, setInstallations] = useState<ModInstallation[]>([]);
    const [history, setHistory] = useState<ModOperation[]>([]);
    const [projects, setProjects] = useState<ModProject[]>([]);
    const [query, setQuery] = useState('');
    const [offset, setOffset] = useState(0);
    const [total, setTotal] = useState(0);
    const [limit, setLimit] = useState(20);
    const [selectedProject, setSelectedProject] = useState<ModProject>();
    const [versions, setVersions] = useState<ModVersion[]>([]);
    const [versionId, setVersionId] = useState('');
    const [plan, setPlan] = useState<ModInstallPlan>();
    const [modalMode, setModalMode] = useState<ModalMode>('install');
    const [editing, setEditing] = useState<ModInstallation>();
    const [modalVisible, setModalVisible] = useState(false);
    const [pendingAction, setPendingAction] = useState<PendingAction | null>(null);
    const [active, setActive] = useState<ModOperation | null>(null);
    const [loading, setLoading] = useState(true);
    const [working, setWorking] = useState(false);

    const reload = async () => {
        const nextContext = await getModContext(server.uuid);
        setContext(nextContext);
        setActive(nextContext.active_operation);
        if (nextContext.enabled) {
            const [nextInstallations, nextHistory] = await Promise.all([
                getModInstallations(server.uuid),
                getModHistory(server.uuid),
            ]);
            setInstallations(nextInstallations);
            setHistory(nextHistory);
        }
        return nextContext;
    };

    const runSearch = async (nextOffset = 0) => {
        setWorking(true);
        clearFlashes('beacon-mods');
        try {
            const result = await searchMods(server.uuid, query.trim(), nextOffset);
            setProjects(result.projects);
            setOffset(result.offset);
            setLimit(result.limit);
            setTotal(result.total);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-mods', error });
        } finally {
            setWorking(false);
        }
    };

    useEffect(() => {
        clearFlashes('beacon-mods');
        reload()
            .then((nextContext) => {
                if (nextContext.enabled) return runSearch(0);

                return Promise.resolve();
            })
            .catch((error) => clearAndAddHttpError({ key: 'beacon-mods', error }))
            .finally(() => setLoading(false));
    }, [server.uuid]);

    useEffect(() => {
        if (!active || working || !['pending', 'running'].includes(active.status)) return;

        let cancelled = false;
        let timer: number | undefined;
        let failures = 0;
        const poll = async () => {
            try {
                const operation = await getModOperation(server.uuid, active.uuid);
                if (cancelled) return;
                failures = 0;
                setActive(operation);
                if (!['pending', 'running'].includes(operation.status)) {
                    await reload();
                    addFlash({
                        key: 'beacon-mods',
                        type: operation.status === 'succeeded' ? 'success' : 'error',
                        message:
                            operation.status === 'succeeded'
                                ? 'Mod operation completed.'
                                : operation.error?.message || 'The mod operation failed.',
                    });
                    return;
                }
                timer = window.setTimeout(poll, POLL_INTERVAL);
            } catch (error) {
                if (cancelled) return;
                failures++;
                if (!retryablePollError(error) || failures >= 6) {
                    clearAndAddHttpError({ key: 'beacon-mods', error });
                    return;
                }
                timer = window.setTimeout(poll, Math.min(POLL_INTERVAL * failures, 30000));
            }
        };
        timer = window.setTimeout(poll, POLL_INTERVAL);

        return () => {
            cancelled = true;
            if (timer !== undefined) window.clearTimeout(timer);
        };
    }, [active?.uuid, active?.status, working, server.uuid]);

    const awaitOperation = async (initial: ModOperation) => {
        let operation = initial;
        let failures = 0;
        setActive(initial);
        for (let attempt = 0; attempt < 720 && ['pending', 'running'].includes(operation.status); attempt++) {
            await wait(POLL_INTERVAL);
            try {
                operation = await getModOperation(server.uuid, operation.uuid);
                failures = 0;
                setActive(operation);
            } catch (error) {
                failures++;
                if (!retryablePollError(error) || failures >= 6) throw error;
                await wait(Math.min(POLL_INTERVAL * failures, 30000));
            }
        }
        await reload();
        if (operation.status !== 'succeeded') {
            throw new Error(operation.error?.message || 'The mod operation did not complete.');
        }
        setActive(null);
    };

    const loadPlan = async (selected: string) => {
        setVersionId(selected);
        setPlan(undefined);
        if (!selected) return;
        setWorking(true);
        try {
            setPlan(await getModPlan(server.uuid, selected));
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-mods', error });
        } finally {
            setWorking(false);
        }
    };

    const openInstall = async (project: ModProject) => {
        setWorking(true);
        clearFlashes('beacon-mods');
        try {
            const available = await getModVersions(server.uuid, project.id);
            if (available.length === 0) throw new Error('No compatible version is available for this server.');
            setSelectedProject(project);
            setEditing(undefined);
            setVersions(available);
            setModalMode('install');
            setModalVisible(true);
            await loadPlan(available[0].id);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-mods', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const openUpdate = async (installation: ModInstallation) => {
        setWorking(true);
        clearFlashes('beacon-mods');
        try {
            const available = await getModVersions(server.uuid, installation.project_id);
            if (available.length === 0) throw new Error('No compatible update is available for this server.');
            setSelectedProject(undefined);
            setEditing(installation);
            setVersions(available);
            setModalMode('update');
            setModalVisible(true);
            await loadPlan(available[0].id);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-mods', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const submit = async () => {
        if (!versionId || !plan) return;
        setWorking(true);
        clearFlashes('beacon-mods');
        try {
            const operation =
                modalMode === 'update' && editing
                    ? await updateMod(server.uuid, editing.id, versionId)
                    : await installMod(server.uuid, versionId);
            setModalVisible(false);
            await awaitOperation(operation);
            addFlash({ key: 'beacon-mods', type: 'success', message: 'Mod operation completed.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-mods', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    const confirmAction = async () => {
        if (!pendingAction) return;
        const current = pendingAction;
        setPendingAction(null);
        setWorking(true);
        clearFlashes('beacon-mods');
        try {
            const operation =
                current.action === 'reinstall'
                    ? await reinstallMod(server.uuid, current.installation.id)
                    : await uninstallMod(server.uuid, current.installation.id);
            await awaitOperation(operation);
            addFlash({ key: 'beacon-mods', type: 'success', message: 'Mod operation completed.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-mods', error: httpErrorToHuman(error) });
        } finally {
            setWorking(false);
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    return (
        <ServerContentBlock title={'Mod Installer'}>
            <FlashMessageRender byKey={'beacon-mods'} css={tw`mb-4`} />
            <Modal visible={modalVisible} onDismissed={() => setModalVisible(false)} showSpinnerOverlay={working}>
                <h2 css={tw`text-xl text-neutral-100 font-medium mb-2`}>
                    {modalMode === 'update'
                        ? `Update ${editing?.project_name || editing?.filename}`
                        : `Install ${selectedProject?.title || 'mod'}`}
                </h2>
                <p css={tw`text-sm text-neutral-300 mb-4`}>
                    Only versions compatible with Minecraft {context?.game_version} and {context?.loader} are shown.
                </p>
                <Select value={versionId} onChange={(event) => void loadPlan(event.currentTarget.value)}>
                    {versions.map((item, index) => (
                        <option value={item.id} key={item.id}>
                            {index === 0 ? 'Latest compatible - ' : ''}
                            {item.name} ({item.version_number})
                        </option>
                    ))}
                </Select>
                {plan && (
                    <div css={tw`bg-neutral-700 rounded p-4 mt-4`}>
                        <p css={tw`text-sm text-neutral-100 mb-2`}>
                            {plan.files.length} file{plan.files.length === 1 ? '' : 's'} ·{' '}
                            {(plan.total_bytes / 1024 / 1024).toFixed(1)} MiB
                        </p>
                        {plan.files.map((file) => (
                            <p key={file.version_id} css={tw`text-xs text-neutral-300 mt-1`}>
                                {file.project_name} · {file.filename}
                                {file.dependency ? ' (required dependency)' : ''}
                            </p>
                        ))}
                        {plan.optional_dependencies.length > 0 && (
                            <p css={tw`text-xs text-yellow-200 mt-3`}>
                                {plan.optional_dependencies.length} optional dependency item(s) are not installed
                                automatically.
                            </p>
                        )}
                    </div>
                )}
                <p css={tw`text-xs text-yellow-200 mt-4`}>
                    If the server is running, Beacon will stop it, create a safety backup, apply the change, and start
                    it again.
                </p>
                <div css={tw`flex justify-end gap-3 mt-6`}>
                    <Button type={'button'} color={'grey'} onClick={() => setModalVisible(false)}>
                        Cancel
                    </Button>
                    <Button type={'button'} color={'green'} onClick={submit} disabled={!plan || working}>
                        {modalMode === 'update' ? 'Update mod' : 'Install mod'}
                    </Button>
                </div>
            </Modal>
            <Dialog.Confirm
                title={pendingAction?.action === 'reinstall' ? 'Reinstall this mod?' : 'Uninstall this mod?'}
                confirm={pendingAction?.action === 'reinstall' ? 'Reinstall mod' : 'Uninstall mod'}
                open={pendingAction !== null}
                onConfirmed={confirmAction}
                onClose={() => setPendingAction(null)}
            >
                <Dialog.Icon
                    type={pendingAction?.action === 'reinstall' ? 'warning' : 'danger'}
                    position={'container'}
                    className={'!w-8 !h-8 !mr-3'}
                />
                <p className={'text-sm text-gray-200 leading-relaxed'}>
                    {serverStatus === 'running' || serverStatus === 'starting'
                        ? 'This server is running. Beacon will stop it automatically, apply the change, and start it again.'
                        : pendingAction?.action === 'reinstall'
                        ? 'Beacon will download and install the selected mod again.'
                        : 'Beacon will remove this mod and dependencies that are not used by another Beacon-managed mod.'}
                </p>
            </Dialog.Confirm>

            {!context?.enabled ? (
                <ContentBox title={'Mod Installer unavailable'}>
                    <p css={tw`text-sm text-neutral-300`}>
                        {context?.unavailable_reason ||
                            'Switch to Forge, Fabric, NeoForge, or Quilt before installing mods.'}
                    </p>
                </ContentBox>
            ) : (
                <div css={tw`space-y-6`}>
                    {active && ['pending', 'running'].includes(active.status) && (
                        <ContentBox title={'Mod operation in progress'}>
                            <div css={tw`flex items-center justify-between text-sm text-neutral-200 mb-2`}>
                                <span>{active.progress?.message || 'Waiting for the worker.'}</span>
                                <span>{active.progress?.percent || 0}%</span>
                            </div>
                            <div css={tw`h-2 bg-neutral-700 rounded overflow-hidden`}>
                                <div
                                    css={tw`h-full bg-blue-500 transition-all`}
                                    style={{ width: `${active.progress?.percent || 0}%` }}
                                />
                            </div>
                        </ContentBox>
                    )}

                    <ContentBox title={'Installed mods'} showLoadingOverlay={working}>
                        {installations.length === 0 ? (
                            <p css={tw`text-sm text-neutral-300`}>No Beacon-managed mods are installed.</p>
                        ) : (
                            installations.map((item) => (
                                <div
                                    key={item.id}
                                    css={tw`flex flex-col md:flex-row md:items-center justify-between gap-3 py-3 border-b border-neutral-600 last:border-0`}
                                >
                                    <div css={tw`flex items-center min-w-0`}>
                                        {item.icon_url && (
                                            <img
                                                src={item.icon_url}
                                                alt={''}
                                                referrerPolicy={'no-referrer'}
                                                css={tw`w-10 h-10 rounded mr-3 object-cover`}
                                            />
                                        )}
                                        <div css={tw`min-w-0`}>
                                            <p css={tw`text-sm text-neutral-100 truncate`}>
                                                {item.project_name || item.filename}
                                            </p>
                                            <p css={tw`text-xs text-neutral-400`}>
                                                {item.version_number || item.version_id} · Minecraft {item.game_version}{' '}
                                                · {item.loader}
                                            </p>
                                            {item.status === 'disabled' && (
                                                <p css={tw`text-xs text-yellow-300 mt-1`}>
                                                    Disabled after a modpack runtime change. Update it to install a
                                                    compatible version.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div css={tw`flex gap-2`}>
                                        <Button
                                            type={'button'}
                                            size={'xsmall'}
                                            onClick={() => void openUpdate(item)}
                                            disabled={working}
                                        >
                                            Update
                                        </Button>
                                        <Button
                                            type={'button'}
                                            size={'xsmall'}
                                            color={'grey'}
                                            onClick={() =>
                                                setPendingAction({ action: 'reinstall', installation: item })
                                            }
                                            disabled={working}
                                        >
                                            Reinstall
                                        </Button>
                                        <Button
                                            type={'button'}
                                            size={'xsmall'}
                                            color={'red'}
                                            onClick={() =>
                                                setPendingAction({ action: 'uninstall', installation: item })
                                            }
                                            disabled={working}
                                        >
                                            Uninstall
                                        </Button>
                                    </div>
                                </div>
                            ))
                        )}
                    </ContentBox>

                    <ContentBox title={'Browse mods'} showLoadingOverlay={working}>
                        <p css={tw`text-xs text-neutral-400 mb-3`}>
                            Modrinth · Minecraft {context.game_version} · {context.loader}
                        </p>
                        <div css={tw`flex gap-3`}>
                            <Input
                                value={query}
                                onChange={(event) => setQuery(event.currentTarget.value)}
                                onKeyDown={(event) => event.key === 'Enter' && void runSearch(0)}
                                placeholder={'Search mods or leave blank for popular mods'}
                                maxLength={100}
                            />
                            <Button type={'button'} onClick={() => void runSearch(0)} disabled={working}>
                                Search
                            </Button>
                        </div>
                        <div css={tw`grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 mt-4`}>
                            {projects.map((item) => (
                                <button
                                    type={'button'}
                                    key={item.id}
                                    onClick={() => void openInstall(item)}
                                    css={tw`flex text-left bg-neutral-700 hover:bg-neutral-600 rounded p-3 min-w-0`}
                                >
                                    {item.icon_url && (
                                        <img
                                            src={item.icon_url}
                                            alt={''}
                                            referrerPolicy={'no-referrer'}
                                            css={tw`w-12 h-12 rounded mr-3 object-cover flex-none`}
                                        />
                                    )}
                                    <span css={tw`min-w-0`}>
                                        <span css={tw`block text-sm font-medium text-neutral-100 truncate`}>
                                            {item.title}
                                        </span>
                                        <span css={tw`block text-xs text-neutral-400 mt-1 line-clamp-2`}>
                                            {item.description}
                                        </span>
                                        <span css={tw`block text-xs text-neutral-500 mt-2`}>
                                            {item.downloads.toLocaleString()} downloads
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                        <div css={tw`flex justify-between mt-4`}>
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                color={'grey'}
                                disabled={offset === 0 || working}
                                onClick={() => void runSearch(Math.max(0, offset - limit))}
                            >
                                Previous
                            </Button>
                            <span css={tw`text-xs text-neutral-400 self-center`}>
                                {total === 0 ? 0 : offset + 1}-{Math.min(offset + limit, total)} of {total}
                            </span>
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                color={'grey'}
                                disabled={offset + limit >= total || working}
                                onClick={() => void runSearch(offset + limit)}
                            >
                                Next
                            </Button>
                        </div>
                    </ContentBox>

                    <ContentBox title={'Operation history'}>
                        {history.length === 0 ? (
                            <p css={tw`text-sm text-neutral-300`}>No mod operations have been recorded.</p>
                        ) : (
                            history.map((operation) => (
                                <div
                                    key={operation.uuid}
                                    css={tw`flex items-center justify-between py-2 border-b border-neutral-600 last:border-0`}
                                >
                                    <div>
                                        <p css={tw`text-sm text-neutral-200`}>{operation.type.replace('mod.', '')}</p>
                                        <p css={tw`text-xs text-neutral-500`}>
                                            {operation.error?.message || operation.progress?.message || 'Completed.'}
                                        </p>
                                    </div>
                                    <span
                                        css={
                                            operation.status === 'succeeded'
                                                ? tw`text-xs text-green-400`
                                                : operation.status === 'failed'
                                                ? tw`text-xs text-red-400`
                                                : tw`text-xs text-yellow-300`
                                        }
                                    >
                                        {operation.status}
                                    </span>
                                </div>
                            ))
                        )}
                    </ContentBox>
                </div>
            )}
        </ServerContentBlock>
    );
};

export default ModInstallerContainer;
