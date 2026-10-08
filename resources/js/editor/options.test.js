import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { ApiError } from '../api.js';
import { OFFER_PREFERENCE } from './offer.js';
import {
    baselineMessage,
    createOptions,
    initialModel,
    modelOptions,
    optionsRequest,
    rememberedModel,
    REVIEWER_PREFERENCE,
    reviewRequest,
    stateActions,
    TRANSLATOR_PREFERENCE,
} from './options.js';
import { createRegistry } from './registry.js';
import { useEditorStore } from './store.js';

let store;
let registry;
let preferences;
let toast;
let translations;

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

// Core's preferences: `get` with a fallback, `set` posts and resolves.
function fakePreferences(values = {}, { fails = false } = {}) {
    return {
        values,
        get: (key, fallback) => (Object.hasOwn(values, key) ? values[key] : fallback),
        set: vi.fn(async (key, value) => {
            if (fails) {
                throw new Error('offline');
            }

            values[key] = value;
        }),
    };
}

beforeEach(() => {
    vi.stubGlobal('Statamic', { $pinia: { defineStore } });
    vi.stubGlobal('__', translate);
    vi.stubGlobal('__n', (key, count, replacements = {}) => {
        const [one, other] = translate(key, { ...replacements, count }).split('|');

        return count === 1 ? one : (other ?? one);
    });
    setActivePinia(createPinia());
    store = useEditorStore();
    registry = createRegistry();
    preferences = fakePreferences();
    toast = { error: vi.fn(), success: vi.fn() };
    translations = { translate: vi.fn(() => Promise.resolve(null)) };
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const catalog = () => ({
    anthropic: [
        { value: 'anthropic:claude-sonnet-5-5', label: 'Claude Sonnet 5.5', tier: 'quality', configured: true },
        { value: 'anthropic:claude-haiku-5-5', label: 'Claude Haiku 5.5', tier: 'fast', configured: true },
    ],
    openai: [{ value: 'openai:gpt-6-luna', label: 'GPT-6 Luna', tier: 'fast', configured: false }],
    gemini: [{ value: 'gemini:gemini-3.8-flash', label: 'Gemini 3.8 Flash', tier: 'quality', configured: true }],
});

const zero = { untranslated: 0, current: 0, outdated: 0, manual: 0, attention: 0, untracked: 0 };

function status(counts = {}) {
    return {
        translatable: true,
        reason: null,
        localization: {
            source: { site: 'en', name: 'English' },
            target: { site: 'el', name: 'Greek' },
            status: 'current',
            fields: {},
            counts: { ...zero, ...counts },
            out_of_scope: [],
            origin_slug: 'chania',
        },
        origin: null,
        defaults: { translator: 'anthropic:claude-sonnet-5-5', providers_configured: true, fake_engine: false },
        offer: true,
    };
}

function fakeApi({ models = { catalog: catalog(), defaults: { translator: 'anthropic:claude-sonnet-5-5' } }, baseline } = {}) {
    return {
        get: vi.fn(async () => (models instanceof Error ? Promise.reject(models) : models)),
        post: vi.fn(async (path, data) => (typeof baseline === 'function' ? baseline(data) : baseline)),
    };
}

function create(api = fakeApi()) {
    return createOptions({ store: () => store, registry, api, translations, preferences: () => preferences, toast: () => toast });
}

// The Greek article's form, attached and with its status loaded.
function mount(reference = 'entry::el') {
    const ctx = { name: ref('base'), reference: ref(reference), site: ref('el'), values: ref({ title: 'Χανιά' }) };

    registry.attachContext('base', ctx);
    store.switched('base', reference, 1_000);
    store.container('base').status = status({ manual: 1, untracked: 1 });

    return ctx;
}

const baselined = (count, counts = { manual: 1 }) => ({ ...status(counts), baselined: count });

describe('modelOptions', () => {
    it('lists the models of configured providers in catalog order and marks the default', () => {
        expect(modelOptions(catalog(), { defaultModel: 'anthropic:claude-sonnet-5-5' })).toEqual([
            { value: 'anthropic:claude-sonnet-5-5', label: 'Claude Sonnet 5.5 (default)' },
            { value: 'anthropic:claude-haiku-5-5', label: 'Claude Haiku 5.5' },
            { value: 'gemini:gemini-3.8-flash', label: 'Gemini 3.8 Flash' },
        ]);
    });

    it('lists every model while the fake engine answers', () => {
        expect(modelOptions(catalog(), { fakeEngine: true }).map((option) => option.value)).toEqual([
            'anthropic:claude-sonnet-5-5',
            'anthropic:claude-haiku-5-5',
            'openai:gpt-6-luna',
            'gemini:gemini-3.8-flash',
        ]);
    });

    it('adds extra values once, labelled by their reference, and skips empty ones', () => {
        const options = modelOptions(catalog(), {
            defaultModel: 'openai:gpt-custom',
            extra: ['openai:gpt-custom', null, 'anthropic:claude-haiku-5-5', 'gemini:gemini-exp', 'gemini:gemini-exp'],
        });

        expect(options.slice(3)).toEqual([
            { value: 'openai:gpt-custom', label: 'openai:gpt-custom (default)' },
            { value: 'gemini:gemini-exp', label: 'gemini:gemini-exp' },
        ]);
    });

    it('reads an empty catalog, which PHP encodes as a list', () => {
        expect(modelOptions([], { extra: ['anthropic:claude-sonnet-5-5'] })).toEqual([
            { value: 'anthropic:claude-sonnet-5-5', label: 'anthropic:claude-sonnet-5-5' },
        ]);
        expect(modelOptions(null)).toEqual([]);
    });
});

describe('initialModel', () => {
    const options = modelOptions(catalog());

    it('picks the remembered model while it is offered', () => {
        expect(initialModel(options, { remembered: 'anthropic:claude-haiku-5-5', defaultModel: 'anthropic:claude-sonnet-5-5' })).toBe(
            'anthropic:claude-haiku-5-5',
        );
    });

    it('falls back to the default, then to the first model', () => {
        expect(initialModel(options, { remembered: 'openai:gpt-6-luna', defaultModel: 'gemini:gemini-3.8-flash' })).toBe('gemini:gemini-3.8-flash');
        expect(initialModel(options, { remembered: null, defaultModel: 'openai:gpt-6-luna' })).toBe('anthropic:claude-sonnet-5-5');
        expect(initialModel([], { remembered: 'openai:gpt-6-luna' })).toBeNull();
    });
});

describe('rememberedModel', () => {
    it('reads the preference, treating null and an empty string as none', () => {
        expect(rememberedModel(preferences)).toBeNull();
        expect(rememberedModel(fakePreferences({ [TRANSLATOR_PREFERENCE]: 'gemini:gemini-3.8-flash' }))).toBe('gemini:gemini-3.8-flash');
        expect(rememberedModel(fakePreferences({ [TRANSLATOR_PREFERENCE]: null }))).toBeNull();
        expect(rememberedModel(fakePreferences({ [TRANSLATOR_PREFERENCE]: '' }))).toBeNull();
    });

    it('reads the reviewer from its own preference', () => {
        const stored = fakePreferences({ [TRANSLATOR_PREFERENCE]: 'gemini:gemini-3.8-flash', [REVIEWER_PREFERENCE]: 'openai:gpt-6-luna' });

        expect(rememberedModel(stored, REVIEWER_PREFERENCE)).toBe('openai:gpt-6-luna');
        expect(rememberedModel(preferences, REVIEWER_PREFERENCE)).toBeNull();
    });
});

describe('optionsRequest', () => {
    it('asks for everything that needs work, or with Retranslate everything for everything', () => {
        expect(optionsRequest({ model: 'anthropic:claude-sonnet-5-5', defaultModel: 'anthropic:claude-sonnet-5-5' })).toEqual({
            mode: 'update',
            overwriteManual: false,
            translator: null,
            review: null,
            reviewer: null,
        });
        expect(optionsRequest({ retranslate: true, overwriteManual: true })).toEqual({ mode: 'all', overwriteManual: true, translator: null, review: null, reviewer: null });
    });

    it('turns the review on or off, and names the reviewer only while it is on and not the default', () => {
        const reviewers = { reviewer: 'gemini:gemini-3.8-flash', defaultReviewer: 'openai:gpt-6.1-sol' };

        expect(optionsRequest({ review: true, ...reviewers })).toMatchObject({ review: true, reviewer: 'gemini:gemini-3.8-flash' });
        expect(optionsRequest({ review: true, reviewer: 'openai:gpt-6.1-sol', defaultReviewer: 'openai:gpt-6.1-sol' })).toMatchObject({ review: true, reviewer: null });
        expect(optionsRequest({ review: false, ...reviewers })).toMatchObject({ review: false, reviewer: null });
    });

    it('names the model only when it is not the default', () => {
        expect(optionsRequest({ model: 'gemini:gemini-3.8-flash', defaultModel: 'anthropic:claude-sonnet-5-5' }).translator).toBe(
            'gemini:gemini-3.8-flash',
        );
    });
});

describe('reviewRequest', () => {
    it('names the reviewer only when it is not the default, and passes the overwrite flag', () => {
        expect(reviewRequest({ reviewer: 'gemini:gemini-3.8-flash', defaultReviewer: 'openai:gpt-6.1-sol', overwriteManual: true })).toEqual({
            overwriteManual: true,
            reviewer: 'gemini:gemini-3.8-flash',
        });
        expect(reviewRequest({ reviewer: 'openai:gpt-6.1-sol', defaultReviewer: 'openai:gpt-6.1-sol' })).toEqual({ overwriteManual: false, reviewer: null });
        expect(reviewRequest()).toEqual({ overwriteManual: false, reviewer: null });
    });
});

describe('stateActions', () => {
    it('offers to mark manual fields while one needs attention or is untracked', () => {
        expect(stateActions(status({ manual: 1, untracked: 1 })).markCurrent).toBe(true);
        expect(stateActions(status({ manual: 1, attention: 1 })).markCurrent).toBe(true);
        expect(stateActions(status({ manual: 2 })).markCurrent).toBe(false);
    });

    it('offers to protect while a field holds machine output', () => {
        expect(stateActions(status({ current: 1 })).protect).toBe(true);
        expect(stateActions(status({ outdated: 1 })).protect).toBe(true);
        expect(stateActions(status({ untranslated: 2, manual: 1 })).protect).toBe(false);
    });

    it('offers nothing without a localization block', () => {
        expect(stateActions(null)).toEqual({ markCurrent: false, protect: false });
        expect(stateActions({ ...status(), localization: null })).toEqual({ markCurrent: false, protect: false });
    });
});

describe('baselineMessage', () => {
    it('says what changed', () => {
        expect(baselineMessage(1, false)).toBe('1 field marked as up to date.');
        expect(baselineMessage(2, true)).toBe('2 fields are now protected from updates.');
        expect(baselineMessage(0, true)).toBe('No field needed changing.');
    });
});

describe('loadModels', () => {
    it('loads the models once per page', async () => {
        const api = fakeApi();
        const created = create(api);

        await created.loadModels();
        const second = await created.loadModels();

        expect(api.get).toHaveBeenCalledTimes(1);
        expect(api.get).toHaveBeenCalledWith('models');
        expect(second.defaults.translator).toBe('anthropic:claude-sonnet-5-5');
    });

    it('tries again after a failed load', async () => {
        const api = fakeApi({ models: new ApiError({ status: 500, code: null, message: 'Server error', data: null }) });
        const created = create(api);

        await expect(created.loadModels()).rejects.toThrow('Server error');
        await expect(created.loadModels()).rejects.toThrow('Server error');

        expect(api.get).toHaveBeenCalledTimes(2);
    });
});

describe('translate', () => {
    it('translates with the chosen mode, flag and model, and remembers the model', () => {
        mount();
        create().translate('base', {
            model: 'gemini:gemini-3.8-flash',
            defaultModel: 'anthropic:claude-sonnet-5-5',
            retranslate: true,
            overwriteManual: true,
        });

        expect(translations.translate).toHaveBeenCalledWith('base', {
            mode: 'all',
            overwriteManual: true,
            translator: 'gemini:gemini-3.8-flash',
            review: null,
            reviewer: null,
        });
        expect(preferences.set).toHaveBeenCalledWith(TRANSLATOR_PREFERENCE, 'gemini:gemini-3.8-flash');
        expect(preferences.set).toHaveBeenCalledTimes(1);
    });

    it('does not store the preference again when it is unchanged', () => {
        preferences = fakePreferences({ [TRANSLATOR_PREFERENCE]: 'gemini:gemini-3.8-flash' });
        mount();
        create().translate('base', { model: 'gemini:gemini-3.8-flash', defaultModel: 'anthropic:claude-sonnet-5-5' });

        expect(preferences.set).not.toHaveBeenCalled();
        expect(translations.translate).toHaveBeenCalledWith('base', {
            mode: 'update',
            overwriteManual: false,
            translator: 'gemini:gemini-3.8-flash',
            review: null,
            reviewer: null,
        });
    });

    it('forgets a remembered model when the default is chosen, and stores nothing without one', () => {
        preferences = fakePreferences({ [TRANSLATOR_PREFERENCE]: 'gemini:gemini-3.8-flash' });
        mount();
        const created = create();

        created.translate('base', { model: 'anthropic:claude-sonnet-5-5', defaultModel: 'anthropic:claude-sonnet-5-5' });
        expect(preferences.set).toHaveBeenCalledWith(TRANSLATOR_PREFERENCE, null);

        preferences.set.mockClear();
        created.translate('base', { model: 'anthropic:claude-sonnet-5-5', defaultModel: 'anthropic:claude-sonnet-5-5' });
        expect(preferences.set).not.toHaveBeenCalled();
    });

    it('reviews after translating with the chosen reviewer, and remembers it', () => {
        mount();
        create().translate('base', { review: true, reviewer: 'gemini:gemini-3.8-flash', defaultReviewer: 'openai:gpt-6.1-sol' });

        expect(translations.translate).toHaveBeenCalledWith('base', { mode: 'update', overwriteManual: false, translator: null, review: true, reviewer: 'gemini:gemini-3.8-flash' });
        expect(preferences.set).toHaveBeenCalledExactlyOnceWith(REVIEWER_PREFERENCE, 'gemini:gemini-3.8-flash');
    });

    it('leaves the remembered reviewer alone when the review is off', () => {
        preferences = fakePreferences({ [REVIEWER_PREFERENCE]: 'gemini:gemini-3.8-flash' });
        mount();
        create().translate('base', { review: false, reviewer: 'openai:gpt-6.1-sol', defaultReviewer: 'openai:gpt-6.1-sol' });

        expect(translations.translate.mock.calls[0][1]).toMatchObject({ review: false, reviewer: null });
        expect(preferences.set).not.toHaveBeenCalled();
    });

    it('does nothing while a translation is under way', () => {
        mount();
        store.setPhase('base', 'working');

        expect(create().translate('base', { model: 'gemini:gemini-3.8-flash' })).toBeNull();
        expect(translations.translate).not.toHaveBeenCalled();
        expect(preferences.set).not.toHaveBeenCalled();
    });

    it('shows a toast when the model cannot be remembered, and still translates', async () => {
        preferences = fakePreferences({}, { fails: true });
        mount();
        create().translate('base', { model: 'gemini:gemini-3.8-flash' });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(toast.error).toHaveBeenCalledWith('Your choice could not be saved.');
        expect(translations.translate).toHaveBeenCalled();
    });
});

describe('review', () => {
    beforeEach(() => {
        translations.review = vi.fn(() => Promise.resolve(null));
    });

    it('reviews with the chosen reviewer and flag, and remembers the reviewer', () => {
        mount();
        create().review('base', { reviewer: 'gemini:gemini-3.8-flash', defaultReviewer: 'openai:gpt-6.1-sol', overwriteManual: true });

        expect(translations.review).toHaveBeenCalledWith('base', { overwriteManual: true, reviewer: 'gemini:gemini-3.8-flash' });
        expect(preferences.set).toHaveBeenCalledExactlyOnceWith(REVIEWER_PREFERENCE, 'gemini:gemini-3.8-flash');
    });

    it('forgets a remembered reviewer when the default is chosen', () => {
        preferences = fakePreferences({ [REVIEWER_PREFERENCE]: 'gemini:gemini-3.8-flash' });
        mount();
        create().review('base', { reviewer: 'openai:gpt-6.1-sol', defaultReviewer: 'openai:gpt-6.1-sol' });

        expect(translations.review).toHaveBeenCalledWith('base', { overwriteManual: false, reviewer: null });
        expect(preferences.set).toHaveBeenCalledExactlyOnceWith(REVIEWER_PREFERENCE, null);
    });

    it('does nothing while a translation is under way', () => {
        mount();
        store.setPhase('base', 'planning');

        expect(create().review('base', { reviewer: 'gemini:gemini-3.8-flash' })).toBeNull();
        expect(translations.review).not.toHaveBeenCalled();
        expect(preferences.set).not.toHaveBeenCalled();
    });
});

describe('baseline', () => {
    it('marks the manual fields as up to date and shows the returned status', async () => {
        const api = fakeApi({ baseline: baselined(1) });
        mount();

        const count = await create(api).baseline('base');

        expect(count).toBe(1);
        expect(api.post).toHaveBeenCalledWith('editor/baseline', { reference: 'entry::el', protect: false });
        expect(store.containers.base.status).toEqual(status({ manual: 1 }));
        expect(store.containers.base.status).not.toHaveProperty('baselined');
        expect(toast.success).toHaveBeenCalledWith('1 field marked as up to date.');
    });

    it('protects the machine fields', async () => {
        const api = fakeApi({ baseline: baselined(3, { manual: 4, untracked: 1 }) });
        mount();

        await create(api).baseline('base', { protect: true });

        expect(api.post).toHaveBeenCalledWith('editor/baseline', { reference: 'entry::el', protect: true });
        expect(store.containers.base.status.localization.counts.manual).toBe(4);
        expect(toast.success).toHaveBeenCalledWith('3 fields are now protected from updates.');
    });

    it('keeps the status of another item when the form moved on during the request', async () => {
        let answer;
        const api = fakeApi({ baseline: () => new Promise((resolve) => (answer = resolve)) });
        const ctx = mount();
        const pending = create(api).baseline('base');

        ctx.reference.value = 'entry::de';
        store.switched('base', 'entry::de', 2_000);
        store.container('base').status = status({ untranslated: 3 });
        answer(baselined(1));

        expect(await pending).toBe(1);
        expect(store.containers.base.status.localization.counts.untranslated).toBe(3);
    });

    it('shows the error and changes nothing when the request fails', async () => {
        const api = fakeApi({
            baseline: () => Promise.reject(new ApiError({ status: 403, code: null, message: 'This action is unauthorized.', data: null })),
        });
        mount();

        expect(await create(api).baseline('base')).toBeNull();
        expect(toast.error).toHaveBeenCalledWith('This action is unauthorized.');
        expect(store.containers.base.status).toEqual(status({ manual: 1, untracked: 1 }));
    });

    it('does nothing while a translation is under way, before the status belongs to the form, or without a form', async () => {
        const api = fakeApi({ baseline: baselined(1) });
        const created = create(api);

        expect(await created.baseline('base')).toBeNull();

        const ctx = mount();
        store.setPhase('base', 'planning');
        expect(await created.baseline('base')).toBeNull();

        store.setPhase('base', 'idle');
        ctx.reference.value = 'entry::de';
        expect(await created.baseline('base')).toBeNull();

        expect(api.post).not.toHaveBeenCalled();
    });
});

describe('setOffers', () => {
    it('stores the offer preference either way', async () => {
        const created = create();

        expect(await created.setOffers(true)).toBe(true);
        expect(preferences.values[OFFER_PREFERENCE]).toBe(true);
        expect(await created.setOffers(false)).toBe(true);
        expect(preferences.values[OFFER_PREFERENCE]).toBe(false);
    });

    it('reports a failure with a toast', async () => {
        preferences = fakePreferences({}, { fails: true });

        expect(await create().setOffers(true)).toBe(false);
        expect(toast.error).toHaveBeenCalledWith('Your choice could not be saved.');
    });
});
