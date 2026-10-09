import { expect, test } from './support/test.js';
import { entryFile, entryState, openEntry, reset } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const action = (page, name) => banner(page).locator(`[data-tolk-action="${name}"]`);
const translated = (page) => banner(page).locator('[data-tolk-summary-translated]');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');

// Holds every `editor/unit` request until the returned function is called, so a scenario can act mid-translation.
async function holdUnits(page) {
    let release;
    const held = new Promise((resolve) => (release = resolve));

    await page.route('**/tolk/editor/unit', async (route) => {
        await held;
        await route.continue();
    });

    return release;
}

// Text fieldtypes pass a change up to the form after a short debounce.
const typed = (page) => page.waitForTimeout(400);

let origin;
let before;

test.beforeEach(async ({ page }) => {
    reset();
    origin = entryFile('pages', 'en', 'home');
    before = entryFile('pages', 'de', 'home');

    await openEntry(page, 'pages', 'home', 'de');
    await expect(action(page, 'translate')).toBeVisible();
    // The banner takes the form's clean values again 600 ms after it mounted (T5.6); text typed before then into a
    // field with a stored value counts as loaded. The status can arrive well inside that window.
    await page.waitForTimeout(700);
});

// Leave the sandbox as it was: other specs read the German home page's counts without resetting.
test.afterAll(() => {
    reset();
});

test('text typed before Translate is kept and listed as skipped', async ({ page, browserErrors }) => {
    await page.locator('#field_intro').fill('Ein familiengeführtes Hotel am alten Hafen.');
    await typed(page);

    const planned = page.waitForRequest((request) => request.url().includes('/tolk/editor/plan'));
    await action(page, 'translate').click();

    expect((await planned).postDataJSON().skip).toEqual(['intro']);
    await expect(translated(page)).toHaveText(/^3 fields translated with /);
    await expect(banner(page).locator('[data-tolk-summary-edited]')).toHaveText('Intro was skipped because you edited it.');
    await expect(page.locator('#field_intro')).toHaveValue('Ein familiengeführtes Hotel am alten Hafen.');
    await expect(page.locator('#field_seo_title')).toHaveValue(`[de] ${origin.seo_title}`);
    await expect(page.locator('#field_seo_description')).toHaveValue(`[de] ${origin.seo_description}`);
    expect(browserErrors()).toEqual([]);
});

test('Save is refused while a translation runs', async ({ page, browserErrors }) => {
    const release = await holdUnits(page);
    const saves = [];
    page.on('request', (request) => request.url().includes(before.id) && request.method() !== 'GET' && saves.push(request.url()));

    await action(page, 'translate').click();
    await expect(banner(page).locator('[data-tolk-phase="working"]')).toBeVisible();

    await page.getByRole('button', { name: 'Save & Publish' }).click();
    await expect(page.getByText('A translation is in progress. Wait for it to finish, or cancel it.')).toBeVisible();
    await page.keyboard.press('ControlOrMeta+s');
    await page.waitForTimeout(500);

    expect(saves).toEqual([]);
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(entryFile('pages', 'de', 'home')).toEqual(before);

    release();
    await expect(translated(page)).toHaveText(/^4 fields translated with /);

    // Once the translation has finished, Save works again and stores the filled fields.
    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    await confirmed;

    expect(saves).toHaveLength(1);
    expect(entryFile('pages', 'de', 'home')).toMatchObject({ intro: `[de] ${origin.intro}`, seo_title: `[de] ${origin.seo_title}` });
    expect(browserErrors()).toEqual([]);
});

