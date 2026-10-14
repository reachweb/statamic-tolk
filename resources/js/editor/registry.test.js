import { beforeEach, describe, expect, it } from 'vitest';
import { computed, ref } from 'vue';
import { clone, createRegistry, sameContent, sameText, textsOf } from './registry.js';

function bus() {
    const handlers = {};

    return {
        $on: (event, handler) => (handlers[event] ??= []).push(handler),
        $emit: (event, payload) => (handlers[event] ?? []).forEach((handler) => handler(payload)),
        count: (event) => (handlers[event] ?? []).length,
    };
}

// A publish container as far as the registry sees it: the event payload and the injected context share the refs.
function container(name, values, reference = 'entry::a') {
    const state = { reference: ref(reference), site: ref('el'), values: ref(values), meta: ref({}) };

    return {
        payload: { name, ...state, setFieldValue: (handle, value) => (state.values.value[handle] = value) },
        context: { name: ref(name), ...state, localizedFields: ref([]) },
    };
}

const stored = () => ({
    title: 'Τίτλος',
    intro: 'Εισαγωγή',
    body: [
        { _id: 'r1', type: 'text', text: 'Πρώτο' },
        { _id: 'r2', type: 'text', text: 'Δεύτερο' },
    ],
});

let events;
let registry;

beforeEach(() => {
    events = bus();
    registry = createRegistry();
    registry.listen(events);
});

describe('clone', () => {
    it('copies plain data at any depth and leaves the value it copies alone', () => {
        const value = { rows: [{ _id: 'a', text: 'A', set: { grid: [{ cell: 'C' }] } }], count: 2, empty: null };
        const copy = clone(value);

        expect(copy).toEqual(value);
        expect(copy.rows[0]).not.toBe(value.rows[0]);
        expect([clone('text'), clone(3), clone(null), clone(undefined)]).toEqual(['text', 3, null, undefined]);
    });

    it('reads through reactive proxies', () => {
        const values = ref({ body: [{ type: 'text', text: 'A' }] });

        expect(clone(values.value)).toEqual({ body: [{ type: 'text', text: 'A' }] });
    });
});

