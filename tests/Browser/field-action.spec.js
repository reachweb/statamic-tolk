import { expect, test } from './support/test.js';
import { entryFile, openEntry, reset, tinker } from './support/sandbox.js';

const TITLE = 'Translate from origin';
const banner = (page) => page.locator('[data-tolk-banner]');

// A dated article whose Replicator body has a text set with a heading and a Bard field.
const ARTICLE = 'family-days-out-in-western-crete';
const ARTICLE_ID = '03863bc8-db48-4483-8169-cd7cb518d369';

// Text fieldtypes pass a change up to the form after a short debounce.
const typed = (page) => page.waitForTimeout(400);

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was: other specs read the German home page's counts without resetting.
test.afterAll(() => {
    reset();
});

// The header of a field: its label and its buttons. `scope` is the page's own form (`main`) or a stack.
const header = (scope, selector) => scope.locator(`[data-ui-field-header]:has(label${selector})`);
const button = (scope, selector) => header(scope, selector).getByRole('button', { name: TITLE, exact: true });

async function expectAction(scope, selector) {
    await expect(button(scope, selector)).toBeVisible();
}

async function expectNoAction(scope, selector) {
    await expect(header(scope, selector)).toBeVisible();
    await expect(button(scope, selector)).toHaveCount(0);
}

// Presses the field's button and, with `confirm`, answers the confirmation it asks first with.
async function runAction(page, selector, { confirm = false } = {}) {
    await button(page.locator('main'), selector).click();

    if (confirm) {
        const modal = page.getByRole('dialog');

        await expect(modal).toContainText('The current content of this field will be replaced.');
        await modal.getByRole('button', { name: 'Translate' }).click();
    }
}

test('shows on the localizable text, Bard and Replicator fields of a localization only', async ({ page, browserErrors }) => {
    const main = page.locator('main');

    // The German home page: a text, a textarea and a Bard field; the Bard field's quote set; a field that is not localizable.
    await openEntry(page, 'pages', 'home', 'de');
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toBeVisible();

    await expectAction(main, '[for="field_title"]');
    await expectAction(main, '[for="field_intro"]');
    await expectAction(main, '[for="field_content"]');
    await expectNoAction(main, '[for^="field_content_"][for$="_attrs_values_text"]');
    await expectNoAction(main, '[for^="field_content_"][for$="_attrs_values_author"]');
    await expectNoAction(main, '[for="field_css_class"]');

    // A Greek article: the Replicator body, the heading of its text set, the set's Bard field, whose handle `body` is also
    // the Replicator's, and a text field that is not localizable.
    tinker(`\\Statamic\\Facades\\Entry::find('${ARTICLE_ID}')->makeLocalization('el')->save();`);
    await openEntry(page, 'articles', ARTICLE, 'el');
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toBeVisible();

    await expectAction(main, '[for="field_title"]');
    await expectAction(main, '[for="field_body"]');
    await expectNoAction(main, '[for="field_body_0_heading"]');
    await expectNoAction(main, '[for="field_body_0_body"]');
    await expectNoAction(main, '[for="field_internal_note"]');

    // The English origin of the home page.
    await openEntry(page, 'pages', 'home', 'en');
    await expect(banner(page).locator('[data-tolk-origin]')).toBeVisible();

    await expectNoAction(main, '[for="field_title"]');
    await expectNoAction(main, '[for="field_intro"]');
    await expectNoAction(main, '[for="field_content"]');
    expect(browserErrors()).toEqual([]);
});

