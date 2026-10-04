import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { ApiError } from '../api.js';
import { SETTLE_MS } from './fill.js';
import { createRegistry } from './registry.js';
import { useEditorStore } from './store.js';
import { createTranslations, remainingHandles, skipHandles, slugToKeep, undoable } from './translate.js';

let store;
let registry;
let dirty;

beforeEach(() => {
    dirty = new Set();
    vi.stubGlobal('Statamic', {
        $pinia: { defineStore },
        $config: { get: (key) => ({ 'tolk.concurrency': 3 })[key] },
        $dirty: { add: (name) => dirty.add(name), remove: (name) => dirty.delete(name), has: (name) => dirty.has(name) },
    });
    vi.stubGlobal('__', (key) => key);
    setActivePinia(createPinia());
    store = useEditorStore();
    registry = createRegistry();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

const stored = () => ({ title: 'Startseite', intro: 'A family-run hotel.', seo_title: 'Kyma Bay Hotel', slug: 'home' });

const field = (status, label) => ({ status, label, source_changed: null, incomplete: false });

const status = (fields = {}) => ({
    translatable: true,
    localization: {
        fields: {
            title: field('manual', 'Title'),
            intro: field('untranslated', 'Intro'),
            seo_title: field('untranslated', 'SEO title'),
            ...fields,
        },
        counts: { untranslated: 2 },
    },
    defaults: { translator: 'anthropic:claude-sonnet-5-5', providers_configured: true, fake_engine: false },
});

// The base container of the German home page, attached as the banner attaches it.
function mount({ reference = 'entry::de', values = stored(), localized = ['title'], fields = {} } = {}) {
    const ctx = {
        name: ref('base'),
        reference: ref(reference),
        site: ref('de'),
        values: ref(values),
        meta: ref({ title: null, intro: null, seo_title: null, slug: null }),
        localizedFields: ref(localized),
        readOnly: ref(false),
        setFieldMeta: vi.fn((handle, meta) => (ctx.meta.value[handle] = meta)),
        setFieldValue: vi.fn((handle, value) => (ctx.values.value[handle] = value)),
        desyncField: vi.fn((handle) => {
            if (!ctx.localizedFields.value.includes(handle)) {
                ctx.localizedFields.value.push(handle);
            }

            dirty.add('base');
        }),
        withoutDirtying: (callback) => callback(),
    };

    registry.attachContext('base', ctx);
    store.switched('base', reference, 0);
    store.container('base').status = status(fields);

    return ctx;
}

const planResponse = (extra = {}) => ({
    plan: 'p1',
    units: [{ id: 'b1', fields: ['intro', 'seo_title'], chars: 40 }],
    fields: ['intro', 'seo_title'],
    skipped: [],
    ready: [],
    ...extra,
});

const summary = (extra = {}) => ({
    delivered: [],
    incomplete: [],
    skipped: {},
    failed: {},
    failedUnits: [],
    cancelledUnits: [],
    warnings: [],
    usage: {},
    models: [],
    stopped: null,
    aborted: false,
    stale: false,
    units: { total: 1, done: 1 },
    ...extra,
});

// An API whose `editor/plan` answers are queued by the test (a value, an error, or a deferred's promise).
function fakeApi(...answers) {
    return {
        post: vi.fn(async (path) => {
            if (path !== 'editor/plan') {
                throw new Error(`Unexpected request to ${path}.`);
            }

            const answer = answers.shift() ?? planResponse();

            if (answer instanceof Error) {
                throw answer;
            }

            return answer;
        }),
    };
}

function translations(overrides = {}) {
    return createTranslations({ store: () => store, registry, api: fakeApi(), run: vi.fn(async () => summary()), fill: vi.fn(), undoFill: vi.fn(), ...overrides });
}

const base = () => store.containers.base;

describe('skipHandles', () => {
    it('lists the fields changed in the form', () => {
        const ctx = mount();
        ctx.values.value.intro = 'Mein eigener Text';
        ctx.values.value.slug = 'other';

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual(['intro']);
    });

    it('adds a field localized since the clean values were taken when nothing is stored for it', () => {
        const ctx = mount();
        ctx.localizedFields.value.push('seo_title');

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual(['seo_title']);
    });

    it('leaves out a newly localized field that has a stored value', () => {
        const ctx = mount({ localized: [], fields: { intro: field('current', 'Intro') } });
        ctx.localizedFields.value.push('intro');

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual([]);
    });

    it('leaves out a field the form localized before the clean values were taken', () => {
        const ctx = mount({ localized: ['title', 'intro'] });

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual([]);
    });

    it('leaves out a field filled earlier that the editor has not touched, and keeps one they changed', () => {
        const ctx = mount();
        const delivered = {
            intro: { plan: 'p0', value: '[de] Intro', pending: false },
            seo_title: { plan: 'p0', value: '[de] SEO', pending: false },
        };
        ctx.values.value.intro = '[de] Intro';
        ctx.values.value.seo_title = '[de] SEO, edited';
        ctx.localizedFields.value.push('intro', 'seo_title');

        expect(skipHandles(ctx, base().status, { name: 'base', registry, delivered })).toEqual(['seo_title']);
    });

    it('is empty without fields in the status', () => {
        const ctx = mount();
        ctx.values.value.intro = 'Changed';

        expect(skipHandles(ctx, { localization: { fields: [] } }, { name: 'base', registry })).toEqual([]);
        expect(skipHandles(ctx, null, { name: 'base', registry })).toEqual([]);
    });
});

describe('remainingHandles', () => {
    it('lists the planned fields neither filled nor skipped', () => {
        expect(remainingHandles({ planned: ['title', 'intro', 'seo_title', 'body'], delivered: ['intro'], skipped: { body: 'edited' } })).toEqual([
            'title',
            'seo_title',
        ]);
        expect(remainingHandles(null)).toEqual([]);
    });
});

describe('undoable', () => {
    const snap = { fields: { intro: { delivered: null }, seo_title: { delivered: { plan: 'p0' } } } };

    it('is true once a field of the snapshot was filled', () => {
        expect(undoable({ snapshot: snap, delivered: { intro: { plan: 'p1', pending: false } } })).toBe(true);
    });

    it('is false while the fill is pending, for an earlier plan\'s record, or without a snapshot', () => {
        expect(undoable({ snapshot: snap, delivered: { intro: { plan: 'p1', pending: true } } })).toBe(false);
        expect(undoable({ snapshot: snap, delivered: { seo_title: { plan: 'p0', pending: false } } })).toBe(false);
        expect(undoable({ snapshot: null, delivered: { intro: { plan: 'p1' } } })).toBe(false);
        expect(undoable(null)).toBe(false);
    });
});

describe('translate', () => {
    it('plans with the unsaved edits as skip, then runs the plan and fills each response', async () => {
        const ctx = mount();
        ctx.values.value.intro = 'Mein eigener Text';
        const api = fakeApi(planResponse({ skipped: { intro: 'edited_in_form', title: 'manual' } }));
        const fill = vi.fn(async () => ({ stale: false, applied: ['seo_title'], skipped: {}, repeated: [] }));
        const run = vi.fn(async ({ onFields }) => {
            await onFields({ seo_title: { value: '[de] Kyma Bay Hotel', meta: null } });

            return summary({ delivered: ['seo_title'], models: ['anthropic:claude-sonnet-5-5'] });
        });
        const t = translations({ api, run, fill });

        const result = await t.translate('base', { mode: 'missing' });

        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/plan', {
            reference: 'entry::de',
            mode: 'missing',
            overwrite_manual: false,
            fields: null,
            skip: ['intro'],
            translator: null,
        });

        const options = run.mock.calls[0][0];

        expect(options.plan).toEqual(planResponse({ skipped: { intro: 'edited_in_form', title: 'manual' } }));
        expect(options.concurrency).toBe(3);
        expect(options.signal).toBeInstanceOf(AbortSignal);

        const [filledCtx, payloads, fillOptions] = fill.mock.calls[0];

        expect(filledCtx).toBe(ctx);
        expect(payloads).toEqual({ seo_title: { value: '[de] Kyma Bay Hotel', meta: null } });
        expect(fillOptions).toMatchObject({ reference: 'entry::de', plan: 'p1', planValues: { ...stored(), intro: 'Mein eigener Text' } });
        expect(fillOptions.delivered).toBe(base().delivered);

        base().lastChangeAt = 4_000;
        expect(fillOptions.lastChangeAt()).toBe(4_000);

        expect(result).toMatchObject({ plan: 'p1', planned: ['intro', 'seo_title'], delivered: ['seo_title'], skipped: { intro: 'edited' }, cancelled: false });
        expect(result.planSkipped).toEqual({ title: 'manual' });
        expect(base()).toMatchObject({ phase: 'done', summary: result, planReference: 'entry::de', error: null });
        expect(base().request).toEqual({ mode: 'missing', fields: null, overwriteManual: false, translator: null });
        expect(t.running('base')).toBe(false);
    });

    it('sends the requested fields, the overwrite flag and the translator', async () => {
        mount();
        const api = fakeApi();
        const t = translations({ api });

        await t.translate('base', { mode: 'all', fields: ['intro'], overwriteManual: true, translator: 'openai:gpt-6-luna' });

        expect(api.post.mock.calls[0][1]).toMatchObject({ mode: 'all', fields: ['intro'], overwrite_manual: true, translator: 'openai:gpt-6-luna' });
    });

    it('keeps no plan reasons when the plan skipped nothing', async () => {
        mount();

        const result = await translations().translate('base');

        expect(result.planSkipped).toEqual({});
        expect(result.skipped).toEqual({});
    });

    it('takes the plan values and the snapshot when it is asked, before the plan returns', async () => {
        const ctx = mount();
        const plan = deferred();
        const fill = vi.fn();
        const t = translations({ api: fakeApi(plan.promise), fill, run: async ({ onFields }) => (onFields({}), summary()) });

        const promise = t.translate('base');
        ctx.values.value.intro = 'Typed while the plan was made';
        plan.resolve(planResponse());
        await promise;

        expect(fill.mock.calls[0][2].planValues.intro).toBe('A family-run hotel.');
        expect(Object.keys(base().snapshot.fields)).toEqual(['title', 'intro', 'seo_title', 'slug']);
        expect(base().snapshot.fields.intro.value).toBe('A family-run hotel.');
    });

    it('takes the snapshot of the requested fields only', async () => {
        mount();
        const t = translations();

        await t.translate('base', { fields: ['seo_title'] });

        expect(Object.keys(base().snapshot.fields)).toEqual(['seo_title']);
    });

    it('goes through planning and working, and passes the progress on', async () => {
        mount();
        const plan = deferred();
        const finish = deferred();
        let report;
        const t = translations({
            api: fakeApi(plan.promise),
            run: ({ onProgress }) => {
                report = onProgress;

                return finish.promise;
            },
        });

        const promise = t.translate('base');
        expect(base().phase).toBe('planning');
        expect(t.running('base')).toBe(true);

        plan.resolve(planResponse({ units: [{ id: 'b1' }, { id: 'b2' }] }));
        await flush();
        expect(base().phase).toBe('working');
        expect(base().progress).toEqual({ total: 2, done: 0, waiting: 0, cancelling: false });

        report({ total: 3, done: 1, waiting: 1 });
        expect(base().progress).toEqual({ total: 3, done: 1, waiting: 1, cancelling: false });

        finish.resolve(summary());
        await promise;
        expect(base().phase).toBe('done');
    });

    it('does not start twice', async () => {
        mount();
        const plan = deferred();
        const api = fakeApi(plan.promise);
        const t = translations({ api });

        const first = t.translate('base');
        expect(await t.translate('base')).toBeNull();

        plan.resolve(planResponse());
        await first;

        expect(api.post).toHaveBeenCalledOnce();
    });

    it('does nothing without a context or a reference', async () => {
        const api = fakeApi();

        expect(await translations({ api }).translate('base')).toBeNull();

        mount({ reference: '' });
        expect(await translations({ api }).translate('base')).toBeNull();
        expect(api.post).not.toHaveBeenCalled();
    });

    it('shows a failed plan request as an error and can start again', async () => {
        mount();
        const api = fakeApi(new ApiError({ status: 422, code: 'no_translator', message: 'No model is configured.', data: null }));
        const t = translations({ api });

        expect(await t.translate('base')).toBeNull();
        expect(base()).toMatchObject({ phase: 'error', error: { status: 422, code: 'no_translator', message: 'No model is configured.' } });
        expect(t.running('base')).toBe(false);

        await t.translate('base');
        expect(base().phase).toBe('done');
    });

    it('shows a stopped run as an error and keeps what it filled', async () => {
        mount();
        const stopped = { status: 409, code: 'source_changed', message: 'The origin was changed.' };
        const t = translations({ run: async () => summary({ delivered: ['intro'], stopped }) });

        await t.translate('base');

        expect(base()).toMatchObject({ phase: 'error', error: stopped, summary: { delivered: ['intro'], stopped } });
    });

    it('never leaves the phase on working when something throws', async () => {
        mount();
        const t = translations({
            run: async () => {
                throw new Error('Unexpected');
            },
        });

        await t.translate('base');

        expect(base()).toMatchObject({ phase: 'error', error: { message: 'Unexpected' } });
        expect(t.running('base')).toBe(false);
    });

    it('drops the plan when the editor switched away while it was made', async () => {
        mount();
        const plan = deferred();
        const run = vi.fn();
        const t = translations({ api: fakeApi(plan.promise), run });

        const promise = t.translate('base');
        t.abort('base');
        store.switched('base', 'entry::el', 5_000);
        plan.resolve(planResponse());

        expect(await promise).toBeNull();
        expect(run).not.toHaveBeenCalled();
        expect(base()).toMatchObject({ reference: 'entry::el', phase: 'idle', summary: null });
    });

    it('stops filling after a switch, also when the editor comes back to the same item', async () => {
        mount();
        const finish = deferred();
        let options;
        const t = translations({
            run: (args) => {
                options = args;

                return finish.promise;
            },
        });

        const promise = t.translate('base');
        await flush();

        t.abort('base');
        store.switched('base', 'entry::de', 5_000);

        expect(options.signal.aborted).toBe(true);
        finish.resolve(summary({ delivered: ['intro'] }));

        expect(await promise).toBeNull();
        expect(base()).toMatchObject({ phase: 'idle', summary: null });
    });

    it('tells the fill it is no longer active after a switch', async () => {
        mount();
        const finish = deferred();
        const fill = vi.fn();
        let onFields;
        const t = translations({
            fill,
            run: (args) => {
                onFields = args.onFields;

                return finish.promise;
            },
        });

        t.translate('base');
        await flush();
        onFields({ intro: {} });
        const active = fill.mock.calls[0][2].active;

        expect(active()).toBe(true);
        t.abort('base');
        expect(active()).toBe(false);

        finish.resolve(summary());
    });

    it('cancels: no new unit starts, and the result says so', async () => {
        mount();
        const plan = deferred();
        const finish = deferred();
        let signal;
        const t = translations({
            api: fakeApi(plan.promise),
            run: (args) => {
                signal = args.signal;

                return finish.promise;
            },
        });

        const promise = t.translate('base');
        t.cancel('base');
        expect(base().phase).toBe('planning');

        plan.resolve(planResponse());
        await flush();
        t.cancel('base');

        expect(signal.aborted).toBe(true);
        expect(base().progress.cancelling).toBe(true);
        expect(t.running('base')).toBe(true);

        finish.resolve(summary({ delivered: ['intro'], aborted: true }));
        await promise;

        expect(base()).toMatchObject({ phase: 'done', summary: { cancelled: true, delivered: ['intro'] } });
    });

    it('keeps the cancelling flag when progress arrives afterwards', async () => {
        mount();
        const finish = deferred();
        let report;
        const t = translations({
            run: ({ onProgress }) => {
                report = onProgress;

                return finish.promise;
            },
        });

        t.translate('base');
        await flush();
        t.cancel('base');
        report({ total: 1, done: 1, waiting: 0 });

        expect(base().progress.cancelling).toBe(true);
        finish.resolve(summary());
    });
});

