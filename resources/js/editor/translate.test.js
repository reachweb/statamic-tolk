import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { ApiError } from '../api.js';
import { restore, SETTLE_MS } from './fill.js';
import { createRegistry } from './registry.js';
import { describeSummary } from './statuses.js';
import { useEditorStore } from './store.js';
import { createTranslations, keptMessage, remainingHandles, reviewable, reviewHandles, skipHandles, slugToKeep, undoable } from './translate.js';

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

// The English origin, whose `intro` and `seo_title` the German page inherits.
const origin = () => ({ title: 'Home', intro: 'A family-run hotel.', seo_title: 'Kyma Bay Hotel', slug: 'home' });

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
function mount({ reference = 'entry::de', values = stored(), originValues = origin(), localized = ['title'], fields = {} } = {}) {
    const ctx = {
        name: ref('base'),
        reference: ref(reference),
        site: ref('de'),
        values: ref(values),
        originValues: ref(originValues),
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

// An API whose `editor/plan` and `editor/review` answers are queued by the test (a value, an error, or a deferred's
// promise).
function fakeApi(...answers) {
    return {
        post: vi.fn(async (path) => {
            if (path !== 'editor/plan' && path !== 'editor/review') {
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

    it('adds an untranslated field whose text differs from the origin\'s, also once the clean values were taken again', () => {
        const ctx = mount();
        ctx.values.value.seo_title = 'Typed while the form settled';
        ctx.localizedFields.value.push('seo_title');
        registry.refreshClean('base');

        expect(registry.dirtyHandles('base')).toEqual([]);
        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual(['seo_title']);
    });

    it('leaves out a field that desynced itself and re-emitted its value without a text change', () => {
        const paragraph = { type: 'paragraph', content: [{ type: 'text', text: 'Rooms with a view.' }] };
        const ctx = mount({ values: { ...stored(), content: [paragraph] }, originValues: { ...origin(), content: [paragraph] }, fields: { content: field('untranslated', 'Content') } });

        // Core's inherited Bard field, after the clean values were taken or before: both orders happen.
        ctx.values.value.content = [{ ...paragraph, attrs: { textAlign: null } }];
        ctx.localizedFields.value.push('content');

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual([]);

        registry.refreshClean('base');

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual([]);
    });

    it('leaves out an untranslated field the CP stored as a copy of the origin', () => {
        const ctx = mount({ localized: ['title', 'intro'] });

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual([]);
    });

    it('compares a field with a stored value with the clean values only', () => {
        const ctx = mount({ values: { ...stored(), intro: 'Ein Familienhotel.' }, localized: ['title', 'intro'], fields: { intro: field('current', 'Intro') } });

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual([]);

        ctx.values.value.intro = 'Ein kleines Familienhotel.';

        expect(skipHandles(ctx, base().status, { name: 'base', registry })).toEqual(['intro']);
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

    it('leaves out the fields a review left unchanged', () => {
        expect(remainingHandles({ planned: ['title', 'intro', 'seo_title'], delivered: ['intro'], skipped: {}, unchanged: ['title'] })).toEqual(['seo_title']);
    });
});

describe('reviewHandles', () => {
    it('lists the fields in scope the form holds a value of its own for', () => {
        const ctx = mount({ localized: ['title', 'slug', 'intro'] });

        expect(reviewHandles(ctx, base().status)).toEqual(['title', 'intro']);
        expect(reviewHandles(ctx, null)).toEqual([]);
    });
});

describe('reviewable', () => {
    const counts = (current) => ({ status: { localization: { counts: { current } } } });

    it('is true while a field is stored up to date or was filled since the last save', () => {
        expect(reviewable(counts(1))).toBe(true);
        expect(reviewable({ ...counts(0), delivered: { intro: { plan: 'p1', pending: false } } })).toBe(true);
    });

    it('is false without such a field, while a fill is pending, and for a field put back to inherited', () => {
        expect(reviewable(counts(0))).toBe(false);
        expect(reviewable({ ...counts(0), delivered: { intro: { plan: 'p1', pending: true }, slug: { plan: 'p1', pending: false, sync: true } } })).toBe(false);
        expect(reviewable(null)).toBe(false);
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

describe('keptMessage', () => {
    it('names the kept fields by their labels', () => {
        vi.stubGlobal('__n', (key, count, { fields }) => key.split('|')[count === 1 ? 0 : 1].replace(':fields', fields));

        expect(keptMessage(['intro'], status({ intro: field('current', 'Intro') }))).toBe('Intro was changed after the translation and was kept.');
        expect(keptMessage(['intro', 'body'], null)).toBe('intro, body were changed after the translation and were kept.');
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
            review: null,
            reviewer: null,
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
        expect(base().request).toEqual({ kind: 'translate', mode: 'missing', fields: null, overwriteManual: false, translator: null, review: null, reviewer: null, replace: false });
        expect(t.running('base')).toBe(false);
    });

    it('shows what the plan noticed about fields it left out with the warnings of the units', async () => {
        mount();
        const note = 'Content (content) is stored in the Bard format of Statamic 2 and was left untouched.';
        const api = fakeApi(planResponse({ skipped: { content: 'not_applicable' }, warnings: [note] }));
        const fill = vi.fn(async () => ({ stale: false, applied: [], skipped: {}, repeated: [] }));
        const run = vi.fn(async () => summary({ warnings: [note, 'Introduction: "A short intro." was left untranslated (invalid).'] }));
        const t = translations({ api, run, fill });

        const result = await t.translate('base', { mode: 'missing' });

        expect(result.warnings).toEqual([note, 'Introduction: "A short intro." was left untranslated (invalid).']);
        expect(result.planSkipped).toEqual({ content: 'not_applicable' });
    });

    it('sends the requested fields, the overwrite flag, the translator and the review switch', async () => {
        mount();
        const api = fakeApi();
        const t = translations({ api });

        await t.translate('base', { mode: 'all', fields: ['intro'], overwriteManual: true, translator: 'openai:gpt-6-luna', review: true, reviewer: 'gemini:gemini-3.8-flash' });

        expect(api.post.mock.calls[0][1]).toMatchObject({
            mode: 'all',
            fields: ['intro'],
            overwrite_manual: true,
            translator: 'openai:gpt-6-luna',
            review: true,
            reviewer: 'gemini:gemini-3.8-flash',
        });
    });

    it('plans a field it replaces although the form holds unsaved text of it, and still skips the other edits', async () => {
        const ctx = mount();
        ctx.values.value.intro = 'Mein eigener Text';
        ctx.values.value.seo_title = 'Mein SEO-Titel';
        const api = fakeApi();
        const fill = vi.fn(async () => ({ stale: false, applied: [], skipped: {}, repeated: [] }));
        const run = vi.fn(async ({ onFields }) => {
            await onFields({ intro: { value: '[de] A family-run hotel.', meta: null } });

            return summary();
        });
        const t = translations({ api, run, fill });

        await t.translate('base', { mode: 'all', fields: ['intro'], overwriteManual: true, replace: true });

        expect(api.post.mock.calls[0][1]).toMatchObject({ fields: ['intro'], skip: ['seo_title'] });
        expect(fill.mock.calls[0][2].planValues.intro).toBe('Mein eigener Text');
        expect(base().request.replace).toBe(true);

        await t.translate('base', { mode: 'all', fields: ['intro'], overwriteManual: true });

        expect(api.post.mock.calls[1][1]).toMatchObject({ fields: ['intro'], skip: ['intro', 'seo_title'] });
    });

    it('replaces the requested fields only, and nothing without fields', async () => {
        const ctx = mount();
        ctx.values.value.intro = 'Mein eigener Text';
        const api = fakeApi();
        const t = translations({ api });

        await t.translate('base', { mode: 'all', replace: true });

        expect(api.post.mock.calls[0][1]).toMatchObject({ fields: null, skip: ['intro'] });
    });

    it('keeps the replacement on retry', async () => {
        const ctx = mount();
        const stopped = { status: 410, code: 'plan_expired', message: 'Expired.' };
        const api = fakeApi(planResponse({ fields: ['intro'] }), planResponse({ plan: 'p2' }));
        const t = translations({ api, run: vi.fn(async () => summary({ stopped })) });

        await t.translate('base', { mode: 'all', fields: ['intro'], overwriteManual: true, replace: true });
        ctx.values.value.intro = 'Mein eigener Text';
        await t.retry('base');

        expect(api.post.mock.calls[1][1]).toMatchObject({ fields: ['intro'], skip: [] });
    });

    it('keeps the plan\'s reviewer and the corrections in the result', async () => {
        mount();
        const corrections = [{ handle: 'intro', id: 's1', label: 'Intro', before: '[de] a', after: '[de] b', reason: 'Better.' }];
        const t = translations({ api: fakeApi(planResponse({ reviewer: 'openai:gpt-6.1-sol' })), run: vi.fn(async () => summary({ delivered: ['intro'], corrections })) });

        const result = await t.translate('base');

        expect(result).toMatchObject({ kind: 'translate', reviewer: 'openai:gpt-6.1-sol', corrections });
        expect((await translations().translate('base')).reviewer).toBeNull();
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

describe('review', () => {
    it('sends the form\'s values of the fields it holds, runs the plan and fills the corrections', async () => {
        const ctx = mount({ values: { ...stored(), intro: '[de] A family-run hotel.' }, localized: ['title', 'intro', 'slug'] });
        const api = fakeApi(planResponse({ fields: ['intro'], skipped: { title: 'manual' }, reviewer: 'openai:gpt-6.1-sol' }));
        const fill = vi.fn(async () => ({ stale: false, applied: ['intro'], skipped: {}, repeated: [] }));
        const run = vi.fn(async ({ onFields }) => {
            await onFields({ intro: { value: '[de] A family hotel.', meta: null, corrections: [] } });

            return summary({ delivered: ['intro'], unchanged: [], models: ['openai:gpt-6.1-sol'] });
        });
        const t = translations({ api, run, fill });

        const result = await t.review('base', { reviewer: 'openai:gpt-6.1-sol' });

        expect(api.post).toHaveBeenCalledExactlyOnceWith('editor/review', {
            reference: 'entry::de',
            values: { title: 'Startseite', intro: '[de] A family-run hotel.' },
            overwrite_manual: false,
            reviewer: 'openai:gpt-6.1-sol',
        });
        expect(fill.mock.calls[0][2]).toMatchObject({ reference: 'entry::de', plan: 'p1', planValues: ctx.values.value });
        expect(result).toMatchObject({ kind: 'review', reviewer: 'openai:gpt-6.1-sol', planned: ['intro'], delivered: ['intro'], planSkipped: { title: 'manual' } });
        expect(Object.keys(base().snapshot.fields)).toEqual(['title', 'intro']);
        expect(base()).toMatchObject({ phase: 'done', request: { kind: 'review', fields: null, overwriteManual: false, reviewer: 'openai:gpt-6.1-sol' } });
    });

    it('sends the overwrite flag, and only the requested fields', async () => {
        mount({ localized: ['title', 'intro'] });
        const api = fakeApi();

        await translations({ api }).review('base', { fields: ['intro'], overwriteManual: true });

        expect(api.post.mock.calls[0][1]).toEqual({ reference: 'entry::de', values: { intro: 'A family-run hotel.' }, overwrite_manual: true, reviewer: null });
    });

    it('does not start while a translation is under way', async () => {
        mount();
        const api = fakeApi(deferred().promise);
        const t = translations({ api });

        t.translate('base');

        expect(await t.review('base')).toBeNull();
        expect(api.post).toHaveBeenCalledOnce();
    });

    it('reviews again on retry, limited to the fields not reviewed yet', async () => {
        mount({ localized: ['title', 'intro', 'seo_title'] });
        const stopped = { status: 502, code: 'provider_rejected', message: 'No.' };
        const api = fakeApi(planResponse({ fields: ['title', 'intro', 'seo_title'] }), planResponse({ plan: 'p2' }));
        const run = vi
            .fn()
            .mockImplementationOnce(async () => summary({ delivered: ['intro'], unchanged: ['title'], stopped }))
            .mockImplementationOnce(async () => summary());
        const t = translations({ api, run });

        await t.review('base', { reviewer: 'gemini:gemini-3.8-flash' });
        expect(base().phase).toBe('error');

        await t.retry('base');

        expect(api.post.mock.calls[1]).toEqual(['editor/review', { reference: 'entry::de', values: { seo_title: 'Kyma Bay Hotel' }, overwrite_manual: false, reviewer: 'gemini:gemini-3.8-flash' }]);
        expect(base().phase).toBe('done');
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
        expect(slugToKeep({ created: true, slugKept: false, status: { localization: { origin_slug: 'home', translate_slug: true } } })).toBeNull();
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

    it('leaves the slug to core in a collection with translated slugs, which makes it from the translated title', async () => {
        created();
        base().status.localization.translate_slug = true;
        const keepSlug = vi.fn();
        const recheckSlug = vi.fn();
        const t = translations({ keepSlug, recheckSlug, run: async () => summary({ delivered: ['intro'] }) });

        await t.translate('base');

        expect(keepSlug).not.toHaveBeenCalled();
        expect(recheckSlug).not.toHaveBeenCalled();
        expect(base().phase).toBe('done');
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

    it('reviews the filled form, and Undo puts back the translation from before the review', async () => {
        vi.useFakeTimers({ now: 100_000 });
        const ctx = mount();
        const correction = { id: 's1', label: 'Intro', before: '[de] A family-run hotel.', after: '[de] A family hotel.', reason: 'Shorter.' };
        const api = {
            post: vi.fn(async (path, data) => {
                if (path === 'editor/plan') {
                    return planResponse();
                }

                if (path === 'editor/review') {
                    return { ...planResponse({ plan: 'r1', units: [{ id: 'b1', fields: ['intro', 'seo_title'], chars: 40 }] }), reviewer: 'openai:gpt-6.1-sol' };
                }

                if (data.plan === 'p1') {
                    return {
                        fields: {
                            intro: { value: '[de] A family-run hotel.', meta: null, incomplete: false, warnings: [], corrections: [], reviewer: null },
                            seo_title: { value: '[de] Kyma Bay Hotel', meta: null, incomplete: false, warnings: [], corrections: [], reviewer: null },
                        },
                        unchanged: [],
                        spawned: [],
                        failed: [],
                        warnings: [],
                        usage: {},
                        remaining: 0,
                        review_skipped: false,
                        reviewed: false,
                    };
                }

                return {
                    fields: { intro: { value: '[de] A family hotel.', meta: null, incomplete: false, warnings: [], corrections: [correction], reviewer: 'openai:gpt-6.1-sol' } },
                    unchanged: ['seo_title'],
                    spawned: [],
                    failed: [],
                    warnings: [],
                    usage: { 'openai:gpt-6.1-sol': { input: 10, output: 5, calls: 1 } },
                    remaining: 0,
                    review_skipped: false,
                    reviewed: true,
                };
            }),
        };
        const t = createTranslations({ store: () => store, registry, api, undoFill: (ctx, snap, delivered) => restore(ctx, snap, delivered, { registry }) });

        const translated = t.translate('base');
        await vi.advanceTimersByTimeAsync(SETTLE_MS + 1_000);
        await translated;

        const reviewed = t.review('base');
        await vi.advanceTimersByTimeAsync(SETTLE_MS + 1_000);
        const result = await reviewed;

        expect(api.post.mock.calls[2]).toEqual(['editor/review', {
            reference: 'entry::de',
            values: { title: 'Startseite', intro: '[de] A family-run hotel.', seo_title: '[de] Kyma Bay Hotel' },
            overwrite_manual: false,
            reviewer: null,
        }]);
        expect(ctx.values.value).toMatchObject({ intro: '[de] A family hotel.', seo_title: '[de] Kyma Bay Hotel' });
        expect(result).toMatchObject({
            kind: 'review',
            delivered: ['intro'],
            unchanged: ['seo_title'],
            corrections: [{ handle: 'intro', ...correction }],
            reviewedUnits: ['b1'],
        });
        expect(base().delivered.intro).toMatchObject({ plan: 'r1', value: '[de] A family hotel.' });
        expect(undoable(base())).toBe(true);

        const undone = t.undo('base');
        await vi.advanceTimersByTimeAsync(1_000);
        await undone;

        expect(ctx.values.value).toMatchObject({ intro: '[de] A family-run hotel.', seo_title: '[de] Kyma Bay Hotel' });
        expect(base().delivered.intro).toMatchObject({ plan: 'p1', value: '[de] A family-run hotel.' });
        expect(base().phase).toBe('idle');
        expect(reviewable(base())).toBe(true);
    });
});

describe('what the summary says about the review, with the real runner', () => {
    const REVIEWER = 'openai:gpt-6.1-sol';
    const intro = { value: '[de] A family-run hotel.', meta: null, incomplete: false, warnings: [], corrections: [], reviewer: REVIEWER };
    const reviewPlan = (extra = {}) => planResponse({ units: [{ id: 'b1', fields: ['intro'], chars: 19 }], fields: ['intro'], reviewer: REVIEWER, ...extra });
    const unitResponse = (extra = {}) => ({
        fields: {},
        unchanged: [],
        spawned: [],
        failed: [],
        warnings: [],
        usage: {},
        remaining: 0,
        review_skipped: false,
        reviewed: false,
        ...extra,
    });
    const translated = unitResponse({ spawned: [{ id: 'b1.v', fields: ['intro'], chars: 19 }], usage: { 'anthropic:claude-sonnet-5-5': { calls: 1 } } });

    // `editor/plan` answers the plan, `editor/unit` by unit id: a response, an error, or a function that returns one.
    function unitApi(plan, units) {
        return {
            post: vi.fn(async (path, data) => {
                if (path === 'editor/plan') {
                    return plan;
                }

                const answer = typeof units[data.unit] === 'function' ? await units[data.unit]() : units[data.unit];

                if (answer instanceof Error) {
                    throw answer;
                }

                return answer;
            }),
        };
    }

    const fill = (ctx, payloads) => ({ applied: Object.keys(payloads), skipped: {}, stale: false });
    const create = (api) => createTranslations({ store: () => store, registry, api, fill, concurrency: () => 1 });

    async function summaryOf(plan, units) {
        const result = await create(unitApi(plan, units)).translate('base', { mode: 'update', review: true });

        return describeSummary(result, base().status);
    }

    beforeEach(() => {
        vi.stubGlobal('__n', (key) => key);
        mount();
    });

    it('names the reviewer once a review unit has reviewed', async () => {
        const summary = await summaryOf(reviewPlan(), { b1: translated, 'b1.v': unitResponse({ fields: { intro }, reviewed: true }) });

        expect(summary.reviewed).toBe('Reviewed with :model: nothing to correct.');
    });

    it('names no reviewer when the plan had no unit to review', async () => {
        const summary = await summaryOf(reviewPlan({ units: [], ready: { intro } }), {});

        expect(summary.translated).not.toBeNull();
        expect(summary.reviewed).toBeNull();
    });

    it('names no reviewer when every review was skipped', async () => {
        const summary = await summaryOf(reviewPlan(), { b1: translated, 'b1.v': unitResponse({ fields: { intro }, review_skipped: true }) });

        expect(summary.reviewSkipped).not.toBeNull();
        expect(summary.reviewed).toBeNull();
    });

    it('names no reviewer when the provider rejected it', async () => {
        const rejected = new ApiError({ status: 502, code: 'provider_rejected', message: 'The AI provider [openai] does not offer the model.', data: null });
        const summary = await summaryOf(reviewPlan(), { b1: translated, 'b1.v': rejected });

        expect(base().phase).toBe('error');
        expect(summary.reviewed).toBeNull();
    });

    it('names no reviewer when the translation was cancelled before a review ran', async () => {
        const held = deferred();
        const t = create(unitApi(reviewPlan(), { b1: () => held.promise }));
        const running = t.translate('base', { mode: 'update', review: true });

        await flush();
        t.cancel('base');
        held.resolve(translated);

        const summary = describeSummary(await running, base().status);

        expect(summary.cancelled).not.toBeNull();
        expect(summary.reviewed).toBeNull();
    });
});
