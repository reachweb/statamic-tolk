import { expect, test } from './support/test.js';
import { cpUrl, entryFile, reset } from './support/sandbox.js';

const ARTICLES = ['meet-our-head-chef', 'our-new-spa', 'olive-oil-tasting'];

const runPage = (page) => page.locator('[data-tolk-run]');

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

test('the listing action creates a run of three articles, whose page starts it and translates them', async ({ page, browserErrors }) => {
    await page.goto(cpUrl('collections/articles'));

    for (const slug of ARTICLES) {
        await page.getByRole('checkbox', { name: `Select ${entryFile('articles', 'en', slug).title}`, exact: true }).click();
    }

    await page.getByRole('button', { name: 'Translate…' }).click();

    const modal = page.getByRole('dialog');

    await expect(modal).toContainText('The next page shows an estimate before these 3 entries are translated.');
    await expect(modal.getByRole('checkbox', { name: 'English' })).toHaveCount(0);
    await expect(modal.getByRole('checkbox', { name: 'Greek' })).toBeChecked();
    await expect(modal.getByRole('checkbox', { name: 'German' })).toBeChecked();
    await modal.getByRole('button', { name: 'Translate 3 items' }).click();

    // The run page: nothing translated until Start.
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    expect(new URL(page.url()).pathname).toMatch(new RegExp(`^${cpUrl('tolk/runs')}/[0-9A-Z]{26}$`));
    await expect(page.locator('[data-tolk-breakdown-row="entry|el"] [data-tolk-breakdown-total]')).toHaveText('3');
    await expect(page.locator('[data-tolk-breakdown-row="entry|de"] [data-tolk-breakdown-total]')).toHaveText('3');
    await expect(page.locator('[data-tolk-option="mode"]')).toHaveText('Only what is not translated yet');
    await expect(page.locator('[data-tolk-option="new_status"]')).toHaveText('Drafts');
    expect(ARTICLES.map((slug) => entryFile('articles', 'de', slug))).toEqual([null, null, null]);

    await page.locator('[data-tolk-action="start"]').click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
    await expect(page.locator('[data-tolk-run-result]')).toHaveText(/The run is complete: 6 done\./);

    for (const slug of ARTICLES) {
        const origin = entryFile('articles', 'en', slug);

        for (const site of ['el', 'de']) {
            const localization = entryFile('articles', site, slug);

            expect(localization, `${site}/${slug}`).not.toBeNull();
            expect(localization.origin).toBe(origin.id);
            expect(localization.published).toBe(false);
            expect(localization.title).toBe(`[${site}] ${origin.title}`);
        }
    }

    expect(browserErrors()).toEqual([]);
});

test('the tree view offers the action too, and follows it to the run page', async ({ page, browserErrors }) => {
    await page.goto(cpUrl('collections/pages'));

    const home = page.getByRole('treeitem').filter({ has: page.getByRole('link', { name: 'Home', exact: true }) });

    await home.getByRole('button', { name: 'Open dropdown menu' }).click();
    await page.getByRole('menuitem', { name: 'Translate…' }).click();

    const modal = page.getByRole('dialog');

    // The checkbox's label covers it.
    await modal.getByText('German', { exact: true }).click();
    await expect(modal.getByRole('checkbox', { name: 'German' })).not.toBeChecked();
    await modal.getByRole('button', { name: 'Translate', exact: true }).click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    await expect(page.locator('[data-tolk-breakdown-row="entry|el"] [data-tolk-breakdown-total]')).toHaveText('1');
    await expect(page.locator('[data-tolk-breakdown-row="entry|de"]')).toHaveCount(0);
    await expect(page.locator('[data-tolk-item]')).toHaveCount(1);
    await expect(page.locator('[data-tolk-item]')).toContainText(entryFile('pages', 'en', 'home').title);
    expect(browserErrors()).toEqual([]);
});
