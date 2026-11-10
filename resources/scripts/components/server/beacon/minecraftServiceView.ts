import type { MinecraftServicesContext } from '@/api/server/beacon/minecraftServices';

export const minecraftServiceView = (context?: Pick<MinecraftServicesContext, 'supported' | 'console_only'>) => {
    if (context?.supported !== false) return 'rcon';

    return context.console_only === true ? 'bedrock-console' : 'hidden';
};
