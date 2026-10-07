import type {
    ConnectionState,
    DashboardState,
    Decision,
    Product,
    Series,
    Settings,
    SimulatorState,
} from '../types';

export interface PushUpdate {
    decision_id: number;
    product_id: number;
    status: string;
    attempts: number;
}

export interface BuyBoxUpdate {
    product_id: number;
    from: string | null;
    to: string | null;
    we_won: boolean;
}

export interface StreamHandlers {
    decision: (d: Decision) => void;
    push: (p: PushUpdate) => void;
    buybox: (b: BuyBoxUpdate) => void;
    product: (p: Product) => void;
    settings: (s: Settings) => void;
    reset: () => void;
    /** Replay only: the recording's market clock. */
    clock?(marketTime: string): void;
}

export type ActionResult<T> =
    | { ok: true; data: T }
    | {
          ok: false;
          status: number;
          message: string;
          errors?: Record<string, string[]>;
      };

export interface DashboardActions {
    killSwitch: (on: boolean) => Promise<ActionResult<{ settings: Settings }>>;
    dryRun: (on: boolean) => Promise<ActionResult<{ settings: Settings }>>;
    pause: (productId: number) => Promise<ActionResult<{ product: Product }>>;
    resume: (productId: number) => Promise<ActionResult<{ product: Product }>>;
    updateRule(
        productId: number,
        payload: object,
    ): Promise<ActionResult<{ product: Product }>>;
    simulator: {
        get: () => Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        speed(
            speed: number,
        ): Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        running(
            running: boolean,
        ): Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        faults(
            http429Bps: number,
            http503Bps: number,
        ): Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        addBot(
            asin: string,
            type: string,
        ): Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        removeBot(
            asin: string,
            seller: string,
        ): Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        stockout(
            asin: string,
            seller: string,
            ticks: number,
        ): Promise<ActionResult<{ simulator: SimulatorState | null }>>;
        reset: () => Promise<
            ActionResult<{ simulator: SimulatorState | null }>
        >;
    };
}

/**
 * Where the dashboard gets its data. The live source talks to the API and Reverb; the replay
 * source plays a recorded simulation entirely in the browser. Components never know which.
 */
export interface DataSource {
    readonly mode: 'live' | 'replay';
    load: () => Promise<DashboardState>;
    series: (productId: number) => Promise<Series>;
    /** Decisions after an id, oldest first (catch-up after a reconnect). */
    decisionsAfter: (afterId: number) => Promise<Decision[]>;
    subscribe(
        handlers: StreamHandlers,
        onConnection: (state: ConnectionState) => void,
    ): () => void;
    /** null = read-only (replay). */
    readonly actions: DashboardActions | null;
}
