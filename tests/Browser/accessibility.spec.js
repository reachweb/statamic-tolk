import AxeBuilder from '@axe-core/playwright';
import { expect, test } from './support/test.js';
import { cpUrl, entryFile, openEntry, reset, tinker } from './support/sandbox.js';

// Keyboard-only passes through the offer, the banner with its modals, and a run from the Overview to its end. Opening
// an entry or creating a localization in core's own UI is setup and may use the mouse; everything of the addon's is
// reached with Tab and worked with Enter, Space and Esc. The CP puts the focus in a page's content when it loads (an
// entry's first field), and the banner sits above an entry's fields, so it is reached with Shift+Tab.

const banner = (page) => page.locator('[data-tolk-banner]');
const action = (page, name) => banner(page).locator(`[data-tolk-action="${name}"]`);
const part = (page, key) => banner(page).locator(`[data-tolk-localization] [data-tolk-part="${key}"]`);
const translated = (page) => banner(page).locator('[data-tolk-summary-translated]');
const offer = (page) => page.locator('[data-tolk-offer]');
const options = (page) => page.locator('[data-tolk-options]');
const pushModal = (page) => page.locator('[data-tolk-push-modal]');
const changes = (page) => page.locator('[data-tolk-changes]');
const runPage = (page) => page.locator('[data-tolk-run]');
const isPlan = (request) => request.url().includes('/tolk/editor/plan');

// Core's modals finish closing 300 ms after they start; one reopened inside that window used to close again (T10.3).
const STALE_CLOSE_MS = 800;

test.beforeEach(() => {
    reset();
});

// Leave the sandbox as it was for the next spec and the owner.
test.afterAll(() => {
    reset();
});

const holdsFocus = (locator) => locator.evaluate((element) => element.contains(document.activeElement)).catch(() => false);
const focusLost = (page) => page.evaluate(() => !document.activeElement || document.activeElement === document.body);

// Presses Tab (or Shift+Tab) until the target, or a control inside it, has the focus, as a keyboard user would; fails
// if it never gets there.
async function tabTo(page, target, { backwards = false, max = 250 } = {}) {
    for (let presses = 0; presses <= max; presses++) {
        if ((await target.count()) > 0 && (await holdsFocus(target.first()))) {
            return;
        }

        await page.keyboard.press(backwards ? 'Shift+Tab' : 'Tab');
    }

    throw new Error(`The keyboard focus never reached ${target}.`);
}

// After a page loads, core puts the focus on its content once the page has mounted (`Layout.vue`, 100 ms later); a
// keyboard user starts from there.
async function pageReady(page) {
    await expect(page.locator('#content-card')).toBeFocused();
}

// A modal closed with Esc gives the focus back to its trigger, and Enter opens it again at once, for good.
async function closeAndReopenWithEsc(page, trigger, modal) {
    await page.keyboard.press('Escape');
    await expect(modal).toHaveCount(0);
    await expect(trigger).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(modal).toBeVisible();
    await expect.poll(() => holdsFocus(modal)).toBe(true);
    await page.waitForTimeout(STALE_CLOSE_MS);
    await expect(modal).toBeVisible();
}

// Opens the English page and creates its German localization through core's "Create Localization" origin picker.
async function createGerman(page, slug) {
    const origin = await openEntry(page, 'pages', slug, 'en');

    await page.getByRole('button', { name: 'German', exact: true }).click();

    const picker = page.getByRole('dialog').filter({ hasText: 'Create Localization' });
    await expect(picker).toBeVisible();
    await picker.getByRole('button', { name: 'Create' }).click();

    return origin;
}

