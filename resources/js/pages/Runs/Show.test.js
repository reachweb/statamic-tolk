import { readFileSync } from 'node:fs';
import { createSSRApp } from 'vue';
import { parse } from 'vue/compiler-sfc';
import { renderToString } from 'vue/server-renderer';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { describeItem } from '../../runs/describe.js';

// Core's translator, without translation files: replacements by name, then the plural form, `one|other`.
const translate = (key, replacements = {}) => Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(String(value)), key);
const translateChoice = (key, count, replacements = {}) => {
    const [one, other] = translate(key, { ...replacements, count }).split('|');

    return count === 1 ? one : (other ?? one);
};

// Core's components, reduced to their text; `Text` renders a `span` as core's does.
const STUBS = {
    Badge: { props: ['text', 'color'], template: '<span>{{ text }}</span>' },
    Button: { props: ['text'], template: '<button>{{ text }}<slot /></button>' },
    Card: { template: '<div><slot /></div>' },
    Description: { template: '<div><slot /></div>' },
    Heading: { props: ['text'], template: '<h2>{{ text }}</h2>' },
    Select: { template: '<select />' },
    Table: { template: '<table><slot /></table>' },
    TableCell: { template: '<td><slot /></td>' },
    TableColumn: { template: '<th><slot /></th>' },
    TableColumns: { template: '<thead><tr><slot /></tr></thead>' },
    TableRow: { template: '<tr><slot /></tr>' },
    TableRows: { template: '<tbody><slot /></tbody>' },
    Text: { props: ['variant', 'size'], template: '<span><slot /></span>' },
    ChangesModal: {
        props: ['modal', 'corrections', 'reviewer'],
        template: '<div data-tolk-changes><p v-for="change in corrections" :key="change.id">{{ change.label }}: {{ change.before }} → {{ change.after }} ({{ change.reason }})</p></div>',
    },
};

// The source of the page's element that carries `attribute`, to render on its own with the data it reads.
function fragment(attribute) {
    const { descriptor } = parse(readFileSync(new URL('./Show.vue', import.meta.url), 'utf8'));
    const find = (node) => (node.props?.some((prop) => prop.name === attribute) ? node : ((node.children ?? []).map(find).find(Boolean) ?? null));

    return find(descriptor.template.ast).loc.source;
}

async function render(attribute, data) {
    const app = createSSRApp({ template: fragment(attribute), data: () => data });
    app.config.globalProperties.__ = translate;
    app.config.globalProperties.__n = translateChoice;
    Object.entries(STUBS).forEach(([name, component]) => app.component(name, component));

    return renderToString(app);
}

const text = (html) => html.replace(/<!--.*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

beforeEach(() => {
    vi.stubGlobal('__', translate);
    vi.stubGlobal('__n', translateChoice);
});

describe('the items of a review run', () => {
    // A done item of a review run, as `runs/{id}/state` sends it.
    const reviewed = {
        index: 0,
        type: 'entry',
        source_id: 'hello',
        target_site: 'el',
        title: 'Planning a wedding by the sea',
        status: 'done',
        fields: { title: 'applied', intro: 'no_corrections' },
        corrections: {
            title: [{ id: 's1', before: 'Σχεδιάζοντας έναν γάμο στη θάλασσα', after: 'Σχεδιάζοντας έναν γάμο δίπλα στη θάλασσα', reason: 'The wedding is by the sea, not in it.' }],
        },
        warnings: [],
        error: null,
        edit_url: '/cp/collections/articles/entries/hello-el',
    };

    const page = (open, shown = []) => ({
        items: [describeItem(reviewed, (handle) => ({ el: 'Greek' })[handle] ?? handle)],
        filter: 'all',
        filters: [],
        pagination: { page: 1, last_page: 1 },
        changes: { open },
        shown,
        filterBy: () => {},
        showPage: () => {},
        viewChanges: () => {},
    });

    it('count the corrections of an item and offer to view them', async () => {
        const html = await render('data-tolk-items', page(false));

        expect(text(html)).toContain('1 field saved 1 correction View changes');
        expect(html).toContain('data-tolk-item-changes');
        expect(html).not.toContain('data-tolk-changes');
    });

    it('show the corrections of the item in the changes modal: the field, the text before and after, and the reason', async () => {
        const data = page(true);
        data.shown = data.items[0].corrections;

        expect(text(await render('data-tolk-items', data))).toContain(
            'title: Σχεδιάζοντας έναν γάμο στη θάλασσα → Σχεδιάζοντας έναν γάμο δίπλα στη θάλασσα (The wedding is by the sea, not in it.)',
        );
    });

    it('offer no changes for an item without corrections', async () => {
        const data = page(false);
        data.items = [describeItem({ ...reviewed, fields: { title: 'no_corrections' }, corrections: [], reason: 'no_corrections' })];

        expect(await render('data-tolk-items', data)).not.toContain('data-tolk-item-changes');
    });
});
