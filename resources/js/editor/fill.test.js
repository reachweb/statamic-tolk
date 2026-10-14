import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import {
    applyFields,
    clone,
    keepOriginSlug,
    recheckOriginSlug,
    REEMIT_MS,
    restore,
    SETTLE_MS,
    SLUG_RECHECK_MS,
    SLUG_RESET_TIMEOUT_MS,
    snapshot,
    takeBack,
} from './fill.js';
import { createRegistry } from './registry.js';

let dirty;

beforeEach(() => {
    vi.useFakeTimers({ now: 100_000 });
    dirty = new Set();
    vi.stubGlobal('Statamic', {
        $dirty: {
            add: (name) => dirty.add(name),
            remove: (name) => dirty.delete(name),
            has: (name) => dirty.has(name),
        },
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const stored = () => ({
    title: 'Title',
    intro: 'Intro',
    seo_title: 'SEO title',
    body: [{ _id: 'r1', type: 'paragraph', content: [{ type: 'text', text: 'Body' }] }],
});

// A publish container as a pushed component injects it. Core's `desyncField` does nothing while dirty tracking is off
// (the 500 ms after a switch or a save); `reemit` imitates a fieldtype such as Bard, which re-emits its own version of
// a value 150 ms after it was set and desyncs the field through its update handler.
// `hidden` is core's `hiddenFields`. Save leaves out the value of a field whose entry has `omitValue` (one a condition
// hides), but still posts its handle in `_localized` while the field is localized.
function container({ reference = 'entry::de', values = stored(), meta = {}, localized = [], reemit = {}, hidden = {} } = {}) {
    const calls = [];
    const ctx = {
        tracking: true,
        calls,
        name: ref('base'),
        reference: ref(reference),
        site: ref('de'),
        values: ref(values),
        meta: ref({ title: null, intro: null, seo_title: null, body: { existing: {} }, ...meta }),
        localizedFields: ref(localized),
        hiddenFields: ref(hidden),
        setFieldMeta: vi.fn((handle, value) => {
            calls.push(['meta', handle]);
            ctx.meta.value[handle] = value;
        }),
        setFieldValue: vi.fn((handle, value) => {
            calls.push(['value', handle]);
            ctx.values.value[handle] = value;

            if (reemit[handle]) {
                setTimeout(() => {
                    ctx.values.value[handle] = reemit[handle](value);
                    ctx.desyncField(handle);
                }, 150);
            }
        }),
        desyncField: vi.fn((handle) => {
            calls.push(['desync', handle]);

            if (!ctx.tracking) {
                return;
            }

            if (!ctx.localizedFields.value.includes(handle)) {
                ctx.localizedFields.value.push(handle);
            }

            dirty.add('base');
        }),
        withoutDirtying: vi.fn((callback) => callback()),
    };

    return ctx;
}

const translated = (text) => ({ value: `[de] ${text}`, meta: null, incomplete: false, warnings: [] });

// Core's Markdown fieldtype hands every new value to CodeMirror, whose `setValue()` splits it, so null throws "Cannot
// read properties of null (reading 'split')" and the editor keeps showing the old text.
function markdown(ctx, handle) {
    const set = ctx.setFieldValue.getMockImplementation();

    ctx.setFieldValue.mockImplementation((field, value) => {
        set(field, value);

        if (field === handle) {
            value.split(/\r\n?|\n/);
        }
    });

    return ctx;
}

// What CodeMirror makes of a value it is given: it splits lines on `\r\n`, `\r` and `\n` and joins them with `\n`.
const lineFeeds = (text) => text.replace(/\r\n?/g, '\n');

// Runs every timer, the fill's guards and read-back included, and returns what the promise resolved with.
async function settled(promise) {
    await vi.runAllTimersAsync();

    return promise;
}

function fill(ctx, fields, options = {}) {
    return applyFields(ctx, fields, {
        reference: 'entry::de',
        plan: 'plan-1',
        planValues: clone(ctx.values.value),
        delivered: {},
        ...options,
    });
}

describe('applyFields', () => {
    it('applies nothing when the form shows another item', async () => {
        const ctx = container({ reference: 'entry::el' });
        const delivered = {};

        const result = await settled(fill(ctx, { title: translated('Title') }, { delivered }));

        expect(result).toEqual({ stale: true, applied: [], skipped: { title: 'stale' }, repeated: [] });
        expect(ctx.calls).toEqual([]);
        expect(ctx.values.value.title).toBe('Title');
        expect(delivered).toEqual({});
    });

    it('applies nothing when the editor switches while the fill waits for the form to settle', async () => {
        const ctx = container();
        const promise = fill(ctx, { title: translated('Title') }, { lastChangeAt: Date.now() });

        await vi.advanceTimersByTimeAsync(100);
        ctx.reference.value = 'entry::el';

        expect((await settled(promise)).stale).toBe(true);
        expect(ctx.calls).toEqual([]);
    });

    it('applies nothing once the translation is no longer active, even on the same item', async () => {
        const ctx = container();
        let active = true;
        const delivered = {};
        const promise = fill(ctx, { title: translated('Title') }, { lastChangeAt: Date.now(), delivered, active: () => active });

        await vi.advanceTimersByTimeAsync(100);
        active = false;

        expect(await settled(promise)).toEqual({ stale: true, applied: [], skipped: { title: 'stale' }, repeated: [] });
        expect(ctx.calls).toEqual([]);
        expect(delivered).toEqual({});
    });

    it('drops its records when the translation stops being active during the read-back', async () => {
        const ctx = container();
        let active = true;
        const delivered = {};
        const promise = fill(ctx, { title: translated('Title') }, { delivered, active: () => active });

        await vi.advanceTimersByTimeAsync(0);
        expect(delivered.title.pending).toBe(true);
        active = false;

        expect((await settled(promise)).stale).toBe(true);
        expect(delivered).toEqual({});
    });

    it('waits until 600 ms have passed since the last switch or save', async () => {
        const ctx = container();
        const promise = fill(ctx, { title: translated('Title') }, { lastChangeAt: Date.now() - 200 });

        await vi.advanceTimersByTimeAsync(SETTLE_MS - 200 - 1);
        expect(ctx.setFieldValue).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(ctx.values.value.title).toBe('[de] Title');

        expect((await settled(promise)).applied).toEqual(['title']);
    });

    it('waits again when the last change moves while it waits', async () => {
        const ctx = container();
        let lastChangeAt = Date.now();
        const promise = fill(ctx, { title: translated('Title') }, { lastChangeAt: () => lastChangeAt });

        await vi.advanceTimersByTimeAsync(400);
        lastChangeAt = Date.now();
        await vi.advanceTimersByTimeAsync(SETTLE_MS - 1);
        expect(ctx.setFieldValue).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(ctx.setFieldValue).toHaveBeenCalledOnce();

        await settled(promise);
    });

    it('does not wait when the last change is long enough ago', async () => {
        const ctx = container();

        fill(ctx, { title: translated('Title') }, { lastChangeAt: Date.now() - SETTLE_MS });
        await vi.advanceTimersByTimeAsync(0);

        expect(ctx.values.value.title).toBe('[de] Title');
        await vi.runAllTimersAsync();
    });

    it('sets the meta before the value, then desyncs the field', async () => {
        const ctx = container();
        const meta = { existing: { r1: { caption: null } } };
        const body = [{ _id: 'r1', type: 'paragraph', content: [{ type: 'text', text: '[de] Body' }] }];

        await settled(fill(ctx, { title: translated('Title'), body: { value: body, meta, incomplete: false } }));

        expect(ctx.calls).toEqual([
            ['meta', 'title'],
            ['value', 'title'],
            ['desync', 'title'],
            ['meta', 'body'],
            ['value', 'body'],
            ['desync', 'body'],
        ]);
        expect(ctx.meta.value.title).toBeNull();
        expect(ctx.meta.value.body).toEqual(meta);
        expect(ctx.values.value.body).toEqual(body);
        expect(ctx.localizedFields.value).toEqual(['title', 'body']);
        expect(dirty.has('base')).toBe(true);
    });

    it('leaves the meta alone when the payload has none, and fills copies of the payload', async () => {
        const ctx = container();
        const payload = { value: ['[de] One'] };

        await settled(fill(ctx, { intro: payload }));
        payload.value.push('[de] Two');

        expect(ctx.setFieldMeta).not.toHaveBeenCalled();
        expect(ctx.values.value.intro).toEqual(['[de] One']);
    });

    it('ignores a field the same plan delivered before', async () => {
        const ctx = container();
        const delivered = { title: { plan: 'plan-1', value: '[de] Title', pending: false } };

        const result = await settled(fill(ctx, { title: translated('Other'), intro: translated('Intro') }, {
            delivered,
            planValues: { ...clone(ctx.values.value), title: 'Title' },
        }));

        expect(result).toEqual({ stale: false, applied: ['intro'], skipped: {}, repeated: ['title'] });
        expect(ctx.values.value.title).toBe('Title');
        expect(delivered.title.value).toBe('[de] Title');
    });

    it('ignores a field that a parallel unit of the same plan is still delivering', async () => {
        const ctx = container();
        const options = { delivered: {}, planValues: clone(ctx.values.value) };

        const first = fill(ctx, { title: translated('Title') }, options);
        await vi.advanceTimersByTimeAsync(0);
        const second = fill(ctx, { title: translated('Title again') }, options);

        expect((await settled(second)).repeated).toEqual(['title']);
        expect((await first).applied).toEqual(['title']);
        expect(ctx.setFieldValue).toHaveBeenCalledOnce();
        expect(ctx.values.value.title).toBe('[de] Title');
    });

    it('fills a field again for another plan', async () => {
        const ctx = container({ values: { ...stored(), title: '[de] Title' } });
        const delivered = { title: { plan: 'plan-1', value: '[de] Title', pending: false } };

        const result = await settled(fill(ctx, { title: translated('New title') }, { plan: 'plan-2', delivered }));

        expect(result.applied).toEqual(['title']);
        expect(delivered.title).toMatchObject({ plan: 'plan-2', value: '[de] New title' });
    });

    it('skips a field the editor changed since the plan was made', async () => {
        const ctx = container();
        const planValues = clone(ctx.values.value);
        const delivered = {};
        ctx.values.value.intro = 'Typed by hand';

        const result = await settled(fill(ctx, { title: translated('Title'), intro: translated('Intro') }, { planValues, delivered }));

        expect(result).toEqual({ stale: false, applied: ['title'], skipped: { intro: 'edited' }, repeated: [] });
        expect(ctx.values.value.intro).toBe('Typed by hand');
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(Object.keys(delivered)).toEqual(['title']);
    });

    it('skips a field a condition hides, whose value Save would not post, so it keeps inheriting', async () => {
        const ctx = container({ hidden: { intro: { hidden: true, omitValue: true } } });
        const delivered = {};

        const result = await settled(fill(ctx, { title: translated('Title'), intro: translated('Intro') }, { delivered }));

        expect(result).toEqual({ stale: false, applied: ['title'], skipped: { intro: 'hidden' }, repeated: [] });
        expect(ctx.values.value.intro).toBe('Intro');
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(Object.keys(delivered)).toEqual(['title']);
    });

    it('fills a hidden field whose value Save still posts, and puts a hidden field back to inheriting', async () => {
        const ctx = container({
            localized: ['seo_title'],
            hidden: { title: { hidden: 'force', omitValue: false }, intro: { hidden: true, omitValue: false }, seo_title: { hidden: true, omitValue: true } },
        });

        const result = await settled(
            fill(ctx, { title: translated('Title'), intro: translated('Intro'), seo_title: { value: 'Origin SEO title', meta: null, sync: true } }),
        );

        expect(result.applied).toEqual(['title', 'intro', 'seo_title']);
        expect(ctx.localizedFields.value).toEqual(['title', 'intro']);
    });

    it('skips a field whose text the editor moved to another field of a set or another paragraph', async () => {
        const block = (heading, body) => [{ id: 's1', type: 'block', enabled: true, heading, body }];
        const ctx = container({ values: { ...stored(), blocks: block('', 'Hello world'), body: [{ type: 'paragraph', content: [{ type: 'text', text: 'Hello world' }] }] } });
        const planValues = clone(ctx.values.value);
        ctx.values.value.blocks = block('Hello world', '');
        ctx.values.value.body = [
            { type: 'paragraph', content: [{ type: 'text', text: 'Hello ' }] },
            { type: 'paragraph', content: [{ type: 'text', text: 'world' }] },
        ];

        const result = await settled(fill(ctx, { blocks: { value: block('', '[de] Hello world') }, body: { value: [], meta: { existing: {} } } }, { planValues }));

        expect(result.skipped).toEqual({ blocks: 'edited', body: 'edited' });
        expect(ctx.values.value.blocks).toEqual(block('Hello world', ''));
    });

    it('skips a Bard field in which the editor linked a word since the plan was made', async () => {
        const ctx = container();
        const planValues = clone(ctx.values.value);
        ctx.values.value.body = [{ _id: 'r1', type: 'paragraph', content: [{ type: 'text', marks: [{ type: 'link', attrs: { href: 'https://example.com' } }], text: 'Body' }] }];

        const result = await settled(fill(ctx, { body: { value: [], meta: { existing: {} } } }, { planValues }));

        expect(result.skipped).toEqual({ body: 'edited' });
        expect(ctx.values.value.body[0].content[0].marks).toHaveLength(1);
    });

    it('does not count new random row ids as an edit', async () => {
        const ctx = container();
        const planValues = clone(ctx.values.value);
        ctx.values.value.body = [{ ...ctx.values.value.body[0], _id: 'x9' }];

        const result = await settled(fill(ctx, { body: { value: [], meta: { existing: {} } } }, { planValues }));

        expect(result.applied).toEqual(['body']);
    });

    it('fills a field whose value was only re-emitted since the plan was made', async () => {
        const ctx = container();
        const planValues = clone(ctx.values.value);
        // An inherited Bard field desyncs itself and re-emits its value with attributes of its own while the form loads.
        ctx.values.value.body = [{ ...ctx.values.value.body[0], attrs: { textAlign: null } }];

        const result = await settled(fill(ctx, { body: { value: [], meta: { existing: {} } } }, { planValues }));

        expect(result.applied).toEqual(['body']);
    });

    it('counts a field missing from the plan values as edited unless the form lacks it too', async () => {
        const ctx = container();
        const planValues = clone(ctx.values.value);
        delete planValues.intro;
        delete planValues.body;
        delete ctx.values.value.body;

        const result = await settled(fill(ctx, { intro: translated('Intro'), body: { value: [], meta: { existing: {} } } }, { planValues }));

        expect(result.applied).toEqual(['body']);
        expect(result.skipped).toEqual({ intro: 'edited' });
    });

    it('marks the field localized and the form dirty itself when desyncField does nothing', async () => {
        const ctx = container();
        ctx.tracking = false;

        await settled(fill(ctx, { title: translated('Title') }));

        expect(ctx.desyncField).toHaveBeenCalledWith('title');
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(dirty.has('base')).toBe(true);
    });

    it('does not mark the form dirty itself when desyncField did', async () => {
        const ctx = container({ localized: ['title'] });
        ctx.desyncField.mockImplementation(() => {});

        await settled(fill(ctx, { title: translated('Title') }));

        expect(dirty.has('base')).toBe(false);
    });

    it('puts a synced field back to the origin value and removes its localized flag', async () => {
        const ctx = container({ values: { ...stored(), intro: 'Eigene Einleitung' }, localized: ['title', 'intro'] });
        const delivered = {};

        const result = await settled(fill(ctx, { intro: { value: 'Origin intro', meta: null, sync: true } }, { delivered }));

        expect(result.applied).toEqual(['intro']);
        expect(ctx.values.value.intro).toBe('Origin intro');
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(ctx.desyncField).not.toHaveBeenCalled();
        expect(dirty.has('base')).toBe(true);
        expect(delivered.intro).toMatchObject({ plan: 'plan-1', value: 'Origin intro', sync: true });
    });

    it('fills an empty string for a synced value of null while the form holds text, and null otherwise', async () => {
        const ctx = markdown(container({ values: { ...stored(), excerpt: 'Eigener Auszug', tags: null }, localized: ['excerpt', 'tags'] }), 'excerpt');
        const delivered = {};

        const result = await settled(
            fill(ctx, { excerpt: { value: null, meta: null, sync: true }, tags: { value: null, meta: null, sync: true } }, { delivered }),
        );

        expect(result.applied).toEqual(['excerpt', 'tags']);
        expect(ctx.values.value).toMatchObject({ excerpt: '', tags: null });
        expect(ctx.localizedFields.value).toEqual([]);
        expect(delivered.excerpt).toMatchObject({ value: '', sync: true });
    });

    it('keeps a synced field unlocalized when its fieldtype re-emits the value', async () => {
        const normalise = (nodes) => nodes.map((node) => ({ ...node, attrs: { textAlign: null } }));
        const ctx = container({ localized: ['body'], reemit: { body: normalise } });
        const origin = [{ type: 'paragraph', content: [{ type: 'text', text: 'Origin body' }] }];

        await settled(fill(ctx, { body: { value: origin, meta: { existing: {} }, sync: true } }));

        expect(ctx.desyncField).toHaveBeenCalledWith('body');
        expect(ctx.localizedFields.value).toEqual([]);
    });

    it('keeps a synced field localized when the editor corrects it before the read-back, so Save posts it', async () => {
        const ctx = container({ values: { ...stored(), intro: 'Eigene Einleitung' }, localized: ['title', 'intro'] });
        const promise = fill(ctx, { intro: { value: 'Origin intro', meta: null, sync: true } });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        // Core's update handler sets what the editor typed and desyncs the field.
        ctx.values.value.intro = 'Origin intro, corrected';
        ctx.desyncField('intro');
        await settled(promise);

        expect(ctx.values.value.intro).toBe('Origin intro, corrected');
        expect(ctx.localizedFields.value).toEqual(['title', 'intro']);
    });

    // A sync value is the origin's empty value, preprocessed: a Group's nested defaults (a toggle arrives as false), a
    // Grid with `min_rows` padded with default rows. A nested field's update desyncs the top-level field, as typing does.
    it('keeps a synced Group localized when the editor switches a toggle in it before the read-back, so Save posts it', async () => {
        const ctx = container({ values: { ...stored(), group: { flag: false, title: 'Eigener Titel' } }, localized: ['group'] });
        const promise = fill(ctx, { group: { value: { flag: false, title: null }, meta: null, sync: true } });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.group = { flag: true, title: null };
        ctx.desyncField('group');
        await settled(promise);

        expect(ctx.values.value.group).toEqual({ flag: true, title: null });
        expect(ctx.localizedFields.value).toEqual(['group']);
    });

    it('keeps a synced Grid localized when the editor switches a toggle in a padded row before the read-back', async () => {
        const ctx = container({ values: { ...stored(), rows: [{ _id: 'r1', flag: true, label: 'Eigene' }] }, localized: ['rows'] });
        const promise = fill(ctx, { rows: { value: [{ _id: 'r1', flag: false, label: null }], meta: null, sync: true } });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.rows = [{ _id: 'r1', flag: true, label: null }];
        ctx.desyncField('rows');
        await settled(promise);

        expect(ctx.localizedFields.value).toEqual(['rows']);
    });

    // Guard: core's Integer, Float and Range fieldtypes pass a typed number up as a string, which is text.
    it('keeps a synced Grid localized when the editor types a number into a padded row before the read-back', async () => {
        const ctx = container({ values: { ...stored(), rows: [{ _id: 'r1', count: 3, label: 'Eigene' }] }, localized: ['rows'] });
        const promise = fill(ctx, { rows: { value: [{ _id: 'r1', count: 1, label: null }], meta: null, sync: true } });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.rows = [{ _id: 'r1', count: '2', label: null }];
        ctx.desyncField('rows');
        await settled(promise);

        expect(ctx.localizedFields.value).toEqual(['rows']);
    });

    // Guard: the field the editor did not touch still goes back to inheriting.
    it('takes a synced Bard field out again when it re-emits while the editor types into another field', async () => {
        const emptyParagraph = (nodes) => (nodes.length ? nodes : [{ type: 'paragraph', attrs: { textAlign: null } }]);
        const ctx = container({ localized: ['body'], reemit: { body: emptyParagraph } });
        const promise = fill(ctx, { body: { value: [], meta: { existing: {} }, sync: true } });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.title = 'Title, by hand';
        ctx.desyncField('title');
        await settled(promise);

        expect(ctx.localizedFields.value).toEqual(['title']);
    });

    // Guard: an upload finishing, a Replicator set added once its fetch answers or a fieldtype with a longer debounce
    // changes a field with no input from anyone in the window. What the field holds decides, never the lack of input.
    it('keeps a synced field localized when a change the editor started earlier lands during the read-back', async () => {
        const ctx = container({ values: { ...stored(), group: { photo: [], caption: 'Eigene' } }, localized: ['group'] });
        const promise = fill(ctx, { group: { value: { photo: [], caption: null }, meta: null, sync: true } });

        await vi.advanceTimersByTimeAsync(50);
        ctx.values.value.group = { photo: ['assets::pool.jpg'], caption: null };
        ctx.desyncField('group');
        await settled(promise);

        expect(ctx.localizedFields.value).toEqual(['group']);
    });

    it('records what the form holds after the fieldtype re-emitted, not the payload', async () => {
        const normalise = (nodes) => nodes.map((node) => ({ ...node, attrs: { textAlign: null } }));
        const ctx = container({ reemit: { body: normalise } });
        const delivered = {};
        const body = [{ type: 'paragraph', content: [{ type: 'text', text: '[de] Body' }] }];

        const promise = fill(ctx, { body: { value: body, meta: { existing: {} }, incomplete: true } }, { delivered });
        await vi.advanceTimersByTimeAsync(REEMIT_MS - 1);

        expect(delivered.body).toMatchObject({ plan: 'plan-1', pending: true });

        await settled(promise);

        expect(delivered.body).toEqual({ plan: 'plan-1', value: normalise(body), pending: false, incomplete: true, sync: false, localizedBefore: false });
        expect(delivered.body.value).not.toBe(ctx.values.value.body);
    });

    it('records what was delivered when the editor changes the text before the read-back', async () => {
        const normalise = (nodes) => nodes.map((node) => ({ ...node, attrs: { textAlign: null } }));
        const ctx = container({ reemit: { body: normalise } });
        const delivered = {};
        const body = [{ type: 'paragraph', content: [{ type: 'text', text: '[de] Body' }] }];
        const promise = fill(ctx, { title: translated('Title'), body: { value: body, meta: { existing: {} } } }, { delivered });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.title = 'Human correction';
        ctx.values.value.body = [{ type: 'paragraph', attrs: { textAlign: null }, content: [{ type: 'text', text: 'Body, by hand' }] }];
        await settled(promise);

        expect(delivered.title.value).toBe('[de] Title');
        expect(delivered.body.value).toEqual(body);
    });

    it('records what a Markdown field passed up with other line breaks, so Undo restores it', async () => {
        const registry = createRegistry();
        const ctx = container({ values: { ...stored(), content: 'Line one\nLine two' }, reemit: { content: lineFeeds } });
        registry.attachContext('base', ctx);
        const snap = snapshot(ctx, ['content'], {});
        const delivered = {};

        await settled(fill(ctx, { content: { value: '[de] Line one\r\n[de] Line two', meta: null } }, { delivered }));

        expect(delivered.content.value).toBe('[de] Line one\n[de] Line two');
        expect(await settled(restore(ctx, snap, delivered, { registry }))).toEqual({ restored: ['content'], kept: [] });
        expect(ctx.values.value.content).toBe('Line one\nLine two');
    });

    it('records whether the field held a value of its own before the first fill since the last save', async () => {
        const ctx = container({ localized: ['title'] });
        const delivered = {};

        await settled(fill(ctx, { title: translated('Title'), intro: translated('Intro') }, { delivered }));
        await settled(fill(ctx, { intro: translated('Intro again') }, { plan: 'plan-2', delivered, planValues: clone(ctx.values.value) }));

        expect(delivered.title).toMatchObject({ plan: 'plan-1', localizedBefore: true });
        expect(delivered.intro).toMatchObject({ plan: 'plan-2', value: '[de] Intro again', localizedBefore: false });
        expect(ctx.localizedFields.value).toEqual(['title', 'intro']);
    });

    it('records nothing when the editor switches before the read-back', async () => {
        const ctx = container();
        const delivered = {};
        const promise = fill(ctx, { title: translated('Title') }, { delivered });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 1);
        ctx.reference.value = 'entry::el';

        expect((await settled(promise)).stale).toBe(true);
        expect(delivered).toEqual({});
    });

    it('accepts the empty list PHP sends for an empty map', async () => {
        const ctx = container();

        expect(await settled(fill(ctx, []))).toEqual({ stale: false, applied: [], skipped: {}, repeated: [] });
    });
});

describe('snapshot', () => {
    it('copies values, meta, the localized flags, the dirty flag and earlier deliveries', () => {
        const ctx = container({ meta: { body: { existing: { r1: {} } } }, localized: ['title'] });
        const delivered = { title: { plan: 'plan-0', value: 'Title', pending: false } };
        dirty.add('base');

        const snap = snapshot(ctx, ['title', 'body'], delivered);
        ctx.values.value.body[0].content[0].text = 'Changed';
        delivered.title.value = 'Changed';

        expect(snap).toEqual({
            reference: 'entry::de',
            dirty: true,
            fields: {
                title: { value: 'Title', meta: null, localized: true, delivered: { plan: 'plan-0', value: 'Title', pending: false } },
                body: { value: stored().body, meta: { existing: { r1: {} } }, localized: false, delivered: null },
            },
        });
    });
});

describe('restore', () => {
    let registry;

    // The banner attaches the context before anything is filled, which takes the clean values.
    function attached(options) {
        const ctx = container(options);
        registry.attachContext('base', ctx);

        return ctx;
    }

    async function translate(ctx, fields, plan = 'plan-1', delivered = {}) {
        const snap = snapshot(ctx, Object.keys(fields), delivered);
        await settled(fill(ctx, fields, { plan, delivered }));

        return { snap, delivered };
    }

    beforeEach(() => {
        registry = createRegistry();
    });

    it('returns the form to the snapshot and clears the dirty flag', async () => {
        const ctx = attached({ localized: ['title'] });
        const before = { values: clone(ctx.values.value), meta: clone(ctx.meta.value) };
        const { snap, delivered } = await translate(ctx, {
            title: translated('Title'),
            intro: { value: '[de] Intro', meta: { counter: 1 }, incomplete: false },
        });

        expect(dirty.has('base')).toBe(true);

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['title', 'intro'], kept: [] });
        expect(ctx.values.value).toEqual(before.values);
        expect(ctx.meta.value).toEqual(before.meta);
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(delivered).toEqual({});
        expect(dirty.has('base')).toBe(false);
        expect(ctx.withoutDirtying).toHaveBeenCalledOnce();
        expect(ctx.desyncField).toHaveBeenCalledTimes(2);
    });

    it('leaves a field the editor changed after the delivery, and the form dirty', async () => {
        const ctx = attached();
        const { snap, delivered } = await translate(ctx, { title: translated('Title'), intro: translated('Intro') });
        ctx.values.value.intro = '[de] Intro, by hand';

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['title'], kept: ['intro'] });
        expect(ctx.values.value.title).toBe('Title');
        expect(ctx.values.value.intro).toBe('[de] Intro, by hand');
        expect(ctx.localizedFields.value).toEqual(['intro']);
        expect(Object.keys(delivered)).toEqual(['intro']);
        expect(dirty.has('base')).toBe(true);
    });

    it('leaves a Bard field in which the editor made a phrase bold or linked a word after the delivery', async () => {
        const paragraph = (...content) => [{ type: 'paragraph', content }];
        const ctx = attached();
        const { snap, delivered } = await translate(ctx, {
            body: { value: paragraph({ type: 'text', text: '[de] Body text' }), meta: { existing: {} } },
            title: { value: [{ type: 'text', text: '[de] Title' }], meta: null },
        });
        ctx.values.value.body = paragraph({ type: 'text', text: '[de] ' }, { type: 'text', marks: [{ type: 'bold' }], text: 'Body' }, { type: 'text', text: ' text' });
        ctx.values.value.title = [{ type: 'text', marks: [{ type: 'link', attrs: { href: 'https://example.com', title: null } }], text: '[de] Title' }];

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: [], kept: ['body', 'title'] });
        expect(ctx.values.value.body[0].content[1].marks).toEqual([{ type: 'bold' }]);
        expect(dirty.has('base')).toBe(true);
    });

    it('keeps a correction typed while the fill waited for its fieldtype to re-emit', async () => {
        const ctx = attached();
        const snap = snapshot(ctx, ['title'], {});
        const delivered = {};
        const promise = fill(ctx, { title: translated('Title') }, { delivered });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.title = 'Human correction';
        await settled(promise);
        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: [], kept: ['title'] });
        expect(ctx.values.value.title).toBe('Human correction');
    });

    it('keeps the dirty flag when the form was dirty before the translation', async () => {
        const ctx = attached();
        dirty.add('base');
        const { snap, delivered } = await translate(ctx, { title: translated('Title') });

        await settled(restore(ctx, snap, delivered, { registry }));

        expect(ctx.values.value.title).toBe('Title');
        expect(dirty.has('base')).toBe(true);
    });

    it('leaves fields the translation did not deliver', async () => {
        const ctx = attached();
        ctx.values.value.intro = 'Typed before';
        const planValues = clone(ctx.values.value);
        const snap = snapshot(ctx, ['title', 'intro'], {});
        const delivered = {};
        ctx.values.value.intro = 'Typed during the translation';

        await settled(fill(ctx, { title: translated('Title'), intro: translated('Intro') }, { delivered, planValues }));
        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['title'], kept: [] });
        expect(ctx.values.value.intro).toBe('Typed during the translation');
    });

    it('brings back the record of an earlier translation', async () => {
        const ctx = attached();
        const first = await translate(ctx, { title: translated('Title') });
        const { snap, delivered } = await translate(ctx, { title: translated('Better title') }, 'plan-2', first.delivered);

        expect(delivered.title.plan).toBe('plan-2');

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result.restored).toEqual(['title']);
        expect(ctx.values.value.title).toBe('[de] Title');
        expect(delivered.title).toMatchObject({ plan: 'plan-1', value: '[de] Title' });
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(dirty.has('base')).toBe(true);
    });

    it('restores a field a fieldtype re-emits without leaving it localized', async () => {
        const normalise = (nodes) => nodes.map((node) => ({ ...node, attrs: { textAlign: null } }));
        const ctx = attached({ reemit: { body: normalise } });
        const body = [{ type: 'paragraph', content: [{ type: 'text', text: '[de] Body' }] }];
        const { snap, delivered } = await translate(ctx, { body: { value: body, meta: { existing: {} } } });

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result.restored).toEqual(['body']);
        expect(ctx.localizedFields.value).toEqual([]);
        // The re-emitted value has the snapshot's text, so nothing is left to save.
        expect(ctx.values.value.body).toEqual(normalise(stored().body));
        expect(dirty.has('base')).toBe(false);
    });

    it('keeps a field localized when the editor types into it right after Undo, so Save posts it', async () => {
        const ctx = attached();
        const { snap, delivered } = await translate(ctx, { title: translated('Title') });
        const promise = restore(ctx, snap, delivered, { registry });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.title = 'Title, by hand';
        ctx.desyncField('title');
        const result = await settled(promise);

        expect(result.restored).toEqual(['title']);
        expect(ctx.values.value.title).toBe('Title, by hand');
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(dirty.has('base')).toBe(true);
    });

    it('keeps a field localized when the editor switches a toggle in a set right after Undo, so Save posts it', async () => {
        const sets = (heading, flag) => [{ _id: 's1', type: 'block', enabled: true, heading, flag }];
        const ctx = attached({ values: { ...stored(), blocks: sets('Heading', false) } });
        const { snap, delivered } = await translate(ctx, { blocks: { value: sets('[de] Heading', false), meta: null } });
        const promise = restore(ctx, snap, delivered, { registry });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.blocks = sets('Heading', true);
        ctx.desyncField('blocks');
        const result = await settled(promise);

        expect(result.restored).toEqual(['blocks']);
        expect(ctx.localizedFields.value).toEqual(['blocks']);
        expect(dirty.has('base')).toBe(true);
    });

    it('keeps a Bard field localized when the editor changes a heading level right after Undo', async () => {
        const heading = (text, level) => [{ type: 'heading', attrs: { level }, content: [{ type: 'text', text }] }];
        const ctx = attached({ values: { ...stored(), body: heading('Welcome', 2) } });
        const { snap, delivered } = await translate(ctx, { body: { value: heading('[de] Welcome', 2), meta: { existing: {} } } });
        const promise = restore(ctx, snap, delivered, { registry });

        await vi.advanceTimersByTimeAsync(REEMIT_MS - 50);
        ctx.values.value.body = heading('Welcome', 3);
        ctx.desyncField('body');
        await settled(promise);

        expect(ctx.localizedFields.value).toEqual(['body']);
        expect(dirty.has('base')).toBe(true);
    });

    // Guard: attributes Bard adds with their defaults when it re-emits are not a change.
    it('puts a Bard field back to inheriting when it re-emits a list with its default attributes after Undo', async () => {
        const list = (text) => [{ type: 'orderedList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] }] }];
        const normalise = (nodes) =>
            nodes.map((node) => ({
                ...node,
                attrs: { start: 1, type: null },
                content: node.content.map((item) => ({ ...item, content: item.content.map((paragraph) => ({ ...paragraph, attrs: { textAlign: null } })) })),
            }));
        const ctx = attached({ values: { ...stored(), body: list('One') }, reemit: { body: normalise } });
        const { snap, delivered } = await translate(ctx, { body: { value: list('[de] One'), meta: { existing: {} } } });

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['body'], kept: [] });
        expect(ctx.values.value.body).toEqual(normalise(list('One')));
        expect(ctx.localizedFields.value).toEqual([]);
        expect(dirty.has('base')).toBe(false);
    });

    // Guard: an upload the editor started before Undo lands without input from anyone in the window.
    it('keeps a field localized when an upload the editor started before Undo lands right after it', async () => {
        const sets = (heading, images) => [{ _id: 's1', type: 'gallery', enabled: true, heading, images }];
        const ctx = attached({ values: { ...stored(), blocks: sets('Heading', []) } });
        const { snap, delivered } = await translate(ctx, { blocks: { value: sets('[de] Heading', []), meta: null } });
        const promise = restore(ctx, snap, delivered, { registry });

        await vi.advanceTimersByTimeAsync(100);
        ctx.values.value.blocks = sets('Heading', ['assets::pool.jpg']);
        ctx.desyncField('blocks');
        await settled(promise);

        expect(ctx.localizedFields.value).toEqual(['blocks']);
    });

    it('restores a field its fieldtype re-emitted again after the delivery was recorded', async () => {
        const ctx = attached();
        const { snap, delivered } = await translate(ctx, { body: { value: [{ type: 'paragraph', content: [{ type: 'text', text: '[de] Body' }] }], meta: { existing: {} } } });
        ctx.values.value.body = ctx.values.value.body.map((node) => ({ ...node, attrs: { textAlign: null } }));

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['body'], kept: [] });
        expect(ctx.values.value.body).toEqual(stored().body);
    });

    it('puts an inherited Markdown field back to inheriting when CodeMirror passes the restored value up with line feeds', async () => {
        // Inherited from an origin whose `.md` body holds CRLF; the form shows it as loaded.
        const ctx = attached({ values: { ...stored(), content: 'Line one\r\nLine two' }, reemit: { content: lineFeeds } });
        const { snap, delivered } = await translate(ctx, { content: { value: '[de] Line one\n[de] Line two', meta: null } });

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['content'], kept: [] });
        expect(ctx.values.value.content).toBe('Line one\nLine two');
        expect(ctx.localizedFields.value).toEqual([]);
        expect(dirty.has('base')).toBe(false);
    });

    it('puts back an empty string for a value that was null while the form holds text', async () => {
        const ctx = markdown(attached({ values: { ...stored(), excerpt: null } }), 'excerpt');
        const { snap, delivered } = await translate(ctx, { excerpt: translated('Excerpt') });

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result.restored).toEqual(['excerpt']);
        expect(ctx.values.value.excerpt).toBe('');
        expect(ctx.localizedFields.value).toEqual([]);
    });

    it('restores nothing after a switch to another item', async () => {
        const ctx = attached();
        const { snap, delivered } = await translate(ctx, { title: translated('Title') });
        ctx.reference.value = 'entry::el';

        expect(await settled(restore(ctx, snap, delivered, { registry }))).toEqual({ restored: [], kept: [] });
        expect(ctx.values.value.title).toBe('[de] Title');
    });

    it('never asks the container to sync a field', async () => {
        const ctx = attached({ localized: ['intro'] });
        ctx.syncField = vi.fn();
        const { snap, delivered } = await translate(ctx, { intro: { value: 'Origin intro', meta: null, sync: true } });

        await settled(restore(ctx, snap, delivered, { registry }));
        await nextTick();

        expect(ctx.syncField).not.toHaveBeenCalled();
        expect(ctx.values.value.intro).toBe('Intro');
        expect(ctx.localizedFields.value).toEqual(['intro']);
    });
});

