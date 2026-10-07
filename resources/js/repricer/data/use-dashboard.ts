import { useCallback, useEffect, useReducer, useRef } from 'react';
import { appendBounded, mergeById } from '../lib/buffer';
import type {
    ConnectionState,
    DashboardState,
    Decision,
    Product,
    Series,
    SeriesPoint,
    Settings,
    SimulatorState,
} from '../types';
import type { BuyBoxUpdate, DataSource, PushUpdate } from './source';

export const MAX_DECISIONS = 150;
export const MAX_POINTS = 600;
export const HEARTBEAT_MS = 30_000;

export interface DashboardModel {
    status: 'loading' | 'ready' | 'error';
    error: string | null;
    data: DashboardState | null;
    connection: ConnectionState;
    series: Record<number, Series>;
    lastSync: number;
}

type Action =
    | { type: 'loaded'; data: DashboardState }
    | { type: 'error'; message: string }
    | { type: 'connection'; state: ConnectionState }
    | { type: 'decisions'; decisions: Decision[] }
    | { type: 'push'; push: PushUpdate }
    | { type: 'buybox'; update: BuyBoxUpdate; ourSellerId: string }
    | { type: 'product'; product: Product }
    | { type: 'settings'; settings: Settings }
    | { type: 'simulator'; simulator: SimulatorState | null }
    | { type: 'series'; series: Series }
    | { type: 'clock'; marketTime: string }
    | { type: 'clear-series' };

/** Turn a decision's offer snapshot into a chart point. */
export function pointFromDecision(d: Decision): SeriesPoint {
    const point: SeriesPoint = {
        t: new Date(d.event_time).getTime(),
        decision_id: d.id,
        buybox: null,
        prices: {},
    };
    for (const o of d.offers) {
        const key = o.is_ours ? 'ours' : o.seller;
        point.prices[key] = o.landed;
        if (o.is_buybox) {
            point.buybox = key;
        }
    }

    return point;
}

export function reducer(state: DashboardModel, action: Action): DashboardModel {
    switch (action.type) {
        case 'loaded':
            return {
                ...state,
                status: 'ready',
                error: null,
                data: action.data,
                lastSync: Date.now(),
            };
        case 'error':
            return {
                ...state,
                status: state.data ? 'ready' : 'error',
                error: action.message,
            };
        case 'connection':
            return { ...state, connection: action.state };
        case 'clear-series':
            return { ...state, series: {} };
        case 'series':
            return {
                ...state,
                series: {
                    ...state.series,
                    [action.series.product_id]: action.series,
                },
            };
        default:
            break;
    }

    if (!state.data) {
        return state;
    }
    const data = state.data;

    switch (action.type) {
        case 'decisions': {
            const decisions = mergeById(
                data.decisions,
                action.decisions,
                MAX_DECISIONS,
            );
            const series = { ...state.series };
            let marketTime = data.market_time;
            for (const d of [...action.decisions].sort((a, b) => a.id - b.id)) {
                if (d.decided_at > marketTime) {
                    marketTime = d.decided_at;
                }
                const s = series[d.product_id];
                if (
                    s &&
                    d.offers.length > 0 &&
                    !s.points.some((p) => p.decision_id === d.id)
                ) {
                    const point = pointFromDecision(d);
                    series[d.product_id] = {
                        ...s,
                        now: Math.max(s.now, point.t),
                        points: appendBounded(s.points, point, MAX_POINTS),
                    };
                }
            }

            return {
                ...state,
                series,
                data: { ...data, decisions, market_time: marketTime },
            };
        }
        case 'push':
            return {
                ...state,
                data: {
                    ...data,
                    decisions: data.decisions.map((d) =>
                        d.id === action.push.decision_id
                            ? {
                                  ...d,
                                  push: {
                                      status: action.push.status,
                                      attempts: action.push.attempts,
                                  },
                              }
                            : d,
                    ),
                },
            };
        case 'buybox':
            return {
                ...state,
                data: {
                    ...data,
                    products: data.products.map((p) =>
                        p.id === action.update.product_id
                            ? {
                                  ...p,
                                  buybox: {
                                      ...p.buybox,
                                      winner: action.update.to,
                                      ours:
                                          action.update.to ===
                                          action.ourSellerId,
                                  },
                              }
                            : p,
                    ),
                },
            };
        case 'product':
            return {
                ...state,
                data: {
                    ...data,
                    // A product we have not seen yet was just added: append it.
                    products: data.products.some(
                        (p) => p.id === action.product.id,
                    )
                        ? data.products.map((p) =>
                              p.id === action.product.id ? action.product : p,
                          )
                        : [...data.products, action.product],
                },
            };
        case 'settings':
            return { ...state, data: { ...data, settings: action.settings } };
        case 'simulator':
            return { ...state, data: { ...data, simulator: action.simulator } };
        case 'clock':
            return {
                ...state,
                data: { ...data, market_time: action.marketTime },
            };
        default:
            return state;
    }
}

