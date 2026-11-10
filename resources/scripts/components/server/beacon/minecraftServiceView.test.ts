import { minecraftServiceView } from './minecraftServiceView';

describe('Minecraft service guidance boundary', () => {
    it('shows console guidance only for explicitly unsupported, console-only runtimes', () => {
        expect(minecraftServiceView({ supported: false, console_only: true })).toBe('bedrock-console');
    });

    it.each([{ supported: false }, { supported: false, console_only: false }])(
        'keeps other unsupported runtimes hidden',
        (context) => expect(minecraftServiceView(context)).toBe('hidden')
    );

    it.each([undefined, {}, { supported: true }, { supported: true, console_only: false }])(
        'preserves supported and older-backend loading behavior',
        (context) => expect(minecraftServiceView(context)).toBe('rcon')
    );
});