// What the CP's Bard component makes of a stored value when it re-emits it (T2.2 check 5, T6.11).
const storedBard = () => [
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Welcome' }] },
    {
        type: 'paragraph',
        content: [
            { type: 'text', text: 'See our ' },
            { type: 'text', marks: [{ type: 'link', attrs: { href: 'statamic://entry::rooms' } }], text: 'rooms' },
            { type: 'text', text: '.' },
        ],
    },
    { type: 'image', attrs: { src: 'asset::assets::pool.jpg', alt: 'The pool' } },
    { type: 'orderedList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'One' }] }] }] },
    { type: 'set', attrs: { id: 'a1', values: { type: 'quote', text: 'Wonderful.' } } },
];

const reemittedBard = () => [
    { type: 'heading', attrs: { level: 2, textAlign: null }, content: [{ type: 'text', text: 'Welcome' }] },
    {
        type: 'paragraph',
        attrs: { textAlign: null },
        content: [
            { type: 'text', text: 'See our ' },
            { type: 'text', marks: [{ type: 'link', attrs: { href: 'statamic://entry::rooms', rel: null, target: null, title: null } }], text: 'rooms' },
            { type: 'text', text: '.' },
        ],
    },
    { type: 'paragraph', attrs: { textAlign: null }, content: [{ type: 'image', attrs: { src: 'asset::assets::pool.jpg', alt: 'The pool' } }] },
    {
        type: 'orderedList',
        attrs: { start: 1, type: null },
        content: [{ type: 'listItem', content: [{ type: 'paragraph', attrs: { textAlign: null }, content: [{ type: 'text', text: 'One' }] }] }],
    },
    { type: 'set', attrs: { id: 'b2', enabled: true, values: { type: 'quote', text: 'Wonderful.' } } },
];

describe('textsOf and sameText', () => {
    it('reads the strings of a value by key and position', () => {
        expect(textsOf('Hello')).toBe('Hello');
        expect(textsOf(['a', 'b'])).toEqual(['a', 'b']);
        expect(JSON.stringify(textsOf({ question: 'Q', answer: 'A' }))).toBe(JSON.stringify(textsOf({ answer: 'A', question: 'Q' })));
        expect([textsOf(null), textsOf(undefined), textsOf(3), textsOf(true), textsOf(''), textsOf([]), textsOf({})]).toEqual([null, null, null, null, null, null, null]);
    });

    it('leaves out types, ids and marks, and reads a Bard text with marks as a part of its own with its link\'s title', () => {
        expect(textsOf([{ _id: 'x', id: 'y', type: 'text', marks: [{ type: 'link', attrs: { href: 'https://example.com' } }], text: 'A' }])).toEqual([{ text: 'A' }]);
        expect(textsOf([{ type: 'text', marks: [{ type: 'link', attrs: { href: 'https://example.com' } }], text: 'A' }])).toEqual([[{ text: 'A' }]]);
        expect(textsOf([{ type: 'text', marks: [{ type: 'link', attrs: { href: 'https://example.com', title: 'Example' } }], text: 'A' }])).toEqual([[{ text: 'A', title: 'Example' }]]);
    });

    it('reads a Bard value as its runs of text and its attributes', () => {
        expect(textsOf(storedBard())).toEqual(['Welcome', ['See our ', { text: 'rooms' }, '.'], { alt: 'The pool', src: 'asset::assets::pool.jpg' }, 'One', { values: { text: 'Wonderful.' } }]);
    });

    it('finds the same text in a Bard value Bard re-emitted', () => {
        expect(sameText(reemittedBard(), storedBard())).toBe(true);
    });

    it('finds rows that differ only in their ids and flags the same', () => {
        expect(sameText([{ _id: 'x1', type: 'text', text: 'A' }], [{ type: 'text', text: 'A', _id: 'y9', enabled: true }])).toBe(true);
    });

    it('tells a changed text, image alt, set value or link title apart', () => {
        const changed = (edit) => {
            const value = storedBard();
            edit(value);

            return value;
        };

        expect(sameText(changed((value) => (value[1].content[0].text = 'See all our ')), storedBard())).toBe(false);
        expect(sameText(changed((value) => (value[2].attrs.alt = 'Our pool')), storedBard())).toBe(false);
        expect(sameText(changed((value) => (value[4].attrs.values.text = 'Lovely.')), storedBard())).toBe(false);
        expect(sameText(changed((value) => value.splice(3, 1)), storedBard())).toBe(false);
        expect(sameText({ rows: [{ cells: ['A', 'B'] }] }, { rows: [{ cells: ['B', 'A'] }] })).toBe(false);
        expect(sameText('A', undefined)).toBe(false);
    });

    it('tells text moved to another field, row, cell or paragraph apart', () => {
        const paragraphs = (...texts) => texts.map((text) => ({ type: 'paragraph', content: [{ type: 'text', text }] }));

        expect(sameText({ heading: '', body: 'Hello world' }, { heading: 'Hello world', body: '' })).toBe(false);
        expect(sameText([{ _id: 'a', text: 'Hello' }, { _id: 'b', text: ' world' }], [{ _id: 'a', text: 'Hello world' }, { _id: 'b', text: '' }])).toBe(false);
        expect(sameText({ rows: [{ cells: ['A', 'B'] }] }, { rows: [{ cells: ['AB', ''] }] })).toBe(false);
        expect(sameText(['a', 'b'], ['ab'])).toBe(false);
        expect(sameText(paragraphs('Hello ', 'world'), paragraphs('Hello world'))).toBe(false);
        expect(sameText(paragraphs('One', 'Two'), [{ type: 'bulletList', content: [{ type: 'listItem', content: paragraphs('One', 'Two') }] }])).toBe(true);
    });

    it('reads CRLF and CR line breaks as line feeds, in strings and in Bard\'s runs of text', () => {
        const paragraph = (...texts) => [{ type: 'paragraph', content: texts.map((text) => ({ type: 'text', text })) }];

        expect(textsOf({ code: 'a\r\nb' })).toEqual({ code: 'a\nb' });
        expect(sameText('Line one\r\nLine two\rLine three', 'Line one\nLine two\nLine three')).toBe(true);
        expect(sameText(paragraph('Line one\r\nLine two'), paragraph('Line one\nLine two'))).toBe(true);
        expect(sameText(paragraph('Line one\r', '\nLine two'), paragraph('Line one\nLine two'))).toBe(true);
        expect(sameText({ rows: [{ text: 'a\r\nb' }] }, { rows: [{ text: 'a\nb' }] })).toBe(true);
        expect(sameText('a\nb', 'a\n\nb')).toBe(false);
        expect(sameText('a\r\nb', 'a b')).toBe(false);
    });

    // The server reads a Bard block's text with its formatted runs as numbered pairs (`See our <1>rooms</1>.`) and each
    // link title as a leaf of its own, so these make a field manual once saved. (A mark named after a Tailwind utility
    // would add that class to the CP build, which scans these files.)
    describe('formatting', () => {
        const paragraph = (...content) => [{ type: 'paragraph', content }];
        const text = (value, ...marks) => (marks.length ? { type: 'text', marks, text: value } : { type: 'text', text: value });
        const bold = { type: 'bold' };
        const strike = { type: 'strike' };
        const link = (attrs = {}) => ({ type: 'link', attrs: { href: 'statamic://entry::rooms', ...attrs } });
        const plain = () => paragraph(text('See our rooms.'));
        const linked = (attrs) => paragraph(text('See our '), text('rooms', link(attrs)), text('.'));
        const image = (...marks) => paragraph(text('See '), { type: 'image', marks, attrs: { src: 'asset::assets::pool.jpg' } });

        it('tells text linked or made bold, formatting moved or split, and a link title changed apart', () => {
            expect(sameText(linked(), plain())).toBe(false);
            expect(sameText(paragraph(text('See our '), text('rooms', bold), text('.')), plain())).toBe(false);
            expect(sameText(paragraph(text('See '), text('our', bold), text(' rooms.')), paragraph(text('See our '), text('rooms', bold), text('.')))).toBe(false);
            expect(sameText(paragraph(text('Hello ', bold), text('world')), paragraph(text('Hello '), text('world', bold)))).toBe(false);
            expect(sameText(paragraph(text('Hello ', bold), text('world', bold, strike)), paragraph(text('Hello world', bold)))).toBe(false);
            expect(sameText([{ type: 'heading', attrs: { level: 2 }, content: [text('Welcome', bold)] }], [{ type: 'heading', attrs: { level: 2 }, content: [text('Welcome')] }])).toBe(false);
            expect(sameText(linked({ title: 'Our rooms' }), linked())).toBe(false);
            expect(sameText(linked({ title: 'Our rooms' }), linked({ title: 'All rooms' }))).toBe(false);
            expect(sameText(image(link({ title: 'Our pool' })), image(link()))).toBe(false);
        });

        it('finds the same text where only a link target, the kind of formatting or empty attributes differ', () => {
            expect(sameText(linked({ href: 'statamic://entry::suites' }), linked())).toBe(true);
            expect(sameText(paragraph(text('See our '), text('rooms', strike), text('.')), paragraph(text('See our '), text('rooms', bold), text('.')))).toBe(true);
            expect(sameText(linked({ rel: null, target: null, title: null }), linked())).toBe(true);
            expect(sameText(linked({ title: '  ' }), linked())).toBe(true);
            expect(sameText(paragraph(text('Hello ', link({ title: null })), text('world', link())), paragraph(text('Hello world', link())))).toBe(true);
            expect(sameText(paragraph(text('Hello ', bold), text(''), text('world', bold)), paragraph(text('Hello world', bold)))).toBe(true);
            expect(sameText(paragraph(text('Line one\r\n', bold), text('Line two')), paragraph(text('Line one\n', bold), text('Line two')))).toBe(true);
        });
    });

    it('finds the same text in runs Bard joined and items it left empty', () => {
        const runs = [{ type: 'paragraph', content: [{ type: 'text', text: 'Hello ' }, { type: 'text', text: 'world' }] }];
        const joined = [{ type: 'paragraph', attrs: { textAlign: null }, content: [{ type: 'text', text: 'Hello world' }] }, { type: 'paragraph' }];

        expect(sameText(runs, joined)).toBe(true);
        expect(sameText(['a', 'b', ''], ['a', 'b'])).toBe(true);
        expect(sameText({ title: 'A', note: '' }, { title: 'A', note: null })).toBe(true);
    });
});

describe('sameContent', () => {
    const changed = (value, edit) => {
        edit(value);

        return value;
    };

    it('tells a switched toggle, a changed number or heading level, or a disabled set apart', () => {
        expect(sameContent({ flag: false, title: null }, { flag: true, title: null })).toBe(false);
        expect(sameContent([{ _id: 'r1', count: 1, label: null }], [{ _id: 'r1', count: 2, label: null }])).toBe(false);
        expect(sameContent({ count: 1 }, { count: null })).toBe(false);
        expect(sameContent(changed(storedBard(), (value) => (value[0].attrs.level = 3)), storedBard())).toBe(false);
        expect(sameContent(changed(reemittedBard(), (value) => (value[4].attrs.enabled = false)), reemittedBard())).toBe(false);
        expect(sameContent(changed(storedBard(), (value) => (value[1].content[0].text = 'See all our ')), storedBard())).toBe(false);
    });

    it('ignores what only one value holds, ids and types, as a fieldtype re-emits them', () => {
        const list = (attrs) => [{ type: 'orderedList', ...attrs, content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'One' }] }] }] }];

        expect(sameContent(list({}), list({ attrs: { start: 1, type: null } }))).toBe(true);
        expect(sameContent([{ _id: 'x1', type: 'text', text: 'A' }], [{ type: 'text', text: 'A', _id: 'y9', enabled: true }])).toBe(true);
        expect(sameContent([{ id: 1, text: 'A' }], [{ id: 2, text: 'A' }])).toBe(true);
        expect(sameContent(reemittedBard(), storedBard())).toBe(true);
        expect(sameContent(storedBard(), reemittedBard())).toBe(true);
        expect(sameContent('Line one\r\nLine two', 'Line one\nLine two')).toBe(true);
    });
});

