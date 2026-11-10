import React from 'react';
import { Link } from 'react-router-dom';
import tw from 'twin.macro';
import TitledGreyBox from '@/components/elements/TitledGreyBox';

const BedrockConsoleHelp = ({ serverId }: { serverId: string }) => (
    <TitledGreyBox title={'Bedrock Console / API'} css={tw`mb-6 md:mb-10`}>
        <p css={tw`text-sm text-neutral-200`}>
            Vanilla Bedrock does not support native RCON. Send commands through Console or the Panel Client API.
        </p>
        <p css={tw`text-xs text-neutral-300 mt-2`}>
            The API accepts commands; output appears in Console, not in the API response. No additional port is
            required.
        </p>
        <code css={tw`block text-xs text-neutral-200 break-all mt-3`}>
            POST /api/client/servers/{'{server}'}/command
        </code>
        <Link css={tw`text-cyan-300 inline-block mt-3 text-sm`} to={`/server/${serverId}`}>
            Open Console
        </Link>
    </TitledGreyBox>
);

export default BedrockConsoleHelp;
