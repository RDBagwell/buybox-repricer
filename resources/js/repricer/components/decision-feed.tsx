import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { formatCents } from '../lib/money';
import { formatMarketTime } from '../lib/time';
import { describeTrace, OUTCOME_LABELS, ruleLabel } from '../lib/trace';
import type { Decision, Product } from '../types';

const OUTCOME_STYLES: Record<Decision['outcome'], string> = {
    reprice: 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100',
    dry_run:
        'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
    no_change: 'bg-muted text-muted-foreground',
    skipped: 'bg-muted text-muted-foreground',
    config_error:
        'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
    stale: 'bg-muted text-muted-foreground',
};

const TONE_STYLES = {
    info: 'text-muted-foreground',
    changed: 'text-foreground',
    ok: 'text-emerald-700 dark:text-emerald-400',
    stop: 'text-red-700 dark:text-red-400',
};

export function OutcomeBadge({ outcome }: { outcome: Decision['outcome'] }) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold tracking-wide uppercase',
                OUTCOME_STYLES[outcome],
            )}
        >
            {OUTCOME_LABELS[outcome]}
        </span>
    );
}

function DecisionRow({
    decision,
    product,
    defaultOpen,
}: {
    decision: Decision;
    product: Product | undefined;
    defaultOpen: boolean;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const described = describeTrace(decision);
    const id = `decision-${decision.id}`;

    return (
        <li className="border-b border-border last:border-0">
            <button
                type="button"
                className="flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                aria-controls={id}
            >
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <OutcomeBadge outcome={decision.outcome} />
                        <span className="truncate font-medium text-foreground">
                            {product?.title ?? `Product ${decision.product_id}`}
                        </span>
                        <span className="ml-auto shrink-0 tabular-nums">
                            {formatMarketTime(decision.event_time, true)}
                        </span>
                    </div>
                    <p className="text-sm leading-snug">{described.summary}</p>
                    {decision.new_price !== null && (
                        <p className="text-xs text-muted-foreground tabular-nums">
                            {formatCents(decision.old_price)} →{' '}
                            <span className="font-semibold text-foreground">
                                {formatCents(decision.new_price)}
                            </span>
                        </p>
                    )}
                </div>
                <ChevronDown
                    className={cn(
                        'mt-1 size-4 shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-180',
                    )}
                    aria-hidden
                />
            </button>
            {open && (
                <div id={id} className="px-3 pb-3">
                    <ol className="space-y-1.5 rounded-md bg-muted/40 p-2.5 text-xs">
                        {described.detail.map((t, i) => (
                            <li
                                key={i}
                                className="grid grid-cols-[7.5rem_1fr] gap-2 sm:grid-cols-[9rem_4.5rem_1fr]"
                            >
                                <span className="font-medium">
                                    {ruleLabel(t.rule)}
                                </span>
                                <span
                                    className={cn(
                                        'hidden tabular-nums sm:block',
                                        t.verdict === 'veto'
                                            ? TONE_STYLES.stop
                                            : t.verdict === 'propose'
                                              ? TONE_STYLES.changed
                                              : TONE_STYLES.info,
                                    )}
                                >
                                    {t.verdict === 'propose'
                                        ? formatCents(t.price_after)
                                        : t.verdict}
                                </span>
                                <span className="text-muted-foreground">
                                    {t.reason}
                                </span>
                            </li>
                        ))}
                        {decision.trace.length === 0 && (
                            <li className="text-muted-foreground">
                                {decision.reason}
                            </li>
                        )}
                    </ol>
                    {decision.push && (
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            Push: {decision.push.status} after{' '}
                            {decision.push.attempts} attempt
                            {decision.push.attempts === 1 ? '' : 's'}
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}

export function DecisionFeed({
    decisions,
    products,
    productFilter,
    expandFirst = false,
}: {
    decisions: Decision[];
    products: Product[];
    productFilter: number | null;
    expandFirst?: boolean;
}) {
    const byId = new Map(products.map((p) => [p.id, p]));
    const shown =
        productFilter === null
            ? decisions
            : decisions.filter((d) => d.product_id === productFilter);

    if (shown.length === 0) {
        return (
            <p className="px-3 py-8 text-center text-sm text-muted-foreground">
                No decisions yet. They appear here as soon as a competitor
                moves.
            </p>
        );
    }

    return (
        <ol
            aria-live="polite"
            aria-label="Pricing decisions, newest first"
            className="max-h-[38rem] overflow-y-auto"
        >
            {shown.slice(0, 80).map((d, i) => (
                <DecisionRow
                    key={d.id}
                    decision={d}
                    product={byId.get(d.product_id)}
                    defaultOpen={expandFirst && i === 0}
                />
            ))}
        </ol>
    );
}
