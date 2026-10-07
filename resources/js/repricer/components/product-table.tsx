import { CircleAlert, Pause, Pencil, Play, Trophy } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { formatBps, formatCents } from '../lib/money';
import type { Product } from '../types';

interface Props {
    products: Product[];
    selectedId: number | null;
    readOnly: boolean;
    onSelect: (id: number) => void;
    onPause: (p: Product) => Promise<void>;
    onResume: (p: Product) => Promise<void>;
    onEditRule: (p: Product) => void;
}

function BuyBoxStatus({ p }: { p: Product }) {
    if (p.buybox.ours) {
        return (
            <span className="inline-flex items-center gap-1 font-medium text-[#0072B2] dark:text-sky-300">
                <Trophy className="size-3.5" aria-hidden /> Ours
            </span>
        );
    }

    return (
        <span className="text-muted-foreground">
            {p.buybox.winner ?? (p.buybox.since ? 'Suppressed' : '—')}
        </span>
    );
}

function PauseToggle({
    p,
    readOnly,
    onPause,
    onAskResume,
}: {
    p: Product;
    readOnly: boolean;
    onPause: () => void;
    onAskResume: () => void;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={readOnly}
            onClick={(e) => {
                e.stopPropagation();
                if (p.paused) {
                    onAskResume();
                } else {
                    onPause();
                }
            }}
            aria-pressed={p.paused}
            aria-label={p.paused ? `Resume ${p.title}` : `Pause ${p.title}`}
            className={cn(
                'h-8 gap-1.5',
                p.paused &&
                    'border-amber-500 text-amber-800 dark:text-amber-300',
            )}
        >
            {p.paused ? (
                <Play className="size-3.5" />
            ) : (
                <Pause className="size-3.5" />
            )}
            {p.paused ? 'Resume' : 'Pause'}
        </Button>
    );
}

