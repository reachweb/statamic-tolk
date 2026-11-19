import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { expect, sessions, test } from './support/test.js';
import { cpUrl, entryFile, reset, sandboxPath } from './support/sandbox.js';

const PAGES = ['home', 'about', 'rooms', 'sea-view-suites', 'honeymoon-suite', 'dining'];

const runPage = (page) => page.locator('[data-tolk-run]');

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

// Creates a pending run through the API, as the bulk action and the overview do, from the runs index.
async function createRun(page, body) {
    await page.goto(cpUrl('tolk/runs'));
    await expect(page.locator('[data-tolk-runs]')).toBeVisible();

    return page.evaluate(async (body) => {
        const response = await Statamic.$app.config.globalProperties.$axios.post(cp_url('tolk/runs'), body);

        return response.data;
    }, body);
}

// Whether the page asks before it is closed or reloaded.
function warnsOnUnload(page) {
    return page.evaluate(() => {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);

        return event.defaultPrevented;
    });
}

function files(collection, site) {
    const folder = join(sandboxPath(), 'content/collections', collection, site);

    return existsSync(folder) ? readdirSync(folder).sort() : [];
}

test('a pending run shows its counts and estimate, and Start translates the pages into Greek drafts', async ({ page, browserErrors }) => {
    expect(files('pages', 'el')).toEqual([]);

    const { url } = await createRun(page, { sites: ['el'], collections: ['pages'] });

    await page.goto(url);
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    await expect(page.locator('[data-tolk-run-status-badge]')).toHaveText('Not started');
    await expect(page.locator('[data-tolk-breakdown-row="entry|el"] [data-tolk-breakdown-total]')).toHaveText(String(PAGES.length));
    await expect(page.locator('[data-tolk-option="mode"]')).toHaveText('Only what is not translated yet');
    await expect(page.locator('[data-tolk-option="new_status"]')).toHaveText('Drafts');

    // The estimate fills in on its own, and Start does not wait for it.
    await expect(page.locator('[data-tolk-estimate]')).toHaveAttribute('data-tolk-estimate-finished', 'true');
    expect(Number((await page.locator('[data-tolk-estimate-segments]').innerText()).replace(/\D/g, ''))).toBeGreaterThan(0);
    expect(Number((await page.locator('[data-tolk-estimate-chars]').innerText()).replace(/\D/g, ''))).toBeGreaterThan(0);
    await expect(page.locator('[data-tolk-item]')).toHaveCount(PAGES.length);
    expect(files('pages', 'el')).toEqual([]);

    await page.locator('[data-tolk-action="start"]').click();

    await expect(page.locator('[data-tolk-keep-open]')).toBeVisible();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
    await expect(page.locator('[data-tolk-run-result]')).toHaveText(new RegExp(`The run is complete: ${PAGES.length} done\\.`));
    await expect(page.locator('[data-tolk-keep-open]')).toHaveCount(0);
    await expect(page.locator('[data-tolk-item][data-tolk-item-status="done"]')).toHaveCount(PAGES.length);
    await expect(page.locator('[data-tolk-run-usage]')).toContainText('Estimated cost so far');

    for (const slug of PAGES) {
        const origin = entryFile('pages', 'en', slug);
        const greek = entryFile('pages', 'el', slug);

        expect(greek, slug).not.toBeNull();
        expect(greek.origin).toBe(origin.id);
        expect(greek.published).toBe(false);
        expect(greek.title).toBe(`[el] ${origin.title}`);
    }

    expect(entryFile('pages', 'el', 'about').intro).toBe(`[el] ${entryFile('pages', 'en', 'about').intro}`);

    // The runs index lists the run with its result.
    await page.locator('[data-tolk-runs-link]').click();
    await expect(page.locator('[data-tolk-run-row]')).toHaveCount(1);
    await expect(page.locator('[data-tolk-run-row]')).toContainText('Completed');
    await expect(page.locator('[data-tolk-run-row]')).toContainText(`${PAGES.length} of ${PAGES.length}`);
    expect(browserErrors()).toEqual([]);
});

test('Cancel stops a run, which keeps what it saved and can be resumed', async ({ page, browserErrors }) => {
    test.setTimeout(90_000);

    const { url } = await createRun(page, { sites: ['de'], collections: ['articles'] });

    await page.goto(url);
    await expect(page.locator('[data-tolk-not-localizable]')).toHaveText('Internal note is not translated because it is not localizable. Open the setup audit');
    await expect(page.locator('[data-tolk-audit-link]')).toHaveAttribute('href', new RegExp(`${cpUrl('tolk/audit')}#tolk-audit-localizable$`));
    expect(await warnsOnUnload(page)).toBe(false);

    await page.locator('[data-tolk-action="start"]').click();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'running');
    expect(await warnsOnUnload(page)).toBe(true);

    await page.locator('[data-tolk-action="cancel"]').click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'cancelled');
    await expect(page.locator('[data-tolk-run-result]')).toContainText('The run was cancelled');
    await expect(page.locator('[data-tolk-action="resume"]')).toBeVisible();
    await expect(page.locator('[data-tolk-action="cancel"]')).toHaveCount(0);

    // Once the lanes saw the cancel they end, and nothing more is saved.
    await expect(page.locator('[data-tolk-keep-open]')).toHaveCount(0);
    await expect.poll(() => warnsOnUnload(page)).toBe(false);
    const saved = files('articles', 'de');
    await page.waitForTimeout(2_000);

    expect(files('articles', 'de')).toEqual(saved);
    expect(saved.length).toBeLessThan(13);
    await expect(page.locator('[data-tolk-item][data-tolk-item-status="pending"]').first()).toBeVisible();

    // Resume runs the rest in this browser.
    await page.locator('[data-tolk-action="resume"]').click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 60_000 });
    await expect(page.locator('[data-tolk-run-result]')).toContainText('The run is complete: 13 done.');
    expect(files('articles', 'de')).toHaveLength(13);
    expect(browserErrors()).toEqual([]);
});

