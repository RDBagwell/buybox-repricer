import { Head } from '@inertiajs/react';
import { ReplayApp } from '@/repricer/replay-app';

export default function RepricerReplay() {
    return (
        <>
            <Head title="Replay" />
            <ReplayApp recordingUrl="/recordings/demo.json" liveUrl="/" />
        </>
    );
}