export function ProductTable({
    products,
    selectedId,
    readOnly,
    onSelect,
    onPause,
    onResume,
    onEditRule,
}: Props) {
    const [resuming, setResuming] = useState<Product | null>(null);
    const [busy, setBusy] = useState(false);

    if (products.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                No products yet. Seed the catalogue with{' '}
                <code>php artisan db:seed</code>.
            </p>
        );
    }

    return (
        <>
            {/* Desktop: a table. Phone: stacked cards. */}
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th className="py-2 pr-3 font-medium">Product</th>
                            <th className="py-2 pr-3 text-right font-medium">
                                Price
                            </th>
                            <th className="py-2 pr-3 font-medium">Buy Box</th>
                            <th
                                className="py-2 pr-3 text-right font-medium"
                                title="Share of the last 24 market hours we held the Buy Box"
                            >
                                Win rate 24h
                            </th>
                            <th className="py-2 pr-3 text-right font-medium">
                                Margin
                            </th>
                            <th className="py-2 pr-3 font-medium">Status</th>
                            <th className="py-2 font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.map((p) => (
                            <tr
                                key={p.id}
                                onClick={() => onSelect(p.id)}
                                className={cn(
                                    'cursor-pointer border-b border-border last:border-0 hover:bg-muted/40',
                                    selectedId === p.id &&
                                        'bg-sky-50 dark:bg-sky-950/30',
                                )}
                            >
                                <td className="py-2.5 pr-3">
                                    <div className="font-medium">{p.title}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {p.sku} · {p.asin}
                                    </div>
                                </td>
                                <td className="py-2.5 pr-3 text-right font-semibold tabular-nums">
                                    {formatCents(p.current_price)}
                                </td>
                                <td className="py-2.5 pr-3">
                                    <BuyBoxStatus p={p} />
                                </td>
                                <td className="py-2.5 pr-3 text-right tabular-nums">
                                    {formatBps(p.win_rate_24h_bps)}
                                </td>
                                <td
                                    className={cn(
                                        'py-2.5 pr-3 text-right tabular-nums',
                                        p.margin < 0 && 'text-red-700',
                                    )}
                                >
                                    {formatCents(p.margin)}{' '}
                                    <span className="text-xs text-muted-foreground">
                                        ({formatBps(p.margin_bps)})
                                    </span>
                                </td>
                                <td className="py-2.5 pr-3">
                                    {p.paused ? (
                                        <span
                                            className="inline-flex items-center gap-1 text-xs font-medium text-amber-800 dark:text-amber-300"
                                            title={p.paused_reason ?? ''}
                                        >
                                            <CircleAlert
                                                className="size-3.5"
                                                aria-hidden
                                            />{' '}
                                            {p.paused_reason?.startsWith(
                                                'Circuit breaker',
                                            )
                                                ? 'Breaker tripped'
                                                : 'Paused'}
                                        </span>
                                    ) : (
                                        <span
                                            className="text-xs text-muted-foreground tabular-nums"
                                            title="Reprices in the last market hour / circuit-breaker limit"
                                        >
                                            {p.reprices_last_hour}/
                                            {p.breaker_limit} per hr
                                        </span>
                                    )}
                                </td>
                                <td className="py-2.5">
                                    <div className="flex justify-end gap-1.5">
                                        <PauseToggle
                                            p={p}
                                            readOnly={readOnly}
                                            onPause={() => void onPause(p)}
                                            onAskResume={() => setResuming(p)}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-8"
                                            disabled={readOnly}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onEditRule(p);
                                            }}
                                            aria-label={`Edit rules for ${p.title}`}
                                        >
                                            <Pencil className="size-3.5" />{' '}
                                            Rules
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="space-y-2 md:hidden">
                {products.map((p) => (
                    <li
                        key={p.id}
                        className={cn(
                            'rounded-lg border border-border p-3',
                            selectedId === p.id &&
                                'border-sky-400 bg-sky-50 dark:bg-sky-950/30',
                        )}
                        onClick={() => onSelect(p.id)}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <div className="font-medium">{p.title}</div>
                                <div className="text-xs text-muted-foreground">
                                    {p.sku}
                                </div>
                            </div>
                            <div className="text-right text-lg font-semibold tabular-nums">
                                {formatCents(p.current_price)}
                            </div>
                        </div>
                        <dl className="mt-2 grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <dt className="text-muted-foreground">
                                    Buy Box
                                </dt>
                                <dd>
                                    <BuyBoxStatus p={p} />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Win 24h
                                </dt>
                                <dd className="tabular-nums">
                                    {formatBps(p.win_rate_24h_bps)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Margin
                                </dt>
                                <dd className="tabular-nums">
                                    {formatCents(p.margin)}
                                </dd>
                            </div>
                        </dl>
                        {p.paused && (
                            <p className="mt-2 text-xs text-amber-800 dark:text-amber-300">
                                {p.paused_reason}
                            </p>
                        )}
                        <div className="mt-2 flex gap-2">
                            <PauseToggle
                                p={p}
                                readOnly={readOnly}
                                onPause={() => void onPause(p)}
                                onAskResume={() => setResuming(p)}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8"
                                disabled={readOnly}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onEditRule(p);
                                }}
                            >
                                <Pencil className="size-3.5" /> Rules
                            </Button>
                        </div>
                    </li>
                ))}
            </ul>

            <Dialog
                open={resuming !== null}
                onOpenChange={(o) => !o && setResuming(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Resume {resuming?.title}?</DialogTitle>
                        <DialogDescription>
                            {resuming?.paused_reason ??
                                'This product is paused.'}{' '}
                            Resuming lets the repricer change its price again.
                            This is recorded in the audit log.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setResuming(null)}
                        >
                            Keep paused
                        </Button>
                        <Button
                            disabled={busy}
                            onClick={async () => {
                                if (resuming) {
                                    setBusy(true);
                                    await onResume(resuming);
                                    setBusy(false);
                                    setResuming(null);
                                }
                            }}
                        >
                            I have reviewed it, resume
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
