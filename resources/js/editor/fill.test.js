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
        // The re-emitted value has the snapshot's text, so nothing is left to save.
        expect(ctx.values.value.body).toEqual(normalise(stored().body));
        expect(dirty.has('base')).toBe(false);
    });

    it('restores a field its fieldtype re-emitted again after the delivery was recorded', async () => {
        const ctx = attached();
        const { snap, delivered } = await translate(ctx, { body: { value: [{ type: 'paragraph', content: [{ type: 'text', text: '[de] Body' }] }], meta: { existing: {} } } });
        ctx.values.value.body = ctx.values.value.body.map((node) => ({ ...node, attrs: { textAlign: null } }));

        const result = await settled(restore(ctx, snap, delivered, { registry }));

        expect(result).toEqual({ restored: ['body'], kept: [] });
        expect(ctx.values.value.body).toEqual(stored().body);
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
