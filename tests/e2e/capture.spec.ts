import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

/*
 * Documentation captures from a real run (docs/screenshots/). Skipped unless CAPTURE=1:
 *
 *   CAPTURE=1 npx playwright test capture --grep-invert @breaker
 *   # restart the worker with REPRICER_BREAKER_MAX_PER_HOUR=4, then:
 *   CAPTURE=1 npx playwright test capture --grep @breaker
 *
 * The GIF comes from the video of the first test; see docs/screenshots/README.md.
 */
const OUT = 'docs/screenshots';

test.skip(!process.env.CAPTURE, 'captures only run with CAPTURE=1');
test.beforeAll(() => mkdirSync(OUT, { recursive: true }));

const feed = (page: Page) =>
    page.getByRole('list', { name: 'Pricing decisions, newest first' });

async function openLive(page: Page) {
    await page.goto('/');
    await expect(page.getByText('Live', { exact: true })).toBeVisible({
        timeout: 15_000,
    });
    await expect(
        page.locator('.recharts-line-curve[stroke="#0072B2"]'),
    ).toHaveAttribute('d', /^M/);
}

// The desktop video of the first test becomes the README GIF.
test.use({ video: { mode: 'on', size: { width: 1440, height: 900 } } });

test('price war, mid-fight', async ({ page }, info) => {
    await openLive(page);
    // Let a few live decisions stream in before the still.
    const first = await feed(page).locator('li').first().textContent();
    await expect
        .poll(async () => feed(page).locator('li').first().textContent(), {
            timeout: 20_000,
        })
        .not.toBe(first);
    await page.waitForTimeout(8_000);
    await page.screenshot({
        path: `${OUT}/${info.project.name}-price-war.png`,
    });
});

test('an expanded rule trace', async ({ page }, info) => {
    await openLive(page);
    // Pause the stream's effect on layout: pick a repriced row and open it.
    const row = feed(page)
        .getByRole('button')
        .filter({ hasText: 'Repriced' })
        .first();
    const panelId = await row.getAttribute('aria-controls');
    await row.click();
    const panel = page.locator(`#${panelId}`);
    await expect(panel.getByText('Should we act?')).toBeVisible();
    await row.evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await page.screenshot({ path: `${OUT}/${info.project.name}-trace.png` });
});

test('the rule editor refusing a floor below the margin floor', async ({
    page,
    isMobile,
}, info) => {
    await openLive(page);
    await page
        .getByRole('button', {
            name: isMobile ? 'Rules' : /Edit rules for Stainless French Press/,
        })
        .first()
        .click();
    await page.getByLabel('Floor').fill('20.00');
    await expect(page.getByText(/is below the margin floor/)).toBeVisible();
    await page.screenshot({
        path: `${OUT}/${info.project.name}-rule-editor.png`,
    });
});

test('the circuit breaker tripped @breaker', async ({
    page,
    isMobile,
}, info) => {
    test.setTimeout(240_000);
    await openLive(page);
    // Needs a low limit (REPRICER_BREAKER_MAX_PER_HOUR=4 on the worker) and a busy war.
    // Phones show the product cards with the full reason; desktops show the table's badge.
    const tripped = page
        .getByText(isMobile ? /^Circuit breaker:/ : 'Breaker tripped')
        .first();
    await expect(tripped).toBeVisible({ timeout: 200_000 });
    if (isMobile) {
        await tripped.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    } else {
        await page
            .getByRole('heading', { name: 'Products' })
            .scrollIntoViewIfNeeded();
    }
    await page.screenshot({ path: `${OUT}/${info.project.name}-breaker.png` });
});
