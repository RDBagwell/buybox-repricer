import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { centsToInput, formatCents, parseMoney } from '../lib/money';
import { presetRule } from '../lib/rule-presets';
import type { ProductForm, ProductPayload } from '../lib/product-validation';
import { RulePresetButtons } from './rule-presets';
import {
    blankProductForm,
    MAX_COMPETITORS,
    validateProduct,
} from '../lib/product-validation';

const BOT_LABELS: Record<string, string> = {
    penny_pincher: 'Penny Pincher',
    anchor: 'Anchor',
    matcher: 'Matcher',
    sleeper: 'Sleeper',
    chaos: 'Chaos',
};

const BOT_HINTS: Record<string, string> = {
    penny_pincher: 'undercuts the Buy Box by 1¢',
    anchor: 'holds one price',
    matcher: 'matches the lowest price',
    sleeper: 'sells, then runs out of stock',
    chaos: 'moves at random',
};

type Field = {
    key: keyof ProductForm;
    label: string;
    hint: string;
    money?: boolean;
    wide?: boolean;
};

const PRODUCT_FIELDS: Field[] = [
    { key: 'title', label: 'Title', hint: 'What shoppers see', wide: true },
    { key: 'sku', label: 'SKU', hint: 'Your stock code, e.g. MUG-CER-12' },
    {
        key: 'price',
        label: 'Starting price',
        hint: 'Between floor and ceiling',
        money: true,
    },
    { key: 'cost', label: 'Cost', hint: 'What you pay per unit', money: true },
    { key: 'fees', label: 'Fees', hint: 'Marketplace fees', money: true },
    {
        key: 'shipping',
        label: 'Shipping',
        hint: 'Charged to the buyer',
        money: true,
    },
];

const RULE_FIELDS: Field[] = [
    {
        key: 'floor',
        label: 'Floor',
        hint: 'Never price below this',
        money: true,
    },
    {
        key: 'ceiling',
        label: 'Ceiling',
        hint: 'Never price above this',
        money: true,
    },
    {
        key: 'min_margin',
        label: 'Minimum margin',
        hint: 'Over cost + fees',
        money: true,
    },
    {
        key: 'offset',
        label: 'Undercut by',
        hint: 'For the "beat" strategies',
        money: true,
    },
    {
        key: 'max_step_pct',
        label: 'Step limit (%)',
        hint: 'Largest single move',
    },
    {
        key: 'cooldown_sec',
        label: 'Cooldown (s)',
        hint: 'Market seconds between changes',
    },
];

interface Props {
    open: boolean;
    botTypes: string[];
    onClose: () => void;
    onCreate: (payload: ProductPayload) => Promise<{
        ok: boolean;
        errors?: Record<string, string[]>;
        message?: string;
    }>;
}

