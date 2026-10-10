import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { ApiError } from '../api.js';
import { SETTLE_MS } from './fill.js';
import { clone, createRegistry } from './registry.js';
import { createSaveHooks, notSavedMessage } from './saveHooks.js';
import { useEditorStore } from './store.js';

class PipelineStopped extends Error {}

let store;
let registry;
let toasts;

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

beforeEach(() => {
    vi.useFakeTimers({ now: 50_000 });
    toasts = [];
    vi.stubGlobal('Statamic', {
        $pinia: { defineStore },
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
    const ctx = { name: ref('base'), reference: ref(reference), values: ref({ title: 'Startseite', intro: 'Intro' }), localizedFields: ref(['title']) };

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
        ]);
    });
});

describe('entry.saving', () => {
    it.each(['planning', 'working'])('refuses a save while the translation is %s', async (phase) => {
        mount();
        store.setPhase('base', phase);

        const outcome = await run(hooks().saving, { collection: 'pages', values: {} });

        expect(outcome.resolved).toBe(false);
        expect(outcome.reason).toBeInstanceOf(PipelineStopped);
        expect(toasts).toEqual([['error', 'A translation is in progress. Wait for it to finish, or cancel it.', undefined]]);
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
});

describe('entry.saved', () => {
    it('confirms the delivered fields and shows the fresh status', async () => {
        const ctx = mount();
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };
        store.container('base').delivered = { intro: { plan: 'p1' }, seo_title: { plan: 'p1' } };
        ctx.values.value.intro = '[de] Intro';

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

        const message = 'Intro, SEO title were not saved because they were hidden when you saved. Show them and save again.';

        expect(store.container('base').notSaved).toEqual(['intro', 'seo_title']);
        expect(toasts).toEqual([['error', message, { duration: 10_000 }]]);
        expect(notSavedMessage(['intro'], freshStatus())).toBe('Intro was not saved because it was hidden when you saved. Show it and save again.');
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
        mount('term::topics::food::el');
        const api = { post: vi.fn(async () => ({ ...freshStatus(), not_saved: [] })) };
        store.container('base').delivered = { description: { plan: 'p1' } };
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
