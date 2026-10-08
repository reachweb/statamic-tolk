import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from 'yaml';
import { expect, sessions, test } from './support/test.js';
import { cpUrl, openEntry, reset, sandboxPath } from './support/sandbox.js';

const ARTICLE = 'collections.articles|article';

const check = (page, id) => page.locator(`[data-tolk-check="${id}"]`);
const article = (page) => check(page, 'localizable').locator(`[data-tolk-blueprint="${ARTICLE}"]`);

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

// A field's config in the sandbox's article blueprint file.
function articleField(handle) {
    const blueprint = parse(readFileSync(join(sandboxPath(), 'resources/blueprints/collections/articles/article.yaml'), 'utf8'));

    return blueprint.tabs
        ? Object.values(blueprint.tabs)
              .flatMap((tab) => tab.sections ?? [])
              .flatMap((section) => section.fields ?? [])
              .find((field) => field.handle === handle)?.field
        : null;
}

async function openAudit(page) {
    await page.goto(cpUrl('tolk/audit'));
    await expect(page.locator('[data-tolk-audit]')).toBeVisible();
}

test('the audit lists the setup checks, and internal_note can be made localizable from the page', async ({ page, browserErrors }) => {
    await openAudit(page);

    await expect(page.locator('[data-tolk-check]')).toHaveCount(9);
    await expect(check(page, 'sites')).toHaveAttribute('data-tolk-check-level', 'ok');
    await expect(check(page, 'localizable')).toHaveAttribute('data-tolk-check-level', 'warning');
    await expect(check(page, 'technical_fields')).toContainText('body.text.anchor');
    await expect(page.locator('[data-tolk-audit-summary]')).toHaveAttribute('data-tolk-audit-level', 'warning');

    const field = article(page).locator('[data-tolk-field="internal_note"]');
    const button = article(page).locator('[data-tolk-make-localizable]');

    await expect(field).toContainText('Internal note (internal_note)');
    await expect(article(page).locator('[data-tolk-blueprint-link]')).toHaveAttribute('href', new RegExp(`${cpUrl('fields/blueprints/collections/articles/article/edit')}$`));
    await expect(button).toBeDisabled();
    expect(articleField('internal_note')).toMatchObject({ localizable: false });

    // Cancel changes nothing.
    // The checkbox's label covers it.
    await field.getByText('Internal note (internal_note)').click();
    await expect(field.getByRole('checkbox')).toBeChecked();
    await button.click();
    await expect(page.getByRole('dialog')).toContainText(
        'This changes the blueprint file of Collection Articles › Article: Internal note (internal_note) becomes localizable',
    );
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(articleField('internal_note')).toMatchObject({ localizable: false });

    // Wait for the closing dialog: it swallows a click in its first 300 ms.
    await page.waitForTimeout(400);
    await button.click();
    await page.getByRole('dialog').getByRole('button', { name: 'Make localizable' }).click();

    await expect(page.getByText('1 field is now localizable.')).toBeVisible();
    await expect(article(page)).toHaveCount(0);
    await expect(check(page, 'localizable').locator('[data-tolk-blueprint]')).toHaveCount(2);
    expect(articleField('internal_note')).toMatchObject({ type: 'text', display: 'Internal note', localizable: true });

    // Check again reads the blueprint as it is now.
    reset();
    await page.locator('[data-tolk-audit-reload]').click();
    await expect(article(page).locator('[data-tolk-field="internal_note"]')).toBeVisible();
    await expect(article(page).locator('[data-tolk-field="internal_note"]').getByRole('checkbox')).not.toBeChecked();
    await page.getByText('Internal note (internal_note)').click();
    await article(page).locator('[data-tolk-make-localizable]').click();
    await page.getByRole('dialog').getByRole('button', { name: 'Make localizable' }).click();
    await expect(article(page)).toHaveCount(0);

    // The banner of a Greek article no longer names it.
    await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
    await expect(page.locator('[data-tolk-banner] [data-tolk-localization]')).toContainText('From English:');
    await expect(page.locator('[data-tolk-banner] [data-tolk-not-localizable]')).toHaveCount(0);

    expect(browserErrors()).toEqual([]);
});

test('the banner\'s hint and the Translations nav item lead to the audit', async ({ page, browserErrors }) => {
    await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
    await page.locator('[data-tolk-banner] [data-tolk-audit-link]').click();

    await expect(page.locator('[data-tolk-audit]')).toBeVisible();
    await expect(check(page, 'localizable')).toBeInViewport();
    await expect(article(page).locator('[data-tolk-field="internal_note"]')).toBeVisible();

    await page.goto(cpUrl('tolk/overview'));
    await page.getByRole('link', { name: 'Audit', exact: true }).click();
    await expect(page.locator('[data-tolk-audit]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(cpUrl('tolk/audit'));

    expect(browserErrors()).toEqual([]);
});

test.describe('without the permission', () => {
    test.use({ storageState: sessions.editor });

    test('the restricted user cannot open the audit', async ({ page }) => {
        const response = await page.request.get(cpUrl('tolk/audit'), { headers: { Accept: 'application/json' } });

        expect(response.status()).toBe(403);
    });
});