describe('takeBack', () => {
    it('puts the fields back as the snapshot holds them, a fill being read back and one edited since included', async () => {
        const ctx = container({ localized: ['title'] });
        const before = { values: clone(ctx.values.value), meta: clone(ctx.meta.value) };
        const snap = snapshot(ctx, ['title', 'intro', 'seo_title'], {});
        const delivered = {};

        await settled(fill(ctx, { title: translated('Title'), intro: { value: '[de] Intro', meta: { counter: 1 }, incomplete: false } }, { delivered }));
        ctx.values.value.title = '[de] Title, by hand';
        fill(ctx, { seo_title: translated('SEO title') }, { plan: 'plan-2', delivered });
        await vi.advanceTimersByTimeAsync(0);

        expect(delivered.seo_title.pending).toBe(true);

        expect(takeBack(ctx, snap, ['title', 'intro', 'seo_title', 'body'])).toEqual(['title', 'intro', 'seo_title']);
        expect(ctx.values.value).toEqual(before.values);
        expect(ctx.meta.value).toEqual(before.meta);
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(ctx.withoutDirtying).toHaveBeenCalledOnce();
    });

    it('puts nothing back after a switch to another item, or without a snapshot', async () => {
        const ctx = container();
        const snap = snapshot(ctx, ['title'], {});

        await settled(fill(ctx, { title: translated('Title') }));
        ctx.reference.value = 'entry::el';

        expect(takeBack(ctx, snap, ['title'])).toEqual([]);
        expect(takeBack(ctx, null, ['title'])).toEqual([]);
        expect(ctx.values.value.title).toBe('[de] Title');
        expect(ctx.withoutDirtying).not.toHaveBeenCalled();
    });
});