describe('a localization created in the form', () => {
    // What the offer module records on `localization.created`, with the origin's slug from the status.
    function created(extra = {}) {
        const ctx = mount();
        base().created = true;
        base().status.localization.origin_slug = 'home';
        Object.assign(base(), extra);

        return ctx;
    }

    const filled = { stale: false, applied: ['intro'], skipped: {}, repeated: [] };

    it('needs the creation, a slug not kept yet and the origin slug', () => {
        const status = { localization: { origin_slug: 'home' } };

        expect(slugToKeep({ created: true, slugKept: false, status })).toBe('home');
        expect(slugToKeep({ created: false, slugKept: false, status })).toBeNull();
        expect(slugToKeep({ created: true, slugKept: true, status })).toBeNull();
        expect(slugToKeep({ created: true, slugKept: false, status: { localization: {} } })).toBeNull();
        expect(slugToKeep({ created: true, slugKept: false, status: null })).toBeNull();
        expect(slugToKeep(null)).toBeNull();
    });

    it('keeps the origin slug before the first fill and checks it again after the fills, while Save stays refused', async () => {
        const ctx = created();
        const order = [];
        const kept = deferred();
        const rechecked = deferred();
        const keepSlug = vi.fn(() => {
            order.push('keep');

            return kept.promise;
        });
        const recheckSlug = vi.fn(() => {
            order.push('recheck');

            return rechecked.promise;
        });
        const fill = vi.fn(async () => {
            order.push('fill');

            return filled;
        });
        const run = vi.fn(async ({ onFields }) => {
            await onFields({ intro: { value: '[de] A family-run hotel.', meta: null } });

            return summary({ delivered: ['intro'] });
        });
        const t = translations({ run, fill, keepSlug, recheckSlug });

        const promise = t.translate('base');

        expect(keepSlug).toHaveBeenCalledOnce();
        const [keptCtx, slug, options] = keepSlug.mock.calls[0];
        expect(keptCtx).toBe(ctx);
        expect(slug).toBe('home');
        expect(options.reference).toBe('entry::de');
        expect(options.active()).toBe(true);
        base().lastChangeAt = 4_000;
        expect(options.lastChangeAt()).toBe(4_000);

        await flush();
        expect(run).not.toHaveBeenCalled();
        expect(base().phase).toBe('planning');

        kept.resolve({ stale: false, kept: true, reset: true });
        await flush();
        expect(base().slugKept).toBe(true);
        expect(recheckSlug).toHaveBeenCalledExactlyOnceWith(ctx, 'home', expect.objectContaining({ reference: 'entry::de' }));
        expect(base().phase).toBe('working');

        rechecked.resolve(false);
        await promise;

        expect(order).toEqual(['keep', 'fill', 'recheck']);
        expect(base().phase).toBe('done');
    });

    it('leaves the slug alone on a later translation', async () => {
        created({ slugKept: true });
        const keepSlug = vi.fn();
        const recheckSlug = vi.fn();
        const t = translations({ keepSlug, recheckSlug, run: async () => summary({ delivered: ['intro'] }) });

        await t.translate('base', { mode: 'update' });

        expect(keepSlug).not.toHaveBeenCalled();
        expect(recheckSlug).not.toHaveBeenCalled();
    });

    it('leaves the slug of a localization that existed before the form opened alone', async () => {
        mount();
        base().status.localization.origin_slug = 'home';
        const keepSlug = vi.fn();
        const recheckSlug = vi.fn();
        const t = translations({ keepSlug, recheckSlug, run: async () => summary({ delivered: ['intro'] }) });

        await t.translate('base');

        expect(keepSlug).not.toHaveBeenCalled();
        expect(recheckSlug).not.toHaveBeenCalled();
        expect(base().phase).toBe('done');
    });

    it('does not check the slug again when nothing was filled', async () => {
        created();
        const recheckSlug = vi.fn();
        const t = translations({ keepSlug: async () => ({ stale: false, kept: true, reset: true }), recheckSlug });

        await t.translate('base');

        expect(recheckSlug).not.toHaveBeenCalled();
        expect(base()).toMatchObject({ phase: 'done', slugKept: true });
    });

    it('keeps the slug on the next try when the plan request failed, and the first try stops', async () => {
        created();
        const api = fakeApi(new ApiError({ status: 503, code: null, message: 'Unavailable.', data: null }));
        const keepSlug = vi.fn(async () => ({ stale: false, kept: true, reset: true }));
        const t = translations({ api, keepSlug });

        await t.translate('base');

        expect(base()).toMatchObject({ phase: 'error', slugKept: false });
        expect(keepSlug.mock.calls[0][2].active()).toBe(false);

        await t.retry('base');

        expect(keepSlug).toHaveBeenCalledTimes(2);
        expect(base()).toMatchObject({ phase: 'done', slugKept: true });
    });

    it('fills nothing when the editor switches while the slug is kept', async () => {
        created();
        const kept = deferred();
        const run = vi.fn();
        const t = translations({ run, keepSlug: () => kept.promise });

        const promise = t.translate('base');
        await flush();
        t.abort('base');
        store.switched('base', 'entry::el', 5_000);
        kept.resolve({ stale: true, kept: false, reset: false });

        expect(await promise).toBeNull();
        expect(run).not.toHaveBeenCalled();
        expect(base()).toMatchObject({ reference: 'entry::el', phase: 'idle', slugKept: false });
    });
});

