import { readFileSync } from 'node:fs';
import { createSSRApp } from 'vue';
import { parse } from 'vue/compiler-sfc';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';

const translate = (key, replacements = {}) => Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(String(value)), key);

// The banner's element whose children carry `data-tolk-action="<action>"`, to render on its own with the data it reads.
function parentOf(action) {
    const { descriptor } = parse(readFileSync(new URL('./EditorBanner.vue', import.meta.url), 'utf8'));
    const carries = (node) => node.props?.some((prop) => prop.name === 'data-tolk-action' && prop.value?.content === action);
    const find = (node) => ((node.children ?? []).some(carries) ? node : ((node.children ?? []).map(find).find(Boolean) ?? null));

    return find(descriptor.template.ast).loc.source;
}

async function render(action, data) {
    const app = createSSRApp({ template: parentOf(action), data: () => ({ translations: null, name: 'base', undo: () => {}, ...data }) });
    app.config.globalProperties.__ = translate;
    app.component('Button', { template: '<button><slot /></button>' });

    return renderToString(app);
}

const text = (html) => html.replace(/<!--.*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

describe('the buttons under the result', () => {
    const result = (extra = {}) => render('retry', { phase: 'done', canRetry: false, canUndo: false, restart: false, ...extra });

    it('offer Retry after a translation that finished with fields it could not translate', async () => {
        expect(text(await result({ canRetry: true }))).toBe('Retry');
        expect(text(await result({ canRetry: true, canUndo: true }))).toBe('Retry Undo');
    });

    it('offer Retry or Start again after an error', async () => {
        expect(text(await result({ phase: 'error', canRetry: true }))).toBe('Retry');
        expect(text(await result({ phase: 'error', canRetry: true, restart: true, canUndo: true }))).toBe('Start again Undo');
    });

    it('offer Undo alone after a translation without failed fields, and nothing while one runs', async () => {
        expect(text(await result({ canUndo: true }))).toBe('Undo');
        expect(text(await result())).toBe('');
        expect(text(await result({ phase: 'working', canUndo: true }))).toBe('');
    });
});
