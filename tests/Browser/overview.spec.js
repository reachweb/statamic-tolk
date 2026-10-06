import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from 'yaml';
import { expect, test } from './support/test.js';
import { cpUrl, reset, sandboxPath, termFile } from './support/sandbox.js';

const TOPICS = ['events', 'family-holidays', 'food-and-drink', 'local-culture', 'wellness'];

// The demo content's cells (`php please tolk:status`): every container into Greek and German.
const CELLS = [
    'entry|pages|el',
    'entry|pages|de',
    'entry|articles|el',
    'entry|articles|de',
    'term|topics|el',
    'term|topics|de',
    'globals|site_settings|el',
    'globals|site_settings|de',
    'nav|main|el',
    'nav|main|de',
];

const cell = (page, key) => page.locator(`[data-tolk-cell="${key}"]`);
const runPage = (page) => page.locator('[data-tolk-run]');

test.beforeEach(() => {
    reset();
});

test.afterAll(() => {
    reset();
});

function contentFile(path) {
    const file = join(sandboxPath(), 'content', path);

    return existsSync(file) ? (parse(readFileSync(file, 'utf8')) ?? {}) : null;
}

async function openOverview(page) {
    await page.goto(cpUrl('tolk'));
    await expect(page.locator('[data-tolk-overview]')).toBeVisible();
}

async function waitForCells(page) {
    await expect(page.locator('[data-tolk-cell-status="done"]')).toHaveCount(CELLS.length, { timeout: 30_000 });
}

// Clicks a cell's button, which creates a pending run and opens its page, then starts the run and waits for its end.
async function translateFromCell(page, key, action = 'missing') {
    await cell(page, key).locator(`[data-tolk-cell-action="${action}"]`).click();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    await page.locator('[data-tolk-action="start"]').click();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
}

test('the matrix fills in with the counts of every container and site', async ({ page, browserErrors }) => {
    await openOverview(page);

    await expect(page.locator('[data-tolk-cell]')).toHaveCount(CELLS.length);
    await waitForCells(page);
    expect(await page.locator('[data-tolk-cell]').evaluateAll((cells) => cells.map((cell) => cell.dataset.tolkCell))).toEqual(CELLS);

    await expect(page.locator('[data-tolk-group]')).toHaveCount(4);
    await expect(page.locator('[data-tolk-column]')).toHaveText([/Greek/, /German/]);
    await expect(cell(page, 'entry|pages|el').locator('[data-tolk-count]')).toHaveText(['Missing 6']);
    await expect(cell(page, 'entry|pages|de').locator('[data-tolk-count]')).toHaveText(['Missing 5', 'Untranslated 1']);
    await expect(cell(page, 'entry|articles|el').locator('[data-tolk-count]')).toHaveText(['Missing 11', 'Current 2']);
    await expect(cell(page, 'entry|articles|el').locator('[data-tolk-untracked]')).toHaveText('12 untracked fields');
    await expect(cell(page, 'term|topics|el').locator('[data-tolk-count]')).toHaveText(['Untranslated 5']);
    await expect(cell(page, 'globals|site_settings|de').locator('[data-tolk-count]')).toHaveText(['Untranslated 1']);
    await expect(cell(page, 'nav|main|el').locator('[data-tolk-count]')).toHaveText(['Missing 1']);
    await expect(page.locator('[data-tolk-baseline-hint]')).toContainText('php please tolk:baseline');

    // Nothing is outdated in the demo content, so no cell offers an update.
    await expect(page.locator('[data-tolk-cell-action="missing"]')).toHaveCount(CELLS.length);
    await expect(page.locator('[data-tolk-cell-action="update"]')).toHaveCount(0);
    await expect(page.locator('[data-tolk-site-action]')).toHaveCount(2);
    await expect(page.locator('[data-tolk-providers] [data-tolk-provider]')).toHaveCount(3);
    await expect(page.locator('[data-tolk-fake-engine]')).toBeVisible();
    await expect(page.locator('[data-tolk-latest-runs]')).toContainText('No translation runs yet.');

    // A second visit is answered from the cache, and Count again counts afresh.
    await openOverview(page);
    await waitForCells(page);
    await page.locator('[data-tolk-count-again]').click();
    await waitForCells(page);
    await expect(cell(page, 'entry|pages|el').locator('[data-tolk-count]')).toHaveText(['Missing 6']);
    expect(browserErrors()).toEqual([]);
});

