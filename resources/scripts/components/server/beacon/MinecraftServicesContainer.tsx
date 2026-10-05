import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faKey, faNetworkWired } from '@fortawesome/free-solid-svg-icons';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/components/FlashMessageRender';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import Modal from '@/components/elements/Modal';
import {
    getMinecraftServiceOperation,
    getMinecraftServicesContext,
    MinecraftNetworkService,
    MinecraftServiceAction,
    MinecraftServiceOperation,
    MinecraftServicesContext,
    mutateMinecraftService,
} from '@/api/server/beacon/minecraftServices';

type PendingAction = { service: MinecraftNetworkService; action: MinecraftServiceAction };
const POLL_INTERVAL = 3000;
const MAX_POLL_FAILURES = 6;

interface PollingError {
    response?: { status?: number };
}

const retryablePollingError = (error: unknown) => {
    const status = (error as PollingError)?.response?.status;

    return status === undefined || status === 429 || status >= 500;
};

const actionLabel = (action: MinecraftServiceAction, service: MinecraftNetworkService) =>
    `${action === 'rotate' ? 'Rotate' : action === 'enable' ? 'Enable' : 'Disable'} ${service.toUpperCase()}`;

const Status = ({ value }: { value: 'enabled' | 'disabled' | 'attention' }) => (
    <span
        css={[
            tw`inline-flex px-2 py-1 rounded text-xs font-medium uppercase`,
            value === 'enabled'
                ? tw`bg-green-500 text-green-50`
                : value === 'attention'
                ? tw`bg-yellow-500 text-yellow-900`
                : tw`bg-red-500 text-red-50`,
        ]}
    >
        {value}
    </span>
);

const Value = ({ children }: { children: React.ReactNode }) => (
    <code css={tw`font-mono bg-neutral-900 rounded py-1 px-2 text-xs text-neutral-200 break-all`}>{children}</code>
);

