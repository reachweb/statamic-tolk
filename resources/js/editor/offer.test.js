import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { createOffers, describeOffer, OFFER_PREFERENCE, offersEnabled, shouldOffer } from './offer.js';
import { createRegistry } from './registry.js';
import { useEditorStore } from './store.js';

let store;
let registry;
let preferences;
let toast;
let events;
let translations;

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

// Core's preferences: `get` with a fallback, `set` posts and resolves.
function fakePreferences(values = {}) {
    return {
        values,
        get: (key, fallback) => (Object.hasOwn(values, key) ? values[key] : fallback),
        set: vi.fn(async (key, value) => {
            values[key] = value;
        }),
    };
}

function fakeEvents() {
    const handlers = {};

    return {
        $on: vi.fn((name, callback) => (handlers[name] ??= []).push(callback)),
        emit: (name, payload) => (handlers[name] ?? []).forEach((callback) => callback(payload)),
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
    toast = { error: vi.fn() };
    events = fakeEvents();
    translations = { translate: vi.fn(() => Promise.resolve(null)) };
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const zero = { untranslated: 0, current: 0, outdated: 0, manual: 0, attention: 0, untracked: 0 };

function status({ untranslated = 2, offer = true, translator = 'anthropic:claude-sonnet-5-5', providers = true, fakeEngine = false } = {}) {
    return {
        translatable: true,
        reason: null,
        localization: {
            source: { site: 'en', name: 'English' },
            target: { site: 'de', name: 'German' },
            status: untranslated ? 'untranslated' : 'current',
            fields: {},
            counts: { ...zero, untranslated, current: untranslated ? 0 : 2 },
            out_of_scope: [],
            origin_slug: 'about',
        },
        origin: null,
        defaults: { translator, providers_configured: providers, fake_engine: fakeEngine },
        offer,
    };
}

const originStatus = () => ({ ...status(), localization: null, origin: { localizations: [] } });

function offers() {
    const created = createOffers({ store: () => store, registry, translations, preferences: () => preferences, toast: () => toast });
    created.listen(events);

    return created;
}

// The English origin's form with its banner attached and its status loaded.
function mount(reference = 'entry::en') {
    const ctx = { name: ref('base'), reference: ref(reference), site: ref('en'), values: ref({ title: 'About us', slug: 'about' }) };

    registry.attachContext('base', ctx);
    store.switched('base', reference, 1_000);
    store.container('base').status = originStatus();

    return ctx;
}

// Core swaps the new localization into the form; the banner's watcher then runs its switch.
function swap(ctx, reference = 'entry::de') {
    ctx.reference.value = reference;
    ctx.site.value = 'de';
    store.switched('base', reference, 2_000);
}

const create = (name = 'base') => events.emit('localization.created', { container: { name } });
const base = () => store.containers.base;

describe('shouldOffer', () => {
    it('offers with the setting on, a model to use and untranslated fields', () => {
        expect(shouldOffer(status(), preferences)).toBe(true);
        expect(shouldOffer(status({ providers: false, fakeEngine: true }), preferences)).toBe(true);
    });

    it('does not offer when the setting is off, the user turned offers off, or nothing is untranslated', () => {
        expect(shouldOffer(status({ offer: false }), preferences)).toBe(false);
        expect(shouldOffer(status(), fakePreferences({ [OFFER_PREFERENCE]: false }))).toBe(false);
        expect(shouldOffer(status({ untranslated: 0 }), preferences)).toBe(false);
    });

    it('does not offer without a provider or a model', () => {
        expect(shouldOffer(status({ providers: false }), preferences)).toBe(false);
        expect(shouldOffer(status({ translator: null }), preferences)).toBe(false);
    });

    it('does not offer for an item that is not translatable, an origin or a missing status', () => {
        expect(shouldOffer({ ...status(), translatable: false, reason: 'single_site' }, preferences)).toBe(false);
        expect(shouldOffer(originStatus(), preferences)).toBe(false);
        expect(shouldOffer(null, preferences)).toBe(false);
    });

    it('reads the preference with offers on by default', () => {
        expect(offersEnabled(preferences)).toBe(true);
        expect(offersEnabled(fakePreferences({ [OFFER_PREFERENCE]: true }))).toBe(true);
        expect(offersEnabled(fakePreferences({ [OFFER_PREFERENCE]: false }))).toBe(false);
    });
});

describe('describeOffer', () => {
    it('names the count, the sites and the model', () => {
        expect(describeOffer(status({ untranslated: 4 }))).toEqual({ count: 4, source: 'English', target: 'German', translator: 'anthropic:claude-sonnet-5-5' });
    });

    it('copes with a status that has no localization block', () => {
        expect(describeOffer(null)).toEqual({ count: 0, source: '', target: '', translator: null });
    });
});

describe('after localization.created', () => {
    it('offers once the new localization\'s status has loaded', async () => {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        create();

        expect(base()).toMatchObject({ reference: 'entry::de', created: true, phase: 'idle' });
        expect(offer.waiting('base')).toBe(true);

        base().status = status();
        await nextTick();

        expect(base().phase).toBe('offer');
        expect(offer.waiting('base')).toBe(false);
    });

    it('waits for the banner\'s switch when the event comes first', async () => {
        offers();
        const ctx = mount();

        ctx.reference.value = 'entry::de';
        create();

        expect(base()).toMatchObject({ reference: 'entry::en', created: false });

        store.switched('base', 'entry::de', 2_000);
        await nextTick();
        expect(base()).toMatchObject({ created: true, phase: 'idle' });

        base().status = status();
        await nextTick();

        expect(base().phase).toBe('offer');
    });

    it('offers at once when the status is already there', () => {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        base().status = status();
        create();

        expect(base()).toMatchObject({ phase: 'offer', created: true });
        expect(offer.waiting('base')).toBe(false);
    });

    it.each([
        ['the setting is off', () => status({ offer: false })],
        ['nothing is untranslated', () => status({ untranslated: 0 })],
        ['no provider is configured', () => status({ providers: false })],
        [
            'the user turned offers off',
            () => {
                preferences.values[OFFER_PREFERENCE] = false;

                return status();
            },
        ],
    ])('records the creation without an offer when %s', async (reason, loaded) => {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        create();
        base().status = loaded();
        await nextTick();

        expect(base()).toMatchObject({ phase: 'idle', created: true });
        expect(offer.waiting('base')).toBe(false);
    });

    it('does not offer while a translation runs', async () => {
        offers();
        const ctx = mount();

        swap(ctx);
        create();
        store.setPhase('base', 'working');
        base().status = status();
        await nextTick();

        expect(base().phase).toBe('working');
    });

    it('ignores another container and a form without a banner', () => {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        registry.attachContext('relate-fieldtype-inline-1', { ...ctx, name: ref('relate-fieldtype-inline-1'), reference: ref('entry::page') });
        create('relate-fieldtype-inline-1');
        events.emit('localization.created', {});

        expect(base().created).toBe(false);
        expect(offer.waiting('relate-fieldtype-inline-1')).toBe(false);
        expect(store.containers['relate-fieldtype-inline-1']).toBeUndefined();

        registry = createRegistry();
        const withoutBanner = offers();
        create();

        expect(withoutBanner.waiting('base')).toBe(false);
    });

    it('stops waiting when the editor moves on before the status arrives', async () => {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        create();
        ctx.reference.value = 'entry::el';
        store.switched('base', 'entry::el', 3_000);
        await nextTick();

        expect(offer.waiting('base')).toBe(false);

        base().status = status();
        await nextTick();

        expect(base()).toMatchObject({ reference: 'entry::el', phase: 'idle', created: false });
    });

    it('waits for the latest localization only', async () => {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        create();
        swap(ctx, 'entry::de2');
        create();
        await nextTick();

        expect(offer.waiting('base')).toBe(true);

        base().status = status();
        await nextTick();

        expect(base()).toMatchObject({ reference: 'entry::de2', phase: 'offer', created: true });
        expect(offer.waiting('base')).toBe(false);
    });

    it('listens once', () => {
        const offer = offers();

        offer.listen(events);

        expect(events.$on).toHaveBeenCalledOnce();
        expect(events.$on).toHaveBeenCalledWith('localization.created', offer.created);
    });
});

describe('accept and decline', () => {
    function offering() {
        const offer = offers();
        const ctx = mount();

        swap(ctx);
        base().status = status();
        create();
        expect(base().phase).toBe('offer');

        return offer;
    }

    it('translates the missing fields', async () => {
        const offer = offering();
        let phase = null;
        translations.translate.mockImplementation(() => {
            phase = base().phase;

            return Promise.resolve({ delivered: ['title'] });
        });

        expect(await offer.accept('base')).toEqual({ delivered: ['title'] });
        expect(translations.translate).toHaveBeenCalledExactlyOnceWith('base', { mode: 'missing' });
        expect(phase).toBe('idle');
        expect(preferences.set).not.toHaveBeenCalled();
    });

    it.each(['accept', 'decline'])('stores "Don\'t offer again" on %s, and no offer comes afterwards', async (answer) => {
        const offer = offering();

        offer[answer]('base', { dontOfferAgain: true });

        expect(preferences.set).toHaveBeenCalledExactlyOnceWith(OFFER_PREFERENCE, false);

        swap(registry.context('base'), 'entry::de2');
        create();
        base().status = status();
        await nextTick();

        expect(base()).toMatchObject({ phase: 'idle', created: true });
    });

    it('declines without touching the form', () => {
        const offer = offering();

        offer.decline('base');

        expect(base().phase).toBe('idle');
        expect(translations.translate).not.toHaveBeenCalled();
        expect(preferences.set).not.toHaveBeenCalled();
    });

    it('does nothing when no offer is open', async () => {
        const offer = offers();
        mount();

        expect(offer.accept('base', { dontOfferAgain: true })).toBeNull();
        offer.decline('base', { dontOfferAgain: true });

        expect(translations.translate).not.toHaveBeenCalled();
        expect(preferences.set).not.toHaveBeenCalled();
        expect(base().phase).toBe('idle');
    });

    it('says so when the preference cannot be stored', async () => {
        const offer = offering();
        preferences.set.mockRejectedValueOnce(new Error('Network Error'));

        offer.decline('base', { dontOfferAgain: true });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(toast.error).toHaveBeenCalledExactlyOnceWith('Your choice could not be saved.');
        expect(base().phase).toBe('idle');
    });
});
