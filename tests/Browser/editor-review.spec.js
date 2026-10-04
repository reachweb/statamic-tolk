import { expect, test } from './support/test.js';
import { entryFile, entryState, openEntry, reset, tinker } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const action = (page, name) => banner(page).locator(`[data-tolk-action="${name}"]`);
const translated = (page) => banner(page).locator('[data-tolk-summary-translated]');
const reviewed = (page) => banner(page).locator('[data-tolk-summary-reviewed]');
const options = (page) => page.locator('[data-tolk-options]');
const changes = (page) => page.locator('[data-tolk-changes]');
const isPlan = (request) => request.url().includes('/tolk/editor/plan');
const isReview = (request) => request.url().includes('/tolk/editor/review');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');

// The article whose body holds the fake engine's markers: `[[review]]` makes the reviewer correct its paragraph, and
// `[[invalid]]` and `[[refuse]]` leave two paragraphs untranslated, so the body is delivered incomplete.
const ARTICLE = 'fake-engine-markers';
const ARTICLE_ID = '3e1bef1b-a083-4fc6-9326-825405cb50eb';
const MARKED = '[[review]] This paragraph asks the fake reviewer for a correction, so the review step has something to change.';
const REVIEWER = 'claude-sonnet-5-5';

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was for the next spec and the owner.
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

// Opens the Greek localization once the banner has taken the form's clean values again (T5.6).
async function openGreek(page) {
    await openEntry(page, 'articles', ARTICLE, 'el');
    await expect(action(page, 'options')).toBeVisible();
    await page.waitForTimeout(700);
}

// Presses the banner's Review and resolves with the body `editor/review` was sent.
async function review(page) {
    const requested = page.waitForRequest(isReview);
    await action(page, 'review').click();

    return (await requested).postDataJSON();
}

const greekId = () => entryFile('articles', 'el', ARTICLE).id;

test('Translate with Review after translating reports the correction, lists it with its reason, and a second Review finds nothing', async ({ page, browserErrors }) => {
    tinker(`\\Statamic\\Facades\\Entry::find('${ARTICLE_ID}')->makeLocalization('el')->save();`);
    await openGreek(page);

    await action(page, 'options').click();
    await expect(options(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();
    // The review setting is off in the sandbox, so the toggle starts unchecked.
    await expect(options(page).getByRole('checkbox', { name: 'Review after translating' })).not.toBeChecked();
    await options(page).locator('label', { hasText: 'Review after translating' }).click();

    const planned = page.waitForRequest(isPlan);
    await options(page).locator('[data-tolk-options-action="translate"]').click();
    expect((await planned).postDataJSON()).toMatchObject({ review: true, reviewer: null });

    await expect(reviewed(page)).toHaveText(`Reviewed with ${REVIEWER}: 1 correction.`);
    await expect(translated(page)).toHaveText(new RegExp(`^\\d+ fields translated with ${REVIEWER}\\.$`));

    await action(page, 'view-changes').click();
    await expect(changes(page).locator('[data-tolk-change]')).toHaveCount(1);
    await expect(changes(page).locator('[data-tolk-change-label]')).toContainText('Body');
    await expect(changes(page).locator('[data-tolk-change-before]')).toHaveText(`[el] ${MARKED}`);
    await expect(changes(page).locator('[data-tolk-change-after]')).toHaveText(`[el, reviewed] ${MARKED}`);
    await expect(changes(page).locator('[data-tolk-change-before] [data-tolk-removed]')).toHaveText(['[el]']);
    await expect(changes(page).locator('[data-tolk-change-after] [data-tolk-added]')).toHaveText(['[el,', 'reviewed]']);
    await expect(changes(page).locator('[data-tolk-change-reason]')).toHaveText('The source contains the [[review]] marker.');
    await changes(page).locator('[data-tolk-changes-action="close"]').click();
    await expect(changes(page)).toHaveCount(0);

    // The filled, unsaved form: the corrected paragraph equals the reviewer's text and the body is incomplete, so there
    // is nothing left to correct.
    const sent = await review(page);

    expect(Object.keys(sent.values)).toEqual(expect.arrayContaining(['title', 'body']));
    await expect(reviewed(page)).toHaveText(`Reviewed with ${REVIEWER}: nothing to correct.`);
    await expect(translated(page)).toHaveCount(0);
    await expect(action(page, 'view-changes')).toHaveCount(0);

    await quickSave(page);

    const state = entryState(greekId());
    const stored = entryFile('articles', 'el', ARTICLE);

    expect(state.fields.body.reviewer).toBe(`anthropic:${REVIEWER}`);
    expect(state.fields.title.reviewer).toBe(`anthropic:${REVIEWER}`);
    expect(JSON.stringify(stored.body)).toContain(`[el, reviewed] ${MARKED}`);
    expect(browserErrors()).toEqual([]);
});

test('Review on the filled form corrects it, Undo puts the translation back, and Review again is saved with the reviewer', async ({ page, browserErrors }) => {
    tinker(
        `$entry = \\Statamic\\Facades\\Entry::find('${ARTICLE_ID}'); $entry->set('title', '[[review]] Fake engine markers')->save(); $entry->makeLocalization('el')->save();`,
    );
    await openGreek(page);

    await action(page, 'translate').click();
    await expect(translated(page)).toHaveText(new RegExp(`^\\d+ fields translated with ${REVIEWER}\\.$`));
    await expect(reviewed(page)).toHaveCount(0);
    await expect(page.locator('#field_title')).toHaveValue('[el] [[review]] Fake engine markers');

    const sent = await review(page);

    expect(sent).toMatchObject({ reference: `entry::${greekId()}`, overwrite_manual: false, reviewer: null });
    expect(sent.values.title).toBe('[el] [[review]] Fake engine markers');
    await expect(reviewed(page)).toHaveText(`Reviewed with ${REVIEWER}: 1 correction.`);
    await expect(page.locator('#field_title')).toHaveValue('[el, reviewed] [[review]] Fake engine markers');

    await action(page, 'view-changes').click();
    await expect(changes(page).locator('[data-tolk-change-label]')).toHaveText('Title');
    await changes(page).locator('[data-tolk-changes-action="close"]').click();

    await action(page, 'undo').click();
    await expect(page.locator('#field_title')).toHaveValue('[el] [[review]] Fake engine markers');
    await expect(banner(page).locator('[data-tolk-summary]')).toHaveCount(0);

    await review(page);
    await expect(reviewed(page)).toHaveText(`Reviewed with ${REVIEWER}: 1 correction.`);
    await expect(page.locator('#field_title')).toHaveValue('[el, reviewed] [[review]] Fake engine markers');

    const status = await quickSave(page);

    expect(status.localization.fields.title).toMatchObject({ status: 'current' });
    expect(entryFile('articles', 'el', ARTICLE).title).toBe('[el, reviewed] [[review]] Fake engine markers');
    expect(entryState(greekId()).fields.title).toMatchObject({ reviewer: `anthropic:${REVIEWER}`, translator: `anthropic:${REVIEWER}` });
    expect(browserErrors()).toEqual([]);
});
