import { expect, test } from './support/test.js';
import { entryFile, openEntry, reset } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const push = (page) => banner(page).locator('[data-tolk-push]');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');
// Core's Sites panel strikes through a site that has no localization yet.
const struckThrough = (page, name) => page.locator('.line-through').filter({ hasText: name });

// An article without localizations: a Bard text set with two paragraphs around an image, a table and a CTA.
const ARTICLE = 'our-new-spa';
const FIELDS = ['title', 'excerpt', 'body'];

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

async function quickSave(page) {
    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    const response = await confirmed;

    expect(response.status()).toBe(200);

    return response.json();
}

const pick = (data, handles) => Object.fromEntries(handles.map((handle) => [handle, data[handle]]));

// Starts a translation into Greek and German through Translate into…, with the modal's defaults.
async function startTranslateInto(page) {
    await push(page).locator('[data-tolk-action="translate-into"]').click();

    const modal = page.locator('[data-tolk-push-modal]');

    await expect(modal.getByRole('checkbox', { name: 'Greek' })).toBeChecked();
    await expect(modal.getByRole('checkbox', { name: 'German' })).toBeChecked();
    await expect(modal.getByRole('radio', { name: 'Drafts' })).toBeChecked();
    await modal.locator('[data-tolk-push-action="translate"]').click();
}

async function translateInto(page) {
    await startTranslateInto(page);
    await expect(push(page).locator('[data-tolk-push-result]')).toHaveAttribute('data-tolk-push-status', 'completed');
}

