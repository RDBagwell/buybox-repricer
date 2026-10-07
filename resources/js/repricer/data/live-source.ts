import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type {
    ConnectionState,
    DashboardConfig,
    DashboardState,
    Decision,
    Series,
} from '../types';
import type {
    ActionResult,
    DashboardActions,
    DataSource,
    StreamHandlers,
} from './source';

function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

async function request<T>(
    method: string,
    url: string,
    body?: object,
): Promise<ActionResult<T>> {
    try {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            const message =
                res.status === 429
                    ? 'Slow down: too many changes in a minute. Try again shortly.'
                    : res.status === 403
                      ? 'You are not allowed to do that.'
                      : (json.message ?? `Request failed (${res.status}).`);

            return {
                ok: false,
                status: res.status,
                message,
                errors: json.errors,
            };
        }

        return { ok: true, data: json as T };
    } catch {
        return {
            ok: false,
            status: 0,
            message: 'Network error: is the server reachable?',
        };
    }
}

async function get<T>(url: string): Promise<T> {
    const r = await request<T>('GET', url);
    if (!r.ok) {
        throw new Error(r.message);
    }

    return r.data;
}

export class LiveSource implements DataSource {
    readonly mode = 'live' as const;

    readonly actions: DashboardActions = {
        killSwitch: (on) =>
            request('POST', '/api/switches/kill', { on, confirm: true }),
        dryRun: (on) => request('POST', '/api/switches/dry-run', { on }),
        pause: (id) => request('POST', `/api/products/${id}/pause`, {}),
        resume: (id) =>
            request('POST', `/api/products/${id}/resume`, {
                acknowledge: true,
            }),
        updateRule: (id, payload) =>
            request('PUT', `/api/products/${id}/rule`, payload),
        previewRule: (id, payload) =>
            request('POST', `/api/products/${id}/rule/preview`, payload),
        createProduct: (payload) => request('POST', '/api/products', payload),
        archive: (id) =>
            request('POST', `/api/products/${id}/archive`, { confirm: true }),
        simulator: {
            get: () => request('GET', '/api/simulator'),
            speed: (speed) =>
                request('POST', '/api/simulator/speed', { speed }),
            running: (running) =>
                request('POST', '/api/simulator/running', { running }),
            faults: (a, b) =>
                request('POST', '/api/simulator/faults', {
                    http_429_bps: a,
                    http_503_bps: b,
                }),
            addBot: (asin, type) =>
                request('POST', '/api/simulator/bots', { asin, type }),
            removeBot: (asin, seller) =>
                request(
                    'DELETE',
                    `/api/simulator/bots/${encodeURIComponent(asin)}/${encodeURIComponent(seller)}`,
                ),
            stockout: (asin, seller, ticks) =>
                request('POST', '/api/simulator/stockout', {
                    asin,
                    seller,
                    ticks,
                }),
            reset: () => request('POST', '/api/simulator/reset', {}),
        },
    };

    constructor(private readonly config: DashboardConfig) {}

    load(): Promise<DashboardState> {
        return get<DashboardState>('/api/dashboard');
    }

    series(productId: number): Promise<Series> {
        return get<Series>(`/api/products/${productId}/series?hours=3`);
    }

    async heartbeat(): Promise<void> {
        await fetch('/api/heartbeat', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
    }

    async decisionsAfter(afterId: number): Promise<Decision[]> {
        return (
            await get<{ decisions: Decision[] }>(
                `/api/decisions?after_id=${afterId}&limit=200`,
            )
        ).decisions;
    }

    subscribe(
        h: StreamHandlers,
        onConnection: (state: ConnectionState) => void,
    ): () => void {
        const r = this.config.reverb;
        if (!r.key) {
            onConnection('offline');

            return () => {};
        }

        const secure =
            (r.scheme ?? window.location.protocol.replace(':', '')) === 'https';
        const port = Number(
            r.port ?? (window.location.port || (secure ? 443 : 80)),
        );
        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

        const echo = new Echo({
            broadcaster: 'reverb',
            key: r.key,
            wsHost: r.host ?? window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: secure,
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: { headers: { 'X-XSRF-TOKEN': xsrfToken() } },
        });

        const channel = this.config.private_channel
            ? echo.private(this.config.channel)
            : echo.channel(this.config.channel);
        channel
            .listen('.decision.recorded', h.decision)
            .listen('.push.recorded', h.push)
            .listen('.buybox.changed', h.buybox)
            .listen('.product.updated', h.product)
            .listen('.settings.changed', h.settings)
            .listen('.world.reset', h.reset);

        const pusher = (echo.connector as unknown as { pusher: Pusher }).pusher;
        const map: Record<string, ConnectionState> = {
            initialized: 'connecting',
            connecting: 'connecting',
            connected: 'live',
            unavailable: 'reconnecting',
            disconnected: 'offline',
            failed: 'offline',
        };
        // pusher-js stops retrying after a "disconnected"/"failed" close (e.g. Reverb restarting,
        // a proxy dropping the socket). Keep trying with capped backoff unless we closed it.
        let closing = false;
        let retryTimer: ReturnType<typeof setTimeout> | undefined;
        let retryDelay = 1000;
        const listener = ({ current }: { current: string }) => {
            const down = current === 'disconnected' || current === 'failed';
            onConnection(
                down ? 'reconnecting' : (map[current] ?? 'reconnecting'),
            );
            if (current === 'connected') {
                retryDelay = 1000;
            }
            if (down && !closing) {
                clearTimeout(retryTimer);
                retryTimer = setTimeout(() => pusher.connect(), retryDelay);
                retryDelay = Math.min(retryDelay * 2, 15_000);
            }
        };
        pusher.connection.bind('state_change', listener);
        onConnection(map[pusher.connection.state] ?? 'connecting');

        return () => {
            closing = true;
            clearTimeout(retryTimer);
            pusher.connection.unbind('state_change', listener);
            echo.leaveAllChannels();
            echo.disconnect();
        };
    }
}
