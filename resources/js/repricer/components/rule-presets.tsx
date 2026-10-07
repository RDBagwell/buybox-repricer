import { Button } from '@/components/ui/button';
import type { PresetKey } from '../lib/rule-presets';
import { PRESETS } from '../lib/rule-presets';

/** One-click starting points for the pricing rule; every field stays editable afterwards. */
export function RulePresetButtons({
    onPick,
    disabled = false,
}: {
    onPick: (key: PresetKey) => void;
    disabled?: boolean;
}) {
    return (
        <div
            className="flex flex-wrap items-center gap-1.5"
            role="group"
            aria-label="Rule presets"
        >
            <span className="mr-1 text-xs text-muted-foreground">Presets:</span>
            {PRESETS.map((p) => (
                <Button
                    key={p.key}
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 px-2.5 text-xs"
                    disabled={disabled}
                    title={p.hint}
                    onClick={() => onPick(p.key)}
                >
                    {p.label}
                </Button>
            ))}
        </div>
    );
}
