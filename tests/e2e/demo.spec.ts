import { expect, test } from '@playwright/test';

/*
 * The 10-second demo and the main interactions, against a live stack.
 * Run: npx playwright test --grep-invert @capture
 */

test('shows the repricer fighting for the Buy Box within 10 seconds', async ({
    page,
}) => {
    const started = Date.now();
    await page.goto('/');

    // A primed price war is on screen immediately: chart lines and decisions.
    // Our price line is drawn (a flat line has zero height, so check the path itself).
    await expect(
        page.locator('.recharts-line-curve[stroke="#0072B2"]'),
    ).toHaveAttribute('d', /^M/, { timeout: 5_000 });
    await expect(
        page.getByRole('heading', { name: 'Decisions' }),
    ).toBeVisible();
    await expect(page.getByText('Live', { exact: true })).toBeVisible({
        timeout: 10_000,
    });

    // And it is live: a new decision streams in.
    const feed = page.getByRole('list', {
        name: 'Pricing decisions, newest first',
    });
    const firstBefore = await feed.locator('li').first().textContent();
    await expect
        .poll(async () => feed.locator('li').first().textContent(), {
            timeout: 10_000 - (Date.now() - started),
        })
        .not.toBe(firstBefore);

    expect(Date.now() - started).toBeLessThan(10_000);
});

test('expands a decision into its rule trace', async ({ page }) => {
    await page.goto('/');
    // Pick a repriced decision (it has a full trace); new rows may stream in above it meanwhile.
    const row = page
        .getByRole('list', { name: 'Pricing decisions, newest first' })
        .getByRole('button')
        .filter({ hasText: 'Repriced' })
        .first();
    const panelId = await row.getAttribute('aria-controls');
    await row.click();
    const panel = page.locator(`#${panelId}`);
    await expect(panel).toBeVisible();
    await expect(panel.getByText('Should we act?')).toBeVisible();
    await expect(panel.getByText('Floor / ceiling')).toBeVisible();
});

test('the kill switch needs a confirmation and shows its state everywhere', async ({
    page,
}) => {
    await page.goto('/');
    await page
        .getByRole('button', { name: /Kill switch: stop all repricing/ })
        .click();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByText('Kill switch is on.')).toHaveCount(0);

    await page
        .getByRole('button', { name: /Kill switch: stop all repricing/ })
        .click();
    await page.getByRole('button', { name: 'Stop everything' }).click();
    await expect(page.getByText('Kill switch is on.')).toBeVisible();

    await page
        .getByRole('button', { name: /Resume repricing/ })
        .first()
        .click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Resume repricing' })
        .click();
    await expect(page.getByText('Kill switch is on.')).toHaveCount(0);
});

test('the rule editor validates instantly and refuses a floor below the margin floor', async ({
    page,
    isMobile,
}) => {
    await page.goto('/');
    const rules = page
        .getByRole(isMobile ? 'button' : 'button', {
            name: isMobile ? 'Rules' : /Edit rules for Stainless French Press/,
        })
        .first();
    await rules.click();
    const floor = page.getByLabel('Floor');
    await floor.fill('20.00');
    await expect(page.getByText(/is below the margin floor/)).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Save rules' }),
    ).toBeDisabled();
    await page.getByRole('button', { name: 'Cancel' }).click();
});

test('shows a dropped connection and catches up when it returns', async ({
    page,
}) => {
    // pusher-js retries on its own schedule (up to ~30 s between attempts once "unavailable").
    test.setTimeout(150_000);
    // Sit between the page and Reverb so the socket can really be dropped and refused.
    let blocked = false;
    // 4100 = Pusher protocol "reconnect after backoff", what a restarting server sends.
    const drop = { code: 4100, reason: 'test: connection dropped' };
    const sockets: {
        close: (o?: { code?: number; reason?: string }) => Promise<void>;
    }[] = [];
    await page.routeWebSocket(/\/app\//, (ws) => {
        if (blocked) {
            void ws.close(drop);

            return;
        }
        ws.connectToServer();
        sockets.push(ws);
    });

    await page.goto('/');
    await expect(page.getByText('Live', { exact: true })).toBeVisible({
        timeout: 10_000,
    });
    const feed = page.getByRole('list', {
        name: 'Pricing decisions, newest first',
    });

    blocked = true;
    await Promise.all(sockets.map((ws) => ws.close(drop)));
    await expect(page.getByText('Live updates disconnected.')).toBeVisible({
        timeout: 30_000,
    });
    const lastSeen = await feed.locator('li').first().textContent();

    // Let decisions happen while we are away, then come back.
    await page.waitForTimeout(4_000);
    blocked = false;
    await expect(page.getByText('Live', { exact: true })).toBeVisible({
        timeout: 60_000,
    });
    await expect(page.getByText('Live updates disconnected.')).toHaveCount(0);
    // The decisions made while disconnected were fetched from the API.
    await expect
        .poll(async () => feed.locator('li').first().textContent(), {
            timeout: 15_000,
        })
        .not.toBe(lastSeen);
});

test('adds a product through the form, sees it repriced, then archives it', async ({
    page,
    isMobile,
}) => {
    const sku = `E2E-${isMobile ? 'P' : 'D'}-${Date.now().toString(36).toUpperCase()}`;
    const title = `Test Kettle ${sku}`;
    await page.goto('/');
    await page.getByRole('button', { name: 'Add product' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Title').fill(title);
    await dialog.getByLabel('SKU').fill(sku);

    // The client refuses a floor below this product's own margin floor before anything is sent.
    await dialog.getByLabel('Floor').fill('12.00');
    await expect(dialog.getByText(/is below the margin floor/)).toBeVisible();
    await dialog.getByLabel('Floor').fill('16.50');

    await dialog.getByRole('button', { name: 'Add product' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText(`${title} added`)).toBeVisible();

    // It is in the catalogue, has its own listing, and the repricer decides on it.
    const feed = page.getByRole('list', {
        name: 'Pricing decisions, newest first',
    });
    await expect(feed.getByText(title).first()).toBeVisible({
        timeout: 15_000,
    });

    await page.getByRole('button', { name: `Archive ${title}` }).click();
    await page.getByRole('button', { name: 'Archive product' }).click();
    await expect(page.getByText(`${title} archived`)).toBeVisible();
    await expect(
        page.getByRole('button', { name: `Archive ${title}` }),
    ).toHaveCount(0);
});