// The form of a localization just created, with core's slug fieldtype imitated. `localization.created` resets the slug
// to follow the title: it slugifies the title asynchronously and turns `meta.slug.auto` on `resetMs` later. A value the
// fieldtype did not generate stops the following. While it follows, a new title is slugified 200 ms later, and a
// slugify response sets the slug even when the following stopped in between (T2.2 check 9). With `debounceMs` the
// reset's slug reaches the form that much after `auto` turned on, unless a slug is set in between, as core's fieldtype
// passes its value up through a debounce.
function createdForm({ originSlug = 'about', title = 'About us', resetMs = 1000, debounceMs = 0 } = {}) {
    const ctx = container({ values: { title, slug: originSlug }, meta: { slug: { generate: true } } });
    const slug = { follows: false, generated: originSlug };
    const slugify = (text) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const generate = (text) => {
        slug.generated = slugify(text);
        ctx.values.value.slug = slug.generated;
    };
    const follow = (auto) => {
        slug.follows = auto;
        ctx.meta.value.slug = { ...ctx.meta.value.slug, auto };
    };
    const set = ctx.setFieldValue;
    let pending = null;

    ctx.setFieldValue = vi.fn((handle, value) => {
        set(handle, value);

        if (handle === 'slug') {
            clearTimeout(pending);
        }

        if (handle === 'slug' && value !== slug.generated) {
            follow(false);
        }

        if (handle === 'title' && slug.follows) {
            setTimeout(() => generate(value), 200);
        }
    });

    setTimeout(() => {
        if (debounceMs === 0) {
            generate(ctx.values.value.title);
        } else {
            slug.generated = slugify(ctx.values.value.title);
            pending = setTimeout(() => (ctx.values.value.slug = slug.generated), debounceMs);
        }

        follow(true);
    }, resetMs);

    ctx.slugValues = () => ctx.setFieldValue.mock.calls.filter(([handle]) => handle === 'slug').map(([, value]) => value);
    ctx.fillTitle = async (value) => {
        ctx.setFieldValue('title', value);
        await vi.advanceTimersByTimeAsync(1_000);
    };

    return ctx;
}

