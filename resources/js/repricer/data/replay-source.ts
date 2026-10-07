import type {
    ConnectionState,
    DashboardState,
    Decision,
    Series,
} from '../types';
import type { Recording } from './recording';
import type { DataSource, StreamHandlers } from './source';

/**
 * Plays a recorded simulation through the same dashboard, entirely in the browser: the payloads
 * are exactly what the server broadcast during the recorded run. Loops when it reaches the end.
 */
export class ReplaySource implements DataSource {
    readonly mode = 'replay' as const;

    readonly actions = null;

    private framesPerSecond = 6;

    constructor(private readonly recording: Recording) {}

    setSpeed(framesPerSecond: number): void {
        this.framesPerSecond = Math.max(1, Math.min(60, framesPerSecond));
    }

    load(): Promise<DashboardState> {
        return Promise.resolve(structuredClone(this.recording.initial));
    }

    series(productId: number): Promise<Series> {
        const recorded = this.recording.series[String(productId)];
        if (recorded) {
            return Promise.resolve(structuredClone(recorded));
        }
        const now = new Date(this.recording.initial.market_time).getTime();

        return Promise.resolve({
            product_id: productId,
            now,
            from: now,
            floor: null,
            ceiling: null,
            margin_floor: null,
            points: [],
        });
    }

    decisionsAfter(): Promise<Decision[]> {
        return Promise.resolve([]);
    }

    subscribe(
        h: StreamHandlers,
        onConnection: (state: ConnectionState) => void,
    ): () => void {
        onConnection('replay');
        let frame = 0;
        let stopped = false;
        let timer: ReturnType<typeof setTimeout> | undefined;

        const step = () => {
            if (stopped) {
                return;
            }
            if (frame >= this.recording.frames.length) {
                frame = 0;
                h.reset(); // loop from the start
            }
            const f = this.recording.frames[frame++];
            h.clock?.(new Date(f.t).toISOString());
            for (const e of f.events) {
                dispatch(h, e.type, e.payload);
            }
            timer = setTimeout(step, 1000 / this.framesPerSecond);
        };
        timer = setTimeout(step, 400);

        return () => {
            stopped = true;
            clearTimeout(timer);
        };
    }
}

export function dispatch(
    h: StreamHandlers,
    type: string,
    payload: unknown,
): void {
    switch (type) {
        case 'decision.recorded':
            return h.decision(
                payload as Parameters<StreamHandlers['decision']>[0],
            );
        case 'push.recorded':
            return h.push(payload as Parameters<StreamHandlers['push']>[0]);
        case 'buybox.changed':
            return h.buybox(payload as Parameters<StreamHandlers['buybox']>[0]);
        case 'product.updated':
            return h.product(
                payload as Parameters<StreamHandlers['product']>[0],
            );
        case 'settings.changed':
            return h.settings(
                payload as Parameters<StreamHandlers['settings']>[0],
            );
        default:
            return;
    }
}