describe('retry', () => {
    it('plans again with the same request, limited to the fields not filled yet', async () => {
        const ctx = mount();
        const stopped = { status: 410, code: 'plan_expired', message: 'Expired.' };
        const api = fakeApi(planResponse({ fields: ['title', 'intro', 'seo_title'] }), planResponse({ plan: 'p2' }));
        const t = translations({
            api,
            run: vi
                .fn()
                .mockImplementationOnce(async () => {
                    base().delivered.intro = { plan: 'p1', value: '[de] Intro', pending: false };
                    ctx.values.value.intro = '[de] Intro';

                    return summary({ delivered: ['intro'], stopped });
                })
                .mockImplementationOnce(async () => summary({ delivered: ['seo_title'] })),
        });

        await t.translate('base', { mode: 'update', translator: 'openai:gpt-6-luna' });
        await t.retry('base');

        expect(api.post.mock.calls[1][1]).toMatchObject({ mode: 'update', fields: ['title', 'seo_title'], translator: 'openai:gpt-6-luna' });
        expect(base().phase).toBe('done');

        // Undo after Start again puts back what the form held before the first translation.
        expect(base().snapshot.fields.intro.value).toBe('A family-run hotel.');
        expect(Object.keys(base().snapshot.fields).sort()).toEqual(['intro', 'seo_title', 'title']);
    });

    it('repeats the request as it was when the plan itself failed', async () => {
        mount();
        const api = fakeApi(new ApiError({ status: 0, code: null, message: 'Offline', data: null }));
        const t = translations({ api });

        await t.translate('base', { mode: 'update' });
        await t.retry('base');

        expect(api.post.mock.calls[1][1]).toMatchObject({ mode: 'update', fields: null });
    });

    it('finishes without a request when nothing is left', async () => {
        mount();
        const api = fakeApi(planResponse({ fields: ['intro'] }));
        const t = translations({ api, run: async () => summary({ delivered: ['intro'], stopped: { status: 502, code: 'provider_rejected', message: 'No.' } }) });

        await t.translate('base');
        expect(await t.retry('base')).toBeNull();

        expect(api.post).toHaveBeenCalledOnce();
        expect(base().phase).toBe('done');
    });

    it('does nothing without an earlier request or while busy', async () => {
        mount();
        const api = fakeApi(deferred().promise);
        const t = translations({ api });

        expect(t.retry('base')).toBeNull();

        t.translate('base');
        base().request = { mode: 'missing', fields: null };
        expect(t.retry('base')).toBeNull();
        expect(api.post).toHaveBeenCalledOnce();
    });
});

