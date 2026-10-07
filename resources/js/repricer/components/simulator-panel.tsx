import { PackageX, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatCents } from '../lib/money';
import { oursFirst } from '../lib/offers';
import type { SimulatorState } from '../types';

interface Props {
    simulator: SimulatorState | null;
    readOnly: boolean;
    run: (
        action: () => Promise<{ ok: boolean; message?: string }>,
    ) => Promise<void>;
    actions: {
        speed: (n: number) => Promise<{ ok: boolean; message?: string }>;
        running: (on: boolean) => Promise<{ ok: boolean; message?: string }>;
        faults(
            a: number,
            b: number,
        ): Promise<{ ok: boolean; message?: string }>;
        addBot(
            asin: string,
            type: string,
        ): Promise<{ ok: boolean; message?: string }>;
        removeBot(
            asin: string,
            seller: string,
        ): Promise<{ ok: boolean; message?: string }>;
        stockout(
            asin: string,
            seller: string,
            ticks: number,
        ): Promise<{ ok: boolean; message?: string }>;
        reset: () => Promise<{ ok: boolean; message?: string }>;
    } | null;
}

const BOT_LABELS: Record<string, string> = {
    penny_pincher: 'Penny Pincher',
    anchor: 'Anchor',
    matcher: 'Matcher',
    sleeper: 'Sleeper',
    chaos: 'Chaos',
};

function pct(bps: number): string {
    return `${(bps - (bps % 100)) / 100}%`;
}

