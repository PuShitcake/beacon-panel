import React, { useEffect, useMemo, useState } from 'react';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { ServerContext } from '@/state/server';
import Input from '@/components/elements/Input';
import Label from '@/components/elements/Label';
import CopyOnClick from '@/components/elements/CopyOnClick';
import { ip } from '@/lib/formatters';
import { Button } from '@/components/elements/button/index';
import getPublicSftpAlias from '@/api/server/getPublicSftpAlias';
import { useFlashKey } from '@/plugins/useFlash';
import tw from 'twin.macro';
import isEqual from 'react-fast-compare';

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const sftp = ServerContext.useStoreState((state) => state.server.data!.sftpDetails, isEqual);
    const [username, setUsername] = useState<string | null>(null);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('settings');

    useEffect(() => {
        setUsername(null);
        clearFlashes();
        getPublicSftpAlias(uuid).then(setUsername).catch(clearAndAddHttpError);
    }, [uuid]);

    const launchUrl = useMemo(
        () => (username ? `sftp://${encodeURIComponent(username)}@${ip(sftp.ip)}:${sftp.port}` : null),
        [username, sftp]
    );

    return (
        <TitledGreyBox title={'SFTP Details'} css={tw`mb-6 md:mb-10`}>
            <div>
                <Label>Server Address</Label>
                <CopyOnClick text={`sftp://${ip(sftp.ip)}:${sftp.port}`}>
                    <Input type={'text'} value={`sftp://${ip(sftp.ip)}:${sftp.port}`} readOnly />
                </CopyOnClick>
            </div>
            <div css={tw`mt-6`}>
                <Label>Username</Label>
                {username ? (
                    <CopyOnClick text={username}>
                        <Input type={'text'} value={username} readOnly />
                    </CopyOnClick>
                ) : (
                    <Input type={'text'} value={'Loading public SFTP alias...'} readOnly />
                )}
            </div>
            <div css={tw`mt-6 flex items-center`}>
                <div css={tw`flex-1`}>
                    <div css={tw`border-l-4 border-cyan-500 p-3`}>
                        <p css={tw`text-xs text-neutral-200`}>
                            Your SFTP password is the same as the password you use to access this panel.
                        </p>
                    </div>
                </div>
                {launchUrl && (
                    <div css={tw`ml-4`}>
                        <a href={launchUrl}>
                            <Button.Text variant={Button.Variants.Secondary}>Launch SFTP</Button.Text>
                        </a>
                    </div>
                )}
            </div>
        </TitledGreyBox>
    );
};
