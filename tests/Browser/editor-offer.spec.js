import { expect, test } from './support/test.js';
import { entryFile, openEntry, reset } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const offer = (page) => page.locator('[data-tolk-offer]');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');

const fields = ['title', 'intro', 'seo_title', 'seo_description'];

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was for the next spec; "Don't offer again" is stored in the admin's user file.
test.afterAll(() => {
    reset();
});

// Opens the English page and creates its German localization through core's "Create Localization" origin picker.
async function createGerman(page, slug) {
    const origin = await openEntry(page, 'pages', slug, 'en');

    await page.getByRole('button', { name: 'German', exact: true }).click();

    const picker = page.getByRole('dialog').filter({ hasText: 'Create Localization' });
    await expect(picker).toBeVisible();
    await expect(offer(page)).toHaveCount(0);
    await picker.getByRole('button', { name: 'Create' }).click();

    return origin;
}

async function formValues(page) {
    return Object.fromEntries(await Promise.all([...fields, 'slug'].map(async (handle) => [handle, await page.locator(`#field_${handle}`).inputValue()])));
}

// Every value the slug input shows from now on, so a scenario can tell that it never followed the translated title,
// not even until the addon put it back.
async function recordSlugs(page) {
    await page.evaluate(() => {
        window.tolkSlugs = new Set();
        setInterval(() => window.tolkSlugs.add(document.querySelector('#field_slug')?.value), 20);
    });

    return async () => (await page.evaluate(() => [...window.tolkSlugs])).filter((value) => value !== '');
}

async function quickSave(page) {
    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    expect((await confirmed).status()).toBe(200);
}

// `about` gets `about-us` from core's slug reset, `dining` gets its own slug back.
for (const slug of ['about', 'dining']) {
    test(`the offer translates a new localization of ${slug} and keeps the origin slug`, async ({ page, browserErrors }) => {
        const origin = await createGerman(page, slug);

        await expect(offer(page)).toBeVisible();
        await expect(offer(page).getByText('Translate this entry?')).toBeVisible();
        await expect(offer(page).locator('[data-tolk-offer-text]')).toHaveText(
            'Translate 4 fields from English into German. Nothing is saved until you press Save.',
        );
        await expect(offer(page).locator('[data-tolk-offer-model]')).toHaveText(/^Model: \S+$/);

        const slugs = await recordSlugs(page);
        await offer(page).locator('[data-tolk-offer-action="translate"]').click();

        await expect(offer(page)).toHaveCount(0);
        await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^4 fields translated with \S+\.$/);

        for (const handle of fields) {
            await expect(page.locator(`#field_${handle}`)).toHaveValue(`[de] ${origin[handle]}`);
        }

        await expect(page.locator('#field_slug')).toHaveValue(slug);

        await quickSave(page);

        // A localization is stored as `{slug}.md`: the file is found under the origin's slug although the title changed.
        const stored = entryFile('pages', 'de', slug);

        expect(stored).toMatchObject(Object.fromEntries(fields.map((handle) => [handle, `[de] ${origin[handle]}`])));
        expect(stored.origin).toBe(origin.id);
        await expect(page.locator('#field_slug')).toHaveValue(slug);
        // Core's reset may still show its own slug for a moment; the translated title's slug never appears.
        expect((await slugs()).filter((value) => value !== slug && value !== 'about-us')).toEqual([]);
        expect(browserErrors()).toEqual([]);
    });
}

test('Not now leaves the form untouched', async ({ page, browserErrors }) => {
    const plans = [];
    page.on('request', (request) => request.url().includes('/tolk/editor/plan') && plans.push(request.url()));

    const origin = await createGerman(page, 'about');

    await expect(offer(page)).toBeVisible();
    // Core's own slug reset, which the addon leaves alone when it does not translate.
    await expect(page.locator('#field_slug')).toHaveValue('about-us');
    const before = await formValues(page);

    expect(before.title).toBe(origin.title);

    await offer(page).locator('[data-tolk-offer-action="decline"]').click();

    await expect(offer(page)).toHaveCount(0);
    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('4 fields untranslated');
    await expect(banner(page).locator('[data-tolk-action="translate"]')).toBeVisible();
    await page.waitForTimeout(1_500);

    expect(await formValues(page)).toEqual(before);
    expect(plans).toEqual([]);
    expect(browserErrors()).toEqual([]);
});

test("with Don't offer again the offer no longer appears, and the banner's Translate still keeps the slug", async ({ page, browserErrors }) => {
    await createGerman(page, 'dining');
    await expect(offer(page)).toBeVisible();

    // The label covers the box, as it does for a user.
    await offer(page).getByText("Don't offer again").click();
    await expect(offer(page).getByRole('checkbox', { name: "Don't offer again" })).toBeChecked();
    const stored = page.waitForResponse((response) => response.url().includes('/preferences/js') && response.request().method() === 'POST');
    await offer(page).locator('[data-tolk-offer-action="decline"]').click();
    expect((await stored).status()).toBe(200);
    await expect(offer(page)).toHaveCount(0);

    // Leave the form clean, so the next page loads without core's unsaved-changes prompt.
    await quickSave(page);

    const origin = await createGerman(page, 'about');

    await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('4 fields untranslated');
    await page.waitForTimeout(1_000);
    await expect(offer(page)).toHaveCount(0);

    const slugs = await recordSlugs(page);
    await banner(page).locator('[data-tolk-action="translate"]').click();
    await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^4 fields translated with \S+\.$/);
    await expect(page.locator('#field_title')).toHaveValue(`[de] ${origin.title}`);
    await expect(page.locator('#field_slug')).toHaveValue('about');

    await quickSave(page);

    expect(entryFile('pages', 'de', 'about')).toMatchObject({ title: `[de] ${origin.title}`, origin: origin.id });
    expect((await slugs()).filter((value) => value !== 'about' && value !== 'about-us')).toEqual([]);
    expect(browserErrors()).toEqual([]);
});
