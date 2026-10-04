import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { applyFields, clone, REEMIT_MS, restore, SETTLE_MS, snapshot } from './fill.js';
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
function container({ reference = 'entry::de', values = stored(), meta = {}, localized = [], reemit = {} } = {}) {
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

    it('does not count new random row ids as an edit', async () => {
        const ctx = container();
        const planValues = clone(ctx.values.value);
        ctx.values.value.body = [{ ...ctx.values.value.body[0], _id: 'x9' }];

        const result = await settled(fill(ctx, { body: { value: [], meta: { existing: {} } } }, { planValues }));

        expect(result.applied).toEqual(['body']);
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

    it('keeps a synced field unlocalized when its fieldtype re-emits the value', async () => {
        const normalise = (nodes) => nodes.map((node) => ({ ...node, attrs: { textAlign: null } }));
        const ctx = container({ localized: ['body'], reemit: { body: normalise } });
        const origin = [{ type: 'paragraph', content: [{ type: 'text', text: 'Origin body' }] }];

        await settled(fill(ctx, { body: { value: origin, meta: { existing: {} }, sync: true } }));

        expect(ctx.desyncField).toHaveBeenCalledWith('body');
        expect(ctx.localizedFields.value).toEqual([]);
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

        expect(delivered.body).toEqual({ plan: 'plan-1', value: normalise(body), pending: false, incomplete: true, sync: false });
        expect(delivered.body.value).not.toBe(ctx.values.value.body);
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