test('switching site during a translation leaves the other form untouched', async ({ page, browserErrors }) => {
    const release = await holdUnits(page);
    const unitAnswered = page.waitForResponse((response) => response.url().includes('/tolk/editor/unit'));

    await action(page, 'translate').click();
    await expect(banner(page).locator('[data-tolk-phase="working"]')).toBeVisible();

    // The German form is already dirty: core's Bard field desyncs itself when the form loads.
    await page.getByRole('button', { name: /^English Origin/ }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Continue' }).click();
    await expect(page.locator('#field_title')).toHaveValue(origin.title);
    await expect(banner(page).locator('[data-tolk-origin]')).toBeVisible();

    release();
    await unitAnswered;
    await page.waitForTimeout(1_500);

    await expect(page.locator('#field_intro')).toHaveValue(origin.intro);
    await expect(page.locator('#field_seo_title')).toHaveValue(origin.seo_title);
    await expect(banner(page).locator('[data-tolk-phase]')).toHaveCount(0);

    // Back on German, the translation that was stopped does not fill the form either.
    await page.getByRole('button', { name: 'German', exact: true }).click();
    await expect(page.locator('#field_title')).toHaveValue(before.title);
    await expect(action(page, 'translate')).toBeVisible();
    await page.waitForTimeout(1_000);

    await expect(page.locator('#field_intro')).toHaveValue(origin.intro);
    await expect(banner(page).locator('[data-tolk-summary]')).toHaveCount(0);
    expect(entryFile('pages', 'en', 'home')).toEqual(origin);
    expect(entryFile('pages', 'de', 'home')).toEqual(before);
    expect(browserErrors()).toEqual([]);
});

test('a filled field edited before saving counts as edited by hand, with the source unchanged', async ({ page, browserErrors }) => {
    await action(page, 'translate').click();
    await expect(translated(page)).toHaveText(/^4 fields translated with /);

    await page.locator('#field_intro').fill('Ein familiengeführtes Hotel am alten Hafen von Chania.');
    await typed(page);

    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    const status = await (await confirmed).json();

    expect(status.not_saved).toEqual([]);
    expect(status.localization.fields.intro).toMatchObject({ status: 'manual', source_changed: false });
    expect(status.localization.fields.seo_title).toMatchObject({ status: 'current' });

    // Title was edited by hand before the addon tracked it; intro now is tracked against its source.
    await expect(banner(page).locator('[data-tolk-part="manual"]')).toHaveText('2 fields edited by hand');
    await expect(banner(page).locator('[data-tolk-part="untracked"]')).toHaveText('1 untracked');
    await expect(banner(page).locator('[data-tolk-part="attention"]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-part="current"]')).toHaveText('3 fields up to date');

    expect(entryFile('pages', 'de', 'home').intro).toBe('Ein familiengeführtes Hotel am alten Hafen von Chania.');

    const intro = entryState(before.id).fields.intro;

    expect(intro.source_hash).toMatch(/^[0-9a-f]{16}$/);
    expect(intro.target_hash).toBeUndefined();
    // The delivery stays pending, rebased onto the saved text, so its exact text saved later reads as machine again
    // (§A5 rule 2, D27).
    expect(intro.pending).toHaveLength(1);
    expect(intro.pending[0].target_hash).toMatch(/^[0-9a-f]{16}$/);
    expect(browserErrors()).toEqual([]);
});

test('Undo puts the previous values back', async ({ page, browserErrors }) => {
    await action(page, 'translate').click();
    await expect(translated(page)).toHaveText(/^4 fields translated with /);
    await expect(page.locator('#field_intro')).toHaveValue(`[de] ${origin.intro}`);

    await action(page, 'undo').click();

    await expect(page.locator('#field_intro')).toHaveValue(origin.intro);
    await expect(page.locator('#field_seo_title')).toHaveValue(origin.seo_title);
    await expect(page.locator('#field_seo_description')).toHaveValue(origin.seo_description);
    await expect(page.locator('#field_title')).toHaveValue(before.title);
    await expect(banner(page).locator('[data-tolk-summary]')).toHaveCount(0);
    await expect(action(page, 'translate')).toBeVisible();

    // The restored fields inherit from the origin again, so a save stores none of them.
    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    await confirmed;

    const after = entryFile('pages', 'de', 'home');

    expect(after.title).toBe(before.title);
    expect(after).not.toHaveProperty('intro');
    expect(after).not.toHaveProperty('seo_title');
    expect(after).not.toHaveProperty('seo_description');
    expect(browserErrors()).toEqual([]);
});

test('Cancel stops the translation and keeps what was already on its way', async ({ page, browserErrors }) => {
    const release = await holdUnits(page);

    await action(page, 'translate').click();
    await expect(banner(page).locator('[data-tolk-phase="working"]')).toBeVisible();
    await action(page, 'cancel').click();
    await expect(banner(page).locator('[data-tolk-phase="working"]')).toContainText('Cancelling.');
    await expect(action(page, 'cancel')).toBeDisabled();

    release();

    await expect(banner(page).locator('[data-tolk-summary]')).toContainText('The translation was cancelled before it finished.');
    await expect(translated(page)).toHaveText(/^4 fields translated with /);
    await expect(page.locator('#field_intro')).toHaveValue(`[de] ${origin.intro}`);
    expect(browserErrors()).toEqual([]);
});