export function AddProductDialog({ open, botTypes, onClose, onCreate }: Props) {
    const [form, setForm] = useState<ProductForm>(blankProductForm);
    const [touched, setTouched] = useState<Set<string>>(new Set());
    const [submitted, setSubmitted] = useState(false);
    const [serverErrors, setServerErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [message, setMessage] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const result = useMemo(() => validateProduct(form), [form]);

    const close = () => {
        setForm(blankProductForm());
        setTouched(new Set());
        setSubmitted(false);
        setServerErrors({});
        setMessage(null);
        onClose();
    };

    const set = (key: keyof ProductForm, value: string | string[]) => {
        setTouched((t) => new Set(t).add(key));
        setServerErrors({});
        setMessage(null);
        setForm({ ...form, [key]: value } as ProductForm);
    };

    const errorFor = (key: string): string | undefined =>
        (submitted || touched.has(key)
            ? result.errors[key as keyof ProductForm]
            : undefined) ?? serverErrors[key]?.[0];

    const cost = parseMoney(form.cost);
    const fees = parseMoney(form.fees);
    const minMargin = parseMoney(form.min_margin);
    const marginFloor =
        cost !== null && fees !== null && minMargin !== null
            ? cost + fees + minMargin
            : null;

    const renderField = (f: Field) => {
        const error = errorFor(f.key);

        return (
            <div
                key={f.key}
                className={cn('space-y-1', f.wide && 'sm:col-span-2')}
            >
                <Label htmlFor={`product-${f.key}`}>{f.label}</Label>
                <div className="relative">
                    {f.money && (
                        <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-sm text-muted-foreground">
                            $
                        </span>
                    )}
                    <Input
                        id={`product-${f.key}`}
                        inputMode={
                            f.money
                                ? 'decimal'
                                : f.key === 'title' || f.key === 'sku'
                                  ? 'text'
                                  : 'numeric'
                        }
                        value={form[f.key] as string}
                        onChange={(e) => set(f.key, e.target.value)}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={`product-${f.key}-hint`}
                        className={cn(
                            f.money ? 'pl-6 tabular-nums' : '',
                            f.key === 'sku' && 'uppercase',
                        )}
                    />
                </div>
                <p
                    id={`product-${f.key}-hint`}
                    className={
                        error
                            ? 'text-xs text-red-700 dark:text-red-400'
                            : 'text-xs text-muted-foreground'
                    }
                    role={error ? 'alert' : undefined}
                >
                    {error ?? f.hint}
                </p>
            </div>
        );
    };

    const competitorsError = errorFor('competitors');

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Add a product</DialogTitle>
                    <DialogDescription>
                        It gets its own listing in the simulated marketplace,
                        against the competitors you pick, and the repricer
                        starts on it straight away. Checked here as you type and
                        again on the server.
                    </DialogDescription>
                </DialogHeader>
                <form
                    noValidate
                    className="space-y-5"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setSubmitted(true);
                        if (!result.payload) {
                            return;
                        }
                        setSaving(true);
                        const r = await onCreate(result.payload);
                        setSaving(false);
                        if (r.ok) {
                            close();
                        } else {
                            setServerErrors(r.errors ?? {});
                            setMessage(r.message ?? null);
                        }
                    }}
                >
                    <fieldset className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <legend className="sr-only">Product</legend>
                        {PRODUCT_FIELDS.map(renderField)}
                    </fieldset>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            Pricing rules
                        </legend>
                        <RulePresetButtons
                            disabled={cost === null || fees === null}
                            onPick={(key) => {
                                if (cost === null || fees === null) {
                                    return;
                                }
                                const rule = presetRule(key, {
                                    cost,
                                    fees,
                                    ceiling: parseMoney(form.ceiling),
                                });
                                // Keep the starting price inside the new floor..ceiling.
                                const floor = parseMoney(rule.floor) ?? 0;
                                const ceiling = parseMoney(rule.ceiling) ?? 0;
                                const price = parseMoney(form.price);
                                const clamped =
                                    price === null
                                        ? floor
                                        : Math.min(
                                              ceiling,
                                              Math.max(floor, price),
                                          );
                                setTouched((t) => new Set(t));
                                setServerErrors({});
                                setForm({
                                    ...form,
                                    ...rule,
                                    price: centsToInput(clamped),
                                });
                            }}
                        />
                        <div className="space-y-1.5">
                            <Label htmlFor="product-strategy">Strategy</Label>
                            <select
                                id="product-strategy"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground [&>option]:bg-background [&>option]:text-foreground"
                                value={form.strategy}
                                onChange={(e) =>
                                    set('strategy', e.target.value)
                                }
                            >
                                <option value="beat_buybox">
                                    Beat the Buy Box holder
                                </option>
                                <option value="beat_lowest">
                                    Beat the lowest price
                                </option>
                                <option value="match_lowest">
                                    Match the lowest price
                                </option>
                            </select>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {RULE_FIELDS.map(renderField)}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Margin floor:{' '}
                            {marginFloor === null
                                ? '—'
                                : `${formatCents(marginFloor)} (cost + fees + minimum margin)`}
                            . The floor can't go below it.
                        </p>
                    </fieldset>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Competitors{' '}
                            <span className="font-normal text-muted-foreground">
                                (up to {MAX_COMPETITORS})
                            </span>
                        </legend>
                        <div className="flex flex-wrap gap-2">
                            {botTypes.map((t) => {
                                const on = form.competitors.includes(t);

                                return (
                                    <label
                                        key={t}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs',
                                            on
                                                ? 'border-[#0072B2] bg-sky-50 dark:bg-sky-950/40'
                                                : 'border-input',
                                        )}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={on}
                                            onChange={() =>
                                                set(
                                                    'competitors',
                                                    on
                                                        ? form.competitors.filter(
                                                              (c) => c !== t,
                                                          )
                                                        : [
                                                              ...form.competitors,
                                                              t,
                                                          ],
                                                )
                                            }
                                            className="accent-[#0072B2]"
                                        />
                                        <span className="font-medium">
                                            {BOT_LABELS[t] ?? t}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {BOT_HINTS[t] ?? ''}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                        <p
                            className={
                                competitorsError
                                    ? 'text-xs text-red-700 dark:text-red-400'
                                    : 'text-xs text-muted-foreground'
                            }
                            role={competitorsError ? 'alert' : undefined}
                        >
                            {competitorsError ??
                                'None at all is fine too: then it has the listing to itself.'}
                        </p>
                    </fieldset>

                    {message && !Object.keys(serverErrors).length && (
                        <p className="text-sm text-red-700 dark:text-red-400">
                            {message}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                saving || (submitted && result.payload === null)
                            }
                        >
                            Add product
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
