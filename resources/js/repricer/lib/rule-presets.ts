import { centsToInput } from './money';
import type { RuleForm } from './rule-validation';

export type PresetKey = 'aggressive' | 'balanced' | 'margin_first';

interface Preset {
    key: PresetKey;
    label: string;
    hint: string;
    strategy: RuleForm['strategy'];
    offset: number; // cents
    marginBps: number; // minimum margin as a share of cost + fees, in basis points
    minMargin: number; // ...but never below this many cents
    stepPct: number;
    cooldownSec: number;
}

export const PRESETS: Preset[] = [
    {
        key: 'aggressive',
        label: 'Aggressive',
        hint: 'Beat the Buy Box holder by 5¢, thin margin, big fast steps',
        strategy: 'beat_buybox',
        offset: 5,
        marginBps: 800,
        minMargin: 100,
        stepPct: 10,
        cooldownSec: 60,
    },
    {
        key: 'balanced',
        label: 'Balanced',
        hint: 'Beat the Buy Box holder by 10¢, moderate margin and steps',
        strategy: 'beat_buybox',
        offset: 10,
        marginBps: 1500,
        minMargin: 200,
        stepPct: 5,
        cooldownSec: 120,
    },
    {
        key: 'margin_first',
        label: 'Margin-first',
        hint: 'Only match the lowest price, keep a wide margin, move slowly',
        strategy: 'match_lowest',
        offset: 0,
        marginBps: 3000,
        minMargin: 300,
        stepPct: 3,
        cooldownSec: 300,
    },
];

/**
 * The rule fields a preset sets for a product with this cost and fees (integer cents). The floor
 * sits exactly at the margin floor (cost + fees + minimum margin), so a preset is always valid;
 * the ceiling is kept unless it would fall below the floor, then it becomes floor + 25%.
 */
export function presetRule(
    key: PresetKey,
    product: {
        cost: number;
        fees: number;
        ceiling: number | null;
        channel?: 'buybox' | 'open';
    },
): Pick<
    RuleForm,
    | 'strategy'
    | 'offset'
    | 'floor'
    | 'ceiling'
    | 'min_margin'
    | 'max_step_pct'
    | 'cooldown_sec'
> {
    const p = PRESETS.find((x) => x.key === key)!;
    const base = product.cost + product.fees;
    const minMargin = Math.max(
        p.minMargin,
        Math.ceil((base * p.marginBps) / 10_000),
    );
    const floor = base + minMargin;
    const ceiling =
        product.ceiling !== null && product.ceiling >= floor
            ? product.ceiling
            : floor + Math.ceil((floor * 2500) / 10_000);

    return {
        // No Buy Box on open listings: "beat the holder" becomes "beat the lowest".
        strategy:
            product.channel === 'open' && p.strategy === 'beat_buybox'
                ? 'beat_lowest'
                : p.strategy,
        offset: centsToInput(p.offset),
        floor: centsToInput(floor),
        ceiling: centsToInput(ceiling),
        min_margin: centsToInput(minMargin),
        max_step_pct: String(p.stepPct),
        cooldown_sec: String(p.cooldownSec),
    };
}
