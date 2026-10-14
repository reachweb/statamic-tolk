import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { ApiError } from '../api.js';
import { applyFields, REEMIT_MS, SETTLE_MS, snapshot } from './fill.js';
import { clone, createRegistry } from './registry.js';
import { createSaveHooks, notSavedMessage } from './saveHooks.js';
import { useEditorStore } from './store.js';

class PipelineStopped extends Error {}

let store;
let registry;
let toasts;
let dirty;

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

beforeEach(() => {
    vi.useFakeTimers({ now: 50_000 });
    toasts = [];
    dirty = new Set();
    vi.stubGlobal('Statamic', {
        $pinia: { defineStore },
        $dirty: { add: (name) => dirty.add(name), remove: (name) => dirty.delete(name), has: (name) => dirty.has(name) },
        $toast: {
            error: (message, options) => toasts.push(['error', message, options]),
            info: (message) => toasts.push(['info', message]),
        },
    });
    vi.stubGlobal('__', translate);
    vi.stubGlobal('__n', (key, count, replacements = {}) => {
        const [one, other] = translate(key, { ...replacements, count }).split('|');

        return count === 1 ? one : (other ?? one);
    });
    setActivePinia(createPinia());
    store = useEditorStore();
    registry = createRegistry();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const freshStatus = () => ({
    translatable: true,
    localization: { fields: { intro: { status: 'current', label: 'Intro' }, seo_title: { status: 'current', label: 'SEO title' } } },
});

function mount(reference = 'entry::de') {
    const ctx = {
        name: ref('base'),
        reference: ref(reference),
        values: ref({ title: 'Startseite', intro: 'Intro' }),
        localizedFields: ref(['title']),
        hiddenFields: ref({}),
    };

    registry.attachContext('base', ctx);
    store.switched('base', reference, 1_000);
    store.container('base').status = { translatable: true, stale: true };

    return ctx;
}

function hooks(overrides = {}) {
    return createSaveHooks({ PipelineStopped, store: () => store, registry, api: { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) }, ...overrides });
}

// Runs a hook the way core's hook runner does and reports how it settled.
function run(callback, payload) {
    return new Promise((resolve) => callback((value) => resolve({ resolved: true, value }), (reason) => resolve({ resolved: false, reason }), payload));
}

const savedPayload = (reference = 'entry::de') => ({ collection: 'pages', reference: 'entry::stale', response: { data: { data: { reference } } } });

// A save as core runs it: its request step puts the response's values into the form before the `entry.saved` hooks
// run (SavePipeline's `Request`). Returns the hooks' payload.
function respond(ctx, values, reference = 'entry::de') {
    ctx.values.value = clone(values);

    return { collection: 'pages', reference: 'entry::stale', response: { data: { data: { reference, values } } } };
}

// A fieldtype passing a value up through core's 150 ms update debounce, as CodeMirror does after a value it changed.
function reemit(ctx, handle, value) {
    setTimeout(() => (ctx.values.value[handle] = value), 150);
}

const filled = (extra = {}) => ({ plan: 'p1', value: '[de] Text', pending: false, incomplete: false, sync: false, localizedBefore: false, ...extra });
const hide = (ctx, ...handles) => handles.forEach((handle) => (ctx.hiddenFields.value[handle] = { hidden: true, omitValue: true }));

// `editor/confirm` as the server answers it: a delivered field the save did not post in `_localized` holds no override,
// whether a condition hid it or the editor put it back to inheriting, and is listed.
function confirming(ctx) {
    return { post: vi.fn(async (url, { delivered }) => ({ ...freshStatus(), not_saved: delivered.filter((handle) => !ctx.localizedFields.value.includes(handle)) })) };
}

describe('listen', () => {
    it('registers the hooks of the entry and term forms once', () => {
        const registered = [];
        const save = hooks();

        save.listen({ on: (name, callback) => registered.push([name, callback]) });
        save.listen({ on: (name, callback) => registered.push([name, callback]) });

        expect(registered).toEqual([
            ['entry.saving', save.saving],
            ['entry.saved', save.saved],
            ['term.saving', save.saving],
            ['term.saved', save.saved],
            ['entry.published', save.published],
        ]);
    });
});

describe('entry.saving', () => {
    it.each(['planning', 'working'])('refuses a save while the translation is %s', async (phase) => {
        mount();
        store.setPhase('base', phase);

        const outcome = await run(hooks({ running: (name) => name === 'base' }).saving, { collection: 'pages', values: {} });

        expect(outcome.resolved).toBe(false);
        expect(outcome.reason).toBeInstanceOf(PipelineStopped);
        expect(toasts).toEqual([['error', 'A translation is in progress. Wait for it to finish, or cancel it.', undefined]]);
    });

    it('says so once per translation while autosave tries every few seconds', async () => {
        mount();
        store.setPhase('base', 'working');
        const translation = {};
        const save = hooks({ running: () => translation });
        const outcomes = [];

        // Core's autosave (experimental, opt-in per collection) saves a dirty form every 5 s by default, and the fills
        // keep the form dirty.
        const autosave = setInterval(() => outcomes.push(run(save.saving, { collection: 'pages', values: {} })), 5_000);
        await vi.advanceTimersByTimeAsync(30_000);
        clearInterval(autosave);

        const settled = await Promise.all(outcomes);

        expect(settled).toHaveLength(6);
        expect(settled.every((outcome) => !outcome.resolved && outcome.reason instanceof PipelineStopped)).toBe(true);
        expect(toasts).toEqual([['error', 'A translation is in progress. Wait for it to finish, or cancel it.', undefined]]);
    });

    it('says so again for the next translation', async () => {
        mount();
        store.setPhase('base', 'working');
        let translation = {};
        const save = hooks({ running: () => translation });

        await run(save.saving, {});
        await run(save.saving, {});
        translation = {};
        await run(save.saving, {});

        expect(toasts.map(([type]) => type)).toEqual(['error', 'error']);
    });

    it('lets a save through when the phase says busy but no translation runs any more', async () => {
        mount();
        store.setPhase('base', 'working');

        expect((await run(hooks({ running: () => false }).saving, {})).resolved).toBe(true);
        expect(toasts).toEqual([]);
    });

    it.each(['idle', 'offer', 'done', 'error'])('lets a save through while %s', async (phase) => {
        mount();
        store.setPhase('base', phase);

        expect((await run(hooks().saving, {})).resolved).toBe(true);
        expect(toasts).toEqual([]);
    });

    it('lets a save through on a form without a banner', async () => {
        expect((await run(hooks().saving, {})).resolved).toBe(true);
    });

    // Core posts a field a condition hides without its value but with its handle in `_localized`, which the server
    // stores as an empty value of the localization's own.
    it('leaves a filled field that a condition now hides out of `_localized`, so it keeps inheriting instead of being stored empty', async () => {
        const ctx = mount();
        ctx.localizedFields.value.push('intro', 'seo_title');
        store.container('base').delivered = { intro: filled(), seo_title: filled() };
        hide(ctx, 'intro');

        expect((await run(hooks().saving, { collection: 'pages', values: ctx.values.value })).resolved).toBe(true);
        expect(ctx.localizedFields.value).toEqual(['title', 'seo_title']);
    });

    it('leaves a hidden field alone that held a value of its own before the fill, was put back to inheriting or is still filling', async () => {
        const ctx = mount();
        ctx.localizedFields.value.push('intro', 'seo_title');
        store.container('base').delivered = {
            title: filled({ localizedBefore: true }),
            intro: filled({ pending: true }),
            seo_title: filled({ value: 'Origin', sync: true }),
            excerpt: filled(),
        };
        hide(ctx, 'title', 'intro', 'seo_title', 'excerpt');

        await run(hooks().saving, {});

        expect(ctx.localizedFields.value).toEqual(['title', 'intro', 'seo_title']);
    });

    it('posts such a field again once it is shown, when the save that left it out failed', async () => {
        const ctx = mount();
        ctx.localizedFields.value.push('intro');
        store.container('base').delivered = { intro: filled(), excerpt: filled() };
        hide(ctx, 'intro');

        await run(hooks().saving, {});
        delete ctx.hiddenFields.value.intro;
        await run(hooks().saving, {});

        expect(ctx.localizedFields.value).toEqual(['title', 'intro']);
    });

    it('leaves the form alone while it refuses the save', async () => {
        const ctx = mount();
        ctx.localizedFields.value.push('intro');
        store.container('base').delivered = { intro: filled() };
        hide(ctx, 'intro');
        store.setPhase('base', 'working');
        const translation = {};

        expect((await run(hooks({ running: () => translation }).saving, {})).resolved).toBe(false);
        expect(ctx.localizedFields.value).toEqual(['title', 'intro']);
    });
});

describe('entry.saved', () => {
    it('confirms the delivered fields and shows the fresh status', async () => {
        const ctx = mount();
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };
        store.container('base').delivered = { intro: { plan: 'p1' }, seo_title: { plan: 'p1' } };
        ctx.values.value.intro = '[de] Intro';
        ctx.localizedFields.value.push('intro', 'seo_title');

        const outcome = await run(hooks({ api }).saved, savedPayload());

        expect(outcome.resolved).toBe(true);
        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/confirm', { reference: 'entry::de', delivered: ['intro', 'seo_title'] });
        expect(store.container('base')).toMatchObject({ status: freshStatus(), notSaved: [], delivered: {}, lastChangeAt: 50_000 });
        expect(registry.dirtyHandles('base')).toEqual([]);
        expect(toasts).toEqual([]);
    });

    it('hands the fresh status on, so a run the save started is followed', async () => {
        mount();
        const onConfirmed = vi.fn();
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };

        await run(hooks({ api, onConfirmed }).saved, savedPayload());

        expect(onConfirmed).toHaveBeenCalledExactlyOnceWith('base', freshStatus());
    });

    it('resolves only once the confirm request has returned', async () => {
        mount();
        let answer;
        const api = { post: vi.fn(() => new Promise((resolve) => (answer = resolve))) };
        let settled = false;

        const outcome = run(hooks({ api }).saved, savedPayload()).then((result) => ((settled = true), result));
        await vi.advanceTimersByTimeAsync(0);
        expect(settled).toBe(false);

        answer({ ...freshStatus(), not_saved: [] });
        expect((await outcome).resolved).toBe(true);
    });

    it('confirms after a save without delivered fields too', async () => {
        mount();
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };

        await run(hooks({ api }).saved, savedPayload());

        expect(api.post).toHaveBeenCalledWith('editor/confirm', { reference: 'entry::de', delivered: [] });
    });

    it('lists the fields that were not saved', async () => {
        mount();
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: ['intro', 'seo_title'] })) };

        await run(hooks({ api }).saved, savedPayload());

        const message = 'Intro, SEO title were not saved because they were hidden when you saved. Show them and translate them again.';

        expect(store.container('base').notSaved).toEqual(['intro', 'seo_title']);
        expect(toasts).toEqual([['error', message, { duration: 10_000 }]]);
        expect(notSavedMessage(['intro'], freshStatus())).toBe('Intro was not saved because it was hidden when you saved. Show it and translate it again.');
    });

    it('does not list a filled field that the editor put back to inheriting before saving', async () => {
        const ctx = mount();
        // Core's sync button took `intro` out of `localizedFields`; a sync fill did the same to `excerpt`.
        ctx.localizedFields.value.push('seo_title');
        store.container('base').delivered = { intro: filled(), seo_title: filled(), excerpt: filled({ value: 'Origin', sync: true }) };
        const api = confirming(ctx);
        const save = hooks({ api });

        await run(save.saving, {});
        await run(save.saved, savedPayload());

        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/confirm', { reference: 'entry::de', delivered: ['seo_title'] });
        expect(store.container('base').notSaved).toEqual([]);
        expect(toasts).toEqual([]);
    });

    it('still lists a filled field that a condition hid, which the saving hook left out of the save', async () => {
        const ctx = mount();
        ctx.localizedFields.value.push('intro', 'seo_title');
        store.container('base').delivered = { intro: filled(), seo_title: filled() };
        hide(ctx, 'intro');
        const api = confirming(ctx);
        const save = hooks({ api });

        await run(save.saving, {});
        await run(save.saved, savedPayload());

        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/confirm', { reference: 'entry::de', delivered: ['intro', 'seo_title'] });
        expect(store.container('base').notSaved).toEqual(['intro']);
        expect(toasts).toEqual([['error', 'Intro was not saved because it was hidden when you saved. Show it and translate it again.', { duration: 10_000 }]]);
    });

    it('ends a finished translation: the summary and Undo no longer apply', async () => {
        mount();
        Object.assign(store.container('base'), { summary: { delivered: ['intro'] }, snapshot: { fields: {} }, request: { mode: 'missing' } });
        store.setPhase('base', 'done');

        await run(hooks().saved, savedPayload());

        expect(store.container('base')).toMatchObject({ phase: 'idle', summary: null, snapshot: null, request: null });
    });

    it('does not list a field whose fieldtype re-emits the saved text with other line breaks', async () => {
        const ctx = mount();

        // A Markdown or code field holding a value stored with CRLF; CodeMirror passes it up with LF.
        await run(hooks().saved, respond(ctx, { title: 'Startseite', intro: 'Line one\r\nLine two' }));
        reemit(ctx, 'intro', 'Line one\nLine two');
        await vi.advanceTimersByTimeAsync(SETTLE_MS);

        expect(ctx.values.value.intro).toBe('Line one\nLine two');
        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('keeps listing a field whose fieldtype re-emits the saved values with other text after the hooks', async () => {
        const ctx = mount();

        // No fieldtype of core 6.35.0 does this once line breaks read alike. A re-emit reaches the form as typing does,
        // so the field reads as edited until the next switch (one that keeps inheriting, again after each save):
        // skipped, never overwritten.
        await run(hooks().saved, respond(ctx, { title: 'Startseite', intro: 'Intro ' }));
        reemit(ctx, 'intro', 'Intro');
        await vi.advanceTimersByTimeAsync(SETTLE_MS * 2);

        expect(registry.dirtyHandles('base')).toEqual(['intro']);
    });

    it('keeps a correction typed right after the save as an edit, so a translation leaves it alone', async () => {
        const ctx = mount();
        await run(hooks().saved, respond(ctx, { title: 'Startseite', intro: 'Intro' }));

        ctx.values.value.title = 'Startseite, korrigiert';
        await vi.advanceTimersByTimeAsync(SETTLE_MS * 2);

        expect(registry.dirtyHandles('base')).toEqual(['title']);
    });

    it('ignores a stacked editor saving another entry', async () => {
        mount();
        const api = { post: vi.fn() };
        store.container('base').delivered = { intro: { plan: 'p1' } };

        expect((await run(hooks({ api }).saved, savedPayload('entry::related'))).resolved).toBe(true);
        expect(api.post).not.toHaveBeenCalled();
        expect(store.container('base').delivered).toEqual({ intro: { plan: 'p1' } });
    });

    it('takes the response\'s reference over the one the form was opened with', async () => {
        mount('entry::el');
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };

        // Switched in place from German to Greek: the hook's own reference still names the German entry.
        await run(hooks({ api }).saved, { collection: 'pages', reference: 'entry::de', response: { data: { data: { reference: 'entry::el' } } } });

        expect(api.post).toHaveBeenCalledWith('editor/confirm', { reference: 'entry::el', delivered: [] });
    });

    it('confirms a term saved by its form, whose response names no reference', async () => {
        const ctx = mount('term::topics::food::el');
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };
        store.container('base').delivered = { description: { plan: 'p1' } };
        ctx.localizedFields.value.push('description');
        const response = { data: { data: { id: 'topics::food', title: 'Food', edit_url: 'http://localhost/cp/taxonomies/topics/terms/food/el' } } };

        // As core's term form runs `term.saved`: with the reference it was opened with.
        await run(hooks({ api }).saved, { taxonomy: 'topics', reference: 'term::topics::food::el', response });

        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/confirm', { reference: 'term::topics::food::el', delivered: ['description'] });
    });

    it('confirms the term open in the form after an in-place switch to another site', async () => {
        mount('term::topics::food::de');
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };

        await run(hooks({ api }).saved, { taxonomy: 'topics', reference: 'term::topics::food::el', response: { data: { data: { id: 'topics::food' } } } });

        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/confirm', { reference: 'term::topics::food::de', delivered: [] });
    });

    it('ignores a stacked editor saving another term', async () => {
        mount('term::topics::food::el');
        const api = { post: vi.fn() };

        await run(hooks({ api }).saved, { taxonomy: 'topics', reference: 'term::topics::wellness::en', response: { data: { data: { id: 'topics::wellness' } } } });
        await run(hooks({ api }).saved, { taxonomy: 'tags', reference: 'term::tags::food::en', response: { data: { data: { id: 'tags::food' } } } });

        expect(api.post).not.toHaveBeenCalled();
    });

    it('does nothing on a form without a banner', async () => {
        const api = { post: vi.fn() };

        expect((await run(hooks({ api }).saved, savedPayload())).resolved).toBe(true);
        expect(api.post).not.toHaveBeenCalled();
    });

    it('reports a failed confirm request and still resolves', async () => {
        mount();
        const api = { post: vi.fn(async () => Promise.reject(new ApiError({ status: 500, code: null, message: 'Server error', data: null }))) };

        expect((await run(hooks({ api }).saved, savedPayload())).resolved).toBe(true);
        expect(toasts).toEqual([['error', 'The translation status could not be updated: Server error', undefined]]);
        expect(store.container('base').status).toEqual({ translatable: true, stale: true });
    });

    it('stays quiet about a 403', async () => {
        mount();
        const api = { post: vi.fn(async () => Promise.reject(new ApiError({ status: 403, code: null, message: 'Forbidden', data: null }))) };

        await run(hooks({ api }).saved, savedPayload());

        expect(toasts).toEqual([]);
    });

    it('never rejects, which would fail the save', async () => {
        mount();
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const broken = { ...registry, refreshClean: () => { throw new Error('boom'); } };

        expect((await run(hooks({ registry: broken }).saved, savedPayload())).resolved).toBe(true);
        expect(error).toHaveBeenCalled();
        error.mockRestore();
    });

    it('does not put the status into a container that switched while the confirm ran', async () => {
        mount();
        let answer;
        const api = { post: vi.fn(() => new Promise((resolve) => (answer = resolve))) };

        const outcome = run(hooks({ api }).saved, savedPayload());
        await vi.advanceTimersByTimeAsync(0);
        store.switched('base', 'entry::el', 60_000);
        answer({ ...freshStatus(), not_saved: ['intro'] });
        await outcome;

        expect(store.container('base')).toMatchObject({ reference: 'entry::el', status: null, notSaved: [] });
    });
});

