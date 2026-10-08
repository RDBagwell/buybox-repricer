import { formatCents } from '../lib/money';
import { formatMarketTime } from '../lib/time';
import type { AuditItem, Product } from '../types';

const ACTION_LABELS: Record<string, string> = {
    'breaker.tripped': 'Circuit breaker tripped',
    'product.paused': 'Product paused',
    'product.resumed': 'Product resumed',
    'product.created': 'Product added',
    'product.archived': 'Product archived',
    'product.restored': 'Product restored',
    'rule.updated': 'Rules changed',
    'kill_switch.on': 'Kill switch on',
    'kill_switch.off': 'Kill switch off',
    'dry_run.on': 'Dry run on',
    'dry_run.off': 'Dry run off',
};

function changes(a: AuditItem): string | null {
    if (a.action !== 'rule.updated' || !a.before || !a.after) {
        return null;
    }

    const money = ['floor', 'ceiling', 'min_margin', 'offset'];
    const show = (k: string, v: unknown) =>
        money.includes(k) && typeof v === 'number' ? formatCents(v) : String(v);

    return Object.keys(a.after)
        .map(
            (k) =>
                `${k.replace(/_/g, ' ')} ${show(k, a.before?.[k])} → ${show(k, a.after?.[k])}`,
        )
        .join(', ');
}

export function AuditList({
    items,
    products,
}: {
    items: AuditItem[];
    products: Product[];
}) {
    const byId = new Map(products.map((p) => [p.id, p.title]));
    const shown = items.filter((a) => !a.action.startsWith('sim.')).slice(0, 8);
    if (shown.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No operator or safety actions yet.
            </p>
        );
    }

    return (
        <ul className="space-y-2 text-sm">
            {shown.map((a) => (
                <li
                    key={a.id}
                    className="flex flex-col gap-0.5 border-b border-border pb-2 last:border-0"
                >
                    <div className="flex items-baseline gap-2">
                        <span
                            className={
                                a.action === 'breaker.tripped'
                                    ? 'font-semibold text-amber-800 dark:text-amber-300'
                                    : 'font-medium'
                            }
                        >
                            {ACTION_LABELS[a.action] ?? a.action}
                        </span>
                        {a.product_id !== null && (
                            <span className="truncate text-muted-foreground">
                                {byId.get(a.product_id)}
                            </span>
                        )}
                        <span className="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums">
                            {a.market_time
                                ? formatMarketTime(a.market_time)
                                : ''}
                        </span>
                    </div>
                    {(a.reason || changes(a)) && (
                        <p className="text-xs text-muted-foreground">
                            {changes(a) ?? a.reason}
                        </p>
                    )}
                    <p className="text-[11px] text-muted-foreground">
                        by {a.actor}
                    </p>
                </li>
            ))}
        </ul>
    );
}
