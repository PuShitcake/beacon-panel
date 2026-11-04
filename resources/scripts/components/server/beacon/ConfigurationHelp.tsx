import React from 'react';
import tw from 'twin.macro';
import { MinecraftConfiguration } from '@/api/server/beacon/configuration';
import { configurationSummary, configurationVersionSourceLabel } from '@/api/server/beacon/configurationSource';

const ConfigurationHelp = ({ configuration }: { configuration: MinecraftConfiguration }) => {
    const [summary, caution] = configurationSummary(configuration);
    const versionSource = configurationVersionSourceLabel(configuration);

    return (
        <div css={tw`text-xs mb-5`}>
            <p css={tw`text-yellow-200`}>{summary}</p>
            <p css={tw`text-neutral-300 mt-1`}>{caution}</p>
            <details css={tw`mt-2 text-neutral-300`}>
                <summary css={tw`cursor-pointer w-max hover:text-neutral-100`}>More details</summary>
                <div css={tw`mt-2 space-y-2 leading-relaxed`}>
                    <p>Only existing, supported fields are editable. Missing settings and defaults are not added.</p>
                    {versionSource && <p>{versionSource}</p>}
                    {configuration.notes?.map((note) => (
                        <p key={note}>{note}</p>
                    ))}
                </div>
            </details>
        </div>
    );
};

export default ConfigurationHelp;