test('Translate into… creates the Greek and German localizations as drafts, without touching the form', async ({ page, browserErrors }) => {
    expect(entryFile('articles', 'el', ARTICLE)).toBeNull();

    const origin = await openEntry(page, 'articles', ARTICLE, 'en');

    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Missing');
    await expect(banner(page).locator('[data-tolk-site="de"]')).toContainText('Missing');
    await expect(push(page).locator('[data-tolk-action="update-translations"]')).toHaveCount(0);

    // The run translates the saved entry, so an unsaved edit has to be saved first.
    await page.locator('#field_title').fill('Our new spa opens in April');
    await expect(push(page).locator('[data-tolk-action="translate-into"]')).toBeDisabled();
    await expect(push(page).locator('[data-tolk-save-first]')).toHaveText('Save first: the translations are made from the saved entry.');

    await quickSave(page);
    await expect(push(page).locator('[data-tolk-save-first]')).toHaveCount(0);

    await translateInto(page);

    const result = push(page).locator('[data-tolk-push-result]');

    await expect(result).toContainText('The translations are done: 2 saved.');
    await expect(result.locator('[data-tolk-push-item="el"] a')).toHaveText('Greek');
    await expect(result.locator('[data-tolk-push-item="de"] a')).toHaveText('German');
    await expect(result.locator('[data-tolk-action="reload"]')).toBeVisible();
    await expect(struckThrough(page, 'German')).toHaveCount(1);
    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Up to date');
    await expect(banner(page).locator('[data-tolk-site="el"] a')).toHaveAttribute('href', /\/collections\/articles\/entries\//);

    // The open form is untouched and has nothing to save.
    await expect(page.locator('#field_title')).toHaveValue('Our new spa opens in April');
    expect(await page.evaluate(() => Statamic.$dirty.has('base'))).toBe(false);

    for (const site of ['el', 'de']) {
        const stored = entryFile('articles', site, ARTICLE);

        expect(stored.origin).toBe(origin.id);
        expect(stored.published).toBe(false);
        expect(stored.title).toBe(`[${site}] Our new spa opens in April`);
        expect(stored.body[0].heading).toBe(`[${site}] A quiet corner of the garden wing`);
    }

    // Reload shows the new localizations in the Sites panel. Both are up to date, so the banner has nothing left to show.
    const status = page.waitForResponse((response) => response.url().includes('/tolk/editor/status'));
    await result.locator('[data-tolk-action="reload"]').click();
    await status;
    await expect(struckThrough(page, 'German')).toHaveCount(0);
    await expect(struckThrough(page, 'Greek')).toHaveCount(0);
    await page.waitForTimeout(500);
    await expect(banner(page)).toHaveCount(0);
    expect(browserErrors()).toEqual([]);
});

test('Update translations changes only the edited paragraph in both stored localizations', async ({ page, browserErrors }) => {
    await openEntry(page, 'articles', ARTICLE, 'en');
    // A first save stores the article as the CP shapes it (the image in a paragraph, ids for every set), so the
    // edit below is the only change Update carries over.
    await quickSave(page);
    await translateInto(page);
    await push(page).locator('[data-tolk-action="push-close"]').click();

    const before = Object.fromEntries(['el', 'de'].map((site) => [site, entryFile('articles', site, ARTICLE)]));

    // The second paragraph of the text set, after the image.
    const paragraph = page.locator('#field_body_0_body .ProseMirror p').last();
    const box = await paragraph.boundingBox();
    await paragraph.click({ position: { x: box.width - 2, y: box.height - 4 } });
    await page.keyboard.press('End');
    await page.keyboard.type(' Book a day ahead.');

    const saved = await quickSave(page);

    expect(saved.origin.localizations.map(({ site, status }) => [site, status])).toEqual([
        ['el', 'outdated'],
        ['de', 'outdated'],
    ]);
    await expect(push(page).locator('[data-tolk-action="translate-into"]')).toHaveCount(0);
    await push(page).locator('[data-tolk-action="update-translations"]').click();
    await expect(push(page).locator('[data-tolk-push-result]')).toHaveAttribute('data-tolk-push-status', 'completed');
    await expect(push(page).locator('[data-tolk-action="reload"]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-site="el"]')).toContainText('Up to date');

    const edited = entryFile('articles', 'en', ARTICLE).body[0].body.at(-1);

    for (const site of ['el', 'de']) {
        const after = entryFile('articles', site, ARTICLE);
        const expected = structuredClone(pick(before[site], FIELDS));
        expected.body[0].body[2] = after.body[0].body[2];

        expect(pick(after, FIELDS)).toEqual(expected);
        expect(after.body[0].body[2]).not.toEqual(before[site].body[0].body[2]);
        expect(after.body[0].body[2].content.at(-1).text).toBe(`[${site}] ${edited.content.at(-1).text}`);
        expect(after.published).toBe(false);
    }

    expect(browserErrors()).toEqual([]);
});

test('reloading the page mid-run resumes the progress display', async ({ page, browserErrors }) => {
    // Hold every apply, so the run stays unfinished until the reloaded page lets its applies through.
    let holding = true;
    const held = [];

    await page.route('**/tolk/runs/*/apply', (route) => (holding ? held.push(route) : route.continue()));

    await openEntry(page, 'articles', ARTICLE, 'en');
    await startTranslateInto(page);

    await expect(push(page).locator('[data-tolk-push-phase="running"]')).toBeVisible();
    await expect(push(page).locator('[data-tolk-push-progress]')).toHaveText('0 of 2 translations finished.');
    await expect(push(page).locator('[data-tolk-push-phase="running"]')).toContainText('Keep this page open');

    await page.reload();
    await expect(page.locator('[id^="field_"]').filter({ visible: true }).first()).toBeVisible();

    await expect(push(page).locator('[data-tolk-push-phase="running"]')).toBeVisible();
    await expect(push(page).locator('[data-tolk-push-phase="running"]')).toContainText('Translating into Greek, German…');
    await expect(push(page).locator('[data-tolk-push-progress]')).toHaveText('0 of 2 translations finished.');
    expect(entryFile('articles', 'el', ARTICLE)).toBeNull();

    holding = false;
    await Promise.all(held.splice(0).map((route) => route.continue().catch(() => {})));

    await expect(push(page).locator('[data-tolk-push-result]')).toHaveAttribute('data-tolk-push-status', 'completed');
    expect(entryFile('articles', 'el', ARTICLE).title).toBe('[el] Our new spa opens this spring');
    expect(entryFile('articles', 'de', ARTICLE).title).toBe('[de] Our new spa opens this spring');
    expect(browserErrors().filter((error) => !error.includes('/apply'))).toEqual([]);
});