describe('entry.published', () => {
    // An origin in a collection with revisions, as `editor/status` reads it once Publish made its working copy the entry.
    const publishedStatus = () => ({
        translatable: true,
        origin: { localizations: [{ site: 'el', exists: true, status: 'outdated' }], auto_update: 'publish', auto_update_sites: ['el'] },
    });

    // As core's PublishActions runs `entry.published`: with the reference the form was opened with, and the publish
    // request's response, which holds no form values the editor posted (Publish posts none).
    const publishedPayload = (reference = 'entry::en') => ({
        collection: 'pages',
        reference: 'entry::stale',
        message: null,
        response: { data: { data: { reference, values: {} }, saved: true } },
    });

    const statusApi = (get = async () => publishedStatus()) => ({ post: vi.fn(), get: vi.fn(get) });

    it('reads the status again and hands it on as a publish\'s, so the outdated localizations and the update it started show', async () => {
        mount('entry::en');
        const api = statusApi();
        const onConfirmed = vi.fn();

        const outcome = await run(hooks({ api, onConfirmed }).published, publishedPayload());
        await vi.advanceTimersByTimeAsync(0);

        expect(outcome.resolved).toBe(true);
        expect(api.get).toHaveBeenCalledExactlyOnceWith('editor/status', { reference: 'entry::en' });
        expect(api.post).not.toHaveBeenCalled();
        expect(store.container('base').status).toEqual(publishedStatus());
        expect(onConfirmed).toHaveBeenCalledExactlyOnceWith('base', publishedStatus(), { published: true });
        expect(toasts).toEqual([]);
    });

    // On a published entry, Save puts the changes in the working copy, and the status read after it says "Publish first"
    // (`unpublished_changes`). Publish posts no form values and runs no save hook, so only this read drops the note.
    it('replaces the status read after the save, so the origin no longer reads as having unpublished changes', async () => {
        mount('entry::en');
        const unpublished = { translatable: true, origin: { translates_published: true, unpublished_changes: true, localizations: [] } };
        const published = { translatable: true, origin: { translates_published: true, unpublished_changes: false, localizations: [] } };
        store.container('base').status = unpublished;
        const api = statusApi(async () => published);
        const onConfirmed = vi.fn();

        await run(hooks({ api, onConfirmed }).published, publishedPayload());
        await vi.advanceTimersByTimeAsync(0);

        expect(store.container('base').status).toEqual(published);
        expect(onConfirmed).toHaveBeenCalledExactlyOnceWith('base', published, { published: true });
    });

    it('resolves at once, so core finishes the publish, or leaves for the listing, without waiting for the status', async () => {
        mount('entry::en');
        const api = statusApi(() => new Promise(() => {}));

        expect((await run(hooks({ api }).published, publishedPayload())).resolved).toBe(true);
        expect(api.get).toHaveBeenCalledOnce();
    });

    it('takes the response\'s reference over the one the form was opened with', async () => {
        mount('entry::el');
        const api = statusApi();

        await run(hooks({ api }).published, { ...publishedPayload('entry::el'), reference: 'entry::de' });
        await vi.advanceTimersByTimeAsync(0);

        expect(api.get).toHaveBeenCalledExactlyOnceWith('editor/status', { reference: 'entry::el' });
    });

    it('ignores a stacked editor publishing another entry, and a form without a banner', async () => {
        const api = statusApi();
        const onConfirmed = vi.fn();

        expect((await run(hooks({ api, onConfirmed }).published, publishedPayload())).resolved).toBe(true);

        mount('entry::en');
        expect((await run(hooks({ api, onConfirmed }).published, publishedPayload('entry::related'))).resolved).toBe(true);
        await vi.advanceTimersByTimeAsync(0);

        expect(api.get).not.toHaveBeenCalled();
        expect(onConfirmed).not.toHaveBeenCalled();
    });

    it('reports a status that could not be read, stays quiet about a 403, and still resolves', async () => {
        mount('entry::en');
        const failing = statusApi(async () => Promise.reject(new ApiError({ status: 500, code: null, message: 'Server error', data: null })));

        expect((await run(hooks({ api: failing }).published, publishedPayload())).resolved).toBe(true);
        await vi.advanceTimersByTimeAsync(0);

        expect(toasts).toEqual([['error', 'The translation status could not be updated: Server error', undefined]]);
        expect(store.container('base').status).toEqual({ translatable: true, stale: true });

        const forbidden = statusApi(async () => Promise.reject(new ApiError({ status: 403, code: null, message: 'Forbidden', data: null })));

        await run(hooks({ api: forbidden }).published, publishedPayload());
        await vi.advanceTimersByTimeAsync(0);

        expect(toasts).toHaveLength(1);
    });

    it('does not put the status into a container that switched while it was read', async () => {
        mount('entry::en');
        let answer;
        const api = statusApi(() => new Promise((resolve) => (answer = resolve)));
        const onConfirmed = vi.fn();

        await run(hooks({ api, onConfirmed }).published, publishedPayload());
        store.switched('base', 'entry::el', 60_000);
        answer(publishedStatus());
        await vi.advanceTimersByTimeAsync(0);

        expect(store.container('base')).toMatchObject({ reference: 'entry::el', status: null });
        expect(onConfirmed).not.toHaveBeenCalled();
    });
});

