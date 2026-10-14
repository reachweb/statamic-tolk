import { readFileSync } from 'node:fs';
import { createSSRApp } from 'vue';
import { parse } from 'vue/compiler-sfc';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';

// Core's translator, without translation files: replacements by name, then the plural form, `one|other`.
const translate = (key, replacements = {}) => Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(String(value)), key);
const translateChoice = (key, count, replacements = {}) => {
    const [one, other] = translate(key, { ...replacements, count }).split('|');

    return count === 1 ? one : (other ?? one);
};

// The source of the page's element that carries `attribute`, to render on its own with the data it reads.
function fragment(attribute) {
    const { descriptor } = parse(readFileSync(new URL('./Overview.vue', import.meta.url), 'utf8'));
    const find = (node) => (node.props?.some((prop) => prop.name === attribute) ? node : ((node.children ?? []).map(find).find(Boolean) ?? null));

    return find(descriptor.template.ast).loc.source;
}

async function render(attribute, data) {
    const app = createSSRApp({ template: fragment(attribute), data: () => data });
    app.config.globalProperties.__ = translate;
    app.config.globalProperties.__n = translateChoice;
    app.component('Description', { template: '<div><slot /></div>' });

    return renderToString(app);
}

const text = (html) => html.replace(/<!--.*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

describe('the usage of the month', () => {
    const month = (calls, cost) => ({ month: { calls, cost }, formatNumber: (value) => String(value), formatCost: (value) => `$${value}` });

    it('counts one call in the singular and more in the plural', async () => {
        expect(text(await render('data-tolk-usage-total', month(1, 0.01)))).toBe('1 call this month, an estimated $0.01.');
        expect(text(await render('data-tolk-usage-total', month(2, 0.01)))).toBe('2 calls this month, an estimated $0.01.');
    });

    it('does so when the cost is unknown too', async () => {
        expect(text(await render('data-tolk-usage-total', month(1, null)))).toBe('1 call this month. The cost is unknown: a model without a price was called.');
        expect(text(await render('data-tolk-usage-total', month(3, null)))).toBe('3 calls this month. The cost is unknown: a model without a price was called.');
    });
});

describe('a cell with items whose state cannot be read', () => {
    it('counts them in the singular and the plural', async () => {
        expect(text(await render('data-tolk-unreadable', { view: { unreadable: 1 } }))).toBe('1 item with unreadable state');
        expect(text(await render('data-tolk-unreadable', { view: { unreadable: 3 } }))).toBe('3 items with unreadable state');
    });

    it('says how to find the files under the table', async () => {
        expect(text(await render('data-tolk-unreadable-hint', { unreadable: true }))).toBe(
            'The translation state of some items cannot be read, so their status is unknown and translating them fails. Run php please tolk:doctor to list the files.',
        );
    });
});
