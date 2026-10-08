import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import type { FeedFilter, FeedKind } from '../lib/feed-filter';
import {
    DEFAULT_FEED_FILTER,
    FEED_KINDS,
    filterDecisions,
} from '../lib/feed-filter';
import { formatCents } from '../lib/money';
import { formatMarketTime } from '../lib/time';
import type { Badge } from '../lib/trace';
import { BADGE_LABELS, badgeFor, describeTrace, ruleLabel } from '../lib/trace';
import type { Decision, Product } from '../types';

const OUTCOME_STYLES: Record<Badge, string> = {
    blocked:
        'bg-orange-100 text-orange-900 dark:bg-orange-900/40 dark:text-orange-100',
    push_failed: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
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

export function OutcomeBadge({ outcome }: { outcome: Badge }) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold tracking-wide uppercase',
                OUTCOME_STYLES[outcome],
            )}
        >
            {BADGE_LABELS[outcome]}
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
                        <OutcomeBadge outcome={badgeFor(decision)} />
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
    filter,
    onFilterChange,
    expandFirst = false,
}: {
    decisions: Decision[];
    products: Product[];
    filter: FeedFilter;
    onFilterChange: (f: FeedFilter) => void;
    expandFirst?: boolean;
}) {
    const byId = new Map(products.map((p) => [p.id, p]));
    const { shown, counts, cooldownHidden } = filterDecisions(
        decisions,
        filter,
    );
    const toggleKind = (kind: FeedKind) =>
        onFilterChange({
            ...filter,
            kinds: filter.kinds.includes(kind)
                ? filter.kinds.filter((k) => k !== kind)
                : [...filter.kinds, kind],
        });
    const filtered =
        filter.kinds.length < FEED_KINDS.length || cooldownHidden > 0;

    return (
        <div>
            <div
                className="mb-2 flex flex-wrap items-center gap-1.5 px-1"
                role="group"
                aria-label="Show decisions of these kinds"
            >
                {FEED_KINDS.map(({ kind, label, hint }) => {
                    const on = filter.kinds.includes(kind);

                    return (
                        <button
                            key={kind}
                            type="button"
                            aria-pressed={on}
                            title={hint}
                            onClick={() => toggleKind(kind)}
                            className={cn(
                                'rounded-full border px-2.5 py-0.5 text-xs tabular-nums transition-colors',
                                on
                                    ? 'border-foreground/20 bg-muted font-medium text-foreground'
                                    : 'border-border text-muted-foreground line-through decoration-muted-foreground/50 hover:bg-muted/50',
                            )}
                        >
                            {label} {counts[kind]}
                        </button>
                    );
                })}
                <label className="ml-auto flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
                    <input
                        type="checkbox"
                        checked={filter.hideCooldown}
                        onChange={(e) =>
                            onFilterChange({
                                ...filter,
                                hideCooldown: e.target.checked,
                            })
                        }
                        className="accent-[#0072B2]"
                    />
                    Hide cooldown skips
                    {filter.hideCooldown && cooldownHidden > 0 && (
                        <span className="tabular-nums">({cooldownHidden})</span>
                    )}
                </label>
            </div>

            {shown.length === 0 ? (
                <div className="px-3 py-8 text-center text-sm text-muted-foreground">
                    {filtered ? (
                        <>
                            <p>No decisions match these filters.</p>
                            <button
                                type="button"
                                className="mt-2 text-xs font-medium text-foreground underline underline-offset-2"
                                onClick={() =>
                                    onFilterChange({
                                        ...DEFAULT_FEED_FILTER,
                                        productId: filter.productId,
                                        hideCooldown: false,
                                    })
                                }
                            >
                                Show everything
                            </button>
                        </>
                    ) : (
                        <p>
                            No decisions yet. They appear here as soon as a
                            competitor moves.
                        </p>
                    )}
                </div>
            ) : (
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
            )}
        </div>
    );
}