describe('undo', () => {
    it('restores the snapshot and returns to idle', async () => {
        const ctx = mount();
        const undoFill = vi.fn(async () => ({ restored: ['intro'], kept: [] }));
        const t = translations({ undoFill });

        await t.translate('base');
        const snap = base().snapshot;
        const result = await t.undo('base');

        expect(undoFill).toHaveBeenCalledExactlyOnceWith(ctx, snap, base().delivered, { registry });
        expect(result).toEqual({ restored: ['intro'], kept: [] });
        expect(base()).toMatchObject({ phase: 'idle', snapshot: null, summary: null, request: null });
    });

    it('does nothing while a translation runs, without a snapshot, or twice at once', async () => {
        mount();
        const undone = deferred();
        const undoFill = vi.fn(() => undone.promise);
        const t = translations({ undoFill, api: fakeApi(deferred().promise) });

        expect(await t.undo('base')).toBeNull();

        base().snapshot = { reference: 'entry::de', fields: {} };
        const first = t.undo('base');
        expect(await t.undo('base')).toBeNull();
        undone.resolve({ restored: [], kept: [] });
        await first;

        base().snapshot = { reference: 'entry::de', fields: {} };
        t.translate('base');
        expect(await t.undo('base')).toBeNull();
        expect(undoFill).toHaveBeenCalledOnce();
    });
});

