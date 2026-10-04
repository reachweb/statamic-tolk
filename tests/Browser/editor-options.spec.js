import { expect, test } from './support/test.js';
import { entryFile, entryState, openEntry, reset } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const action = (page, name) => banner(page).locator(`[data-tolk-action="${name}"]`);
const part = (page, key) => banner(page).locator(`[data-tolk-localization] [data-tolk-part="${key}"]`);
const translated = (page) => banner(page).locator('[data-tolk-summary-translated]');
const dialog = (page) => page.locator('[data-tolk-options]');
const isPlan = (request) => request.url().includes('/tolk/editor/plan');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');

async function openOptions(page) {
    await action(page, 'options').click();
    await expect(dialog(page)).toBeVisible();
    // The models load when the options open; until then Translate is disabled.
    await expect(dialog(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();
}

// Translate in the options, resolving with the plan request's body.
async function translateFromOptions(page) {
    const planned = page.waitForRequest(isPlan);
    await dialog(page).locator('[data-tolk-options-action="translate"]').click();

    return (await planned).postDataJSON();
}

// Quick save stays on the form; resolves with the status `editor/confirm` returns.
async function quickSave(page) {
    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');

    return (await confirmed).json();
}

const checkbox = (page, name) => dialog(page).getByRole('checkbox', { name });
// Core's checkbox label covers its box, so the label takes the click.
const toggle = (page, name) => dialog(page).locator('label', { hasText: name }).click();
const modelSelect = (page) => dialog(page).getByRole('combobox');

const preference = (page, key) => page.evaluate((key) => Statamic.$preferences.get(key, null), key);

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was: other specs read the German home page and the Greek article without resetting.
test.afterAll(() => {
    reset();
});

test.describe('the German home page, whose title was written by hand', () => {
    let origin;
    let before;

    test.beforeEach(async ({ page }) => {
        origin = entryFile('pages', 'en', 'home');
        before = entryFile('pages', 'de', 'home');

        await openEntry(page, 'pages', 'home', 'de');
        await expect(action(page, 'options')).toBeVisible();
        // The banner takes the form's clean values again 600 ms after it mounted (T5.6).
        await page.waitForTimeout(700);
    });

    test('a field with hand-written text is skipped and listed, and replaced with Overwrite manual edits', async ({ page, browserErrors }) => {
        await openOptions(page);

        await expect(checkbox(page, 'Overwrite manual edits')).not.toBeChecked();

        expect(await translateFromOptions(page)).toMatchObject({ mode: 'update', overwrite_manual: false, translator: null });
        await expect(translated(page)).toHaveText(/^3 fields translated with /);
        await expect(banner(page).locator('[data-tolk-summary-manual]')).toHaveText(
            'Title was skipped because it was edited by hand. Overwrite manual edits in the options replaces it.',
        );
        await expect(page.locator('#field_title')).toHaveValue(before.title);
        await expect(page.locator('#field_intro')).toHaveValue(`[de] ${origin.intro}`);

        await action(page, 'undo').click();
        await expect(page.locator('#field_intro')).toHaveValue(origin.intro);

        await openOptions(page);
        await toggle(page, 'Overwrite manual edits');
        await expect(checkbox(page, 'Overwrite manual edits')).toBeChecked();

        expect(await translateFromOptions(page)).toMatchObject({ mode: 'update', overwrite_manual: true });
        await expect(translated(page)).toHaveText(/^4 fields translated with /);
        await expect(banner(page).locator('[data-tolk-summary-manual]')).toHaveCount(0);
        await expect(page.locator('#field_title')).toHaveValue(`[de] ${origin.title}`);

        const status = await quickSave(page);

        expect(status.localization.fields.title).toMatchObject({ status: 'current' });
        expect(entryFile('pages', 'de', 'home')).toMatchObject({ title: `[de] ${origin.title}`, intro: `[de] ${origin.intro}` });
        expect(browserErrors()).toEqual([]);
    });

    test('Retranslate everything fills fields that are up to date again', async ({ page, browserErrors }) => {
        await action(page, 'translate').click();
        await expect(translated(page)).toHaveText(/^3 fields translated with /);
        await quickSave(page);
        await expect(part(page, 'current')).toHaveText('3 fields up to date');

        // Without a flag there is nothing to do.
        await openOptions(page);
        await translateFromOptions(page);
        await expect(translated(page)).toHaveText('No fields were translated.');
        await quickSave(page);
        await expect(action(page, 'options')).toBeVisible();

        await openOptions(page);
        await expect(checkbox(page, 'Retranslate everything')).not.toBeChecked();
        await toggle(page, 'Retranslate everything');

        const plan = page.waitForResponse((response) => isPlan(response.request()));
        expect(await translateFromOptions(page)).toMatchObject({ mode: 'all', overwrite_manual: false });
        expect((await (await plan).json()).fields).toEqual(['intro', 'seo_title', 'seo_description']);

        await expect(translated(page)).toHaveText(/^3 fields translated with /);
        await expect(banner(page).locator('[data-tolk-summary-manual]')).toContainText('Title was skipped');
        await expect(page.locator('#field_seo_title')).toHaveValue(`[de] ${origin.seo_title}`);
        expect(browserErrors()).toEqual([]);
    });

    test('Protect this entry from updates turns the translated fields manual', async ({ page, browserErrors }) => {
        await action(page, 'translate').click();
        await expect(translated(page)).toHaveText(/^3 fields translated with /);
        await quickSave(page);
        await expect(part(page, 'current')).toHaveText('3 fields up to date');

        await openOptions(page);
        await dialog(page).locator('[data-tolk-action="protect"]').click();

        // The hand-written title is baselined with them, so it is tracked from now on.
        await expect(page.getByText('4 fields are now protected from updates.')).toBeVisible();
        await expect(dialog(page)).toHaveCount(0);
        await expect(part(page, 'manual')).toHaveText('4 fields edited by hand');
        await expect(part(page, 'untracked')).toHaveCount(0);
        await expect(part(page, 'current')).toHaveCount(0);
        await expect(action(page, 'update')).toHaveCount(0);

        const intro = entryState(before.id).fields.intro;

        expect(intro.source_hash).toMatch(/^[0-9a-f]{16}$/);
        expect(intro.target_hash).toBeUndefined();
        expect(browserErrors()).toEqual([]);
    });

    test('the model choice is remembered, the flags start unchecked every time', async ({ page, browserErrors }) => {
        await openOptions(page);
        // The combobox shows the chosen model as its placeholder.
        await expect(modelSelect(page)).toHaveAttribute('placeholder', /\(default\)$/);

        await modelSelect(page).click();
        await page.getByRole('option', { name: 'GPT-6 Luna' }).click();
        await toggle(page, 'Overwrite manual edits');

        expect(await translateFromOptions(page)).toMatchObject({ translator: 'openai:gpt-6-luna', overwrite_manual: true });
        await expect(translated(page)).toHaveText('4 fields translated with gpt-6-luna.');
        await expect.poll(() => preference(page, 'tolk.editor.translator')).toBe('openai:gpt-6-luna');

        await page.reload();
        await expect(action(page, 'options')).toBeVisible();
        await openOptions(page);

        await expect(modelSelect(page)).toHaveAttribute('placeholder', 'GPT-6 Luna');
        await expect(checkbox(page, 'Overwrite manual edits')).not.toBeChecked();
        await expect(checkbox(page, 'Retranslate everything')).not.toBeChecked();

        // Choosing the default again forgets the choice.
        await modelSelect(page).click();
        await page.getByRole('option', { name: /\(default\)$/ }).click();
        await translateFromOptions(page);
        await expect.poll(() => preference(page, 'tolk.editor.translator')).toBeNull();
        expect(browserErrors()).toEqual([]);
    });

    test('a user who may edit the settings can type a model outside the catalog', async ({ page, browserErrors }) => {
        await openOptions(page);
        await expect(dialog(page)).toContainText('You may also type a model outside the list, as provider:model.');

        await modelSelect(page).click();
        await modelSelect(page).pressSequentially('openai:gpt-custom');
        await expect(page.getByRole('option')).toHaveText(['openai:gpt-custom']);
        await modelSelect(page).press('Enter');
        await expect(modelSelect(page)).toHaveAttribute('placeholder', 'openai:gpt-custom');

        expect(await translateFromOptions(page)).toMatchObject({ translator: 'openai:gpt-custom' });
        await expect(translated(page)).toHaveText('3 fields translated with gpt-custom.');
        expect(browserErrors()).toEqual([]);
    });

    test('the toggle turns the offer for new localizations off and back on', async ({ page, browserErrors }) => {
        await openOptions(page);

        const offer = 'Offer to translate new localizations';
        await expect(checkbox(page, offer)).toBeChecked();

        await toggle(page, offer);
        await expect(checkbox(page, offer)).not.toBeChecked();
        await expect.poll(() => preference(page, 'tolk.offer')).toBe(false);

        await page.reload();
        await expect(action(page, 'options')).toBeVisible();
        await openOptions(page);
        await expect(checkbox(page, offer)).not.toBeChecked();

        await toggle(page, offer);
        await expect.poll(() => preference(page, 'tolk.offer')).toBe(true);
        expect(browserErrors()).toEqual([]);
    });
});

test('after Mark manual fields as up to date, the Greek article reads edited by hand, then needs attention after an origin edit', async ({
    page,
    browserErrors,
}) => {
    const greek = await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
    await expect(part(page, 'manual')).toHaveText('2 fields edited by hand');
    await expect(part(page, 'untracked')).toHaveText('2 untracked');

    await openOptions(page);
    await expect(dialog(page).locator('[data-tolk-action="protect"]')).toHaveCount(0);
    await dialog(page).locator('[data-tolk-action="mark-current"]').click();

    await expect(page.getByText('2 fields marked as up to date.')).toBeVisible();
    await expect(part(page, 'manual')).toHaveText('2 fields edited by hand');
    await expect(part(page, 'untracked')).toHaveCount(0);
    await expect(part(page, 'attention')).toHaveCount(0);
    expect(entryState(greek.id).fields.title.source_hash).toMatch(/^[0-9a-f]{16}$/);

    // An editor changes the English title.
    await page.getByRole('button', { name: /^English Origin/ }).click();
    await expect(banner(page).locator('[data-tolk-origin]')).toBeVisible();
    await page.locator('#field_title').fill('What to eat in the old town of Chania');
    await page.waitForTimeout(400);
    await quickSave(page);
    await expect(banner(page).locator('[data-tolk-origin] [data-tolk-site="el"]')).toContainText('1 needs attention');

    await page.getByRole('button', { name: /^Greek/ }).click();
    await expect(page.locator('#field_title')).toHaveValue(greek.title);
    await expect(part(page, 'attention')).toHaveText('1 needs attention');
    await expect(part(page, 'untracked')).toHaveCount(0);

    // Marking it again clears the attention.
    await openOptions(page);
    await dialog(page).locator('[data-tolk-action="mark-current"]').click();
    await expect(part(page, 'attention')).toHaveCount(0);
    await expect(part(page, 'manual')).toHaveText('2 fields edited by hand');
    expect(browserErrors()).toEqual([]);
});