test('running it replaces only that field, typed text included, asks first only then, and leaves the form unsaved', async ({ page, browserErrors }) => {
    const main = page.locator('main');
    const origin = entryFile('pages', 'en', 'home');
    const before = entryFile('pages', 'de', 'home');

    await openEntry(page, 'pages', 'home', 'de');
    await expect(banner(page).locator('[data-tolk-action="translate"]')).toBeVisible();
    // The banner takes the form's clean values again 600 ms after it mounted (T5.6).
    await page.waitForTimeout(700);

    await page.locator('#field_title').fill('Mein Titel');
    await typed(page);

    // Units wait until the progress line was seen.
    let release;
    const held = new Promise((resolve) => (release = resolve));
    await page.route('**/tolk/editor/unit', async (route) => {
        await held;
        await route.continue();
    });

    const planned = page.waitForRequest((request) => request.url().includes('/tolk/editor/plan'));
    await runAction(page, '[for="field_title"]', { confirm: true });
    const plan = (await planned).postDataJSON();

    expect(plan).toMatchObject({ mode: 'all', fields: ['title'], overwrite_manual: true });
    expect(plan.skip).not.toContain('title');
    await expect(banner(page).locator('[data-tolk-working-field]')).toHaveText('Translating Title into German…');

    // The buttons stay in their headers while the translation runs, disabled.
    await expect(button(main, '[for="field_title"]')).toBeDisabled();
    await expect(button(main, '[for="field_content"]')).toBeDisabled();
    release();

    await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^1 field translated with .+\.$/);
    await expect(button(main, '[for="field_content"]')).toBeEnabled();
    await expect(page.locator('#field_title')).toHaveValue(`[de] ${origin.title}`);
    await expect(page.locator('#field_intro')).toHaveValue(origin.intro);
    await expect(page.locator('#field_content .ProseMirror h2')).toHaveText('Welcome to Kyma Bay');

    // The Bard field next, untranslated, so it shows the origin's text: no confirmation, and the focus the disabled button
    // lost goes to the banner. The title keeps its translation.
    const direct = page.waitForRequest((request) => request.url().includes('/tolk/editor/plan'));
    await runAction(page, '[for="field_content"]');

    expect((await direct).postDataJSON()).toMatchObject({ fields: ['content'] });
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator('#field_content .ProseMirror h2')).toHaveText('[de] Welcome to Kyma Bay');
    await expect.poll(() => page.evaluate(() => Boolean(document.activeElement?.closest('[data-tolk-banner]')))).toBe(true);
    await expect(page.locator('#field_title')).toHaveValue(`[de] ${origin.title}`);
    await expect(page.locator('#field_intro')).toHaveValue(origin.intro);

    expect(entryFile('pages', 'de', 'home')).toEqual(before);
    expect(await page.evaluate(() => Statamic.$dirty.has('base'))).toBe(true);
    expect(browserErrors()).toEqual([]);
});

test('with a related entry open in a stack, the stacked form\'s fields have no action', async ({ page, browserErrors }) => {
    const about = entryFile('pages', 'en', 'about');
    const home = entryFile('pages', 'de', 'home');
    const main = page.locator('main');
    const stack = page.locator('[data-ui-stack-content]');

    // An `entries` field on the pages, and the German home page related to a German localization of `about`.
    tinker(
        `$blueprint = \\Statamic\\Facades\\Blueprint::find('collections.pages.page'); ` +
            `$contents = $blueprint->contents(); ` +
            `$contents['tabs']['main']['sections'][0]['fields'][] = ['handle' => 'related', 'field' => ['type' => 'entries', 'display' => 'Related', 'collections' => ['pages'], 'localizable' => true]]; ` +
            `$blueprint->setContents($contents)->save(); ` +
            `$about = \\Statamic\\Facades\\Entry::find('${about.id}')->makeLocalization('de'); ` +
            `$about->save(); ` +
            `\\Statamic\\Facades\\Entry::find('${home.id}')->set('related', [$about->id()])->save();`,
    );

    await openEntry(page, 'pages', 'home', 'de');
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toBeVisible();
    await expectAction(main, '[for="field_title"]');

    await main.locator('.entries-fieldtype').getByRole('link', { name: about.title }).click();
    await expect(stack.locator('#field_title')).toHaveValue(about.title);

    await expectNoAction(stack, '[for="field_title"]');
    await expectNoAction(stack, '[for="field_intro"]');
    await expectNoAction(stack, '[for="field_content"]');

    // Closing the stack brings the action back to the page's own form.
    await page.keyboard.press('Escape');
    await expect(stack).toHaveCount(0);
    await expectAction(main, '[for="field_title"]');
    expect(browserErrors()).toEqual([]);
});
