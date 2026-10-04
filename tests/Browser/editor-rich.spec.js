import { expect, test } from './support/test.js';
import { entryFile, entryState, openEntry, reset, tinker } from './support/sandbox.js';

const banner = (page) => page.locator('[data-tolk-banner]');
const isConfirm = (response) => response.url().includes('/tolk/editor/confirm');

// A dated article with a Bard text set (bold, a link), a specs set holding a table, a list, a group and a keyed array.
const ARTICLE = 'family-days-out-in-western-crete';
const ARTICLE_ID = '03863bc8-db48-4483-8169-cd7cb518d369';
const ARTICLE_FIELDS = ['title', 'excerpt', 'body', 'features', 'meta', 'facts'];

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was: other specs read the German home page's counts without resetting.
test.afterAll(() => {
    reset();
});

// Quick save stays on the form; resolves with the body of the confirm request that follows every save.
async function quickSave(page) {
    const confirmed = page.waitForResponse(isConfirm);
    await page.keyboard.press('ControlOrMeta+s');
    const response = await confirmed;

    expect(response.status()).toBe(200);

    return response.json();
}

// A value without the fake engine's `[el] ` and `[de] ` and without the `null` attributes the CP's Bard component adds
// when it re-emits a value, so a translation compares equal to its source's structure.
function skeleton(value) {
    if (typeof value === 'string') {
        return value.replace(/\[(el|de)\] /g, '');
    }

    if (Array.isArray(value)) {
        return value.map(skeleton);
    }

    if (value === null || typeof value !== 'object') {
        return value;
    }

    const entries = Object.entries(value)
        .filter(([, item]) => item !== null)
        .map(([key, item]) => [key, skeleton(item)])
        .filter(([key, item]) => key !== 'attrs' || Object.keys(item).length > 0);

    return Object.fromEntries(entries);
}

const pick = (data, handles) => Object.fromEntries(handles.map((handle) => [handle, data[handle]]));

// Every node of a Bard value, depth first.
const nodes = (value) => (Array.isArray(value) ? value.flatMap((node) => [node, ...nodes(node.content ?? [])]) : []);

// The texts of a Bard value's text nodes, in order.
const texts = (value) => nodes(value).filter((node) => node.type === 'text').map((node) => node.text);

test.describe('an article with rich content', () => {
    test.beforeEach(async ({ page }) => {
        // Saved once in the CP, as an origin edited there is: its Replicator and table rows get ids of their own, so
        // a translation built from it keeps the same ids every time.
        await openEntry(page, 'articles', ARTICLE, 'en');
        await quickSave(page);
        expect(entryFile('articles', 'en', ARTICLE).body.every((row) => typeof row.id === 'string')).toBe(true);

        tinker(`\\Statamic\\Facades\\Entry::find('${ARTICLE_ID}')->makeLocalization('el')->save();`);
    });

    test('Translate fills the Bard body, the sets, the table and the list; the stored structure is the origin\'s, and an update changes only the edited paragraph', async ({ page, browserErrors }) => {
        const origin = entryFile('articles', 'en', ARTICLE);
        const localization = entryFile('articles', 'el', ARTICLE);

        await openEntry(page, 'articles', ARTICLE, 'el');
        await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('6 fields untranslated');
        await banner(page).locator('[data-tolk-action="translate"]').click();

        await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^6 fields translated with \S+\.$/);
        await expect(banner(page).locator('[data-tolk-summary-edited]')).toHaveCount(0);

        // Formatting and the link are kept in the Bard editor; the Replicator set's fields and the group are filled.
        const bard = page.locator('#field_body_0_body .ProseMirror');
        await expect(bard.locator('p').first()).toContainText('[el] Children love the goat farm near Vatolakkos');
        await expect(bard.locator('strong')).toHaveText('goat farm');
        await expect(bard.locator('a')).toHaveText('olive oil tasting');
        await expect(page.locator('#field_body_0_heading')).toHaveValue('[el] Ideas for every age');
        await expect(page.locator('#field_meta_subtitle')).toHaveValue(`[el] ${origin.meta.subtitle}`);

        await quickSave(page);

        const saved = entryFile('articles', 'el', ARTICLE);

        expect(saved.origin).toBe(origin.id);
        expect(skeleton(pick(saved, ARTICLE_FIELDS))).toEqual(skeleton(pick(origin, ARTICLE_FIELDS)));
        expect(saved.body[1].rows[1].cells).toEqual(['[el] Goat farm', '3+', '[el] 25 km']);
        expect(saved.features).toEqual(origin.features.map((feature) => `[el] ${feature}`));
        expect(Object.keys(entryState(localization.id).fields).sort()).toEqual([...ARTICLE_FIELDS].sort());

        // Opening it again and saving without an edit keeps every field current (D27).
        await openEntry(page, 'articles', ARTICLE, 'el');
        await expect(banner(page).locator('[data-tolk-part="current"]')).toHaveText('6 fields up to date');

        const resaved = await quickSave(page);

        expect(resaved.localization.counts).toEqual({ untranslated: 0, current: 6, outdated: 0, manual: 0, attention: 0, untracked: 0 });
        expect(pick(entryFile('articles', 'el', ARTICLE), ARTICLE_FIELDS)).toEqual(pick(saved, ARTICLE_FIELDS));

        // An edit of one English paragraph: Update in Greek changes that paragraph and nothing else in the file.
        await openEntry(page, 'articles', ARTICLE, 'en');
        const paragraph = page.locator('#field_body_0_body .ProseMirror p').nth(1);
        const box = await paragraph.boundingBox();
        await paragraph.click({ position: { x: box.width - 2, y: box.height - 4 } });
        await page.keyboard.press('End');
        await page.keyboard.type(' Bring an umbrella.');
        await quickSave(page);

        const edited = entryFile('articles', 'en', ARTICLE);

        expect(texts(edited.body[0].body).at(-1)).toBe(' here at the hotel. Bring an umbrella.');

        await openEntry(page, 'articles', ARTICLE, 'el');
        await expect(banner(page).locator('[data-tolk-part="outdated"]')).toHaveText('1 field outdated');
        await banner(page).locator('[data-tolk-action="update"]').click();
        await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^1 field translated with \S+\.$/);

        const updated = await quickSave(page);
        const after = entryFile('articles', 'el', ARTICLE);
        const expected = structuredClone(pick(saved, ARTICLE_FIELDS));
        expected.body[0].body[1] = after.body[0].body[1];

        expect(updated.localization.counts).toMatchObject({ untranslated: 0, current: 6, outdated: 0, manual: 0 });
        expect(pick(after, ARTICLE_FIELDS)).toEqual(expected);
        expect(after.body[0].body[1]).not.toEqual(saved.body[0].body[1]);
        expect(skeleton(after.body[0].body[1])).toEqual(skeleton(edited.body[0].body[1]));
        expect(texts(after.body[0].body).at(-1)).toBe(' here at the hotel. Bring an umbrella.');
        expect(browserErrors()).toEqual([]);
    });
});

