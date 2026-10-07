import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';

const NEXT: Record<Appearance, Appearance> = {
    system: 'light',
    light: 'dark',
    dark: 'system',
};

const LABEL: Record<Appearance, string> = {
    system: 'System theme',
    light: 'Light theme',
    dark: 'Dark theme',
};

/** Cycles system → light → dark. Stored like the starter kit's appearance setting. */
export function ThemeToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const Icon =
        appearance === 'light' ? Sun : appearance === 'dark' ? Moon : Monitor;

    return (
        <Button
            variant="outline"
            size="icon"
            className="size-8"
            onClick={() => updateAppearance(NEXT[appearance])}
            title={`${LABEL[appearance]} (click for ${LABEL[NEXT[appearance]].toLowerCase()})`}
            aria-label={`${LABEL[appearance]}. Switch to ${LABEL[NEXT[appearance]].toLowerCase()}`}
        >
            <Icon className="size-4" />
        </Button>
    );
}
