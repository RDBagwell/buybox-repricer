import type { DashboardState, Series } from '../types';

/** A recorded simulation run (produced by `php artisan sim:record`), played by ReplaySource. */
export interface Recording {
    version: 1;
    meta: {
        seed: number;
        ticks: number;
        tick_seconds: number;
        our_seller_id: string;
        command: string;
        recorded_at: string;
        app_version?: string;
    };
    initial: DashboardState;
    series: Record<string, Series>;
    frames: { t: number; events: { type: string; payload: unknown }[] }[];
}
