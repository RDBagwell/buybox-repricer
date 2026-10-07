import {
    Activity,
    CircleSlash,
    FlaskConical,
    PlayCircle,
    Plus,
    Radio,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { ActionResult, DataSource } from '../data/source';
import { useDashboard } from '../data/use-dashboard';
import type { FeedFilter, FeedKind } from '../lib/feed-filter';
import { DEFAULT_FEED_FILTER, FEED_KINDS } from '../lib/feed-filter';
import { formatCents } from '../lib/money';
import { formatMarketTime, marketDay } from '../lib/time';
import type {
    ConnectionState,
    DashboardConfig,
    DashboardState,
    Product,
} from '../types';
import { AddProductDialog } from './add-product-dialog';
import { AuditList } from './audit-list';
import { DecisionFeed } from './decision-feed';
import { KillSwitch } from './kill-switch';
import { ThemeToggle } from './theme-toggle';
import { PriceChart } from './price-chart';
import { ProductTable } from './product-table';
import { RuleEditor } from './rule-editor';
import { SimulatorPanel } from './simulator-panel';

interface Props {
    source: DataSource;
    config: DashboardConfig;
    initial: DashboardState | null;
    /** Replay mode: link to the live demo, shown in the banner. */
    liveUrl?: string;
    /** Replay mode: the live server answered (it may have been asleep). */
    liveReady?: boolean;
    /** Replay mode: how the recording was made. */
    replayMeta?: {
        seed: number;
        ticks: number;
        command: string;
        recorded_at: string;
    };
}

const CONNECTION: Record<
    ConnectionState,
    { label: string; className: string }
> = {
    connecting: {
        label: 'Connecting…',
        className: 'bg-muted text-muted-foreground',
    },
    live: {
        label: 'Live',
        className:
            'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
    },
    reconnecting: {
        label: 'Reconnecting…',
        className: 'bg-amber-100 text-amber-900',
    },
    offline: { label: 'Offline', className: 'bg-red-100 text-red-900' },
    replay: {
        label: 'Replay',
        className:
            'bg-violet-100 text-violet-900 dark:bg-violet-900/40 dark:text-violet-100',
    },
};

function Logo() {
    return (
        <svg viewBox="0 0 32 32" className="size-8 shrink-0" aria-hidden>
            <rect x="2" y="2" width="28" height="28" rx="7" fill="#0B3B5A" />
            <path
                d="M8 21 L13 15 L17 18 L24 9"
                fill="none"
                stroke="#56B4E9"
                strokeWidth="2.6"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <rect
                x="8"
                y="23"
                width="16"
                height="2.4"
                rx="1.2"
                fill="#E69F00"
            />
        </svg>
    );
}

function Banner({
    tone,
    icon,
    children,
}: {
    tone: 'red' | 'amber' | 'gray' | 'violet';
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    const tones = {
        red: 'border-red-300 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/50 dark:text-red-100',
        amber: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-100',
        gray: 'border-border bg-muted text-foreground',
        violet: 'border-violet-300 bg-violet-50 text-violet-900 dark:border-violet-900 dark:bg-violet-950/50 dark:text-violet-100',
    };

    return (
        <div
            role="status"
            className={cn(
                'flex items-start gap-2 rounded-lg border px-3 py-2 text-sm',
                tones[tone],
            )}
        >
            <span className="mt-0.5 shrink-0">{icon}</span>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

function Section({
    title,
    aside,
    children,
    className,
}: {
    title: string;
    aside?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'min-w-0 rounded-xl border border-border bg-card p-3 shadow-xs sm:p-4',
                className,
            )}
        >
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold tracking-tight">
                    {title}
                </h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

const FEED_FILTER_KEY = 'repricer.feed-filter';

/** The feed's chips and cooldown toggle, remembered per browser (a convenience, never required). */
function loadFeedFilter(): FeedFilter {
    try {
        const raw = window.localStorage.getItem(FEED_FILTER_KEY);
        const saved = raw ? (JSON.parse(raw) as Partial<FeedFilter>) : {};
        const kinds = Array.isArray(saved.kinds)
            ? saved.kinds.filter((k): k is FeedKind =>
                  FEED_KINDS.some((f) => f.kind === k),
              )
            : DEFAULT_FEED_FILTER.kinds;

        return {
            productId: null,
            kinds,
            hideCooldown:
                typeof saved.hideCooldown === 'boolean'
                    ? saved.hideCooldown
                    : DEFAULT_FEED_FILTER.hideCooldown,
        };
    } catch {
        return DEFAULT_FEED_FILTER;
    }
}

function saveFeedFilter(f: FeedFilter): void {
    try {
        window.localStorage.setItem(
            FEED_FILTER_KEY,
            JSON.stringify({ kinds: f.kinds, hideCooldown: f.hideCooldown }),
        );
    } catch {
        // storage unavailable (private mode): the filter just isn't remembered
    }
}

/** Open on the featured product (the liveliest price war), else the one with most recent activity. */
function busiestProduct(
    state: DashboardState,
    featuredSku?: string | null,
): number | null {
    const active = state.products.filter((p) => !p.archived);
    const featured = active.find((p) => p.sku === featuredSku);
    if (featured) {
        return featured.id;
    }
    const counts = new Map<number, number>();
    const activeIds = new Set(active.map((p) => p.id));
    for (const d of state.decisions
        .slice(0, 40)
        .filter((d) => activeIds.has(d.product_id))) {
        counts.set(
            d.product_id,
            (counts.get(d.product_id) ?? 0) + (d.outcome === 'reprice' ? 3 : 1),
        );
    }
    const best = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];

    return best?.[0] ?? active[0]?.id ?? null;
}

export function Dashboard({
    source,
    config,
    initial,
    liveUrl,
    liveReady,
    replayMeta,
}: Props) {
    const { model, loadSeries, setProduct, setSettings, setSimulator, reload } =
        useDashboard(source, initial, config.our_seller_id);
    const data = model.data;
    const [selected, setSelected] = useState<number | null>(() =>
        initial ? busiestProduct(initial, config.featured_sku) : null,
    );
    const [editing, setEditing] = useState<Product | null>(null);
    const [adding, setAdding] = useState(false);
    const [feedFilter, setFeedFilter] = useState<'all' | 'selected'>('all');
    const [feedKinds, setFeedKinds] = useState<FeedFilter>(loadFeedFilter);
    const actions = source.actions;
    const readOnly = actions === null;

    useEffect(() => {
        // Nothing selected yet, or the selected product was just archived.
        if (
            data &&
            (selected === null ||
                data.products.find((p) => p.id === selected)?.archived)
        ) {
            setSelected(busiestProduct(data, config.featured_sku));
        }
    }, [data, selected, config.featured_sku]);

    useEffect(() => {
        if (selected !== null && !model.series[selected]) {
            void loadSeries(selected);
        }
    }, [selected, model.series, loadSeries]);

    // The simulator panel is not part of the broadcast stream: refresh it every few seconds.
    useEffect(() => {
        if (!actions) {
            return;
        }
        const timer = setInterval(async () => {
            const r = await actions.simulator.get();
            if (r.ok) {
                setSimulator(r.data.simulator);
            }
        }, 4000);

        return () => clearInterval(timer);
    }, [actions, setSimulator]);

    const run = useCallback(
        async <T,>(
            action: () => Promise<ActionResult<T>>,
            onOk?: (data: T) => void,
            success?: string,
        ): Promise<ActionResult<T>> => {
            const r = await action();
            if (r.ok) {
                onOk?.(r.data);
                if (success) {
                    toast.success(success);
                }
            } else {
                toast.error(r.message);
            }

            return r;
        },
        [],
    );

    const activeProducts = useMemo(
        () => (data?.products ?? []).filter((p) => !p.archived),
        [data],
    );

    const selectedProduct = useMemo(
        () => data?.products.find((p) => p.id === selected),
        [data, selected],
    );

    if (model.status === 'loading' || !data) {
        return (
            <div className="flex min-h-screen items-center justify-center p-6 text-sm text-muted-foreground">
                {model.status === 'error' ? (
                    <div className="space-y-3 text-center">
                        <p>Could not load the dashboard: {model.error}</p>
                        <Button variant="outline" onClick={() => void reload()}>
                            Try again
                        </Button>
                    </div>
                ) : (
                    'Loading the marketplace…'
                )}
            </div>
        );
    }

    const conn = CONNECTION[model.connection];
    const killed = data.settings.kill_switch;

    return (
        <div className="min-h-screen bg-[#F6F8FA] text-foreground dark:bg-background">
            <header
                className={cn(
                    'sticky top-0 z-30 border-b backdrop-blur',
                    killed
                        ? 'border-red-300 bg-red-50/95 dark:bg-red-950/90'
                        : 'border-border bg-white/90 dark:bg-background/90',
                )}
            >
                <div className="mx-auto flex max-w-[1400px] flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2.5 sm:px-5">
                    <div className="flex items-center gap-2.5">
                        <Logo />
                        <div className="leading-tight">
                            <h1 className="text-base font-semibold tracking-tight">
                                Buy Box Repricer
                            </h1>
                            <p className="text-[11px] text-muted-foreground">
                                Live simulation · not affiliated with Amazon
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 text-xs">
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full px-2 py-1 font-medium',
                                conn.className,
                            )}
                        >
                            {model.connection === 'live' ? (
                                <Radio className="size-3" />
                            ) : model.connection === 'replay' ? (
                                <PlayCircle className="size-3" />
                            ) : (
                                <WifiOff className="size-3" />
                            )}
                            {conn.label}
                        </span>
                        <span
                            className="hidden items-center gap-1 rounded-full bg-muted px-2 py-1 tabular-nums sm:inline-flex"
                            title="Market time (the simulator's clock)"
                        >
                            <Activity className="size-3" /> Day{' '}
                            {marketDay(data.market_time)} ·{' '}
                            {formatMarketTime(data.market_time, true)}
                        </span>
                    </div>
                    <div className="ml-auto flex items-center gap-2">
                        <ThemeToggle />
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={readOnly}
                            aria-pressed={data.settings.dry_run}
                            onClick={() =>
                                actions &&
                                run(
                                    () =>
                                        actions.dryRun(!data.settings.dry_run),
                                    (d) => setSettings(d.settings),
                                    data.settings.dry_run
                                        ? 'Dry run off: prices will be pushed'
                                        : 'Dry run on: nothing will be pushed',
                                )
                            }
                            className={cn(
                                data.settings.dry_run &&
                                    'border-amber-500 bg-amber-50 text-amber-900',
                            )}
                        >
                            <FlaskConical className="size-4" /> Dry run{' '}
                            {data.settings.dry_run ? 'on' : 'off'}
                        </Button>
                        <KillSwitch
                            on={killed}
                            disabled={readOnly}
                            onChange={async (on) => {
                                if (actions) {
                                    await run(
                                        () => actions.killSwitch(on),
                                        (d) => setSettings(d.settings),
                                        on
                                            ? 'Kill switch on: all repricing stopped'
                                            : 'Repricing resumed',
                                    );
                                }
                            }}
                        />
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-[1400px] space-y-3 px-3 py-3 sm:px-5 sm:py-4">
                <div className="space-y-2">
                    {config.replay && (
                        <Banner
                            tone="violet"
                            icon={<PlayCircle className="size-4" />}
                        >
                            <strong>Replay of a recorded simulation.</strong>{' '}
                            Real output from a seeded run
                            {replayMeta
                                ? ` (seed ${replayMeta.seed}, ${replayMeta.ticks} ticks)`
                                : ''}
                            , played back in your browser; controls are
                            read-only.{' '}
                            {liveUrl &&
                                (liveReady ? (
                                    <a
                                        className="font-semibold underline"
                                        href={liveUrl}
                                    >
                                        The live demo is ready: open it
                                    </a>
                                ) : (
                                    <span>Waking the live demo server…</span>
                                ))}
                        </Banner>
                    )}
                    {killed && (
                        <Banner
                            tone="red"
                            icon={<CircleSlash className="size-4" />}
                        >
                            <strong>Kill switch is on.</strong> No product is
                            being repriced. Decisions are still recorded as
                            skipped.
                        </Banner>
                    )}
                    {data.settings.dry_run && (
                        <Banner
                            tone="amber"
                            icon={<FlaskConical className="size-4" />}
                        >
                            <strong>Dry run.</strong> The repricer decides and
                            records prices but pushes nothing to the
                            marketplace.
                        </Banner>
                    )}
                    {(model.connection === 'reconnecting' ||
                        model.connection === 'offline') && (
                        <Banner
                            tone="gray"
                            icon={<WifiOff className="size-4" />}
                        >
                            <strong>Live updates disconnected.</strong> Showing
                            the last known state. The page catches up
                            automatically when the connection returns.
                        </Banner>
                    )}
                    {config.demo && !config.replay && config.reset_minutes && (
                        <p className="text-xs text-muted-foreground">
                            Public demo: everyone shares this world, changes are
                            rate-limited, and it resets to its seed every{' '}
                            {config.reset_minutes} minutes.
                        </p>
                    )}
                </div>

                <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]">
                    <div className="min-w-0 space-y-3">
                        <Section
                            title={
                                selectedProduct
                                    ? `${selectedProduct.title}`
                                    : 'Price war'
                            }
                            aside={
                                selectedProduct && (
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        now{' '}
                                        {formatCents(
                                            selectedProduct.current_price,
                                        )}{' '}
                                        ·{' '}
                                        {selectedProduct.buybox.ours
                                            ? 'we hold the Buy Box'
                                            : `Buy Box: ${selectedProduct.buybox.winner ?? 'none'}`}
                                    </span>
                                )
                            }
                        >
                            <div
                                role="tablist"
                                aria-label="Products"
                                className="-mx-1 mb-2 flex gap-1 overflow-x-auto px-1 pb-1"
                            >
                                {activeProducts.map((p) => (
                                    <button
                                        key={p.id}
                                        role="tab"
                                        aria-selected={p.id === selected}
                                        onClick={() => setSelected(p.id)}
                                        className={cn(
                                            'shrink-0 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                            p.id === selected
                                                ? 'border-[#0072B2] bg-[#0072B2] text-white'
                                                : 'border-border bg-background hover:bg-muted',
                                        )}
                                    >
                                        {p.title.split(',')[0]}
                                        {p.paused && ' ⏸'}
                                    </button>
                                ))}
                            </div>
                            <PriceChart
                                series={
                                    selected !== null
                                        ? model.series[selected]
                                        : undefined
                                }
                                height={
                                    typeof window !== 'undefined' &&
                                    window.innerWidth < 640
                                        ? 240
                                        : 340
                                }
                            />
                        </Section>

                        <Section
                            title="Products"
                            aside={
                                readOnly ? undefined : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-8"
                                        onClick={() => setAdding(true)}
                                    >
                                        <Plus className="size-3.5" /> Add
                                        product
                                    </Button>
                                )
                            }
                        >
                            <ProductTable
                                products={activeProducts}
                                selectedId={selected}
                                readOnly={readOnly}
                                onSelect={setSelected}
                                onPause={async (p) => {
                                    if (actions) {
                                        await run(
                                            () => actions.pause(p.id),
                                            (d) => setProduct(d.product),
                                            `${p.title} paused`,
                                        );
                                    }
                                }}
                                onResume={async (p) => {
                                    if (actions) {
                                        await run(
                                            () => actions.resume(p.id),
                                            (d) => setProduct(d.product),
                                            `${p.title} resumed`,
                                        );
                                    }
                                }}
                                onEditRule={setEditing}
                                onArchive={async (p) => {
                                    if (actions) {
                                        await run(
                                            () => actions.archive(p.id),
                                            (d) => {
                                                setProduct(d.product);
                                                setSimulator(d.simulator);
                                            },
                                            `${p.title} archived`,
                                        );
                                    }
                                }}
                            />
                        </Section>
                    </div>

                    <Section
                        title="Decisions"
                        className="lg:sticky lg:top-20 lg:self-start"
                        aside={
                            <div
                                className="flex rounded-md border border-border text-xs"
                                role="group"
                                aria-label="Filter decisions"
                            >
                                {(['all', 'selected'] as const).map((f) => (
                                    <button
                                        key={f}
                                        onClick={() => setFeedFilter(f)}
                                        aria-pressed={feedFilter === f}
                                        className={cn(
                                            'px-2 py-1',
                                            feedFilter === f
                                                ? 'bg-muted font-medium'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {f === 'all' ? 'All' : 'This product'}
                                    </button>
                                ))}
                            </div>
                        }
                    >
                        <div className="-mx-3 sm:-mx-4">
                            <DecisionFeed
                                decisions={data.decisions}
                                products={data.products}
                                filter={{
                                    ...feedKinds,
                                    productId:
                                        feedFilter === 'selected'
                                            ? selected
                                            : null,
                                }}
                                onFilterChange={(f) => {
                                    setFeedKinds(f);
                                    saveFeedFilter(f);
                                }}
                                expandFirst={false}
                            />
                        </div>
                    </Section>
                </div>

                <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]">
                    <Section
                        title="Simulator"
                        aside={
                            <span className="text-xs text-muted-foreground">
                                The marketplace stand-in
                            </span>
                        }
                    >
                        <SimulatorPanel
                            simulator={data.simulator}
                            readOnly={readOnly}
                            actions={actions?.simulator ?? null}
                            run={async (a) => {
                                const r = (await a()) as ActionResult<{
                                    simulator: DashboardState['simulator'];
                                }>;
                                if (r.ok) {
                                    setSimulator(r.data.simulator);
                                } else {
                                    toast.error(r.message);
                                }
                            }}
                        />
                    </Section>
                    <Section title="Safety & audit">
                        <AuditList
                            items={data.audit}
                            products={data.products}
                        />
                    </Section>
                </div>

                <footer className="pt-2 pb-6 text-center text-xs text-muted-foreground">
                    Simulated marketplace with a documented Buy Box
                    approximation. Not affiliated with Amazon. Money is integer
                    cents end to end.
                </footer>
            </main>

            <AddProductDialog
                open={adding}
                botTypes={data.simulator?.bot_types ?? []}
                onClose={() => setAdding(false)}
                onCreate={async (payload) => {
                    if (!actions) {
                        return { ok: false, message: 'Read-only replay.' };
                    }
                    const r = await actions.createProduct(payload);
                    if (r.ok) {
                        setProduct(r.data.product);
                        setSimulator(r.data.simulator);
                        setSelected(r.data.product.id);
                        toast.success(
                            `${r.data.product.title} added: the repricer is on it`,
                        );

                        return { ok: true };
                    }

                    return { ok: false, errors: r.errors, message: r.message };
                }}
            />

            <RuleEditor
                product={editing}
                onClose={() => setEditing(null)}
                onSave={async (p, payload) => {
                    if (!actions) {
                        return { ok: false, message: 'Read-only replay.' };
                    }
                    const r = await actions.updateRule(p.id, payload);
                    if (r.ok) {
                        setProduct(r.data.product);
                        toast.success(`Rules saved for ${p.title}`);

                        return { ok: true };
                    }

                    return { ok: false, errors: r.errors, message: r.message };
                }}
            />
        </div>
    );
}