test('the offer takes the focus, keeps it inside, and Esc counts as Not now', async ({ page, browserErrors }) => {
    const plans = [];
    page.on('request', (request) => isPlan(request) && plans.push(request.url()));

    await createGerman(page, 'about');
    await expect(offer(page)).toBeVisible();

    const dontOffer = offer(page).getByRole('checkbox', { name: "Don't offer again" });
    const notNow = offer(page).locator('[data-tolk-offer-action="decline"]');
    const translate = offer(page).locator('[data-tolk-offer-action="translate"]');

    await expect(dontOffer).toBeFocused();
    await page.keyboard.press('Space');
    await expect(dontOffer).toBeChecked();
    await page.keyboard.press('Space');
    await expect(dontOffer).not.toBeChecked();

    await page.keyboard.press('Tab');
    await expect(notNow).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(translate).toBeFocused();
    // The focus stays in the offer.
    await page.keyboard.press('Tab');
    await expect(dontOffer).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(translate).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(offer(page)).toHaveCount(0);
    await expect(part(page, 'untranslated')).toHaveText('5 fields untranslated');
    await page.waitForTimeout(500);
    expect(plans).toEqual([]);
    expect(browserErrors()).toEqual([]);
});

test('Enter on the offer translates, and the focus is not lost', async ({ page, browserErrors }) => {
    const origin = await createGerman(page, 'dining');
    await expect(offer(page)).toBeVisible();

    await tabTo(page, offer(page).locator('[data-tolk-offer-action="translate"]'));
    await page.keyboard.press('Enter');

    await expect(offer(page)).toHaveCount(0);
    await expect(translated(page)).toHaveText(/^5 fields translated with \S+\.$/);
    await expect(page.locator('#field_title')).toHaveValue(`[de] ${origin.title}`);
    await page.waitForTimeout(500);
    expect(await focusLost(page)).toBe(false);
    expect(browserErrors()).toEqual([]);
});

