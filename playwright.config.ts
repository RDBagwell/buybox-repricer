import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end checks and documentation captures against a running stack (`make up`, or the
 * local processes listed in the README). Set E2E_BASE_URL to point elsewhere.
 * PLAYWRIGHT_CHROMIUM_PATH lets you use an already-installed Chromium.
 */
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined;

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 60_000,
    workers: 1, // one shared demo world
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        launchOptions: { executablePath },
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
                launchOptions: { executablePath },
            },
        },
        {
            name: 'phone',
            use: { ...devices['Pixel 7'], launchOptions: { executablePath } },
        },
    ],
});