export function SimulatorPanel({ simulator, readOnly, run, actions }: Props) {
    const [speed, setSpeed] = useState(simulator?.controls.speed ?? 20);
    const [f429, setF429] = useState(simulator?.controls.fault_429_bps ?? 0);
    const [f503, setF503] = useState(simulator?.controls.fault_503_bps ?? 0);
    const [addType, setAddType] = useState<Record<string, string>>({});

    useEffect(() => {
        if (simulator) {
            setSpeed(simulator.controls.speed);
            setF429(simulator.controls.fault_429_bps);
            setF503(simulator.controls.fault_503_bps);
        }
    }, [
        simulator?.controls.speed,
        simulator?.controls.fault_429_bps,
        simulator?.controls.fault_503_bps,
    ]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!simulator) {
        return (
            <p className="text-sm text-muted-foreground">
                The simulator has not been initialised. Run{' '}
                <code>php artisan sim:reset</code>.
            </p>
        );
    }

    const disabled = readOnly || actions === null;
    const c = simulator.controls;

    return (
        <div className="space-y-5 text-sm">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <label
                        htmlFor="sim-speed"
                        className="flex flex-wrap justify-between gap-x-2 font-medium"
                    >
                        <span>Speed</span>
                        <span className="text-muted-foreground tabular-nums">
                            {speed}x · 1 tick = {simulator.tick_seconds}s market
                            time
                        </span>
                    </label>
                    <input
                        id="sim-speed"
                        type="range"
                        min={1}
                        max={simulator.limits.max_speed}
                        value={speed}
                        disabled={disabled}
                        onChange={(e) => setSpeed(Number(e.target.value))}
                        onPointerUp={() =>
                            actions && void run(() => actions.speed(speed))
                        }
                        onKeyUp={() =>
                            actions && void run(() => actions.speed(speed))
                        }
                        className="w-full accent-[#0072B2]"
                    />
                </div>
                <div className="flex items-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={disabled}
                        onClick={() =>
                            actions && run(() => actions.running(!c.running))
                        }
                    >
                        {c.running ? 'Pause simulation' : 'Run simulation'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={disabled}
                        onClick={() => actions && run(() => actions.reset())}
                        title="Rebuild the marketplace from its seed"
                    >
                        <RotateCcw className="size-3.5" /> Reset world
                    </Button>
                </div>
                <div className="space-y-1.5">
                    <label
                        htmlFor="sim-429"
                        className="flex flex-wrap justify-between gap-x-2 font-medium"
                    >
                        <span>Inject 429 (throttled)</span>
                        <span className="text-muted-foreground tabular-nums">
                            {pct(f429)} of requests
                        </span>
                    </label>
                    <input
                        id="sim-429"
                        type="range"
                        min={0}
                        max={simulator.limits.max_fault_bps}
                        step={100}
                        value={f429}
                        disabled={disabled}
                        onChange={(e) => setF429(Number(e.target.value))}
                        onPointerUp={() =>
                            actions &&
                            void run(() => actions.faults(f429, f503))
                        }
                        onKeyUp={() =>
                            actions &&
                            void run(() => actions.faults(f429, f503))
                        }
                        className="w-full accent-[#E69F00]"
                    />
                </div>
                <div className="space-y-1.5">
                    <label
                        htmlFor="sim-503"
                        className="flex flex-wrap justify-between gap-x-2 font-medium"
                    >
                        <span>Inject 503 (unavailable)</span>
                        <span className="text-muted-foreground tabular-nums">
                            {pct(f503)} of requests
                        </span>
                    </label>
                    <input
                        id="sim-503"
                        type="range"
                        min={0}
                        max={simulator.limits.max_fault_bps}
                        step={100}
                        value={f503}
                        disabled={disabled}
                        onChange={(e) => setF503(Number(e.target.value))}
                        onPointerUp={() =>
                            actions &&
                            void run(() => actions.faults(f429, f503))
                        }
                        onKeyUp={() =>
                            actions &&
                            void run(() => actions.faults(f429, f503))
                        }
                        className="w-full accent-[#D55E00]"
                    />
                </div>
            </div>

            <div className="grid gap-3 lg:grid-cols-2">
                {simulator.listings.map((l) => (
                    <div
                        key={l.asin}
                        className="min-w-0 rounded-lg border border-border p-3"
                    >
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <div className="min-w-0">
                                <div className="truncate font-medium">
                                    {l.title}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {l.asin}
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                <select
                                    aria-label={`Bot to add to ${l.title}`}
                                    className="h-8 rounded-md border border-input bg-background px-2 text-xs text-foreground [&>option]:bg-background [&>option]:text-foreground"
                                    value={addType[l.asin] ?? 'matcher'}
                                    disabled={disabled}
                                    onChange={(e) =>
                                        setAddType({
                                            ...addType,
                                            [l.asin]: e.target.value,
                                        })
                                    }
                                >
                                    {simulator.bot_types.map((t) => (
                                        <option key={t} value={t}>
                                            {BOT_LABELS[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-8"
                                    disabled={
                                        disabled ||
                                        l.offers.length >=
                                            simulator.limits
                                                .max_offers_per_listing
                                    }
                                    onClick={() =>
                                        actions &&
                                        run(() =>
                                            actions.addBot(
                                                l.asin,
                                                addType[l.asin] ?? 'matcher',
                                            ),
                                        )
                                    }
                                    aria-label={`Add a bot to ${l.title}`}
                                >
                                    <Plus className="size-3.5" />
                                </Button>
                            </div>
                        </div>
                        <ul className="space-y-1">
                            {oursFirst(l.offers).map((o) => (
                                <li
                                    key={o.seller}
                                    className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs"
                                >
                                    <span
                                        className={
                                            o.seller === 'ours'
                                                ? 'font-semibold text-[#0072B2] dark:text-sky-400'
                                                : 'font-medium'
                                        }
                                    >
                                        {o.seller === 'ours' ? 'Us' : o.seller}
                                    </span>
                                    {o.bot && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                            {BOT_LABELS[o.bot] ?? o.bot}
                                        </span>
                                    )}
                                    {l.buybox === o.seller && (
                                        <span className="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-900 dark:bg-sky-900/40 dark:text-sky-100">
                                            Buy Box
                                        </span>
                                    )}
                                    {!o.in_stock && (
                                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900">
                                            out of stock
                                        </span>
                                    )}
                                    <span className="ml-auto tabular-nums">
                                        {formatCents(o.price + o.shipping)}
                                    </span>
                                    {o.bot && (
                                        <span className="flex gap-0.5">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                disabled={
                                                    disabled || !o.in_stock
                                                }
                                                title="Trigger a stockout (60 ticks)"
                                                aria-label={`Stockout ${o.seller}`}
                                                onClick={() =>
                                                    actions &&
                                                    run(() =>
                                                        actions.stockout(
                                                            l.asin,
                                                            o.seller,
                                                            60,
                                                        ),
                                                    )
                                                }
                                            >
                                                <PackageX className="size-3.5" />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                disabled={disabled}
                                                title="Remove this bot"
                                                aria-label={`Remove ${o.seller}`}
                                                onClick={() =>
                                                    actions &&
                                                    run(() =>
                                                        actions.removeBot(
                                                            l.asin,
                                                            o.seller,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}
