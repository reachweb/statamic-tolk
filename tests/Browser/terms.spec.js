import { expect, test } from './support/test.js';
import { cpUrl, reset, termFile, termState } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const runPage = (page) => page.locator('[data-tolk-run]');

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

// Opens the publish form of a topic in a site and waits until its fields render.
async function openTopic(page, slug, site) {
    await page.goto(cpUrl(`taxonomies/topics/terms/${slug}/${site}`));
    await expect(page.locator('[id^="field_"]').filter({ visible: true }).first()).toBeVisible();
}

test('Translate fills a topic in the Greek term form, and Save stores it under the Greek site with its state', async ({ page, browserErrors }) => {
    const origin = termFile('topics', 'food-and-drink');

    expect(origin.localizations).toBeUndefined();

    await openTopic(page, 'food-and-drink', 'el');
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('2 fields untranslated');

    await banner(page).locator('[data-tolk-action="translate"]').click();

    await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^2 fields translated with \S+\.$/);
    await expect(page.locator('#field_title')).toHaveValue(`[el] ${origin.title}`);
    await expect(page.locator('#field_description')).toHaveValue(`[el] ${origin.description}`);
    expect(termFile('topics', 'food-and-drink')).toEqual(origin);
    expect(Object.keys(termState('topics', 'food-and-drink', 'el').fields).sort()).toEqual(['description', 'title']);

    // With the default "after saving" preference Save leaves for the listing; the confirm request still completes.
    const confirmed = page.waitForResponse((response) => response.url().includes('/tolk/editor/confirm'));
    await page.getByRole('button', { name: 'Save & Publish' }).click();
    const body = await (await confirmed).json();

    expect(body.not_saved).toEqual([]);
    await page.waitForURL((url) => !url.pathname.includes('food-and-drink'));

    const stored = termFile('topics', 'food-and-drink');

    expect(stored.title).toBe(origin.title);
    expect(stored.description).toBe(origin.description);
    expect(stored.localizations.el).toMatchObject({ title: `[el] ${origin.title}`, description: `[el] ${origin.description}` });
    // The CP stores the slug it posted; a term's slug is never localized (D16).
    expect(stored.localizations.el.slug ?? 'food-and-drink').toBe('food-and-drink');
    expect(stored.localizations.de).toBeUndefined();

    const state = termState('topics', 'food-and-drink', 'el');

    expect(state.reference).toBe('term::topics::food-and-drink::el');
    expect(state.source_site).toBe('en');
    Object.values(state.fields).forEach((field) => {
        expect(field.pending).toBeUndefined();
        expect(field.target_hash).toMatch(/^[0-9a-f]{16}$/);
    });

    await openTopic(page, 'food-and-drink', 'el');
    await expect(banner(page).locator('[data-tolk-part="current"]')).toHaveText('2 fields up to date');
    await expect(banner(page).locator('[data-tolk-action="translate"]')).toHaveCount(0);

    // The English term is the origin of the others.
    await openTopic(page, 'food-and-drink', 'en');
    await expect(banner(page).locator('[data-tolk-origin]')).toContainText('Translations of this term:');
    await expect(banner(page).locator('[data-tolk-origin] [data-tolk-site="el"]')).toContainText('Up to date');
    await expect(banner(page).locator('[data-tolk-origin] [data-tolk-site="de"]')).toContainText('Untranslated');
    expect(browserErrors()).toEqual([]);
});

test('the listing action on two topics creates a run of their terms, whose page starts it and translates them', async ({ page, browserErrors }) => {
    const slugs = ['events', 'wellness'];

    await page.goto(cpUrl('taxonomies/topics'));

    for (const slug of slugs) {
        await page.getByRole('checkbox', { name: `Select ${termFile('topics', slug).title}`, exact: true }).click();
    }

    await page.getByRole('button', { name: 'Translate…' }).click();

    const modal = page.getByRole('dialog');

    await expect(modal).toContainText('The next page shows an estimate before these 2 terms are translated.');
    await expect(modal.getByRole('checkbox', { name: 'English' })).not.toBeChecked();
    await expect(modal.getByRole('checkbox', { name: 'Greek' })).toBeChecked();
    await expect(modal.getByRole('checkbox', { name: 'German' })).toBeChecked();
    await expect(modal.getByText('New localizations')).toHaveCount(0);
    await modal.getByRole('button', { name: 'Translate 2 items' }).click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    expect(new URL(page.url()).pathname).toMatch(new RegExp(`^${cpUrl('tolk/runs')}/[0-9A-Z]{26}$`));
    await expect(runPage(page)).toContainText('2 selected terms into Greek, German');
    await expect(page.locator('[data-tolk-breakdown-row="term|el"] [data-tolk-breakdown-total]')).toHaveText('2');
    await expect(page.locator('[data-tolk-breakdown-row="term|de"] [data-tolk-breakdown-total]')).toHaveText('2');
    expect(slugs.map((slug) => termFile('topics', slug).localizations)).toEqual([undefined, undefined]);

    await page.locator('[data-tolk-action="start"]').click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
    await expect(page.locator('[data-tolk-run-result]')).toHaveText(/The run is complete: 4 done\./);

    for (const slug of slugs) {
        const term = termFile('topics', slug);

        for (const site of ['el', 'de']) {
            expect(term.localizations[site], `${site}/${slug}`).toMatchObject({ title: `[${site}] ${term.title}`, description: `[${site}] ${term.description}` });
        }
    }

    expect(browserErrors()).toEqual([]);
});
