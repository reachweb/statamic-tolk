import { expect, test } from './support/test.js';
import { entryFile, entryState, openEntry, reset } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was: other specs read the German home page's counts without resetting.
test.afterAll(() => {
    reset();
});

test('Translate fills the untranslated fields, and Save stores them with their state', async ({ page, browserErrors }) => {
    const origin = entryFile('pages', 'en', 'home');
    const before = entryFile('pages', 'de', 'home');

    expect(Object.keys(before).sort()).toEqual(['id', 'origin', 'title']);

    await openEntry(page, 'pages', 'home', 'de');
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('4 fields untranslated');

    await banner(page).locator('[data-tolk-action="translate"]').click();

    await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^4 fields translated with .+\.$/);
    await expect(banner(page).locator('[data-tolk-summary-unsaved]')).toHaveText('Not saved yet. Check the fields, then save.');
    await expect(banner(page).locator('[data-tolk-action="undo"]')).toBeVisible();
    await expect(page.locator('#field_title')).toHaveValue(before.title);
    await expect(page.locator('#field_intro')).toHaveValue(`[de] ${origin.intro}`);
    await expect(page.locator('#field_seo_title')).toHaveValue(`[de] ${origin.seo_title}`);
    await expect(page.locator('#field_seo_description')).toHaveValue(`[de] ${origin.seo_description}`);

    // The form is filled, nothing is stored: the entry is as it was, and the state only knows what was handed out.
    expect(entryFile('pages', 'de', 'home')).toEqual(before);

    const pending = entryState(before.id).fields;

    expect(Object.keys(pending).sort()).toEqual(['content', 'intro', 'seo_description', 'seo_title']);
    Object.values(pending).forEach((field) => {
        expect(field.pending).toHaveLength(1);
        expect(field.target_hash).toBeUndefined();
    });

    // With the default "after saving" preference Save leaves for the listing; the confirm request still completes.
    const confirmed = page.waitForResponse((response) => response.url().includes('/tolk/editor/confirm'));
    await page.getByRole('button', { name: 'Save & Publish' }).click();
    expect((await confirmed).status()).toBe(200);
    await page.waitForURL((url) => !url.pathname.includes(before.id));

    const after = entryFile('pages', 'de', 'home');

    expect(after.title).toBe(before.title);
    expect(after.intro).toBe(`[de] ${origin.intro}`);
    expect(after.seo_title).toBe(`[de] ${origin.seo_title}`);
    expect(after.seo_description).toBe(`[de] ${origin.seo_description}`);

    const state = entryState(before.id);

    expect(state.reference).toBe(`entry::${before.id}`);
    expect(state.source_site).toBe('en');
    expect(Object.keys(state.fields).sort()).toEqual(['content', 'intro', 'seo_description', 'seo_title']);
    Object.values(state.fields).forEach((field) => {
        expect(field.pending).toBeUndefined();
        expect(field.source_hash).toMatch(/^[0-9a-f]{16}$/);
        expect(field.target_hash).toMatch(/^[0-9a-f]{16}$/);
        expect(field.translator).toMatch(/^[a-z]+:\S+$/);
    });

    await openEntry(page, 'pages', 'home', 'de');

    await expect(banner(page).locator('[data-tolk-part="current"]')).toHaveText('4 fields up to date');
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-part="outdated"]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-action="translate"]')).toHaveCount(0);
    expect(browserErrors()).toEqual([]);
});

test('a quick save keeps the form and the banner reads current at once', async ({ page, browserErrors }) => {
    const before = entryFile('pages', 'de', 'home');

    await openEntry(page, 'pages', 'home', 'de');
    await banner(page).locator('[data-tolk-action="translate"]').click();
    await expect(banner(page).locator('[data-tolk-summary-translated]')).toBeVisible();

    const confirmed = page.waitForResponse((response) => response.url().includes('/tolk/editor/confirm'));
    await page.keyboard.press('ControlOrMeta+s');
    const body = await (await confirmed).json();

    expect(body.not_saved).toEqual([]);
    expect(body.localization.counts).toMatchObject({ untranslated: 0, current: 4 });
    await expect(page).toHaveURL(new RegExp(before.id));
    await expect(banner(page).locator('[data-tolk-part="current"]')).toHaveText('4 fields up to date');
    await expect(banner(page).locator('[data-tolk-summary]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-action="undo"]')).toHaveCount(0);
    expect(await page.evaluate(() => Statamic.$dirty.has('base'))).toBe(false);
    expect(browserErrors()).toEqual([]);
});