const keep = (ctx, originSlug = 'about', options = {}) =>
    keepOriginSlug(ctx, originSlug, { reference: 'entry::de', lastChangeAt: Date.now(), ...options });

describe('keepOriginSlug', () => {
    it('puts the origin slug back once core has reset the slug, so it no longer follows the title', async () => {
        const ctx = createdForm();
        const promise = keep(ctx);

        await vi.advanceTimersByTimeAsync(999);
        expect(ctx.slugValues()).toEqual([]);
        expect(ctx.values.value.slug).toBe('about');

        expect(await settled(promise)).toEqual({ stale: false, kept: true, reset: true });
        expect(ctx.slugValues()).toEqual(['about']);
        expect(ctx.meta.value.slug.auto).toBe(false);

        await ctx.fillTitle('[de] About us');
        expect(ctx.values.value.slug).toBe('about');
    });

    it('stops the following when the reset gave the origin slug again', async () => {
        const ctx = createdForm({ originSlug: 'dining', title: 'Dining' });

        expect(await settled(keep(ctx, 'dining'))).toEqual({ stale: false, kept: true, reset: true });
        expect(ctx.slugValues()).toEqual(['', 'dining']);
        expect(ctx.meta.value.slug.auto).toBe(false);

        await ctx.fillTitle('[de] Dining');
        expect(ctx.values.value.slug).toBe('dining');
    });

    it('stops the following when the reset turned it on before its slug reached the form', async () => {
        const ctx = createdForm({ resetMs: 950, debounceMs: 150 });
        const promise = keep(ctx);

        await vi.advanceTimersByTimeAsync(960);
        expect(ctx.meta.value.slug.auto).toBe(true);
        expect(ctx.values.value.slug).toBe('about');

        expect(await settled(promise)).toEqual({ stale: false, kept: true, reset: true });
        expect(ctx.values.value.slug).toBe('about');
        expect(ctx.meta.value.slug.auto).toBe(false);

        await ctx.fillTitle('[de] About us');
        expect(ctx.values.value.slug).toBe('about');
    });

    it('waits for the 600 ms guard even when the reset has finished', async () => {
        const ctx = createdForm({ resetMs: 100 });
        const promise = keep(ctx);

        await vi.advanceTimersByTimeAsync(SETTLE_MS - 1);
        expect(ctx.slugValues()).toEqual([]);

        await vi.advanceTimersByTimeAsync(1);
        expect(ctx.slugValues()).toEqual(['about']);
        await settled(promise);
    });

    it('does not wait when the localization was created long enough ago', async () => {
        const ctx = createdForm();
        const createdAt = Date.now();

        await vi.advanceTimersByTimeAsync(3_000);
        keep(ctx, 'about', { lastChangeAt: createdAt });
        await vi.advanceTimersByTimeAsync(0);

        expect(ctx.slugValues()).toEqual(['about']);
    });

    it('gives up waiting for the reset 2 seconds after the last change', async () => {
        const ctx = createdForm({ resetMs: 5_000 });
        let resolved = null;
        keep(ctx).then((result) => (resolved = result));

        await vi.advanceTimersByTimeAsync(SLUG_RESET_TIMEOUT_MS - 1);
        expect(resolved).toBeNull();

        await vi.advanceTimersByTimeAsync(1);
        expect(resolved).toEqual({ stale: false, kept: true, reset: false });
        // The slug still holds the origin's, so there was nothing to set.
        expect(ctx.slugValues()).toEqual([]);
    });

    it('leaves the form alone when it shows another item', async () => {
        const ctx = createdForm();

        expect(await settled(keep(ctx, 'about', { reference: 'entry::el' }))).toEqual({ stale: true, kept: false, reset: false });
        expect(ctx.slugValues()).toEqual([]);
    });

    it('stops when the editor switches while it waits', async () => {
        const ctx = createdForm();
        const promise = keep(ctx);

        await vi.advanceTimersByTimeAsync(300);
        ctx.reference.value = 'entry::el';

        expect((await settled(promise)).stale).toBe(true);
        expect(ctx.slugValues()).toEqual([]);
    });

    it('stops when the translation is no longer active', async () => {
        const ctx = createdForm();
        let active = true;
        const promise = keep(ctx, 'about', { active: () => active });

        await vi.advanceTimersByTimeAsync(700);
        active = false;

        expect((await settled(promise)).stale).toBe(true);
        expect(ctx.slugValues()).toEqual([]);
    });

    it('does not set the second value after a switch between the two', async () => {
        const ctx = createdForm({ originSlug: 'dining', title: 'Dining' });
        const promise = keep(ctx, 'dining');

        await vi.advanceTimersByTimeAsync(1_000);
        expect(ctx.slugValues()).toEqual(['']);
        ctx.reference.value = 'entry::el';

        expect((await settled(promise)).stale).toBe(true);
        expect(ctx.slugValues()).toEqual(['']);
    });

    it('does nothing without a slug field or an origin slug', async () => {
        const ctx = container({ values: { title: 'About us' } });

        expect(await keep(ctx)).toEqual({ stale: false, kept: false, reset: false });
        expect(await keep(createdForm(), null)).toEqual({ stale: false, kept: false, reset: false });
        expect(ctx.setFieldValue).not.toHaveBeenCalled();
    });
});

