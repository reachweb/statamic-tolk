import { test as base, expect } from '@playwright/test';

export { expect };
export { sessions } from './sessions.js';

// Browser errors that come from core, not from the addon (T1.9, T2.2).
const coreNoise = [
    // The login answers 409 with `X-Inertia-Location`, Inertia's full-page redirect.
    (text) => text.includes('status of 409'),
    // Every collection listing cancels its first entries request.
    (text) => text.includes('net::ERR_ABORTED'),
    // Logged twice on every in-place site switch and localization creation of an article.
    (text) => text.includes("Cannot read properties of undefined (reading 'some')"),
    // A font the CP requests but does not ship.
    (text, url) => url.includes('intervariable-italic.woff2'),
];

export const test = base.extend({
    page: async ({ page }, use) => {
        // Statamic Pro's trial opens a "Licensing Alert" modal that blocks every click until it is snoozed. Snooze
        // it before the CP loads, the way its button does; the handler is a fallback should the storage key change.
        await page.addInitScript(() => {
            try {
                localStorage.setItem('statamic.snooze_license_banner', String(Date.now() + 86_400_000));
            } catch {}
        });
        await page.addLocatorHandler(page.getByRole('button', { name: 'Snooze' }), (button) => button.click());

        await use(page);
    },

    // Uncaught exceptions and console errors, without core's known noise. Read it at the end of a scenario.
    browserErrors: async ({ page }, use) => {
        const errors = [];

        page.on('pageerror', (error) => errors.push({ text: error.message, url: '' }));
        page.on('console', (message) => {
            if (message.type() === 'error') {
                errors.push({ text: message.text(), url: message.location().url ?? '' });
            }
        });

        await use(() => errors.filter(({ text, url }) => !coreNoise.some((isNoise) => isNoise(text, url))).map(({ text, url }) => (url ? `${text} (${url})` : text)));
    },
});
