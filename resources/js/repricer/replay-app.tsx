import { useEffect, useMemo, useState } from 'react';
import { Dashboard } from './components/dashboard';
import { DashboardErrorBoundary } from './components/error-boundary';
import type { Recording } from './data/recording';
import { ReplaySource } from './data/replay-source';
import type { DashboardConfig } from './types';

/**
 * Replay mode: plays a recorded simulation (`php artisan sim:record`) through the same dashboard
 * entirely in the browser. Used as the static GitHub Pages demo and as the instant fallback while
 * a sleeping free-tier server wakes up.
 */
export function ReplayApp({
    recordingUrl,
    liveUrl,
}: {
    recordingUrl: string;
    liveUrl?: string;
}) {
    const [recording, setRecording] = useState<Recording | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [liveReady, setLiveReady] = useState(false);

    useEffect(() => {
        fetch(recordingUrl)
            .then((r) =>
                r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)),
            )
            .then((json: Recording) => setRecording(json))
            .catch((e: Error) => setError(e.message));
    }, [recordingUrl]);

    // Wake the live server (free tiers sleep) and say when it is ready.
    useEffect(() => {
        if (!liveUrl) {
            return;
        }
        let stopped = false;
        const ping = async () => {
            try {
                await fetch(`${liveUrl.replace(/\/$/, '')}/up`, {
                    mode: 'no-cors',
                    cache: 'no-store',
                });
                if (!stopped) {
                    setLiveReady(true);
                }
            } catch {
                if (!stopped) {
                    setTimeout(ping, 5000);
                }
            }
        };
        void ping();

        return () => {
            stopped = true;
        };
    }, [liveUrl]);

    const source = useMemo(
        () => (recording ? new ReplaySource(recording) : null),
        [recording],
    );
    const config: DashboardConfig | null = useMemo(
        () =>
            recording
                ? {
                      demo: true,
                      replay: true,
                      channel: '',
                      private_channel: false,
                      reverb: {
                          key: null,
                          host: null,
                          port: null,
                          scheme: null,
                      },
                      our_seller_id: recording.meta.our_seller_id,
                      reset_minutes: null,
                      featured_sku: 'FP-1L-STEEL',
                  }
                : null,
        [recording],
    );

    if (error) {
        return (
            <p className="p-6 text-sm text-red-700">
                Could not load the recording ({error}).
            </p>
        );
    }
    if (!recording || !source || !config) {
        return (
            <p className="p-6 text-sm text-muted-foreground">
                Loading the recorded simulation…
            </p>
        );
    }

    return (
        <DashboardErrorBoundary>
            <Dashboard
                source={source}
                config={config}
                initial={recording.initial}
                liveUrl={liveUrl}
                liveReady={liveReady}
                replayMeta={recording.meta}
            />
        </DashboardErrorBoundary>
    );
}
