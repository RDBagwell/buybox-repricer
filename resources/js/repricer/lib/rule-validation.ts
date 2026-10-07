import type { Product, Rule } from '../types';
import { formatCents, parseMoney } from './money';

/** What the rule editor's fields hold: money as text ("26.99"), the rest as text or enums. */
export interface RuleForm {
    strategy: Rule['strategy'];
    offset: string;
    floor: string;
    ceiling: string;
    min_margin: string;
    max_step_pct: string;
    cooldown_sec: string;
}

export type RulePayload = Pick<
    Rule,
    | 'strategy'
    | 'offset'
    | 'floor'
    | 'ceiling'
    | 'min_margin'
    | 'max_step_pct'
    | 'cooldown_sec'
>;

export type RuleErrors = Partial<Record<keyof RuleForm, string>>;

const MAX_CENTS = 10_000_000;

function integer(value: string): number | null {
    return /^\d{1,9}$/.test(value.trim()) ? Number(value.trim()) : null;
}

/**
 * Client-side mirror of App\Http\Requests\Dashboard\UpdatePricingRuleRequest, for instant
 * feedback. The server re-validates everything; keep the two in step.
 */
export function validateRule(
    form: RuleForm,
    product: Pick<Product, 'cost' | 'fees'> & { channel?: Product['channel'] },
): { errors: RuleErrors; payload: RulePayload | null } {
    const errors: RuleErrors = {};

    const money = (
        key: 'offset' | 'floor' | 'ceiling' | 'min_margin',
        min: number,
        max: number,
    ): number | null => {
        const cents = parseMoney(form[key]);
        if (cents === null) {
            errors[key] = 'Enter an amount like 12.34.';

            return null;
        }
        if (cents < min) {
            errors[key] = `Must be at least ${formatCents(min)}.`;
        } else if (cents > max) {
            errors[key] = `Must be at most ${formatCents(max)}.`;
        }

        return cents;
    };

    const offset = money('offset', 0, 10_000);
    const floor = money('floor', 1, MAX_CENTS);
    const ceiling = money('ceiling', 1, MAX_CENTS);
    const minMargin = money('min_margin', 0, MAX_CENTS);

    const step = integer(form.max_step_pct);
    if (step === null || step < 1 || step > 50) {
        errors.max_step_pct =
            'Step limit must be a whole percentage between 1 and 50.';
    }

    const cooldown = integer(form.cooldown_sec);
    if (cooldown === null || cooldown > 86_400) {
        errors.cooldown_sec = 'Cooldown must be between 0 and 86400 seconds.';
    }

    if (
        !['beat_lowest', 'match_lowest', 'beat_buybox'].includes(form.strategy)
    ) {
        errors.strategy = 'Choose a strategy.';
    } else if (product.channel === 'open' && form.strategy === 'beat_buybox') {
        errors.strategy =
            'There is no Buy Box on an open-listing marketplace: beat or match the lowest price instead.';
    }

    if (
        floor !== null &&
        ceiling !== null &&
        !errors.floor &&
        !errors.ceiling &&
        floor > ceiling
    ) {
        errors.floor = `The floor (${formatCents(floor)}) must not be above the ceiling (${formatCents(ceiling)}).`;
    }

    if (
        floor !== null &&
        minMargin !== null &&
        !errors.floor &&
        !errors.min_margin
    ) {
        const marginFloor = product.cost + product.fees + minMargin;
        if (floor < marginFloor) {
            errors.floor = `The floor (${formatCents(floor)}) is below the margin floor (${formatCents(marginFloor)} = cost + fees + minimum margin).`;
        }
    }

    if (
        Object.keys(errors).length > 0 ||
        offset === null ||
        floor === null ||
        ceiling === null ||
        minMargin === null ||
        step === null ||
        cooldown === null
    ) {
        return { errors, payload: null };
    }

    return {
        errors,
        payload: {
            strategy: form.strategy,
            offset,
            floor,
            ceiling,
            min_margin: minMargin,
            max_step_pct: step,
            cooldown_sec: cooldown,
        },
    };
}
