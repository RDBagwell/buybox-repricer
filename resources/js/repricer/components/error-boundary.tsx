import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';

const RELOAD_AFTER_MS = 3_000;
// Reloads more than a minute apart count as unrelated; three within a minute stop the reloads.
const BURST_WINDOW_MS = 60_000;
const MAX_RELOADS_PER_BURST = 3;
const STORAGE_KEY = 'repricer.error-reloads';

type State = { error: Error | null; reloading: boolean };

/** Reload timestamps in the last minute, kept across reloads (best effort). */
function recentReloads(now: number): number[] {
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);
        const list: unknown = raw ? JSON.parse(raw) : [];

        return Array.isArray(list)
            ? list.filter(
                  (t): t is number =>
                      typeof t === 'number' && now - t < BURST_WINDOW_MS,
              )
            : [];
    } catch {
        return [];
    }
}

function rememberReloads(list: number[]): void {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch {
        // storage blocked: the reload still happens, just without the loop guard
    }
}

/**
 * Without a boundary, one render error unmounts the whole dashboard and leaves a blank page
 * until a manual refresh. This shows what failed, then reloads the page a few seconds later so
 * a live demo recovers on its own. A full reload, not a re-mount, so the dashboard starts from
 * fresh server data. Three failures within a minute stop the reloads instead of looping.
 */
export class DashboardErrorBoundary extends Component<
    { children: ReactNode },
    State
> {
    state: State = { error: null, reloading: false };

    private timer: number | undefined;

    static getDerivedStateFromError(error: Error): Partial<State> {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('Dashboard render error', error, info.componentStack);
        const now = Date.now();
        const reloads = recentReloads(now);
        const reloading = reloads.length < MAX_RELOADS_PER_BURST;
        this.setState({ reloading });
        if (reloading) {
            rememberReloads([...reloads, now]);
            this.timer = window.setTimeout(
                () => window.location.reload(),
                RELOAD_AFTER_MS,
            );
        }
    }

    componentWillUnmount(): void {
        window.clearTimeout(this.timer);
    }

    render() {
        const { error, reloading } = this.state;
        if (!error) {
            return this.props.children;
        }

        return (
            <div
                role="alert"
                className="mx-auto mt-16 max-w-xl space-y-3 rounded-lg border bg-card p-6 text-card-foreground"
            >
                <p className="font-medium">The dashboard hit an error.</p>
                <p className="font-mono text-sm break-words text-muted-foreground">
                    {error.message}
                </p>
                <p className="text-sm">
                    {reloading
                        ? 'Reloading the page in a few seconds…'
                        : 'It keeps failing, so it has stopped reloading.'}
                </p>
                <button
                    type="button"
                    className="rounded-md border px-3 py-1.5 text-sm"
                    onClick={() => window.location.reload()}
                >
                    Reload the page
                </button>
            </div>
        );
    }
}