describe('with the real runner and fill', () => {
    it('fills the form and records what it delivered', async () => {
        vi.useFakeTimers({ now: 100_000 });
        const ctx = mount();
        const api = {
            post: vi.fn(async (path, data) => {
                if (path === 'editor/plan') {
                    return planResponse({ ready: { slug: { value: 'home', meta: null, sync: true } } });
                }

                expect(data).toEqual({ plan: 'p1', unit: 'b1' });

                return {
                    fields: {
                        intro: { value: '[de] A family-run hotel.', meta: null, incomplete: false, warnings: [] },
                        seo_title: { value: '[de] Kyma Bay Hotel', meta: null, incomplete: true, warnings: [] },
                    },
                    spawned: [],
                    failed: [],
                    warnings: [],
                    usage: { 'anthropic:claude-sonnet-5-5': { input: 10, output: 10, calls: 1 } },
                    remaining: 0,
                };
            }),
        };
        const t = createTranslations({ store: () => store, registry, api });

        const promise = t.translate('base');
        await vi.advanceTimersByTimeAsync(SETTLE_MS + 1_000);
        const result = await promise;

        expect(ctx.values.value).toMatchObject({ intro: '[de] A family-run hotel.', seo_title: '[de] Kyma Bay Hotel' });
        expect(ctx.localizedFields.value).toEqual(['title', 'intro', 'seo_title']);
        expect(base().delivered).toMatchObject({
            intro: { plan: 'p1', value: '[de] A family-run hotel.', pending: false },
            seo_title: { plan: 'p1', value: '[de] Kyma Bay Hotel', pending: false, incomplete: true },
            slug: { plan: 'p1', sync: true },
        });
        expect(result).toMatchObject({ delivered: ['slug', 'intro', 'seo_title'], incomplete: ['seo_title'], models: ['anthropic:claude-sonnet-5-5'] });
        expect(base().phase).toBe('done');
        expect(undoable(base())).toBe(true);
        expect(dirty.has('base')).toBe(true);
    });
});
