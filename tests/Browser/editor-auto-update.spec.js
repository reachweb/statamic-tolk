import { expect, test } from './support/test.js';
import { cpUrl, entryFile, openEntry, please, reset, tinker } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const push = (page) => banner(page).locator('[data-tolk-push]');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');
// What the banner shows once an automatic update is over: the run's result when it saw the run, else its own line.
const finished = (page) => push(page).locator('[data-tolk-push-result], [data-tolk-push-phase="updated"]');

// A published article without localizations.
const ARTICLE = 'our-new-spa';

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

// Saves Tolk's settings: `values` is a PHP array literal.
function settings(values) {
    tinker(`app(Reach\\StatamicTolk\\Support\\Settings::class)->save(${values});`);
}

async function saveTitle(page, title) {
    await page.locator('#field_title').fill(title);

    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    const response = await confirmed;

    expect(response.status()).toBe(200);

    return response.json();
}

test('saving a published origin updates its Greek translation, and the banner and the Greek banner say so', async ({ page, browserErrors }) => {
    const origin = entryFile('articles', 'en', ARTICLE);
    please(`tolk:translate --entry=${origin.id} --site=el --concurrency=1 --yes`);

    await openEntry(page, 'articles', ARTICLE, 'en');
    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Up to date');
    await expect(banner(page).locator('[data-tolk-auto-update]')).toHaveCount(0);

    settings("['auto_update' => true]");
    await openEntry(page, 'articles', ARTICLE, 'en');
    await expect(banner(page).locator('[data-tolk-auto-update]')).toHaveText('Translations are updated automatically each time this entry is saved.');

    const saved = await saveTitle(page, 'Our new spa opens in April');

    expect(saved.origin.auto_update_sites).toEqual(['el']);
    await expect(finished(page)).toBeVisible();
    await expect(finished(page)).toContainText(/The translations are done: 1 saved\.|The translations into Greek were updated automatically\./);
    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Up to date');
    await expect(banner(page).locator('[data-tolk-site="de"]')).toContainText('Missing');
    await expect.poll(() => entryFile('articles', 'el', ARTICLE).title).toBe('[el] Our new spa opens in April');
    expect(entryFile('articles', 'de', ARTICLE)).toBeNull();
    expect(browserErrors()).toEqual([]);

    // The Greek banner says when the update came.
    await openEntry(page, 'articles', ARTICLE, 'el');
    await expect(banner(page).locator('[data-tolk-auto-updated]')).toContainText('Updated automatically from English on');

    // The runs list names it.
    await page.goto(cpUrl('tolk/runs'));
    await expect(page.locator('[data-tolk-run-row]').first().locator('[data-tolk-run-automatic]')).toHaveText('Automatic update');
    await page.locator('[data-tolk-run-row]').first().getByRole('link').last().click();
    await expect(page.locator('[data-tolk-run-summary]')).toContainText('Started automatically on');
    await expect(page.locator('[data-tolk-run-summary]')).toContainText('saved the entry.');
    expect(browserErrors()).toEqual([]);
});

test('with missing localizations created, a save translates the origin into Greek and German as drafts', async ({ page, browserErrors }) => {
    settings("['auto_update' => true, 'auto_update_create' => true]");

    await openEntry(page, 'articles', ARTICLE, 'en');
    await expect(banner(page).locator('[data-tolk-auto-update]')).toHaveText('Translations are updated automatically each time this entry is saved.');
    await expect(push(page).locator('[data-tolk-action="translate-into"]')).toBeVisible();

    const saved = await saveTitle(page, 'Our new spa opens in April');

    expect(saved.origin.auto_update_sites).toEqual(['el', 'de']);
    await expect(finished(page)).toBeVisible();
    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Up to date');
    await expect(banner(page).locator('[data-tolk-site="de"]')).toContainText('Up to date');

    for (const site of ['el', 'de']) {
        const stored = entryFile('articles', site, ARTICLE);

        expect(stored.title).toBe(`[${site}] Our new spa opens in April`);
        expect(stored.published).toBe(false);
    }

    expect(browserErrors()).toEqual([]);
});

test('a save while automatic updates are off starts nothing', async ({ page, browserErrors }) => {
    const origin = entryFile('articles', 'en', ARTICLE);
    please(`tolk:translate --entry=${origin.id} --site=el --concurrency=1 --yes`);

    await openEntry(page, 'articles', ARTICLE, 'en');

    const saved = await saveTitle(page, 'Our new spa opens in April');

    expect(saved.origin.auto_update).toBeNull();
    await page.waitForTimeout(2000);
    await expect(push(page).locator('[data-tolk-push-phase]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Outdated');
    expect(entryFile('articles', 'el', ARTICLE).title).toBe('[el] Our new spa opens this spring');
    expect(browserErrors()).toEqual([]);
});
