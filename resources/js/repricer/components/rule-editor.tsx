import { useEffect, useMemo, useRef, useState } from 'react';
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
import { centsToInput, formatCents } from '../lib/money';
import type { RuleForm, RulePayload } from '../lib/rule-validation';
import { validateRule } from '../lib/rule-validation';
import type { RulePreviewResult } from '../lib/trace';
import { describePreview } from '../lib/trace';
import type { Product } from '../types';

interface Props {
    product: Product | null;
    onClose: () => void;
    onSave: (
        product: Product,
        payload: RulePayload,
    ) => Promise<{
        ok: boolean;
        errors?: Record<string, string[]>;
        message?: string;
    }>;
    /** Live preview of the draft (absent in read-only replay). */
    onPreview?: (
        product: Product,
        payload: RulePayload,
    ) => Promise<RulePreviewResult | null>;
}

function toForm(p: Product): RuleForm {
    const r = p.rule!;

    return {
        strategy: r.strategy,
        offset: centsToInput(r.offset),
        floor: centsToInput(r.floor),
        ceiling: centsToInput(r.ceiling),
        min_margin: centsToInput(r.min_margin),
        max_step_pct: String(r.max_step_pct),
        cooldown_sec: String(r.cooldown_sec),
    };
}

const FIELDS: {
    key: keyof RuleForm;
    label: string;
    hint: string;
    money?: boolean;
}[] = [
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

export function RuleEditor({ product, onClose, onSave, onPreview }: Props) {
    const [form, setForm] = useState<RuleForm | null>(null);
    const [touched, setTouched] = useState(false);
    const [serverErrors, setServerErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [message, setMessage] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setForm(product?.rule ? toForm(product) : null);
        setTouched(false);
        setServerErrors({});
        setMessage(null);
    }, [product]);

    const result = useMemo(
        () => (form && product ? validateRule(form, product) : null),
        [form, product],
    );

    // Live preview: debounce, and drop answers that arrive after a newer request.
    const [preview, setPreview] = useState<RulePreviewResult | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const latestRequest = useRef(0);
    const payloadKey = result?.payload ? JSON.stringify(result.payload) : null;
    useEffect(() => {
        if (!onPreview || !product || !result?.payload) {
            return;
        }
        const payload = result.payload;
        const id = ++latestRequest.current;
        setPreviewing(true);
        const timer = window.setTimeout(async () => {
            const r = await onPreview(product, payload);
            if (id === latestRequest.current) {
                setPreview(r);
                setPreviewing(false);
            }
        }, 350);

        return () => window.clearTimeout(timer);
    }, [payloadKey, product?.id]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => setPreview(null), [product?.id]);

    if (!product || !form || !result) {
        return null;
    }

    const set = (key: keyof RuleForm, value: string) => {
        setTouched(true);
        setServerErrors({});
        setForm({ ...form, [key]: value } as RuleForm);
    };
    const marginFloor = product.cost + product.fees;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pricing rules · {product.title}</DialogTitle>
                    <DialogDescription>
                        Cost {formatCents(product.cost)} + fees{' '}
                        {formatCents(product.fees)} = {formatCents(marginFloor)}{' '}
                        before margin. Checked here as you type and again on the
                        server.
                    </DialogDescription>
                </DialogHeader>
                <form
                    noValidate
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setTouched(true);
                        if (!result.payload) {
                            return;
                        }
                        setSaving(true);
                        const r = await onSave(product, result.payload);
                        setSaving(false);
                        if (r.ok) {
                            onClose();
                        } else {
                            setServerErrors(r.errors ?? {});
                            setMessage(r.message ?? null);
                        }
                    }}
                    className="space-y-4"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="rule-strategy">Strategy</Label>
                        <select
                            id="rule-strategy"
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground [&>option]:bg-background [&>option]:text-foreground"
                            value={form.strategy}
                            onChange={(e) => set('strategy', e.target.value)}
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
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {FIELDS.map((f) => {
                            const error =
                                (touched ? result.errors[f.key] : undefined) ??
                                serverErrors[f.key]?.[0];

                            return (
                                <div key={f.key} className="space-y-1">
                                    <Label htmlFor={`rule-${f.key}`}>
                                        {f.label}
                                    </Label>
                                    <div className="relative">
                                        {f.money && (
                                            <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-sm text-muted-foreground">
                                                $
                                            </span>
                                        )}
                                        <Input
                                            id={`rule-${f.key}`}
                                            inputMode={
                                                f.money ? 'decimal' : 'numeric'
                                            }
                                            value={form[f.key]}
                                            onChange={(e) =>
                                                set(f.key, e.target.value)
                                            }
                                            aria-invalid={
                                                error ? true : undefined
                                            }
                                            aria-describedby={`rule-${f.key}-hint`}
                                            className={
                                                f.money
                                                    ? 'pl-6 tabular-nums'
                                                    : 'tabular-nums'
                                            }
                                        />
                                    </div>
                                    <p
                                        id={`rule-${f.key}-hint`}
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
                        })}
                    </div>
                    {onPreview && (
                        <div
                            className="rounded-md border border-border bg-muted/40 p-3 text-sm"
                            aria-live="polite"
                        >
                            <div className="mb-1 flex items-center justify-between gap-2 text-xs font-medium text-muted-foreground">
                                <span>With these rules, right now</span>
                                {previewing && <span>updating…</span>}
                            </div>
                            {!result.payload ? (
                                <p className="text-muted-foreground">
                                    Fix the highlighted fields to see what these
                                    rules would do.
                                </p>
                            ) : preview === null ? (
                                <p className="text-muted-foreground">…</p>
                            ) : (
                                <>
                                    {preview.available &&
                                        preview.old_price != null && (
                                            <p className="mb-1 text-base tabular-nums">
                                                {formatCents(preview.old_price)}{' '}
                                                →{' '}
                                                <span className="font-semibold">
                                                    {formatCents(
                                                        preview.new_price ??
                                                            preview.old_price,
                                                    )}
                                                </span>
                                                {preview.outcome ===
                                                    'no_change' && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        no change
                                                    </span>
                                                )}
                                            </p>
                                        )}
                                    <p
                                        className="text-xs leading-snug"
                                        data-testid="rule-preview"
                                    >
                                        {describePreview(preview)}
                                    </p>
                                    {(preview.notes ?? []).map((n) => (
                                        <p
                                            key={n}
                                            className="mt-1 text-xs text-amber-800 dark:text-amber-300"
                                        >
                                            {n}
                                        </p>
                                    ))}
                                    {preview.available && (
                                        <p className="mt-1 text-[11px] text-muted-foreground">
                                            Against the latest market snapshot.
                                            Nothing is saved or pushed until you
                                            save.
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                    {message && !Object.keys(serverErrors).length && (
                        <p className="text-sm text-red-700">{message}</p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                saving || (touched && result.payload === null)
                            }
                        >
                            Save rules
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
