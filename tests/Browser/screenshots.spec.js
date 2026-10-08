import { fileURLToPath } from 'node:url';
import { expect, test } from './support/test.js';
import { cpUrl, entryFile, openEntry, please, reset, tinker } from './support/sandbox.js';

// Writes the screenshots of docs/images from the sandbox. Opt-in, since it rewrites committed files:
// TOLK_SCREENSHOTS=1 npx playwright test screenshots
const IMAGES = fileURLToPath(new URL('../../docs/images/', import.meta.url));
const ARTICLE = 'planning-a-wedding-by-the-sea';
const MARKERS = 'fake-engine-markers';

test.skip(!process.env.TOLK_SCREENSHOTS, 'Set TOLK_SCREENSHOTS=1 to write the screenshots of docs/images.');
test.use({ viewport: { width: 1280, height: 860 }, colorScheme: 'light' });

const banner = (page) => page.locator('[data-tolk-banner]');
const action = (page, name) => banner(page).locator(`[data-tolk-action="${name}"]`);
const runPage = (page) => page.locator('[data-tolk-run]');

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

// The banner with a little of the page around it.
async function shootAround(page, locator, file, margin = 24) {
    const box = await locator.boundingBox();

    await page.screenshot({
        path: IMAGES + file,
        clip: { x: Math.max(0, box.x - margin), y: Math.max(0, box.y - margin), width: box.width + 2 * margin, height: box.height + 2 * margin },
    });
}

async function createRun(page, body) {
    await page.goto(cpUrl('tolk/runs'));
    await expect(page.locator('[data-tolk-runs]')).toBeVisible();

    return page.evaluate(async (body) => (await Statamic.$app.config.globalProperties.$axios.post(cp_url('tolk/runs'), body)).data, body);
}

test('the banner on a localization with outdated and hand-edited fields', async ({ page }) => {
    const { id } = entryFile('articles', 'en', ARTICLE);

    please(`tolk:translate --entry=${id} --site=el --concurrency=1 --yes`);
    tinker(`$entry = \\Statamic\\Facades\\Entry::find('${id}'); $entry->set('excerpt', $entry->get('excerpt').' Book early for June.')->saveQuietly(); $greek = $entry->in('el'); $greek->set('title', 'Γάμος δίπλα στη θάλασσα')->saveQuietly();`);

    await openEntry(page, 'articles', ARTICLE, 'el');
    await expect(action(page, 'update')).toBeVisible();
    await page.waitForTimeout(700);

    await shootAround(page, banner(page), 'banner.png');
});

test('the offer after creating a localization', async ({ page }) => {
    await openEntry(page, 'pages', 'about', 'en');
    await page.getByRole('button', { name: 'German', exact: true }).click();

    const picker = page.getByRole('dialog').filter({ hasText: 'Create Localization' });
    await expect(picker).toBeVisible();
    await picker.getByRole('button', { name: 'Create' }).click();

    await expect(page.locator('[data-tolk-offer]')).toBeVisible();
    await page.waitForTimeout(600);

    await page.screenshot({ path: IMAGES + 'offer.png' });
});

test('a run page while the run is in progress, and the overview afterwards', async ({ page }) => {
    const { url } = await createRun(page, { sites: ['el', 'de'], collections: ['articles'], taxonomies: ['topics'] });

    await page.setViewportSize({ width: 1280, height: 1500 });
    await page.goto(url);
    await expect(page.locator('[data-tolk-estimate]')).toHaveAttribute('data-tolk-estimate-finished', 'true');
    await page.locator('[data-tolk-action="start"]').click();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'running');
    await expect(page.locator('[data-tolk-count="done"]')).toContainText(/\b([4-9]|\d\d+) done/, { timeout: 30_000 });

    await page.screenshot({ path: IMAGES + 'run.png' });

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', /^completed/, { timeout: 60_000 });

    await page.goto(cpUrl('tolk/overview'));
    await expect(page.locator('[data-tolk-overview]')).toBeVisible();
    await expect(page.locator('[data-tolk-cell-status="done"]')).toHaveCount(10, { timeout: 30_000 });
    await page.waitForTimeout(300);

    // Down to the matrix: the usage below it holds whatever earlier scenarios left in the sandbox's usage log.
    const matrix = await page.locator('[data-tolk-baseline-hint]').boundingBox();
    await page.screenshot({ path: IMAGES + 'overview.png', clip: { x: 0, y: 0, width: 1280, height: Math.ceil(matrix.y + matrix.height + 48) } });
});

test('the reviewer changes in the editor', async ({ page }) => {
    const { id } = entryFile('articles', 'en', MARKERS);

    tinker(`\\Statamic\\Facades\\Entry::find('${id}')->makeLocalization('el')->save();`);
    await openEntry(page, 'articles', MARKERS, 'el');
    await expect(action(page, 'options')).toBeVisible();
    await page.waitForTimeout(700);

    await action(page, 'options').click();
    const options = page.locator('[data-tolk-options]');
    await options.locator('label', { hasText: 'Review after translating' }).click();
    await options.locator('[data-tolk-options-action="translate"]').click();

    await expect(action(page, 'view-changes')).toBeVisible({ timeout: 30_000 });
    await action(page, 'view-changes').click();
    await expect(page.locator('[data-tolk-changes] [data-tolk-change]')).toHaveCount(1);
    await page.waitForTimeout(600);

    await page.screenshot({ path: IMAGES + 'review.png' });
});
