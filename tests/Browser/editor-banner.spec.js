import { expect, sessions, test } from './support/test.js';
import { cpUrl, entryFile, openEntry, please, reset } from './support/sandbox.js';

const isStatusRequest = (request) => request.url().includes('/tolk/editor/status');

// Resolves with the next status response, so a scenario can tell "not shown" from "not loaded yet".
function nextStatus(page) {
    return page.waitForResponse((response) => isStatusRequest(response.request()));
}

const banner = (page) => page.locator('[data-tolk-banner]');

test('the banner shows the counts of a Greek article', async ({ page, browserErrors }) => {
    const status = nextStatus(page);
    await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
    expect((await status).status()).toBe(200);

    const localization = banner(page).locator('[data-tolk-localization]');

    await expect(localization).toContainText('From English:');
    await expect(localization.locator('[data-tolk-part="manual"]')).toHaveText('6 fields edited by hand');
    await expect(localization.locator('[data-tolk-part="untracked"]')).toHaveText('6 untracked');
    await expect(localization.locator('[data-tolk-part]')).toHaveCount(2);
    await expect(localization.locator('[data-tolk-not-localizable]')).toHaveText(
        'Internal note is not translated because it is not localizable. Open the setup audit',
    );
    await expect(localization.locator('[data-tolk-audit-link]')).toHaveAttribute('href', new RegExp(`${cpUrl('tolk/audit')}#tolk-audit-localizable$`));
    await expect(banner(page).locator('[data-tolk-setup]')).toHaveCount(0);

    await localization.locator('[data-tolk-part="untracked"]').focus();
    await expect(page.getByText('Edited by hand before their translation was tracked')).toBeVisible();

    expect(browserErrors()).toEqual([]);
});

test('the banner stays away from a single-site collection', async ({ page, browserErrors }) => {
    const status = nextStatus(page);
    await openEntry(page, 'notes', 'front-desk-handover');
    const body = await (await status).json();

    expect(body).toMatchObject({ translatable: false, reason: 'single_site' });
    await expect(banner(page)).toHaveCount(0);
    expect(browserErrors()).toEqual([]);
});

test.describe('without the permission to translate', () => {
    test.use({ storageState: sessions.editor });

    test('the banner is not shown and the status is not asked for', async ({ page, browserErrors }) => {
        const requests = [];
        page.on('request', (request) => isStatusRequest(request) && requests.push(request.url()));

        const entry = await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
        await expect(page.locator('input#field_title')).toHaveValue(entry.title);
        await page.waitForLoadState('networkidle');

        await expect(banner(page)).toHaveCount(0);
        expect(requests).toEqual([]);
        expect(browserErrors()).toEqual([]);
    });
});

test('switching site in place updates the banner', async ({ page, browserErrors }) => {
    const origin = entryFile('articles', 'en', 'what-to-eat-in-chania');
    let status = nextStatus(page);
    await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
    await status;
    await expect(banner(page).locator('[data-tolk-localization]')).toContainText('From English:');

    status = nextStatus(page);
    // The Sites panel names a button after its site and its badges ("English Origin", "Greek Active").
    await page.getByRole('button', { name: /^English Origin/ }).click();
    await status;

    await expect(page.locator('input#field_title')).toHaveValue(origin.title);
    await expect(banner(page).locator('[data-tolk-localization]')).toHaveCount(0);

    const greek = banner(page).locator('[data-tolk-origin] [data-tolk-site="el"]');
    const german = banner(page).locator('[data-tolk-origin] [data-tolk-site="de"]');

    await expect(greek).toContainText('Greek');
    await expect(greek).toContainText('6 fields edited by hand, 6 untracked');
    await expect(german).toContainText('German');
    await expect(german).toContainText('Missing');

    status = nextStatus(page);
    await page.getByRole('button', { name: 'Greek', exact: true }).click();
    await status;

    await expect(banner(page).locator('[data-tolk-origin]')).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-localization] [data-tolk-part="manual"]')).toHaveText('6 fields edited by hand');
    expect(browserErrors()).toEqual([]);
});

test('the banner keeps its status when Live Preview re-mounts it', async ({ page, browserErrors }) => {
    const requests = [];
    page.on('request', (request) => isStatusRequest(request) && requests.push(request.url()));

    await openEntry(page, 'articles', 'what-to-eat-in-chania', 'el');
    await expect(banner(page).locator('[data-tolk-part="manual"]')).toBeVisible();

    await page.getByRole('button', { name: 'Live Preview' }).click();
    await expect(page.locator('iframe').first()).toBeVisible();
    await expect(banner(page).locator('[data-tolk-part="manual"]')).toBeVisible();

    expect(requests).toHaveLength(1);
    expect(browserErrors()).toEqual([]);
});