// The id at the end of each item title's link.
async function linkedIds(page) {
    const hrefs = await page.locator('[data-tolk-item] td a').evaluateAll((links) => links.map((link) => link.getAttribute('href')));

    return hrefs.map((href) => href.split('/').pop());
}

const ids = (collection, site) => files(collection, site).map((file) => entryFile(collection, site, file.replace(/^(?:[^.]+\.)?([^.]+)\.md$/, '$1')).id);

test('items link to their entries, are removed one at a time or in a batch, and the run is deleted from its page or the runs', async ({ page, browserErrors }) => {
    test.setTimeout(90_000);

    const { url } = await createRun(page, { sites: ['el'], collections: ['pages'] });
    const items = page.locator('[data-tolk-item]');
    const estimated = page.locator('[data-tolk-estimate]');

    await page.goto(url);
    await expect(estimated).toHaveAttribute('data-tolk-estimate-finished', 'true');
    const segments = Number((await page.locator('[data-tolk-estimate-segments]').innerText()).replace(/\D/g, ''));

    // Before a localization exists, its item opens the English page.
    expect((await linkedIds(page)).sort()).toEqual(ids('pages', 'en').sort());

    // One at a time, from its row.
    const first = (await items.first().locator('td a').innerText()).trim();
    await items.first().locator('[data-tolk-item-remove]').click();
    const one = page.getByRole('dialog').filter({ hasText: 'Remove 1 item from the run?' });
    await expect(one).toBeVisible();
    await one.getByRole('button', { name: 'Remove', exact: true }).click();

    await expect(items).toHaveCount(PAGES.length - 1);
    await expect(items.filter({ hasText: first })).toHaveCount(0);
    await expect(page.locator('[data-tolk-breakdown-row="entry|el"] [data-tolk-breakdown-total]')).toHaveText(String(PAGES.length - 1));
    // The estimate starts over without the removed item.
    await expect(estimated).toHaveAttribute('data-tolk-estimate-finished', 'true');
    expect(Number((await page.locator('[data-tolk-estimate-segments]').innerText()).replace(/\D/g, ''))).toBeLessThan(segments);

    // A batch, ticked one by one.
    const titles = (await items.locator('td a').allInnerTexts()).map((title) => title.trim());
    await page.getByRole('checkbox', { name: `Select ${titles[0]} in Greek` }).click();
    await page.getByRole('checkbox', { name: `Select ${titles[1]} in Greek` }).click();
    await expect(page.locator('[data-tolk-remove-selected]')).toHaveText('Remove 2 items');
    await page.locator('[data-tolk-remove-selected]').click();
    const two = page.getByRole('dialog').filter({ hasText: 'Remove 2 items from the run?' });
    await two.getByRole('button', { name: 'Remove', exact: true }).click();

    await expect(items).toHaveCount(PAGES.length - 3);
    await expect(page.locator('[data-tolk-remove-selected]')).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: 'Select every item on this page' })).not.toBeChecked();

    // The rest is translated, and each item opens its Greek localization.
    await page.locator('[data-tolk-action="start"]').click();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
    await expect(page.locator('[data-tolk-run-result]')).toHaveText(new RegExp(`The run is complete: ${PAGES.length - 3} done\\.`));
    expect(files('pages', 'el')).toHaveLength(PAGES.length - 3);
    expect((await linkedIds(page)).sort()).toEqual(ids('pages', 'el').sort());

    // Delete on its page goes back to the runs, and the translations stay.
    await page.locator('[data-tolk-run-delete]').click();
    await page.getByRole('dialog').filter({ hasText: 'Delete this run?' }).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.locator('[data-tolk-runs-empty]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(cpUrl('tolk/runs'));
    expect(files('pages', 'el')).toHaveLength(PAGES.length - 3);

    // On the runs, a pending run can be deleted and a running one cannot.
    await createRun(page, { sites: ['de'], collections: ['pages'] });
    const running = await createRun(page, { sites: ['de'], collections: ['articles'] });
    await page.evaluate((url) => Statamic.$app.config.globalProperties.$axios.post(`${url}/start`), running.url);
    await page.reload();

    const rows = page.locator('[data-tolk-run-row]');
    await expect(rows).toHaveCount(2);
    await expect(rows.filter({ hasText: 'Running' }).locator('[data-tolk-run-delete]')).toHaveCount(0);
    await rows.filter({ hasText: 'Not started' }).locator('[data-tolk-run-delete]').click();
    await page.getByRole('dialog').filter({ hasText: 'Delete this run?' }).getByRole('button', { name: 'Delete', exact: true }).click();

    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('Running');
    expect(browserErrors()).toEqual([]);
});

test('the Translations nav item leads the admin to the overview, and its Runs item to the runs', async ({ page }) => {
    await page.goto(cpUrl('dashboard'));

    const item = page.getByRole('link', { name: 'Translations', exact: true });

    await expect(item).toBeVisible();
    await item.click();
    await expect(page.locator('[data-tolk-overview]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(cpUrl('tolk/overview'));

    await page.getByRole('link', { name: 'Runs', exact: true }).click();
    await expect(page.locator('[data-tolk-runs-empty]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(cpUrl('tolk/runs'));
});

test.describe('without the permission', () => {
    test.use({ storageState: sessions.editor });

    test('the restricted user has no Translations nav item', async ({ page }) => {
        await page.goto(cpUrl('dashboard'));

        await expect(page.getByRole('link', { name: 'Collections' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Translations', exact: true })).toHaveCount(0);
    });
});