describe('dirtyHandles', () => {
    let base;

    beforeEach(() => {
        base = container('base', stored());
        events.$emit('publish-container-created', base.payload);
        registry.attachContext('base', base.context);
    });

    it('is empty right after the context is attached', () => {
        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('lists the top-level handles changed in the form', () => {
        base.context.values.value.intro = 'Άλλη εισαγωγή';
        base.context.values.value.body[1].text = 'Αλλαγμένο';

        expect(registry.dirtyHandles('base')).toEqual(['intro', 'body']);
    });

    it('lists added and removed handles', () => {
        base.context.values.value.seo_title = 'SEO';
        delete base.context.values.value.title;

        expect(registry.dirtyHandles('base')).toEqual(['seo_title', 'title']);
    });

    it('does not count new random row ids', () => {
        base.context.values.value = { ...stored(), body: stored().body.map((row, i) => ({ ...row, _id: `new${i}` })) };

        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('does not count a value Bard re-emitted with attributes of its own', () => {
        base.context.values.value.content = storedBard();
        registry.refreshClean('base');

        base.context.values.value.content = reemittedBard();

        expect(registry.dirtyHandles('base')).toEqual([]);

        base.context.values.value.content[0].content[0].text = 'Willkommen';

        expect(registry.dirtyHandles('base')).toEqual(['content']);
    });

    it('is empty after a save until the next change', () => {
        base.context.values.value.title = 'Νέος τίτλος';
        base.context.values.value.body.push({ _id: 'r3', type: 'text', text: 'Τρίτο' });
        expect(registry.dirtyHandles('base')).toEqual(['title', 'body']);

        // The save response replaces the values with what was stored, rows with ids of their own.
        base.context.values.value = {
            ...stored(),
            title: 'Νέος τίτλος',
            body: [...stored().body, { _id: 'r3', type: 'text', text: 'Τρίτο' }].map((row, i) => ({ ...row, id: `s${i}` })),
        };
        registry.refreshClean('base');

        expect(registry.dirtyHandles('base')).toEqual([]);

        base.context.values.value.intro = 'Μετά την αποθήκευση';

        expect(registry.dirtyHandles('base')).toEqual(['intro']);
    });

    it('does not count line breaks a fieldtype passed up as line feeds', () => {
        base.context.values.value.content = 'Line one\r\nLine two';
        registry.refreshClean('base');

        // CodeMirror's re-emit of a value stored with CRLF, as the Markdown fieldtype passes it up.
        base.context.values.value.content = 'Line one\nLine two';

        expect(registry.dirtyHandles('base')).toEqual([]);

        base.context.values.value.content = 'Line one\n\nLine two';

        expect(registry.dirtyHandles('base')).toEqual(['content']);
    });

    it('is empty after a site switch until the next change', () => {
        base.context.values.value.intro = 'Unsaved Greek';

        // An in-place switch keeps the container and swaps its reference, site and values.
        base.context.reference.value = 'entry::b';
        base.context.site.value = 'de';
        base.context.values.value = { title: 'Titel', intro: 'Einleitung', body: [] };
        expect(registry.dirtyHandles('base')).toEqual(['title', 'intro', 'body']);

        registry.refreshClean('base');

        expect(registry.dirtyHandles('base')).toEqual([]);

        base.context.values.value.title = 'Neuer Titel';

        expect(registry.dirtyHandles('base')).toEqual(['title']);
    });

    it('keeps the clean values when the context is attached again', () => {
        base.context.values.value.title = 'Typed before Live Preview opened';

        // Live Preview mounts a new banner, which injects the same container again.
        registry.attachContext('base', { ...base.context });

        expect(registry.dirtyHandles('base')).toEqual(['title']);
    });

    it('is empty for a container without clean values', () => {
        const stack = container('relate-fieldtype-inline-1', stored());
        events.$emit('publish-container-created', stack.payload);
        stack.payload.values.value.title = 'Changed';

        expect(registry.dirtyHandles('relate-fieldtype-inline-1')).toEqual([]);
        expect(registry.dirtyHandles('unknown')).toEqual([]);
    });

    it('takes clean values from the payload when no context is attached', () => {
        const stack = container('relate-fieldtype-inline-1', stored());
        events.$emit('publish-container-created', stack.payload);
        registry.refreshClean('relate-fieldtype-inline-1');
        stack.payload.values.value.title = 'Changed';

        expect(registry.dirtyHandles('relate-fieldtype-inline-1')).toEqual(['title']);
    });
});

describe('containers', () => {
    it('listens once', () => {
        registry.listen(events);
        registry.listen(events);

        expect(events.count('publish-container-created')).toBe(1);
        expect(events.count('publish-container-destroyed')).toBe(1);
    });

    it('keeps the payload and the attached context per container', () => {
        const base = container('base', stored());
        events.$emit('publish-container-created', base.payload);

        expect(registry.payload('base')).toBe(base.payload);
        expect(registry.context('base')).toBeNull();
        expect(registry.base()).toBeNull();

        registry.attachContext('base', base.context);

        expect(registry.context('base')).toBe(base.context);
        expect(registry.base()).toBe(base.context);
    });

    it('lists the containers other than base', () => {
        events.$emit('publish-container-created', container('base', {}).payload);
        expect(registry.others()).toEqual([]);

        events.$emit('publish-container-created', container('relate-fieldtype-inline-7', {}).payload);
        expect(registry.others()).toEqual(['relate-fieldtype-inline-7']);

        events.$emit('publish-container-destroyed', { name: 'relate-fieldtype-inline-7' });
        expect(registry.others()).toEqual([]);
    });

    it('forgets a destroyed container', () => {
        const base = container('base', stored());
        events.$emit('publish-container-created', base.payload);
        registry.attachContext('base', base.context);

        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(registry.payload('base')).toBeNull();
        expect(registry.base()).toBeNull();
        expect(registry.dirtyHandles('base')).toEqual([]);
        expect(() => events.$emit('publish-container-destroyed', { name: 'base' })).not.toThrow();
    });

    it('keeps a new container whose predecessor of the same name is destroyed after it was created', () => {
        const first = container('base', stored(), 'entry::a');
        const second = container('base', { title: 'Other' }, 'entry::b');
        events.$emit('publish-container-created', first.payload);
        registry.attachContext('base', first.context);

        events.$emit('publish-container-created', second.payload);
        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(registry.payload('base')).toBe(second.payload);
        expect(registry.base()).toBeNull();

        registry.attachContext('base', second.context);
        second.context.values.value.title = 'Changed';

        expect(registry.dirtyHandles('base')).toEqual(['title']);

        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(registry.payload('base')).toBeNull();
    });

    it('is reactive for computed values that read it', () => {
        const alone = computed(() => registry.others().length === 0);
        const attached = computed(() => registry.base() !== null);
        const base = container('base', {});
        events.$emit('publish-container-created', base.payload);

        expect([alone.value, attached.value]).toEqual([true, false]);

        registry.attachContext('base', base.context);
        events.$emit('publish-container-created', container('relate-fieldtype-inline-2', {}).payload);

        expect([alone.value, attached.value]).toEqual([false, true]);

        events.$emit('publish-container-destroyed', { name: 'relate-fieldtype-inline-2' });

        expect(alone.value).toBe(true);
    });
});