test('without a provider the banner points to the settings', async ({ page, browserErrors }) => {
    // The sandbox runs with the fake engine on, so the status is changed on its way to the browser.
    await page.route('**/tolk/editor/status*', async (route) => {
        const response = await route.fetch();
        const json = { ...(await response.json()), defaults: { translator: null, providers_configured: false, fake_engine: false } };

        await route.fulfill({ response, json });
    });

    await openEntry(page, 'pages', 'home', 'de');
    const setup = banner(page).locator('[data-tolk-setup]');

    await expect(setup).toContainText('Translation is not set up yet.');
    await expect(setup.getByRole('link', { name: 'Open the Tolk settings' })).toHaveAttribute('href', new RegExp(`${cpUrl('addons/statamic-tolk/settings')}$`));
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('4 fields untranslated');
    expect(browserErrors()).toEqual([]);
});

test.describe('a translated field that holds the origin\'s text again', () => {
    const ARTICLE = 'our-new-spa';
    const copies = (page) => banner(page).locator('[data-tolk-copies]');
    const part = (page, key) => banner(page).locator(`[data-tolk-localization] [data-tolk-part="${key}"]`);

    test.beforeEach(() => {
        reset();
    });

    test.afterAll(() => {
        reset();
    });

    // A form opened before a run translated the entry and saved after it puts the origin's title back.
    async function saveOriginTitle(page) {
        const origin = entryFile('articles', 'en', ARTICLE);
        please(`tolk:translate --entry=${origin.id} --site=el --concurrency=1 --yes`);

        await openEntry(page, 'articles', ARTICLE, 'el');
        await expect(part(page, 'current')).toBeVisible();
        await expect(copies(page)).toHaveCount(0);

        await page.locator('#field_title').fill(origin.title);
        const confirmed = page.waitForResponse((response) => response.url().includes('/tolk/editor/confirm'));
        await page.keyboard.press('ControlOrMeta+s');
        expect((await confirmed).status()).toBe(200);

        return origin;
    }

    test('needs attention, and Translate it fills only that field into the form', async ({ page, browserErrors }) => {
        const origin = await saveOriginTitle(page);

        await expect(part(page, 'attention')).toHaveText('1 needs attention');
        await expect(copies(page)).toContainText('Title holds the text of the origin again. Translate it, or mark it as up to date to keep it.');
        await expect(banner(page).locator('[data-tolk-action="update"]')).toHaveCount(0);

        const excerpt = await page.locator('#field_excerpt').inputValue();
        await copies(page).locator('[data-tolk-action="translate-copies"]').click();

        await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^1 field translated with .+\.$/);
        await expect(page.locator('#field_title')).toHaveValue(`[el] ${origin.title}`);
        await expect(page.locator('#field_excerpt')).toHaveValue(excerpt);
        await expect(banner(page).locator('[data-tolk-action="undo"]')).toBeVisible();
        expect(entryFile('articles', 'el', ARTICLE).title).toBe(origin.title);

        const confirmed = page.waitForResponse((response) => response.url().includes('/tolk/editor/confirm'));
        await page.keyboard.press('ControlOrMeta+s');
        expect((await confirmed).status()).toBe(200);

        await expect(part(page, 'attention')).toHaveCount(0);
        await expect(copies(page)).toHaveCount(0);
        expect(entryFile('articles', 'el', ARTICLE).title).toBe(`[el] ${origin.title}`);
        expect(browserErrors()).toEqual([]);
    });

    test('Mark manual fields as up to date keeps the text and clears the flag', async ({ page, browserErrors }) => {
        const origin = await saveOriginTitle(page);
        await expect(copies(page)).toBeVisible();

        await banner(page).locator('[data-tolk-action="options"]').click();
        await page.locator('[data-tolk-options] [data-tolk-action="mark-current"]').click();

        await expect(page.locator('[data-tolk-options]')).toHaveCount(0);
        await expect(copies(page)).toHaveCount(0);
        await expect(part(page, 'attention')).toHaveCount(0);
        await expect(part(page, 'manual')).toBeVisible();
        await expect(page.locator('#field_title')).toHaveValue(origin.title);
        expect(entryFile('articles', 'el', ARTICLE).title).toBe(origin.title);
        expect(browserErrors()).toEqual([]);
    });
});
