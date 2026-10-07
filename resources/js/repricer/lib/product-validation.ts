import { formatCents, parseMoney } from './money';
import type { RuleForm, RulePayload } from './rule-validation';
import { validateRule } from './rule-validation';

/** The add-product form: money as text ("14.99"), the rule as in the rule editor. */
export interface ProductForm extends RuleForm {
    channel: 'buybox' | 'open';
    title: string;
    sku: string;
    cost: string;
    fees: string;
    shipping: string;
    price: string;
    competitors: string[];
}

export interface ProductPayload extends RulePayload {
    channel: 'buybox' | 'open';
    title: string;
    sku: string;
    cost: number;
    fees: number;
    shipping: number;
    price: number;
    competitors: string[];
}

export type ProductErrors = Partial<Record<keyof ProductForm, string>>;

export const MAX_COMPETITORS = 2;
const MAX_CENTS = 10_000_000;

/** Sensible starting values, so a demo product can be added in a few seconds. */
export function blankProductForm(): ProductForm {
    return {
        channel: 'buybox',
        title: '',
        sku: '',
        cost: '10.00',
        fees: '3.50',
        shipping: '0.00',
        price: '24.99',
        competitors: ['penny_pincher'],
        strategy: 'beat_buybox',
        offset: '0.10',
        floor: '16.50',
        ceiling: '34.99',
        min_margin: '2.00',
        max_step_pct: '5',
        cooldown_sec: '120',
    };
}

/**
 * Client-side mirror of App\Http\Requests\Dashboard\StoreProductRequest (which re-validates
 * everything). The pricing rule goes through the same checks as the rule editor.
 */
export function validateProduct(form: ProductForm): {
    errors: ProductErrors;
    payload: ProductPayload | null;
} {
    const errors: ProductErrors = {};

    const title = form.title.trim();
    if (title.length < 3 || title.length > 80) {
        errors.title = 'Give it a title of 3–80 characters.';
    }

    const sku = form.sku.trim().toUpperCase();
    if (!/^[A-Z0-9][A-Z0-9-]{1,31}$/.test(sku)) {
        errors.sku =
            'Use 2–32 capital letters, digits and dashes (e.g. MUG-CER-12).';
    }

    const money = (
        key: 'cost' | 'fees' | 'shipping' | 'price',
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

    const cost = money('cost', 0, MAX_CENTS);
    const fees = money('fees', 0, MAX_CENTS);
    const shipping = money('shipping', 0, 100_000);
    const price = money('price', 1, MAX_CENTS);

    if (form.competitors.length > MAX_COMPETITORS) {
        errors.competitors = `Pick at most ${MAX_COMPETITORS} competitors.`;
    }

    // The rule's margin floor needs cost and fees; check it once they are valid.
    const rule = validateRule(form, {
        cost: errors.cost || cost === null ? 0 : cost,
        fees: errors.fees || fees === null ? 0 : fees,
        channel: form.channel,
    });
    Object.assign(errors, rule.errors);

    if (
        rule.payload &&
        price !== null &&
        !errors.price &&
        (price < rule.payload.floor || price > rule.payload.ceiling)
    ) {
        errors.price = `The starting price (${formatCents(price)}) must be between the floor (${formatCents(rule.payload.floor)}) and the ceiling (${formatCents(rule.payload.ceiling)}).`;
    }

    if (
        Object.keys(errors).length > 0 ||
        !rule.payload ||
        cost === null ||
        fees === null ||
        shipping === null ||
        price === null
    ) {
        return { errors, payload: null };
    }

    return {
        errors,
        payload: {
            ...rule.payload,
            channel: form.channel,
            title,
            sku,
            cost,
            fees,
            shipping,
            price,
            competitors: form.competitors,
        },
    };
}