test('the banner and its options work from the keyboard, and the options open again at once after Esc or Close', async ({ page, browserErrors }) => {
    const before = entryFile('pages', 'de', 'home');

    await openEntry(page, 'pages', 'home', 'de');
    await expect(action(page, 'options')).toBeVisible();
    // The banner takes the form's clean values again 600 ms after it loads (T5.6).
    await page.waitForTimeout(700);

    // The counts are badges with a description, reachable on their own.
    await tabTo(page, part(page, 'untranslated'), { backwards: true });
    await tabTo(page, action(page, 'translate'));
    await tabTo(page, action(page, 'options'));

    await page.keyboard.press('Enter');
    await expect(options(page)).toBeVisible();
    await expect.poll(() => holdsFocus(options(page))).toBe(true);
    await expect(options(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();

    await closeAndReopenWithEsc(page, action(page, 'options'), options(page));

    await tabTo(page, options(page).locator('[data-tolk-options-action="close"]'));
    await page.keyboard.press('Enter');
    await expect(options(page)).toHaveCount(0);
    await expect(action(page, 'options')).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(options(page)).toBeVisible();
    await page.waitForTimeout(STALE_CLOSE_MS);
    await expect(options(page)).toBeVisible();

    await expect(options(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();
    await tabTo(page, options(page).locator('[data-tolk-options-action="translate"]'));
    await page.keyboard.press('Enter');

    await expect(translated(page)).toHaveText(/^\d+ fields? translated with \S+\.$/);
    // Options left with the idle phase; the focus stays in the banner instead of falling back to the top of the page.
    await expect.poll(() => holdsFocus(banner(page))).toBe(true);
    await expect(page.locator('#field_intro')).toHaveValue(/^\[de\] /);

    await tabTo(page, action(page, 'undo'));
    await page.keyboard.press('Enter');
    await expect(page.locator('#field_intro')).toHaveValue(before.intro ?? entryFile('pages', 'en', 'home').intro);
    await expect.poll(() => focusLost(page)).toBe(false);
    expect(browserErrors()).toEqual([]);
});

test('Translate into… works from the keyboard, and its modal opens again at once after Esc', async ({ page, browserErrors }) => {
    await openEntry(page, 'articles', 'our-new-spa', 'en');
    const trigger = banner(page).locator('[data-tolk-action="translate-into"]');
    await expect(trigger).toBeVisible();

    await tabTo(page, trigger, { backwards: true });
    await page.keyboard.press('Enter');
    await expect(pushModal(page)).toBeVisible();
    await expect.poll(() => holdsFocus(pushModal(page))).toBe(true);

    await closeAndReopenWithEsc(page, trigger, pushModal(page));

    // Space unticks German; the modal keeps the focus.
    await tabTo(page, pushModal(page).getByRole('checkbox', { name: 'German' }));
    await page.keyboard.press('Space');
    await expect(pushModal(page).getByRole('checkbox', { name: 'German' })).not.toBeChecked();

    await tabTo(page, pushModal(page).locator('[data-tolk-push-action="translate"]'));
    await page.keyboard.press('Enter');

    await expect(banner(page).locator('[data-tolk-push-result]')).toHaveAttribute('data-tolk-push-status', 'completed', { timeout: 30_000 });
    await expect(banner(page).locator('[data-tolk-push-item]')).toHaveCount(1);
    await expect(banner(page).locator('[data-tolk-push-announcement]')).not.toHaveText('');
    // Translate into… left while the run went on; the focus stayed in the banner.
    await expect.poll(() => holdsFocus(banner(page))).toBe(true);
    expect(entryFile('articles', 'el', 'our-new-spa')).not.toBeNull();
    expect(entryFile('articles', 'de', 'our-new-spa')).toBeNull();
    expect(browserErrors()).toEqual([]);
});

test("the reviewer's changes open from the keyboard, and again at once after Esc", async ({ page, browserErrors }) => {
    tinker("\\Statamic\\Facades\\Entry::find('3e1bef1b-a083-4fc6-9326-825405cb50eb')->makeLocalization('el')->save();");
    await openEntry(page, 'articles', 'fake-engine-markers', 'el');
    await expect(action(page, 'options')).toBeVisible();
    await page.waitForTimeout(700);

    await tabTo(page, action(page, 'options'), { backwards: true });
    await page.keyboard.press('Enter');
    await expect(options(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();

    const reviewAfter = options(page).getByRole('checkbox', { name: 'Review after translating' });
    await tabTo(page, reviewAfter);
    await page.keyboard.press('Space');
    await expect(reviewAfter).toBeChecked();
    await tabTo(page, options(page).locator('[data-tolk-options-action="translate"]'));
    await page.keyboard.press('Enter');

    await expect(banner(page).locator('[data-tolk-summary-reviewed]')).toHaveText(/1 correction\.$/, { timeout: 30_000 });
    await tabTo(page, action(page, 'view-changes'));
    await page.keyboard.press('Enter');
    await expect(changes(page)).toBeVisible();
    await expect(changes(page).locator('[data-tolk-changes-action="close"]')).toBeFocused();
    // Removed and added words are marked up as such, not by colour alone.
    await expect(changes(page).locator('[data-tolk-change-before] del').first()).toBeVisible();
    await expect(changes(page).locator('[data-tolk-change-after] ins').first()).toBeVisible();

    await closeAndReopenWithEsc(page, action(page, 'view-changes'), changes(page));

    await page.keyboard.press('Enter');
    await expect(changes(page)).toHaveCount(0);
    await expect(action(page, 'view-changes')).toBeFocused();
    expect(browserErrors()).toEqual([]);
});

test('a run is created on the Overview, started and followed to its end from the keyboard', async ({ page, browserErrors }) => {
    await page.goto(cpUrl('tolk/overview'));
    const missing = page.locator('[data-tolk-cell="term|topics|el"] [data-tolk-cell-action="missing"]');
    await expect(missing).toBeVisible({ timeout: 30_000 });
    await pageReady(page);

    await tabTo(page, missing);
    await page.keyboard.press('Enter');

    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
    await expect(page.locator('[data-tolk-estimate]')).toHaveAttribute('data-tolk-estimate-finished', 'true');
    await pageReady(page);

    await tabTo(page, page.locator('[data-tolk-action="start"]'));
    await page.keyboard.press('Enter');

    // Start leaves with the pending run; Cancel takes the focus while the run is driven by this page.
    await expect(page.locator('[data-tolk-action="cancel"]')).toBeFocused();
    await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
    await expect(page.locator('[data-tolk-run-result]')).toHaveText(/The run is complete: 5 done\./);
    // Cancel left with the running run; the focus moved to the page's first control.
    await expect(page.locator('[data-tolk-runs-link]')).toBeFocused();

    // The items' filter is a select that opens and chooses with the keyboard.
    const filter = page.locator('[data-tolk-items-filter]');
    await tabTo(page, filter);
    await page.keyboard.press('Enter');
    const failed = page.getByRole('option', { name: 'Failed' });
    await expect(failed).toBeVisible();

    for (let presses = 0; presses < 10 && (await failed.getAttribute('data-highlighted')) === null; presses++) {
        await page.keyboard.press('ArrowDown');
    }

    await page.keyboard.press('Enter');
    await expect(failed).toHaveCount(0);
    await expect(page.locator('[data-tolk-item]')).toHaveCount(0);
    await expect(page.locator('[data-tolk-items]')).toContainText('No items.');

    await tabTo(page, page.locator('[data-tolk-runs-link]'), { backwards: true });
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-tolk-runs] [data-tolk-run-row]')).toHaveCount(1);
    expect(browserErrors()).toEqual([]);
});

// Core's Combobox (reka-ui) names the list of its options in `aria-controls` before the list is rendered.
const coreFindings = [(violation, node) => violation.id === 'aria-valid-attr-value' && node.target.join(' ').includes('[data-ui-combobox-trigger')];

// What axe finds against WCAG 2.2 AA in a part of the page (names, roles, text contrast and the like), as "rule: element",
// once the page's transitions have finished: a modal or a tab fading in reads as low contrast.
async function axe(page, selector) {
    await expect(page.locator(selector).first()).toBeVisible();
    await page.evaluate(() => {
        const running = document
            .getAnimations()
            .filter((animation) => animation.playState === 'running' && animation.effect?.getComputedTiming().endTime !== Infinity);

        return Promise.race([Promise.allSettled(running.map((animation) => animation.finished)), new Promise((resolve) => setTimeout(resolve, 2_000))]);
    });

    const { violations } = await new AxeBuilder({ page }).include(selector).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();

    return violations.flatMap((violation) =>
        violation.nodes.filter((node) => !coreFindings.some((isCore) => isCore(violation, node))).map((node) => `${violation.id}: ${node.target.join(' ')}`),
    );
}

// The contrast of the progress bar's fill with its track and with what is around it: a non-text contrast, which axe
// does not check, of at least 3:1 (WCAG 1.4.11).
function progressContrast(page) {
    return page.locator('[data-tolk-progress]').first().evaluate((track) => {
        const context = document.createElement('canvas').getContext('2d');
        const rgb = (color) => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);

            return [...context.getImageData(0, 0, 1, 1).data].slice(0, 3);
        };
        const luminance = (color) =>
            rgb(color)
                .map((channel) => channel / 255)
                .map((channel) => (channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4))
                .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
        const ratio = (first, second) => {
            const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);

            return (light + 0.05) / (dark + 0.05);
        };
        let around = track.parentElement;

        while (getComputedStyle(around).backgroundColor === 'rgba(0, 0, 0, 0)') {
            around = around.parentElement;
        }

        const fill = getComputedStyle(track.firstElementChild).backgroundColor;

        return { track: ratio(fill, getComputedStyle(track).backgroundColor), around: ratio(fill, getComputedStyle(around).backgroundColor) };
    });
}

// The CP is dark when the user's colour mode or, without one, the system's is (core's head script reads the media query).
for (const colorScheme of ['light', 'dark']) {
    test.describe(`in ${colorScheme} mode`, () => {
        test.use({ colorScheme });

        test('the banner, the offer and the modals pass axe', async ({ page, browserErrors }) => {
            await createGerman(page, 'about');
            await expect(page.locator('html')).toHaveClass(colorScheme === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/);
            await expect(offer(page)).toBeVisible();
            expect(await axe(page, '[data-tolk-offer]')).toEqual([]);
            await offer(page).locator('[data-tolk-offer-action="decline"]').click();
            await expect(offer(page)).toHaveCount(0);
            expect(await axe(page, '[data-tolk-banner]')).toEqual([]);

            await action(page, 'options').click();
            await expect(options(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();
            expect(await axe(page, '[data-tolk-options]')).toEqual([]);
            await options(page).locator('[data-tolk-options-action="close"]').click();

            tinker("\\Statamic\\Facades\\Entry::find('3e1bef1b-a083-4fc6-9326-825405cb50eb')->makeLocalization('el')->save();");
            await openEntry(page, 'articles', 'fake-engine-markers', 'el');
            await expect(action(page, 'options')).toBeVisible();
            await page.waitForTimeout(700);
            await action(page, 'options').click();
            await expect(options(page).locator('[data-tolk-options-action="translate"]')).toBeEnabled();
            await options(page).locator('label', { hasText: 'Review after translating' }).click();
            await options(page).locator('[data-tolk-options-action="translate"]').click();
            await expect(banner(page).locator('[data-tolk-summary-reviewed]')).toHaveText(/1 correction\.$/, { timeout: 30_000 });
            expect(await axe(page, '[data-tolk-banner]')).toEqual([]);
            await action(page, 'view-changes').click();
            await expect(changes(page)).toBeVisible();
            expect(await axe(page, '[data-tolk-changes]')).toEqual([]);
            await changes(page).locator('[data-tolk-changes-action="close"]').click();
            // Leave the form clean, so the next page loads without core's unsaved-changes prompt.
            const saved = page.waitForResponse((response) => response.url().includes('/tolk/editor/confirm'));
            await page.keyboard.press('ControlOrMeta+s');
            expect((await saved).status()).toBe(200);

            await openEntry(page, 'articles', 'our-new-spa', 'en');
            await banner(page).locator('[data-tolk-action="translate-into"]').click();
            await expect(pushModal(page)).toBeVisible();
            expect(await axe(page, '[data-tolk-push-modal]')).toEqual([]);
            expect(browserErrors()).toEqual([]);
        });

        test('the Overview, a run, the runs, the audit and the settings pass axe, and the progress bar stands out', async ({ page, browserErrors }) => {
            await page.goto(cpUrl('tolk/overview'));
            await expect(page.locator('[data-tolk-cell-status="done"]')).toHaveCount(10, { timeout: 30_000 });
            expect(await axe(page, '[data-tolk-overview]')).toEqual([]);

            await page.locator('[data-tolk-cell="term|topics|el"] [data-tolk-cell-action="missing"]').click();
            await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'pending');
            await expect(page.locator('[data-tolk-estimate]')).toHaveAttribute('data-tolk-estimate-finished', 'true');
            expect(await axe(page, '[data-tolk-run]')).toEqual([]);

            await page.locator('[data-tolk-action="start"]').click();
            await expect(runPage(page)).toHaveAttribute('data-tolk-run-status', 'completed', { timeout: 45_000 });
            expect(await axe(page, '[data-tolk-run]')).toEqual([]);
            // The fill's width eases in for 300 ms; its colour does not change.
            const contrast = await progressContrast(page);
            expect(contrast.track).toBeGreaterThanOrEqual(3);
            expect(contrast.around).toBeGreaterThanOrEqual(3);

            await page.goto(cpUrl('tolk/runs'));
            await expect(page.locator('[data-tolk-run-row]')).toHaveCount(1);
            expect(await axe(page, '[data-tolk-runs]')).toEqual([]);

            await page.goto(cpUrl('tolk/audit'));
            await expect(page.locator('[data-tolk-check]').first()).toBeVisible();
            expect(await axe(page, '[data-tolk-audit]')).toEqual([]);

            // The settings' own fieldtypes, tab by tab.
            await page.goto(cpUrl('addons/statamic-tolk/settings'));
            await expect(page.locator('[data-tolk-model-select]').first()).toBeVisible();
            expect(await axe(page, '[data-tolk-model-select]')).toEqual([]);
            await page.getByRole('tab', { name: 'Context' }).click();
            await expect(page.locator('[data-tolk-site-profile]')).toBeVisible();
            expect(await axe(page, '[data-tolk-site-profile]')).toEqual([]);
            await page.getByRole('tab', { name: 'Providers' }).click();
            await expect(page.locator('[data-tolk-providers]')).toBeVisible();
            expect(await axe(page, '[data-tolk-providers]')).toEqual([]);
            expect(browserErrors()).toEqual([]);
        });
    });
}
