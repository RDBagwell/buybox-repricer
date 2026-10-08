/** Shapes of the dashboard API and broadcast payloads. All money is integer cents. */

export type Outcome =
    | 'reprice'
    | 'dry_run'
    | 'no_change'
    | 'skipped'
    | 'config_error'
    | 'stale';

export interface TraceEntry {
    rule: string;
    verdict: 'propose' | 'pass' | 'veto';
    reason: string;
    price_before: number;
    price_after: number;
    code: string | null;
}

export interface DecisionOffer {
    seller: string;
    price: number;
    shipping: number;
    landed: number;
    is_buybox: boolean;
    is_ours: boolean;
}

export interface Decision {
    id: number;
    product_id: number;
    outcome: Outcome;
    reason_code: string | null;
    reason: string;
    old_price: number;
    new_price: number | null;
    event_time: string;
    decided_at: string;
    trace: TraceEntry[];
    offers: DecisionOffer[];
    push: { status: string; attempts: number } | null;
}

export interface Rule {
    strategy: 'beat_lowest' | 'match_lowest' | 'beat_buybox';
    offset: number;
    floor: number;
    ceiling: number;
    min_margin: number;
    max_step_pct: number;
    cooldown_sec: number;
    min_competitor_rating: number;
    max_competitor_handling_days: number;
    no_competition: 'hold' | 'raise_to_ceiling';
    margin_floor: number;
}

export type Channel = 'buybox' | 'open';

export interface Product {
    id: number;
    sku: string;
    asin: string;
    title: string;
    current_price: number;
    shipping: number;
    cost: number;
    fees: number;
    margin: number;
    margin_bps: number;
    paused: boolean;
    paused_reason: string | null;
    /** Archived products stay in the list (for decision history) but leave the table. */
    archived?: boolean;
    buybox: { winner: string | null; ours: boolean; since: string | null };
    /** Null on open-listing marketplaces (there is no Buy Box to win). */
    win_rate_24h_bps: number | null;
    /** 'buybox': sellers share a listing and compete for one featured offer; 'open': no Buy Box. */
    channel?: Channel;
    /** Open listings only: our price position among comparable listings (1 = cheapest). */
    rank?: { position: number; of: number } | null;
    reprices_last_hour: number;
    breaker_limit: number;
    rule: Rule | null;
}

export interface Settings {
    kill_switch: boolean;
    dry_run: boolean;
}

export interface SimOffer {
    seller: string; // 'ours' for our own offer
    bot: string | null;
    price: number;
    shipping: number;
    in_stock: boolean;
}

export interface SimulatorState {
    controls: {
        running: boolean;
        speed: number;
        fault_429_bps: number;
        fault_503_bps: number;
    };
    tick: number;
    tick_seconds: number;
    bot_types: string[];
    limits: {
        max_speed: number;
        max_offers_per_listing: number;
        max_fault_bps: number;
    };
    listings: {
        asin: string;
        title: string;
        buybox: string | null;
        model?: 'buybox' | 'open';
        offers: SimOffer[];
    }[];
}

export interface AuditItem {
    id: number;
    action: string;
    product_id: number | null;
    actor: string;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    market_time: string | null;
}

export interface DashboardState {
    market_time: string;
    settings: Settings;
    products: Product[];
    decisions: Decision[];
    audit: AuditItem[];
    simulator: SimulatorState | null;
}

export interface SeriesPoint {
    t: number; // market time, epoch ms
    decision_id: number;
    buybox: string | null; // 'ours' | seller | null
    prices: Record<string, number>; // 'ours' | seller -> landed cents
}

export interface Series {
    product_id: number;
    now: number;
    from: number;
    floor: number | null;
    ceiling: number | null;
    margin_floor: number | null;
    points: SeriesPoint[];
}

export interface DashboardConfig {
    demo: boolean;
    replay: boolean;
    channel: string;
    private_channel: boolean;
    reverb: {
        key: string | null;
        host: string | null;
        port: string | number | null;
        scheme: string | null;
    };
    our_seller_id: string;
    reset_minutes: number | null;
    featured_sku?: string | null;
}

export type ConnectionState =
    | 'connecting'
    | 'live'
    | 'reconnecting'
    | 'offline'
    | 'replay';