const recheck = (ctx, originSlug = 'about', options = {}) => recheckOriginSlug(ctx, originSlug, { reference: 'entry::de', ...options });

describe('recheckOriginSlug', () => {
    it('sets the slug again when a late reset made it follow the translated title', async () => {
        const ctx = createdForm({ resetMs: 5_000 });

        await settled(keep(ctx));
        ctx.setFieldValue('title', '[de] About us');
        await vi.advanceTimersByTimeAsync(2_500);
        expect(ctx.values.value.slug).toBe('de-about-us');

        expect(await settled(recheck(ctx))).toBe(true);
        expect(ctx.values.value.slug).toBe('about');
        expect(ctx.meta.value.slug.auto).toBe(false);

        await ctx.fillTitle('[de] Über uns');
        expect(ctx.values.value.slug).toBe('about');
    });

    it('waits a second before it checks', async () => {
        const ctx = createdForm();
        await settled(keep(ctx));
        ctx.values.value.slug = 'de-about-us';
        const promise = recheck(ctx);

        await vi.advanceTimersByTimeAsync(SLUG_RECHECK_MS - 1);
        expect(ctx.values.value.slug).toBe('de-about-us');

        await vi.advanceTimersByTimeAsync(1);
        expect(ctx.values.value.slug).toBe('about');
        await settled(promise);
    });

    it('leaves a kept slug alone', async () => {
        const ctx = createdForm();
        await settled(keep(ctx));
        await ctx.fillTitle('[de] About us');

        expect(await settled(recheck(ctx))).toBe(false);
        expect(ctx.slugValues()).toEqual(['about']);
    });

    it('stops the following again when the slug holds the origin slug but follows the title', async () => {
        const ctx = createdForm({ originSlug: 'dining', title: 'Dining', resetMs: 5_000 });
        await settled(keep(ctx, 'dining'));

        expect(ctx.meta.value.slug.auto).toBe(true);
        expect(await settled(recheck(ctx, 'dining'))).toBe(true);
        expect(ctx.slugValues()).toEqual(['', 'dining']);

        await ctx.fillTitle('[de] Dining');
        expect(ctx.values.value.slug).toBe('dining');
    });

    it('does nothing after a switch', async () => {
        const ctx = createdForm();
        await settled(keep(ctx));
        ctx.values.value.slug = 'de-about-us';
        const promise = recheck(ctx);

        ctx.reference.value = 'entry::el';

        expect(await settled(promise)).toBe(false);
        expect(ctx.values.value.slug).toBe('de-about-us');
    });
});
