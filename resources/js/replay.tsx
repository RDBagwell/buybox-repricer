import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from './hooks/use-appearance';
import { ReplayApp } from './repricer/replay-app';

// Static entry (GitHub Pages): no Laravel, no server. The live URL is baked in at build time.
const liveUrl = import.meta.env.VITE_LIVE_DEMO_URL as string | undefined;

initializeTheme();

createRoot(document.getElementById('app')!).render(
    <ReplayApp
        recordingUrl="./recordings/demo.json"
        liveUrl={liveUrl || undefined}
    />,
);