const initialModel = (initial: DashboardState | null): DashboardModel => ({
    status: initial ? 'ready' : 'loading',
    error: null,
    data: initial,
    connection: 'connecting',
    series: {},
    lastSync: Date.now(),
});

/**
 * Owns the dashboard state: initial load, live stream (bounded buffers), and catch-up from the
 * API whenever the socket reconnects after a drop.
 */
export function useDashboard(
    source: DataSource,
    initial: DashboardState | null,
    ourSellerId: string,
) {
    const [model, dispatch] = useReducer(reducer, initial, initialModel);
    const modelRef = useRef(model);
    modelRef.current = model;
    const wasDown = useRef(false);

    const reload = useCallback(async () => {
        try {
            dispatch({ type: 'loaded', data: await source.load() });
        } catch (e) {
            dispatch({
                type: 'error',
                message:
                    e instanceof Error
                        ? e.message
                        : 'Could not load the dashboard.',
            });
        }
    }, [source]);

    const loadSeries = useCallback(
        async (productId: number) => {
            try {
                dispatch({
                    type: 'series',
                    series: await source.series(productId),
                });
            } catch {
                // the chart shows its own empty state; the next decision event fills it in
            }
        },
        [source],
    );

    const catchUp = useCallback(async () => {
        const decisions = modelRef.current.data?.decisions ?? [];
        const lastId = decisions.reduce((m, d) => Math.max(m, d.id), 0);
        try {
            const missed = await source.decisionsAfter(lastId);
            dispatch({ type: 'decisions', decisions: missed });
        } catch {
            // fall through to the full reload below
        }
        await reload();
        dispatch({ type: 'clear-series' }); // charts refetch on next render
    }, [source, reload]);

    useEffect(() => {
        if (!initial) {
            void reload();
        }
    }, [initial, reload]);

    // Keep the demo world ticking while this tab is visible (the simulator idles otherwise).
    useEffect(() => {
        if (!source.heartbeat) {
            return;
        }
        const beat = () => {
            if (document.visibilityState === 'visible') {
                source.heartbeat?.().catch(() => undefined);
            }
        };
        const timer = window.setInterval(beat, HEARTBEAT_MS);
        document.addEventListener('visibilitychange', beat);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', beat);
        };
    }, [source]);

    useEffect(() => {
        return source.subscribe(
            {
                decision: (d) =>
                    dispatch({ type: 'decisions', decisions: [d] }),
                push: (p) => dispatch({ type: 'push', push: p }),
                buybox: (b) =>
                    dispatch({ type: 'buybox', update: b, ourSellerId }),
                product: (p) => dispatch({ type: 'product', product: p }),
                settings: (s) => dispatch({ type: 'settings', settings: s }),
                reset: () => {
                    dispatch({ type: 'clear-series' });
                    void reload();
                },
                clock: (t) => dispatch({ type: 'clock', marketTime: t }),
            },
            (state) => {
                dispatch({ type: 'connection', state });
                if (state === 'reconnecting' || state === 'offline') {
                    wasDown.current = true;
                }
                if (state === 'live' && wasDown.current) {
                    wasDown.current = false;
                    void catchUp(); // the socket is back: fetch everything we missed
                }
            },
        );
    }, [source, ourSellerId, reload, catchUp]);

    return {
        model,
        reload,
        loadSeries,
        setProduct: (product: Product) =>
            dispatch({ type: 'product', product }),
        setSettings: (settings: Settings) =>
            dispatch({ type: 'settings', settings }),
        setSimulator: (simulator: SimulatorState | null) =>
            dispatch({ type: 'simulator', simulator }),
    };
}