test.describe('the German home page, whose Bard content is inherited', () => {
    // Core's Bard field desyncs itself and re-emits its value while this form loads (`07-spike-findings.md`), about
    // when the banner mounts and before the banner takes the clean values again 600 ms later.
    test('Translate clicked at once fills the Bard content too, with its formatting, link and set', async ({ page, browserErrors }) => {
        const origin = entryFile('pages', 'en', 'home');
        const before = entryFile('pages', 'de', 'home');

        await openEntry(page, 'pages', 'home', 'de');
        await expect(banner(page).locator('[data-tolk-action="translate"]')).toBeVisible();
        await expect.poll(() => page.evaluate(() => Statamic.$dirty.has('base')), { intervals: [10] }).toBe(true);
        await banner(page).locator('[data-tolk-action="translate"]').click();

        await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^4 fields translated with \S+\.$/);
        await expect(banner(page).locator('[data-tolk-summary-edited]')).toHaveCount(0);
        await expect(page.locator('#field_content .ProseMirror h2')).toHaveText('[de] Welcome to Kyma Bay');

        await quickSave(page);

        const saved = entryFile('pages', 'de', 'home');
        const of = (value, type) => nodes(value).filter((node) => node.type === type);
        const links = (value) => nodes(value).flatMap((node) => node.marks ?? []).filter((mark) => mark.type === 'link');

        expect(saved.title).toBe(before.title);
        expect(of(saved.content, 'heading').map((node) => texts([node])[0])).toEqual(of(origin.content, 'heading').map((node) => `[de] ${texts([node])[0]}`));
        expect(of(saved.content, 'text').find((node) => node.marks?.[0]?.type === 'bold').text).toBe('42 rooms and suites');
        expect(links(saved.content).map((mark) => mark.attrs.href)).toEqual(links(origin.content).map((mark) => mark.attrs.href));
        expect(of(saved.content, 'image')[0].attrs).toMatchObject({ src: of(origin.content, 'image')[0].attrs.src, alt: `[de] ${of(origin.content, 'image')[0].attrs.alt}` });
        expect(of(saved.content, 'set').map((node) => node.attrs.values)).toEqual(
            of(origin.content, 'set').map(({ attrs }) => ({ ...attrs.values, text: `[de] ${attrs.values.text}`, author: `[de] ${attrs.values.author}` })),
        );
        expect(entryState(before.id).fields.content.target_hash).toMatch(/^[0-9a-f]{16}$/);
        await expect(banner(page).locator('[data-tolk-part="current"]')).toHaveText('4 fields up to date');
        expect(browserErrors()).toEqual([]);
    });

    test('the copy of the origin\'s Bard value that a save stores reads untranslated, and Translate replaces it', async ({ page, browserErrors }) => {
        const origin = entryFile('pages', 'en', 'home');

        await openEntry(page, 'pages', 'home', 'de');
        await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('4 fields untranslated');
        await expect.poll(() => page.evaluate(() => Statamic.$dirty.has('base'))).toBe(true);

        const status = await quickSave(page);
        const copy = entryFile('pages', 'de', 'home').content;

        // The CP stored the English Bard value as the German page's own, without a single text changed.
        expect(texts(copy)).toEqual(texts(origin.content));
        expect(copy).not.toEqual(origin.content);
        expect(status.localization.fields.content.status).toBe('untranslated');
        expect(status.localization.counts).toMatchObject({ untranslated: 4, manual: 1, untracked: 1 });
        await expect(banner(page).locator('[data-tolk-part="untranslated"]')).toHaveText('4 fields untranslated');

        await banner(page).locator('[data-tolk-action="translate"]').click();
        await expect(banner(page).locator('[data-tolk-summary-translated]')).toHaveText(/^4 fields translated with \S+\.$/);
        await expect(page.locator('#field_content .ProseMirror h2')).toHaveText('[de] Welcome to Kyma Bay');

        const translated = await quickSave(page);

        expect(texts(entryFile('pages', 'de', 'home').content)[0]).toBe('[de] Welcome to Kyma Bay');
        expect(translated.localization.counts).toMatchObject({ untranslated: 0, current: 4 });
        expect(browserErrors()).toEqual([]);
    });
});
