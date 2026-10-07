import { Head } from '@inertiajs/react';
import { useMemo } from 'react';
import { Dashboard } from '@/repricer/components/dashboard';
import { LiveSource } from '@/repricer/data/live-source';
import type { DashboardConfig, DashboardState } from '@/repricer/types';

export default function RepricerDashboard({
    initial,
    config,
}: {
    initial: DashboardState;
    config: DashboardConfig;
}) {
    const source = useMemo(() => new LiveSource(config), [config]);

    return (
        <>
            <Head title="Live demo" />
            <Dashboard source={source} config={config} initial={initial} />
        </>
    );
}