test('Translate missing for topics into Greek leads to a run that completes', async ({ page, browserErrors }) => {
    await openOverview(page);
    await expect(cell(page, 'term|topics|el')).toHaveAttribute('data-tolk-cell-status', 'done');

    await translateFromCell(page, 'term|topics|el');
    await expect(page.locator('[data-tolk-run-result]')).toHaveText(new RegExp(`The run is complete: ${TOPICS.length} done\\.`));

    for (const slug of TOPICS) {
        const term = termFile('topics', slug);

        expect(term.localizations?.el?.title, slug).toBe(`[el] ${term.title}`);
        expect(term.localizations?.de, slug).toBeUndefined();
    }

    // Saving the terms counted the taxonomy again, so the overview shows them current; German is untouched.
    await openOverview(page);
    await expect(cell(page, 'term|topics|el')).toHaveAttribute('data-tolk-cell-status', 'done');
    await expect(cell(page, 'term|topics|el').locator('[data-tolk-count]')).toHaveText(['Current 5']);
    await expect(cell(page, 'term|topics|el').locator('[data-tolk-cell-action]')).toHaveCount(0);
    await expect(cell(page, 'term|topics|de').locator('[data-tolk-count]')).toHaveText(['Untranslated 5']);
    await expect(page.locator('[data-tolk-latest-runs] [data-tolk-run-row]')).toHaveCount(1);
    // The fake engine records its calls, without tokens, as the run's.
    await expect(page.locator('[data-tolk-usage-model]').filter({ hasText: 'Runs' }).first()).toBeVisible();
    expect(browserErrors()).toEqual([]);
});

test('the global set and the navigation are translated from the overview', async ({ page, browserErrors }) => {
    test.setTimeout(90_000);

    expect(contentFile('globals/el/site_settings.yaml')).toEqual({});
    expect(contentFile('trees/navigation/el/main.yaml')).toBeNull();

    await openOverview(page);
    await translateFromCell(page, 'globals|site_settings|el');

    const english = contentFile('globals/en/site_settings.yaml');
    const greek = contentFile('globals/el/site_settings.yaml');

    expect(greek.tagline).toBe(`[el] ${english.tagline}`);
    expect(greek.footer_text[0].content.map((node) => node.text).join('')).toBe(`[el] ${english.footer_text[0].content.map((node) => node.text).join('')}`);
    expect(greek.footer_text[0].content.find((node) => node.text === 'Kyma Bay Hotel')?.marks).toEqual([{ type: 'bold' }]);

    await openOverview(page);
    await translateFromCell(page, 'nav|main|el');

    const tree = contentFile('trees/navigation/el/main.yaml').tree;
    const source = contentFile('trees/navigation/en/main.yaml').tree;

    // No page is translated into Greek, so only the link of its own is kept, with its id and a translated title.
    expect(tree).toEqual([{ id: source.at(-1).id, url: source.at(-1).url, title: `[el] ${source.at(-1).title}` }]);

    await openOverview(page);
    await expect(cell(page, 'globals|site_settings|el')).toHaveAttribute('data-tolk-cell-status', 'done');
    await expect(cell(page, 'globals|site_settings|el').locator('[data-tolk-count]')).toHaveText(['Current 1']);
    await expect(cell(page, 'nav|main|el').locator('[data-tolk-count]')).toHaveText(['Current 1']);
    expect(browserErrors()).toEqual([]);
});

test('Translate everything missing creates a run of every container with work in that site', async ({ page, browserErrors }) => {
    await openOverview(page);
    await waitForCells(page);

    await page.locator('[data-tolk-site-action="de"]').click();

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    await expect(page.locator('[data-tolk-breakdown-row="entry|de"] [data-tolk-breakdown-total]')).toHaveText('19');
    await expect(page.locator('[data-tolk-breakdown-row="term|de"] [data-tolk-breakdown-total]')).toHaveText('5');
    await expect(page.locator('[data-tolk-breakdown-row="globals|de"] [data-tolk-breakdown-total]')).toHaveText('1');
    await expect(page.locator('[data-tolk-breakdown-row="nav|de"] [data-tolk-breakdown-total]')).toHaveText('1');
    await expect(page.locator('[data-tolk-option="mode"]')).toHaveText('Only what is not translated yet');
    expect(browserErrors()).toEqual([]);
});