describe('entry.published while a translation fills the form', () => {
    // A localization in a collection with revisions: its title is its own, its intro inherits the origin's. Core's
    // `desyncField` localizes a field the editor or a fill changes.
    const stored = () => ({ title: 'Startseite', intro: 'Welcome' });

    function form() {
        const ctx = {
            name: ref('base'),
            reference: ref('entry::de'),
            values: ref(stored()),
            meta: ref({ title: null, intro: null }),
            localizedFields: ref(['title']),
            setFieldMeta: vi.fn((handle, value) => (ctx.meta.value[handle] = value)),
            setFieldValue: vi.fn((handle, value) => (ctx.values.value[handle] = value)),
            desyncField: vi.fn((handle) => {
                if (!ctx.localizedFields.value.includes(handle)) {
                    ctx.localizedFields.value.push(handle);
                }

                dirty.add('base');
            }),
            withoutDirtying: vi.fn((callback) => callback()),
        };

        registry.attachContext('base', ctx);
        store.switched('base', 'entry::de', 1_000);
        store.container('base').status = freshStatus();

        return ctx;
    }

    // A translation as `translations.start()` runs it: the snapshot taken when it started, its fills recorded in the
    // container's delivered map, and `stop` as `translations.abort()` (no fill lands any more, a busy phase goes idle).
    function translating(ctx, fields) {
        const container = store.container('base');
        const translation = {};
        let active = true;

        container.snapshot = snapshot(ctx, Object.keys(ctx.values.value), container.delivered);
        store.setPhase('base', 'working');

        const filling = applyFields(ctx, fields, {
            reference: 'entry::de',
            plan: 'plan-1',
            planValues: clone(ctx.values.value),
            delivered: container.delivered,
            active: () => active,
        });

        const stop = vi.fn((name) => {
            active = false;

            if (['planning', 'working'].includes(store.phaseOf(name))) {
                store.setPhase(name, 'idle');
            }
        });

        return { filling, stop, running: () => (active ? translation : null) };
    }

    const api = () => ({ get: vi.fn(async () => freshStatus()), post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) });

    const payload = (reference = 'entry::de') => ({
        collection: 'pages',
        reference: 'entry::de',
        message: null,
        response: { data: { data: { reference, values: stored() }, saved: true } },
    });

    // Core's PublishActions after its request: the `entry.published` hooks, then, with "continue editing", the form's
    // `publishActionCompleted()`, which clears the dirty state and puts the published values into the form, keeping
    // its localized fields and meta.
    async function publish(save, ctx, reference = 'entry::de') {
        const outcome = await run(save.published, payload(reference));

        dirty.delete('base');
        ctx.values.value = stored();

        return outcome;
    }

    const discarded = ['info', 'Publishing discarded the unsaved translation. Translate again to fill in the form.'];

    it('takes back what it filled, which Publish replaces, so the next save stores no origin text as the localization\'s own', async () => {
        const ctx = form();
        const service = api();
        const translation = translating(ctx, { intro: { value: '[de] Willkommen', meta: { counter: 1 } } });
        await vi.advanceTimersByTimeAsync(REEMIT_MS);

        expect(ctx.localizedFields.value).toEqual(['title', 'intro']);

        const save = hooks({ api: service, running: translation.running, stop: translation.stop });

        expect((await publish(save, ctx)).resolved).toBe(true);
        expect(translation.stop).toHaveBeenCalledExactlyOnceWith('base');
        expect(ctx.values.value).toEqual(stored());
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(ctx.meta.value).toEqual({ title: null, intro: null });
        expect(store.container('base')).toMatchObject({ phase: 'idle', delivered: {}, summary: null, snapshot: null, request: null });
        expect(toasts).toEqual([discarded]);

        // The next save confirms nothing as translated.
        await run(save.saved, respond(ctx, stored()));

        expect(service.post).toHaveBeenCalledExactlyOnceWith('editor/confirm', { reference: 'entry::de', delivered: [] });
    });

    it('takes back a fill still being read back, which an aborted translation stops recording', async () => {
        const ctx = form();
        const translation = translating(ctx, { intro: { value: '[de] Willkommen' } });
        await vi.advanceTimersByTimeAsync(0);

        expect(store.container('base').delivered.intro).toMatchObject({ pending: true });

        await publish(hooks({ api: api(), running: translation.running, stop: translation.stop }), ctx);
        await vi.advanceTimersByTimeAsync(REEMIT_MS);
        await translation.filling;

        expect(ctx.values.value).toEqual(stored());
        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(store.container('base').delivered).toEqual({});
    });

    it('takes back the fills of a translation that finished while the Publish dialog was open', async () => {
        const ctx = form();
        const translation = translating(ctx, { intro: { value: '[de] Willkommen' } });
        await vi.advanceTimersByTimeAsync(REEMIT_MS);
        Object.assign(store.container('base'), { summary: { delivered: ['intro'] }, request: { mode: 'missing' } });
        store.setPhase('base', 'done');

        await publish(hooks({ api: api(), running: () => null, stop: translation.stop }), ctx);

        expect(ctx.localizedFields.value).toEqual(['title']);
        expect(store.container('base')).toMatchObject({ phase: 'idle', delivered: {}, summary: null, snapshot: null, request: null });
        expect(toasts).toEqual([discarded]);
    });

    it('stops a translation still planning, whose fills would land after core put the published values in', async () => {
        const ctx = form();
        const translation = translating(ctx, {});
        store.setPhase('base', 'planning');

        await publish(hooks({ api: api(), running: translation.running, stop: translation.stop }), ctx);

        expect(translation.stop).toHaveBeenCalledExactlyOnceWith('base');
        expect(store.container('base')).toMatchObject({ phase: 'idle', snapshot: null });
        expect(toasts).toEqual([discarded]);
    });

    it('takes the publish as the form\'s last change, so the next translation waits out core\'s window without dirty tracking', async () => {
        const ctx = form();

        await publish(hooks({ api: api(), stop: vi.fn() }), ctx);

        expect(store.container('base').lastChangeAt).toBe(50_000);
        expect(SETTLE_MS).toBeGreaterThan(500);
    });

    it('says nothing for a busy phase with no translation behind it', async () => {
        const ctx = form();
        store.setPhase('base', 'working');

        await publish(hooks({ api: api(), running: () => null, stop: vi.fn() }), ctx);

        expect(store.container('base').phase).toBe('idle');
        expect(toasts).toEqual([]);
    });

    it('leaves the form and the banner alone when nothing was filled since the last save', async () => {
        const ctx = form();
        store.setPhase('base', 'offer');

        await publish(hooks({ api: api(), stop: vi.fn() }), ctx);

        expect(ctx.setFieldValue).not.toHaveBeenCalled();
        expect(store.container('base').phase).toBe('offer');
        expect(toasts).toEqual([]);
    });

    it('leaves a translation alone when a stacked editor publishes another entry', async () => {
        const ctx = form();
        const translation = translating(ctx, { intro: { value: '[de] Willkommen' } });
        await vi.advanceTimersByTimeAsync(REEMIT_MS);

        await run(hooks({ api: api(), running: translation.running, stop: translation.stop }).published, payload('entry::related'));

        expect(translation.stop).not.toHaveBeenCalled();
        expect(ctx.values.value.intro).toBe('[de] Willkommen');
        expect(Object.keys(store.container('base').delivered)).toEqual(['intro']);
        expect(store.container('base').phase).toBe('working');
    });
});