const MinecraftServicesContainer = () => {
    const server = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [context, setContext] = useState<MinecraftServicesContext>();
    const [active, setActive] = useState<MinecraftServiceOperation | null>(null);
    const [pending, setPending] = useState<PendingAction | null>(null);
    const [loading, setLoading] = useState(true);
    const [working, setWorking] = useState(false);

    const reload = async () => {
        const nextContext = await getMinecraftServicesContext(server);
        setContext(nextContext);
        setActive(nextContext.active_operation);
    };

    useEffect(() => {
        clearFlashes('beacon-minecraft-services');
        reload()
            .catch((error) => clearAndAddHttpError({ key: 'beacon-minecraft-services', error }))
            .finally(() => setLoading(false));
    }, [server]);

    useEffect(() => {
        if (!active || !['pending', 'running'].includes(active.status)) return;
        let cancelled = false;
        let timer: number | undefined;
        let failures = 0;
        const poll = async () => {
            try {
                const operation = await getMinecraftServiceOperation(server, active.uuid);
                if (cancelled) return;
                failures = 0;
                setActive(operation);
                if (['pending', 'running'].includes(operation.status)) {
                    timer = window.setTimeout(poll, POLL_INTERVAL);
                    return;
                }
                await reload();
                addFlash({
                    key: 'beacon-minecraft-services',
                    type: operation.status === 'succeeded' ? 'success' : 'error',
                    message:
                        operation.status === 'succeeded'
                            ? `${operation.service.toUpperCase()} was updated successfully.`
                            : operation.error?.message || 'The Minecraft service operation failed.',
                });
            } catch (error) {
                if (cancelled) return;
                failures++;
                if (!retryablePollingError(error) || failures >= MAX_POLL_FAILURES) {
                    clearAndAddHttpError({ key: 'beacon-minecraft-services', error });
                    return;
                }
                timer = window.setTimeout(poll, Math.min(POLL_INTERVAL * failures, 15000));
            }
        };
        timer = window.setTimeout(poll, POLL_INTERVAL);

        return () => {
            cancelled = true;
            if (timer !== undefined) window.clearTimeout(timer);
        };
    }, [active?.uuid, active?.status, server]);

    const execute = async () => {
        if (!pending) return;
        setWorking(true);
        clearFlashes('beacon-minecraft-services');
        try {
            const operation = await mutateMinecraftService(server, pending.service, pending.action);
            setActive(operation);
            setPending(null);
        } catch (error) {
            clearAndAddHttpError({ key: 'beacon-minecraft-services', error });
        } finally {
            setWorking(false);
        }
    };

    const busy = !!active && ['pending', 'running'].includes(active.status);

    return (
        <>
            <FlashMessageRender byKey={'beacon-minecraft-services'} css={tw`mb-4`} />
            <TitledGreyBox title={'Minecraft RCON / Query'} css={tw`mb-6 md:mb-10`}>
                {loading || !context ? (
                    <Spinner size={'large'} centered />
                ) : (
                    <>
                        <div css={tw`flex items-start border-l-4 border-cyan-500 p-3 mb-5`}>
                            <FontAwesomeIcon icon={faNetworkWired} css={tw`text-cyan-300 mt-1 mr-3`} />
                            <p css={tw`text-xs text-neutral-200`}>
                                Beacon manages dedicated ports and safely stops and restarts the server when these
                                settings change.
                            </p>
                        </div>

                        <div css={tw`space-y-3 text-sm`}>
                            <div css={tw`flex items-center justify-between gap-3`}>
                                <span>RCON Status</span>
                                <Status value={context.rcon.status} />
                            </div>
                            <div css={tw`flex items-center justify-between gap-3`}>
                                <span>RCON Address</span>
                                <Value>
                                    {context.rcon.address && context.rcon.port
                                        ? `${context.rcon.address}:${context.rcon.port}`
                                        : 'Not assigned'}
                                </Value>
                            </div>
                            <div css={tw`flex items-center justify-between gap-3`}>
                                <span>RCON Password</span>
                                <Value>
                                    {context.rcon.password_configured ? '****************' : 'Not configured'}
                                </Value>
                            </div>
                            <div css={tw`flex items-center justify-between gap-3 pt-2 border-t border-neutral-600`}>
                                <span>Query Status</span>
                                <Status value={context.query.status} />
                            </div>
                            <div css={tw`flex items-center justify-between gap-3`}>
                                <span>Query Address</span>
                                <Value>
                                    {context.query.address && context.query.port
                                        ? `${context.query.address}:${context.query.port}`
                                        : 'Not assigned'}
                                </Value>
                            </div>
                        </div>

                        {(context.rcon.drift || context.query.drift) && (
                            <div css={tw`mt-4 text-xs text-yellow-300`}>
                                {context.rcon.drift && <p>{context.rcon.drift}</p>}
                                {context.query.drift && (
                                    <p css={context.rcon.drift ? tw`mt-1` : undefined}>{context.query.drift}</p>
                                )}
                            </div>
                        )}

                        {busy && (
                            <div css={tw`mt-5`}>
                                <div css={tw`flex justify-between text-xs text-neutral-300 mb-2`}>
                                    <span>{active?.progress?.message || 'Waiting for the worker.'}</span>
                                    <span>{active?.progress?.percent || 0}%</span>
                                </div>
                                <div css={tw`h-2 rounded bg-neutral-700 overflow-hidden`}>
                                    <div
                                        css={tw`h-full bg-primary-500 transition-all duration-300`}
                                        style={{ width: `${active?.progress?.percent || 0}%` }}
                                    />
                                </div>
                            </div>
                        )}

                        <div css={tw`flex flex-wrap justify-end gap-2 mt-5`}>
                            {context.rcon.managed && (
                                <Button
                                    type={'button'}
                                    size={'xsmall'}
                                    color={'grey'}
                                    disabled={busy || !!context.rcon.drift}
                                    onClick={() => setPending({ service: 'rcon', action: 'rotate' })}
                                >
                                    <FontAwesomeIcon icon={faKey} css={tw`mr-2`} /> Rotate RCON password
                                </Button>
                            )}
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                color={context.rcon.managed ? 'red' : 'primary'}
                                disabled={busy || !!context.rcon.drift || !context.enabled}
                                onClick={() =>
                                    setPending({ service: 'rcon', action: context.rcon.managed ? 'disable' : 'enable' })
                                }
                            >
                                {context.rcon.managed ? 'Disable' : 'Enable'} RCON
                            </Button>
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                color={context.query.managed ? 'red' : 'primary'}
                                disabled={busy || !!context.query.drift || !context.enabled}
                                onClick={() =>
                                    setPending({
                                        service: 'query',
                                        action: context.query.managed ? 'disable' : 'enable',
                                    })
                                }
                            >
                                {context.query.managed ? 'Disable' : 'Enable'} Query
                            </Button>
                        </div>
                    </>
                )}
            </TitledGreyBox>

            <Modal
                visible={pending !== null}
                onDismissed={() => !working && setPending(null)}
                showSpinnerOverlay={working}
                top={false}
            >
                {pending && (
                    <>
                        <h2 css={tw`text-xl text-neutral-100 mb-3`}>{actionLabel(pending.action, pending.service)}?</h2>
                        <p css={tw`text-sm text-neutral-300`}>
                            {pending.action === 'disable'
                                ? `Beacon will stop the server if needed, disable ${pending.service.toUpperCase()}, release only its managed port, and restore the previous power state.`
                                : pending.action === 'rotate'
                                ? 'Beacon will generate a new secure RCON password. Existing RCON clients will stop working.'
                                : `Beacon will reserve a port, enable ${pending.service.toUpperCase()}, and restore the previous power state.`}
                        </p>
                        <div css={tw`flex justify-end gap-3 mt-6`}>
                            <Button type={'button'} color={'grey'} onClick={() => setPending(null)} disabled={working}>
                                Cancel
                            </Button>
                            <Button
                                type={'button'}
                                color={pending.action === 'disable' ? 'red' : 'primary'}
                                onClick={execute}
                                disabled={working}
                            >
                                {actionLabel(pending.action, pending.service)}
                            </Button>
                        </div>
                    </>
                )}
            </Modal>
        </>
    );
};

export default MinecraftServicesContainer;
