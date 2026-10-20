import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from 'yaml';
import { expect, test } from './support/test.js';
import { cpUrl, reset, sandboxPath } from './support/sandbox.js';

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

// The addon settings as the page stores them, or null before the first save.
function settingsFile() {
    const file = join(sandboxPath(), 'resources/addons/statamic-tolk.yaml');

    return existsSync(file) ? parse(readFileSync(file, 'utf8')) : null;
}

// A field of the form by its handle; grid fields are `{grid}_{index}_{field}`.
function field(page, handle) {
    return page.locator('[data-ui-input-group]').filter({ has: page.locator(`label[for="field_${handle}"]`) }).last();
}

async function openSettings(page) {
    await page.goto(cpUrl('addons/statamic-tolk/settings'));
    await expect(field(page, 'translator_model').locator('[data-tolk-model-select]')).toBeVisible();
}

async function choose(page, handle, option) {
    await field(page, handle).getByRole('combobox').first().click();
    await page.getByRole('option', { name: option }).click();
}

async function save(page) {
    const saved = page.waitForResponse((response) => response.url().includes('/addons/statamic-tolk/settings') && response.request().method() === 'PATCH');
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    return saved;
}

test('The settings page saves the translator, the reviewer, a glossary and a language instruction with the types Settings reads', async ({ page, browserErrors }) => {
    expect(settingsFile()).toBeNull();

    await openSettings(page);
    await expect(field(page, 'reviewer_model')).toBeHidden();

    // Anchored: with provider keys in the sandbox, an "Automatic (…)" option can name the same model.
    await choose(page, 'translator_model', /^GPT-6 Luna/);

    // The reviewer shows while review is on, and keeps its value once review is off again.
    await page.locator('#field_review_enabled').click();
    await expect(field(page, 'reviewer_model')).toBeVisible();
    await choose(page, 'reviewer_model', /^Gemini 3\.8 Flash/);
    await page.locator('#field_review_enabled').click();
    await expect(field(page, 'reviewer_model')).toBeHidden();

    await page.getByRole('tab', { name: 'Glossary' }).click();
    await page.getByRole('button', { name: 'Add term' }).click();
    await page.locator('#field_glossary_0_term').fill('Kyma Bay');
    await page.locator('#field_glossary_0_do_not_translate').click();
    await page.getByRole('button', { name: 'Add term' }).click();
    await page.locator('#field_glossary_1_term').fill('Breakfast');
    await choose(page, 'glossary_1_site', 'Greek');
    await page.locator('#field_glossary_1_translation').fill('Πρωινό');

    await page.getByRole('tab', { name: 'Context' }).click();
    await page.getByRole('button', { name: 'Add language' }).click();
    await choose(page, 'language_instructions_0_site', 'German');
    await field(page, 'language_instructions_0_formality').getByRole('button', { name: 'Formal', exact: true }).click();
    await page.locator('#field_language_instructions_0_instructions').fill('Use Sie.');

    expect((await save(page)).status()).toBe(200);

    const stored = settingsFile();

    expect(stored).toMatchObject({
        translator_model: 'openai:gpt-6-luna',
        review_enabled: false,
        reviewer_model: 'gemini:gemini-3.8-flash',
        new_localization_status: 'draft',
        offer_on_create: true,
        strict_fields: false,
        glossary: [
            { term: 'Kyma Bay', do_not_translate: true },
            { term: 'Breakfast', site: 'el', translation: 'Πρωινό', do_not_translate: false },
        ],
        language_instructions: [{ site: 'de', formality: 'formal', instructions: 'Use Sie.' }],
    });
    expect(stored).not.toHaveProperty('providers');
    expect(stored).not.toHaveProperty('site_profile');
    expect(stored.glossary[0]).not.toHaveProperty('site');
    stored.glossary.forEach((row) => expect(row.id).toEqual(expect.any(String)));

    // The saved values come back after a reload.
    await openSettings(page);
    await expect(field(page, 'translator_model').getByRole('combobox')).toHaveAttribute('placeholder', 'GPT-6 Luna');
    await page.getByRole('tab', { name: 'Glossary' }).click();
    await expect(page.locator('#field_glossary_1_translation')).toHaveValue('Πρωινό');

    expect(browserErrors()).toEqual([]);
});

test('A duplicate glossary term is refused on its row and nothing is saved', async ({ page, browserErrors }) => {
    await openSettings(page);
    await page.getByRole('tab', { name: 'Glossary' }).click();

    for (const [index, translation] of ['Agora market', 'The market'].entries()) {
        await page.getByRole('button', { name: 'Add term' }).click();
        await page.locator(`#field_glossary_${index}_term`).fill('Agora');
        await page.locator(`#field_glossary_${index}_translation`).fill(translation);
    }

    expect((await save(page)).status()).toBe(422);
    await expect(field(page, 'glossary_1_term')).toContainText('"Agora" is already in the glossary for every language.');
    await expect(field(page, 'glossary_0_term')).not.toContainText('already in the glossary');
    expect(settingsFile()).toBeNull();

    // A refused save logs the 422 and core's `PipelineStopped`, by the name its build gives the class (`pq` in 6.36).
    const stopped = await page.evaluate(() => __STATAMIC__.savePipeline.PipelineStopped.name);
    expect(browserErrors().filter((error) => !error.includes('status of 422') && error !== stopped)).toEqual([]);
});

test('Generate fills the site profile and Test connection shows a result, both with the fake engine', async ({ page, browserErrors }) => {
    await openSettings(page);

    await page.getByRole('tab', { name: 'Context' }).click();
    await page.locator('[data-tolk-site-profile-generate]').click();
    await expect(page.locator('[data-tolk-site-profile] textarea')).toHaveValue(/^\[profile\] /);
    await expect(page.locator('[data-tolk-site-profile-written]')).toBeVisible();

    await page.getByRole('tab', { name: 'Providers' }).click();
    const anthropic = page.locator('[data-tolk-provider="anthropic"]');

    // The fake engine answers whether or not the sandbox has a key.
    await expect(anthropic.locator('[data-tolk-provider-status]')).toHaveText(/^(No API key|Configured)$/);
    await anthropic.locator('[data-tolk-provider-test]').click();
    await expect(anthropic.locator('[data-tolk-provider-result="ok"]')).toContainText('The fake engine answered for');

    // Nothing is saved until the form is.
    expect(settingsFile()).toBeNull();
    expect(browserErrors()).toEqual([]);
});
