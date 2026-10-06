import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/components/FlashMessageRender';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import ContentBox from '@/components/elements/ContentBox';
import Select from '@/components/elements/Select';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import Modal from '@/components/elements/Modal';
import {
    MinecraftBuild,
    MinecraftVersion,
    VersionContext,
    VersionOperation,
    VersionSoftware,
    changeMinecraftVersion,
    getMinecraftBuilds,
    getMinecraftVersions,
    getVersionContext,
    getVersionHistory,
    getVersionOperation,
} from '@/api/server/beacon/versions';

const POLL_INTERVAL = 5000;

const VersionManagerContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [context, setContext] = useState<VersionContext>();
    const [history, setHistory] = useState<VersionOperation[]>([]);
    const [software, setSoftware] = useState<VersionSoftware>('vanilla');
    const [versions, setVersions] = useState<MinecraftVersion[]>([]);
    const [version, setVersion] = useState('');
    const [builds, setBuilds] = useState<MinecraftBuild[]>([]);
    const [build, setBuild] = useState('');
    const [active, setActive] = useState<VersionOperation | null>(null);
    const [loading, setLoading] = useState(true);
    const [working, setWorking] = useState(false);
    const [modalVisible, setModalVisible] = useState(false);

    const reload = async () => {
        const [nextContext, nextHistory] = await Promise.all([
            getVersionContext(server.uuid),
            getVersionHistory(server.uuid),
        ]);
        setContext(nextContext);
        setHistory(nextHistory);
        setActive(nextContext.active_operation);
    };

    useEffect(() => {
        clearFlashes('beacon-versions');
        reload()
            .catch((error) => clearAndAddHttpError({ key: 'beacon-versions', error }))
            .finally(() => setLoading(false));
    }, [server.uuid]);

    useEffect(() => {
        if (!active || !['pending', 'running'].includes(active.status)) return;
        let cancelled = false;
        let timer: number;
        const poll = async () => {
            try {
                const operation = await getVersionOperation(server.uuid, active.uuid);
                if (cancelled) return;
                setActive(operation);
                if (['pending', 'running'].includes(operation.status)) {
                    timer = window.setTimeout(poll, POLL_INTERVAL);
                    return;
                }
                await reload();
                if (operation.status === 'failed') {
                    const rollback = operation.result?.rollback as { status?: string } | undefined;
                    addFlash({
                        key: 'beacon-versions',
                        type: 'error',
                        message:
                            (operation.error?.message || 'The version change failed.') +
                            (rollback?.status === 'succeeded' ? ' The safety backup was restored.' : ''),
                    });
                } else {
                    addFlash({ key: 'beacon-versions', type: 'success', message: 'Minecraft version changed.' });
                }
            } catch (error) {
                if (!cancelled) timer = window.setTimeout(poll, POLL_INTERVAL * 2);
            }
        };
        timer = window.setTimeout(poll, POLL_INTERVAL);
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [active?.uuid, active?.status, server.uuid]);

    const openChange = async () => {
        if (!context) return;
        setWorking(true);
        clearFlashes('beacon-versions');
        try {
            const selected = context.software.some((item) => item.key === context.current.software)
                ? (context.current.software as VersionSoftware)
                : 'vanilla';
            const catalog = await getMinecraftVersions(server.uuid, selected);
            setSoftware(selected);
            setVersions(catalog);
            setVersion(catalog[0]?.version || '');
            const nextBuilds = catalog[0] ? await getMinecraftBuilds(server.uuid, selected, catalog[0].version) : [];
            setBuilds(nextBuilds);
            setBuild(nextBuilds[0]?.uuid || '');
            setModalVisible(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-versions', error });
        } finally {
            setWorking(false);
        }
    };

    const selectSoftware = async (next: VersionSoftware) => {
        setSoftware(next);
        setVersion('');
        setBuild('');
        setWorking(true);
        try {
            const catalog = await getMinecraftVersions(server.uuid, next);
            setVersions(catalog);
            const nextVersion = catalog[0]?.version || '';
            setVersion(nextVersion);
            const nextBuilds = nextVersion ? await getMinecraftBuilds(server.uuid, next, nextVersion) : [];
            setBuilds(nextBuilds);
            setBuild(nextBuilds[0]?.uuid || '');
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-versions', error });
        } finally {
            setWorking(false);
        }
    };

    const selectVersion = async (next: string) => {
        setVersion(next);
        setBuild('');
        setWorking(true);
        try {
            const nextBuilds = await getMinecraftBuilds(server.uuid, software, next);
            setBuilds(nextBuilds);
            setBuild(nextBuilds[0]?.uuid || '');
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-versions', error });
        } finally {
            setWorking(false);
        }
    };

    const submit = async () => {
        if (!version || !build || working) return;
        setWorking(true);
        clearFlashes('beacon-versions');
        try {
            const operation = await changeMinecraftVersion(server.uuid, software, version, build);
            setActive(operation);
            setModalVisible(false);
            addFlash({ key: 'beacon-versions', type: 'info', message: 'Version change queued.' });
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-versions', error });
        } finally {
            setWorking(false);
        }
    };

    if (loading) return <Spinner size={'large'} centered />;

    return (
        <ServerContentBlock title={'Versions'}>
            <FlashMessageRender byKey={'beacon-versions'} css={tw`mb-4`} />
            <Modal visible={modalVisible} onDismissed={() => setModalVisible(false)} showSpinnerOverlay={working}>
                <h2 css={tw`text-xl font-semibold text-neutral-100 mb-2`}>Change Minecraft version</h2>
                <p css={tw`text-sm text-neutral-300 mb-5`}>
                    Beacon will stop the server, create a mandatory backup, reinstall the trusted Beacon Egg, and
                    restart it if it was running.
                </p>
                <label css={tw`block text-xs uppercase text-neutral-300 mb-2`}>Server software</label>
                <Select
                    value={software}
                    onChange={(event) => void selectSoftware(event.currentTarget.value as VersionSoftware)}
                >
                    {context?.software.map((item) => (
                        <option key={item.key} value={item.key}>
                            {item.name}
                        </option>
                    ))}
                </Select>
                <label css={tw`block text-xs uppercase text-neutral-300 mt-4 mb-2`}>Minecraft version</label>
                <Select value={version} onChange={(event) => void selectVersion(event.currentTarget.value)}>
                    {versions.map((item) => (
                        <option key={item.version} value={item.version}>
                            {item.version} · Java {item.java}
                        </option>
                    ))}
                </Select>
                <label css={tw`block text-xs uppercase text-neutral-300 mt-4 mb-2`}>Build</label>
                <Select value={build} onChange={(event) => setBuild(event.currentTarget.value)}>
                    {builds.map((item) => (
                        <option key={item.uuid} value={item.uuid}>
                            {item.name}
                        </option>
                    ))}
                </Select>
                <div
                    css={tw`bg-yellow-900 bg-opacity-30 border border-yellow-700 rounded p-3 mt-5 text-sm text-yellow-100`}
                >
                    Worlds and configuration are preserved. Incompatible <code>plugins/</code> or <code>mods/</code>{' '}
                    folders are kept on disk but ignored by the new software. Downgrades are blocked.
                </div>
                <div css={tw`flex justify-end gap-3 mt-6`}>
                    <Button type={'button'} color={'grey'} onClick={() => setModalVisible(false)}>
                        Cancel
                    </Button>
                    <Button type={'button'} color={'red'} disabled={!version || !build} onClick={submit}>
                        Change version
                    </Button>
                </div>
            </Modal>

            {!context?.enabled ? (
                <ContentBox title={'Version Manager disabled'}>
                    <p css={tw`text-sm text-neutral-300`}>Enable BEACON_VERSION_MANAGER_ENABLED to use this feature.</p>
                </ContentBox>
            ) : !context.available ? (
                <ContentBox title={'Version Manager unavailable'}>
                    <p css={tw`text-sm text-neutral-300`}>{context.unavailable_reason}</p>
                </ContentBox>
            ) : (
                <div css={tw`space-y-6`}>
                    <ContentBox title={'Current runtime'} showLoadingOverlay={working}>
                        <div css={tw`flex flex-col md:flex-row md:items-center justify-between gap-4`}>
                            <div>
                                <h3 css={tw`text-lg text-neutral-100 font-medium`}>{context.current.software_name}</h3>
                                <p css={tw`text-sm text-neutral-400 mt-1`}>
                                    Minecraft {context.current.minecraft_version || 'unknown'}
                                    {context.current.build ? ` · Build ${context.current.build}` : ''}
                                </p>
                            </div>
                            <Button disabled={!!active || context.managed_modpack} onClick={() => void openChange()}>
                                Change version
                            </Button>
                        </div>
                        {context.managed_modpack && (
                            <p css={tw`text-sm text-yellow-200 mt-4`}>
                                Remove the managed modpack before changing this runtime.
                            </p>
                        )}
                    </ContentBox>

                    {active && ['pending', 'running'].includes(active.status) && (
                        <ContentBox title={'Version change in progress'}>
                            <div css={tw`flex justify-between text-sm text-neutral-300 mb-2`}>
                                <span>{active.progress?.message || 'Waiting for the worker.'}</span>
                                <span>{active.progress?.percent || 0}%</span>
                            </div>
                            <div css={tw`h-2 bg-neutral-700 rounded overflow-hidden`}>
                                <div
                                    css={tw`h-full bg-cyan-500 transition-all duration-300`}
                                    style={{ width: `${active.progress?.percent || 0}%` }}
                                />
                            </div>
                        </ContentBox>
                    )}

                    <ContentBox title={'Operation history'}>
                        {history.length === 0 ? (
                            <p css={tw`text-sm text-neutral-400`}>No version changes yet.</p>
                        ) : (
                            history.map((item) => (
                                <div
                                    key={item.uuid}
                                    css={tw`py-3 border-b border-neutral-700 last:border-0 flex justify-between gap-4`}
                                >
                                    <div>
                                        <p css={tw`text-sm text-neutral-100`}>
                                            {item.target?.software || 'runtime'} · Minecraft{' '}
                                            {item.target?.minecraft_version || 'unknown'}
                                        </p>
                                        <p css={tw`text-xs text-neutral-500 mt-1`}>{item.progress?.message}</p>
                                    </div>
                                    <span
                                        css={[
                                            tw`text-sm`,
                                            item.status === 'succeeded'
                                                ? tw`text-green-400`
                                                : item.status === 'failed'
                                                ? tw`text-red-400`
                                                : tw`text-yellow-300`,
                                        ]}
                                    >
                                        {item.status}
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

export default VersionManagerContainer;
