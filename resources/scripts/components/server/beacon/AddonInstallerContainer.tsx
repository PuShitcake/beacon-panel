import React, { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/components/FlashMessageRender';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import ContentBox from '@/components/elements/ContentBox';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Modal from '@/components/elements/Modal';
import Spinner from '@/components/elements/Spinner';
import { Dialog } from '@/components/elements/dialog';
import {
    AddonContext,
    AddonInstallation,
    AddonOperation,
    AddonPreview,
    AddonProject,
    AddonVersion,
    addonContext,
    addonSearch,
    addonVersions,
    addonPreview,
    addonOperation,
    installAddon,
    uninstallAddon,
} from '@/api/server/beacon/addons';

const AddonInstallerContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [context, setContext] = useState<AddonContext>();
    const [loading, setLoading] = useState(true);
    const [working, setWorking] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshRequired, setRefreshRequired] = useState(false);
    const [provider, setProvider] = useState('curseforge');
    const [query, setQuery] = useState('');
    const [projects, setProjects] = useState<AddonProject[]>([]);
    const [offset, setOffset] = useState(0);
    const [total, setTotal] = useState(0);
    const [world, setWorld] = useState('');
    const [versions, setVersions] = useState<AddonVersion[]>([]);
    const [version, setVersion] = useState('');
    const [preview, setPreview] = useState<AddonPreview>();
    const [acknowledge, setAcknowledge] = useState(false);
    const [modal, setModal] = useState(false);
    const [notice, setNotice] = useState('');
    const [active, setActive] = useState<AddonOperation | null>(null);
    const [remove, setRemove] = useState<AddonInstallation | null>(null);
    const generation = useRef(0);
    const busy =
        working || refreshing || refreshRequired || (!!active && ['pending', 'running'].includes(active.status));
    const key = 'beacon-addons';

    const reload = async (token = generation.current) => {
        const next = await addonContext(server.uuid);
        if (token !== generation.current) return;
        setContext(next);
        setActive(next.active_operation);
        setWorld((current) => (next.worlds.includes(current) ? current : next.worlds[0] || ''));
        setRefreshRequired(false);
        return next;
    };
    const search = async (page = 0, token = generation.current) => {
        setWorking(true);
        clearFlashes(key);
        setNotice('');
        try {
            const result = await addonSearch(server.uuid, query.trim(), page);
            if (token !== generation.current) return;
            setProjects(result.projects);
            setOffset(result.offset);
            setTotal(result.total);
        } catch (error) {
            if (token === generation.current) clearAndAddHttpError({ key, error });
        } finally {
            if (token === generation.current) setWorking(false);
        }
    };
    useEffect(() => {
        const token = ++generation.current;
        setLoading(true);
        setRefreshing(false);
        reload(token)
            .then((next) => {
                if (next?.ready && next.curseforge_available) void search(0, token);
            })
            .catch((error) => {
                if (token === generation.current) clearAndAddHttpError({ key, error });
            })
            .finally(() => {
                if (token === generation.current) setLoading(false);
            });
        return () => {
            generation.current++;
        };
    }, [server.uuid]);
    useEffect(() => {
        if (!active || !['pending', 'running'].includes(active.status)) return;
        let cancelled = false;
        let timer: number;
        let failures = 0;
        const token = generation.current;
        const poll = async () => {
            try {
                const result = await addonOperation(server.uuid, active.uuid);
                if (cancelled || token !== generation.current) return;
                failures = 0;
                setActive(result);
                if (!['pending', 'running'].includes(result.status)) {
                    addFlash({
                        key,
                        type: result.status === 'succeeded' ? 'success' : 'error',
                        message: result.error?.message || 'Add-on operation completed.',
                    });
                    setRefreshing(true);
                    try {
                        await reload(token);
                    } catch {
                        if (token === generation.current) setRefreshRequired(true);
                    } finally {
                        if (token === generation.current) setRefreshing(false);
                    }
                    return;
                }
            } catch (error) {
                if (cancelled) return;
                if (++failures >= 6) {
                    setRefreshRequired(true);
                    return;
                }
            }
            timer = window.setTimeout(poll, Math.min(5000 * Math.max(1, failures), 30000));
        };
        timer = window.setTimeout(poll, 5000);
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [active?.uuid, active?.status]);

    const choose = async (project: AddonProject) => {
        const token = generation.current;
        setWorking(true);
        setPreview(undefined);
        setVersion('');
        setVersions([]);
        setNotice('');
        clearFlashes(key);
        try {
            const listed = await addonVersions(server.uuid, project.id);
            if (token !== generation.current) return;
            if (!listed.length) {
                setNotice('No supported release with a verifiable archive is available for this add-on.');
                return;
            }
            setVersions(listed);
            setVersion(listed[0].id);
            setAcknowledge(false);
            setModal(true);
        } catch (error) {
            if (token === generation.current) clearAndAddHttpError({ key, error });
        } finally {
            if (token === generation.current) setWorking(false);
        }
    };
    const inspect = async (selection: File | string) => {
        const token = generation.current;
        setWorking(true);
        setPreview(undefined);
        setAcknowledge(false);
        clearFlashes(key);
        try {
            const result = await addonPreview(server.uuid, selection);
            if (token === generation.current) {
                setPreview(result);
                setModal(true);
            }
        } catch (error) {
            if (token === generation.current) clearAndAddHttpError({ key, error });
        } finally {
            if (token === generation.current) setWorking(false);
        }
    };
    const mutate = async (installation?: AddonInstallation) => {
        if (busy || (!installation && (!preview || !world || !acknowledge))) return;
        setWorking(true);
        setRemove(null);
        clearFlashes(key);
        try {
            const result = installation
                ? await uninstallAddon(server.uuid, installation.id)
                : await installAddon(server.uuid, preview!.archive_id, world);
            setActive(result);
            setModal(false);
            setPreview(undefined);
        } catch (error) {
            clearAndAddHttpError({ key, error });
        } finally {
            setWorking(false);
        }
    };
    const refresh = async () => {
        setWorking(true);
        clearFlashes(key);
        try {
            await reload();
        } catch (error) {
            clearAndAddHttpError({ key, error });
        } finally {
            setWorking(false);
        }
    };
    if (loading) return <Spinner size={'large'} centered />;
    return (
        <ServerContentBlock title={'Add-ons Installer'}>
            <FlashMessageRender byKey={key} css={tw`mb-4`} />
            {!context?.ready ? (
                <ContentBox title={'Add-ons Installer'}>
                    <p css={tw`text-sm text-neutral-300 mb-4`}>
                        {context?.unavailable_reason || 'Unable to load Bedrock add-on details.'}
                    </p>
                    <Button type={'button'} onClick={refresh} disabled={working}>
                        Refresh
                    </Button>
                </ContentBox>
            ) : (
                <div css={tw`space-y-6`}>
                    <ContentBox title={'Bedrock add-ons'}>
                        <p css={tw`text-sm text-neutral-300`}>
                            Bedrock {context.version}. Install behavior/resource packs into an existing world. Java mods
                            and Marketplace purchases are not supported.
                        </p>
                        <p css={tw`text-xs text-neutral-400 mt-2`}>
                            Experimental Features are never enabled automatically. Back up your world and check the
                            author&apos;s version and Script API requirements.
                        </p>
                    </ContentBox>
                    {refreshRequired && (
                        <ContentBox title={'Refresh required'}>
                            <p css={tw`text-sm text-neutral-300 mb-3`}>
                                Details could not be refreshed. No operation will be submitted again automatically.
                            </p>
                            <Button type={'button'} onClick={refresh} disabled={working}>
                                Refresh details
                            </Button>
                        </ContentBox>
                    )}
                    {active && (
                        <ContentBox title={'Latest add-on operation'}>
                            <p css={tw`text-sm text-neutral-200`}>
                                {active.error?.message || active.progress?.message || 'Waiting for the worker.'}
                            </p>
                            <div css={tw`bg-neutral-700 rounded h-2 mt-3 overflow-hidden`}>
                                <div
                                    css={tw`bg-cyan-500 h-full`}
                                    style={{ width: `${active.progress?.percent || 0}%` }}
                                />
                            </div>
                            <p css={tw`text-xs text-neutral-400 mt-2`}>
                                {active.status} · {active.progress?.percent || 0}%
                            </p>
                        </ContentBox>
                    )}
                    <ContentBox title={'Installed add-ons'} showLoadingOverlay={refreshing}>
                        {refreshing && <p css={tw`text-sm text-neutral-300`}>Refreshing installed add-ons...</p>}
                        {!refreshing && !context.installations.length && (
                            <p css={tw`text-sm text-neutral-300`}>No Beacon-managed add-ons are installed.</p>
                        )}
                        {context.installations.map((item) => (
                            <div
                                key={item.id}
                                css={tw`flex items-center justify-between py-3 border-b border-neutral-600 gap-3`}
                            >
                                <div>
                                    <p css={tw`text-sm text-neutral-100`}>
                                        {item.packs.map((pack) => pack.name).join(', ')}
                                    </p>
                                    <p css={tw`text-xs text-neutral-400`}>
                                        World: {item.world} · {item.provider}
                                    </p>
                                </div>
                                <Button
                                    type={'button'}
                                    size={'xsmall'}
                                    color={'red'}
                                    disabled={busy}
                                    onClick={() => setRemove(item)}
                                >
                                    Uninstall
                                </Button>
                            </div>
                        ))}
                    </ContentBox>
                    <ContentBox title={'Browse add-ons'} showLoadingOverlay={working}>
                        <Select
                            aria-label={'Provider'}
                            value={provider}
                            disabled={busy}
                            onChange={(event) => {
                                setProvider(event.currentTarget.value);
                                setPreview(undefined);
                                setVersions([]);
                                setNotice('');
                            }}
                        >
                            <option value={'curseforge'}>CurseForge</option>
                            <option value={'upload'}>Upload archive</option>
                        </Select>
                        {notice && <p css={tw`text-sm text-neutral-300 mt-4`}>{notice}</p>}
                        {provider === 'upload' ? (
                            <div css={tw`mt-4`}>
                                <label css={tw`block text-sm text-neutral-300 mb-2`}>
                                    Choose .mcpack, .mcaddon or ZIP. Available server storage and hosting limits apply.
                                </label>
                                <Input
                                    type={'file'}
                                    aria-label={'Add-on archive'}
                                    accept={'.mcpack,.mcaddon,.zip'}
                                    disabled={busy}
                                    onChange={(event) => {
                                        const file = event.currentTarget.files?.[0];
                                        if (file) void inspect(file);
                                        event.currentTarget.value = '';
                                    }}
                                />
                            </div>
                        ) : !context.curseforge_available ? (
                            <p css={tw`text-sm text-neutral-300 mt-4`}>
                                CurseForge requires an administrator-configured API key. You can upload an archive
                                instead.
                            </p>
                        ) : (
                            <>
                                <div css={tw`flex gap-3 mt-4`}>
                                    <Input
                                        aria-label={'Search add-ons'}
                                        value={query}
                                        maxLength={100}
                                        onChange={(event) => setQuery(event.currentTarget.value)}
                                        placeholder={'Search add-ons or leave blank for popular results'}
                                        onKeyDown={(event) => event.key === 'Enter' && !busy && void search()}
                                    />
                                    <Button type={'button'} disabled={busy} onClick={() => void search()}>
                                        Search
                                    </Button>
                                </div>
                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-3 mt-4`}>
                                    {projects.map((project) => (
                                        <button
                                            type={'button'}
                                            key={project.id}
                                            disabled={busy}
                                            onClick={() => void choose(project)}
                                            css={tw`text-left bg-neutral-700 hover:bg-neutral-600 rounded p-3 min-w-0`}
                                        >
                                            <span css={tw`block text-sm text-neutral-100`}>{project.title}</span>
                                            <span css={tw`block text-xs text-neutral-400 mt-1 line-clamp-2`}>
                                                {project.description}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                <div css={tw`flex justify-between mt-4`}>
                                    <Button
                                        type={'button'}
                                        size={'xsmall'}
                                        disabled={busy || !offset}
                                        onClick={() => void search(Math.max(0, offset - 20))}
                                    >
                                        Previous
                                    </Button>
                                    <span css={tw`text-xs self-center text-neutral-400`}>
                                        {total ? offset + 1 : 0}–{Math.min(offset + 20, total)} of {total}
                                    </span>
                                    <Button
                                        type={'button'}
                                        size={'xsmall'}
                                        disabled={busy || offset + 20 >= total}
                                        onClick={() => void search(offset + 20)}
                                    >
                                        Next
                                    </Button>
                                </div>
                            </>
                        )}
                    </ContentBox>
                </div>
            )}
            <Modal
                visible={modal}
                top={false}
                onDismissed={() => {
                    setModal(false);
                    setPreview(undefined);
                }}
                showSpinnerOverlay={working}
            >
                <h2 css={tw`text-xl font-medium text-neutral-100 mb-3`}>Install Bedrock add-on</h2>
                <FlashMessageRender byKey={key} css={tw`mb-4`} />
                {!!versions.length && (
                    <>
                        <Select
                            aria-label={'Add-on version'}
                            disabled={working}
                            value={version}
                            onChange={(event) => {
                                setVersion(event.currentTarget.value);
                                setPreview(undefined);
                                setAcknowledge(false);
                            }}
                        >
                            {versions.map((item, index) => (
                                <option key={item.id} value={item.id}>
                                    {index === 0 ? 'Latest listed – ' : ''}
                                    {item.name}
                                </option>
                            ))}
                        </Select>
                        <Button
                            type={'button'}
                            css={tw`mt-3`}
                            disabled={busy || !version}
                            onClick={() => void inspect(version)}
                        >
                            Inspect archive
                        </Button>
                    </>
                )}
                {preview && (
                    <div css={tw`mt-4 space-y-3`}>
                        {preview.packs.map((pack) => (
                            <div key={pack.uuid} css={tw`bg-neutral-700 rounded p-3`}>
                                <p css={tw`text-sm text-neutral-100`}>
                                    {pack.name} · {pack.version.join('.')}
                                </p>
                                <p css={tw`text-xs text-neutral-400`}>
                                    {pack.directory === 'behavior_packs' ? 'Behavior pack' : 'Resource pack'} · Minimum
                                    Bedrock {pack.minimum_engine?.join('.')}
                                    {pack.scripts ? ' · Script API required' : ''}
                                </p>
                            </div>
                        ))}
                        <label css={tw`block text-xs text-neutral-300`}>
                            World
                            <Select
                                aria-label={'World'}
                                value={world}
                                disabled={working}
                                onChange={(event) => setWorld(event.currentTarget.value)}
                            >
                                {context?.worlds.map((name) => (
                                    <option key={name} value={name}>
                                        {name}
                                    </option>
                                ))}
                            </Select>
                        </label>
                        <p css={tw`text-xs text-yellow-200`}>
                            Beacon will stop the server if needed, create a full safety backup and restart it only if it
                            was running. Experimental Features are not enabled. A successful install does not guarantee
                            the add-on will load or work in gameplay.
                        </p>
                        <label css={tw`flex gap-2 items-start text-sm text-neutral-300`}>
                            <input
                                aria-label={'Acknowledge add-on requirements'}
                                type={'checkbox'}
                                checked={acknowledge}
                                onChange={(event) => setAcknowledge(event.currentTarget.checked)}
                            />
                            <span>
                                I have checked the author&apos;s Bedrock version, Script API and experimental
                                requirements.
                            </span>
                        </label>
                    </div>
                )}
                <div css={tw`flex justify-end gap-3 mt-5`}>
                    <Button type={'button'} color={'grey'} disabled={working} onClick={() => setModal(false)}>
                        Cancel
                    </Button>
                    <Button
                        type={'button'}
                        disabled={busy || !preview || !world || !acknowledge}
                        onClick={() => void mutate()}
                    >
                        Install add-on
                    </Button>
                </div>
            </Modal>
            <Dialog.Confirm
                title={'Uninstall this add-on?'}
                confirm={'Uninstall add-on'}
                open={!!remove}
                onClose={() => setRemove(null)}
                onConfirmed={() => void mutate(remove || undefined)}
            >
                Bedrock will stop automatically if running. A full safety backup is required. Only the managed packs and
                their selected-world references are removed; world data is preserved. Pack folders are retained
                privately for recovery.
            </Dialog.Confirm>
        </ServerContentBlock>
    );
};

export default AddonInstallerContainer;
