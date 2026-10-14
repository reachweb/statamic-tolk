import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { ApiError } from '../api.js';
import { attachBanner, createBanners, isTermReference, shouldPush, showsActions } from './banner.js';
import { SETTLE_MS } from './fill.js';
import { createRegistry } from './registry.js';
import { createSaveHooks } from './saveHooks.js';
import { useEditorStore } from './store.js';
import { createTranslations } from './translate.js';

function bus() {
    const handlers = {};

    return {
        $on: (event, handler) => (handlers[event] ??= []).push(handler),
        $emit: (event, payload) => (handlers[event] ?? []).forEach((handler) => handler(payload)),
        count: (event) => (handlers[event] ?? []).length,
    };
}

let config;
let permissions;
let store;

beforeEach(() => {
    config = { 'tolk.multisite': true };
    permissions = ['translate content'];
    vi.stubGlobal('Statamic', {
        $pinia: { defineStore },
        $config: { get: (key) => config[key] },
        $permissions: { has: (permission) => permissions.includes(permission) || permissions.includes('super') },
    });
    setActivePinia(createPinia());
    store = useEditorStore();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

// A publish container: the event payload and the context a component injects share the refs.
function container(name = 'base', reference = 'entry::a', values = { title: 'Τίτλος', intro: 'Εισαγωγή' }) {
    const state = { reference: ref(reference), site: ref('el'), values: ref(values) };
    const pushed = [];

    return {
        pushed,
        payload: {
            name,
            ...state,
            pushComponent: vi.fn((component, options) => {
                const banner = { component, options, destroy: vi.fn() };
                pushed.push(banner);

                return banner;
            }),
        },
        context: { name: ref(name), ...state, readOnly: ref(false), localizedFields: ref([]) },
    };
}

describe('isTermReference', () => {
    it('tells a term\'s reference from the others', () => {
        expect(isTermReference('term::topics::food::el')).toBe(true);
        expect(isTermReference('entry::abc')).toBe(false);
        expect(isTermReference('globals::site_settings::el')).toBe(false);
        expect(isTermReference(null)).toBe(false);
    });
});

describe('showsActions', () => {
    const localization = { localization: { translate: true }, setup: false };

    it('shows the action row before and after a translation, and after one failed, so another model can be chosen', () => {
        expect(['idle', 'done', 'error'].map((phase) => showsActions(phase, localization))).toEqual([true, true, true]);
    });

    it('hides it while a translation runs or the offer is open, in an origin, and while setup is needed', () => {
        expect(['planning', 'working', 'offer'].map((phase) => showsActions(phase, localization))).toEqual([false, false, false]);
        expect(showsActions('idle', { localization: null, setup: false })).toBe(false);
        expect(showsActions('error', { ...localization, setup: true })).toBe(false);
        expect(showsActions('idle', null)).toBe(false);
    });
});

describe('shouldPush', () => {
    it('pushes into the base container of an entry or a term', () => {
        expect(shouldPush(container().payload)).toBe(true);
        expect(shouldPush(container('base', 'term::topics::food::el').payload)).toBe(true);
        expect(shouldPush(container('base', 'term::topics::food::en').payload)).toBe(true);
    });

    it.each([
        ['an inline editor', container('relate-fieldtype-inline-3').payload],
        ['an inline term editor', container('relate-fieldtype-inline-3', 'term::topics::food::el').payload],
        ['a global set', container('base', 'globals::site_settings::el').payload],
        ['the create page', container('base', '').payload],
        ['a missing reference', container('base', null).payload],
    ])('ignores %s', (_, payload) => {
        expect(shouldPush(payload)).toBe(false);
    });

    it('ignores every container when multi-site is off', () => {
        config['tolk.multisite'] = false;

        expect(shouldPush(container().payload)).toBe(false);
    });

    it('ignores every container without the permission to translate', () => {
        permissions = ['access cp'];

        expect(shouldPush(container().payload)).toBe(false);

        permissions = ['super'];

        expect(shouldPush(container().payload)).toBe(true);
    });
});

describe('createBanners', () => {
    let events;
    let banners;
    let abort;

    beforeEach(() => {
        events = bus();
        abort = vi.fn();
        banners = createBanners({ abort });
        banners.listen(events);
    });

    it('listens once', () => {
        banners.listen(events);

        expect(events.count('publish-container-created')).toBe(1);
        expect(events.count('publish-container-destroyed')).toBe(1);
    });

    it('pushes the banner once per container, with the container name', () => {
        const base = container();
        const inline = container('relate-fieldtype-inline-3');

        events.$emit('publish-container-created', base.payload);
        events.$emit('publish-container-created', inline.payload);

        expect(base.payload.pushComponent).toHaveBeenCalledOnce();
        expect(base.payload.pushComponent).toHaveBeenCalledWith('tolk-banner', { props: { containerName: 'base' } });
        expect(inline.payload.pushComponent).not.toHaveBeenCalled();
        expect(banners.pushed('base')).toBe(1);
    });

    it('starts a new container afresh, even on the same item', () => {
        store.switched('base', 'entry::a', 1_000);
        store.setPhase('base', 'done');

        events.$emit('publish-container-created', container().payload);

        expect(store.containers.base).toBeUndefined();
    });

    it('stops a translation still running for the container a new one replaces', () => {
        events.$emit('publish-container-created', container('base', '').payload);
        events.$emit('publish-container-created', container('relate-fieldtype-inline-3').payload);

        expect(abort.mock.calls).toEqual([['base'], ['relate-fieldtype-inline-3']]);
    });

    it('also starts a form without a banner afresh, so the create page does not inherit a translation in progress', () => {
        store.switched('base', 'entry::a', 1_000);
        store.setPhase('base', 'working');

        events.$emit('publish-container-created', container('base', '').payload);

        expect(abort).toHaveBeenCalledExactlyOnceWith('base');
        expect(store.containers.base).toBeUndefined();
        expect(store.phaseOf('base')).toBe('idle');
    });

    it('stops the translation and forgets the state once the last container of a name is destroyed', () => {
        events.$emit('publish-container-created', container().payload);
        store.switched('base', 'entry::a', 1_000);
        store.setPhase('base', 'working');

        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(abort.mock.calls).toEqual([['base'], ['base']]);
        expect(store.containers.base).toBeUndefined();
    });

    it('keeps the state of a container that replaced its predecessor before that one was destroyed', () => {
        events.$emit('publish-container-created', container('base', 'entry::a').payload);
        events.$emit('publish-container-created', container('base', 'entry::b').payload);
        store.switched('base', 'entry::b', 1_000);
        store.setPhase('base', 'working');

        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(abort).toHaveBeenCalledTimes(2);
        expect(store.containers.base).toMatchObject({ reference: 'entry::b', phase: 'working' });
    });

    it('destroys the banner with its container', () => {
        const base = container();

        events.$emit('publish-container-created', base.payload);
        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(base.pushed[0].destroy).toHaveBeenCalledOnce();
        expect(banners.pushed('base')).toBe(0);
    });

    it('destroys the oldest banner when a container is replaced before its predecessor is destroyed', () => {
        const first = container('base', 'entry::a');
        const second = container('base', 'entry::b');

        events.$emit('publish-container-created', first.payload);
        events.$emit('publish-container-created', second.payload);
        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(first.pushed[0].destroy).toHaveBeenCalledOnce();
        expect(second.pushed[0].destroy).not.toHaveBeenCalled();
        expect(banners.pushed('base')).toBe(1);
    });

    it('keeps the banner of an entry that replaces a form without one', () => {
        const create = container('base', '');
        const entry = container('base', 'entry::a');

        events.$emit('publish-container-created', create.payload);
        events.$emit('publish-container-created', entry.payload);
        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(entry.pushed[0].destroy).not.toHaveBeenCalled();
        expect(banners.pushed('base')).toBe(1);
    });
});

describe('attachBanner', () => {
    let events;
    let registry;
    let base;
    let loadStatus;
    let clock;

    const status = (reference) => ({ translatable: true, reference });

    beforeEach(() => {
        vi.useFakeTimers();
        events = bus();
        registry = createRegistry();
        registry.listen(events);
        base = container();
        events.$emit('publish-container-created', base.payload);
        loadStatus = vi.fn(async (reference) => status(reference));
        clock = 1_000;
    });

    function attach(overrides = {}) {
        return attachBanner({ name: 'base', ctx: base.context, store, registry, loadStatus, now: () => clock, ...overrides });
    }

    async function settled() {
        await nextTick();
        await vi.advanceTimersByTimeAsync(0);
    }

    it('starts afresh on a first mount and loads the status', async () => {
        attach();
        await settled();

        expect(loadStatus).toHaveBeenCalledExactlyOnceWith('entry::a');
        expect(store.container('base')).toMatchObject({ reference: 'entry::a', lastChangeAt: 1_000, status: status('entry::a') });
        expect(registry.context('base')).toBe(base.context);
    });

    it('takes the clean values again once the fieldtypes have settled', async () => {
        attach();
        await settled();

        base.context.values.value = { ...base.context.values.value, intro: 'Normalised' };
        expect(registry.dirtyHandles('base')).toEqual(['intro']);

        await vi.advanceTimersByTimeAsync(SETTLE_MS - 1);
        expect(registry.dirtyHandles('base')).toEqual(['intro']);

        await vi.advanceTimersByTimeAsync(1);
        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('keeps the state, the clean values and a running translation when re-mounted on the same item', async () => {
        const first = attach();
        await settled();
        await vi.advanceTimersByTimeAsync(SETTLE_MS);
        first.stop();

        store.setPhase('base', 'working');
        store.container('base').delivered = { title: { value: '[el] Title' } };
        base.context.values.value = { ...base.context.values.value, title: '[el] Title' };
        clock = 5_000;

        const context = { ...base.context };
        attach({ ctx: context });
        await settled();
        await vi.advanceTimersByTimeAsync(SETTLE_MS);

        expect(loadStatus).toHaveBeenCalledOnce();
        expect(store.container('base')).toMatchObject({ lastChangeAt: 1_000, phase: 'working', delivered: { title: { value: '[el] Title' } } });
        expect(registry.context('base')).toBe(context);
        expect(registry.dirtyHandles('base')).toEqual(['title']);
    });

    it('loads the status again when re-mounted before it arrived', async () => {
        loadStatus.mockRejectedValueOnce(new ApiError({ status: 0, code: null, message: 'Offline', data: null }));
        const first = attach();
        await settled();
        first.stop();

        attach();
        await settled();

        expect(loadStatus).toHaveBeenCalledTimes(2);
        expect(store.container('base').status).toEqual(status('entry::a'));
    });

    it('follows a site switch in place', async () => {
        attach();
        await settled();
        store.setPhase('base', 'done');

        clock = 9_000;
        base.context.reference.value = 'entry::b';
        base.context.site.value = 'de';
        base.context.values.value = { title: 'Titel', intro: 'Einleitung' };
        await settled();

        expect(loadStatus).toHaveBeenLastCalledWith('entry::b');
        expect(store.container('base')).toMatchObject({ reference: 'entry::b', lastChangeAt: 9_000, phase: 'idle', status: status('entry::b') });
        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('stops a running translation on a switch, not on a re-mount', async () => {
        const onSwitch = vi.fn();
        const first = attach({ onSwitch });
        await settled();
        expect(onSwitch).toHaveBeenCalledExactlyOnceWith('base');
        first.stop();

        attach({ onSwitch });
        await settled();
        expect(onSwitch).toHaveBeenCalledOnce();

        base.context.reference.value = 'entry::b';
        await settled();
        expect(onSwitch).toHaveBeenCalledTimes(2);
    });

    it('drops a status that arrives after the editor switched away', async () => {
        let answer;
        loadStatus.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        attach();
        await settled();

        base.context.reference.value = 'entry::b';
        await settled();
        answer(status('entry::a'));
        await settled();

        expect(store.container('base').status).toEqual(status('entry::b'));
    });

    it('hands every status it keeps to the push module, which follows a run still running', async () => {
        let answer;
        const onStatus = vi.fn();
        loadStatus.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        attach({ onStatus });
        await settled();

        base.context.reference.value = 'entry::b';
        await settled();
        answer(status('entry::a'));
        await settled();

        expect(onStatus).toHaveBeenCalledExactlyOnceWith('base', status('entry::b'));
    });

    it('does not take the clean values late after a later switch', async () => {
        attach();
        await settled();

        clock = 1_300;
        await vi.advanceTimersByTimeAsync(300);
        base.context.reference.value = 'entry::b';
        await settled();
        base.context.values.value = { ...base.context.values.value, intro: 'Typed' };

        await vi.advanceTimersByTimeAsync(SETTLE_MS - 300);
        expect(registry.dirtyHandles('base')).toEqual(['intro']);
    });

    it('does not take the clean values late after a save', async () => {
        attach();
        await settled();

        await vi.advanceTimersByTimeAsync(300);
        store.saved('base', 1_300);
        base.context.values.value = { ...base.context.values.value, intro: 'Typed' };

        await vi.advanceTimersByTimeAsync(SETTLE_MS - 300);
        expect(registry.dirtyHandles('base')).toEqual(['intro']);
    });

    it('shows nothing for a user who may not edit the item', async () => {
        loadStatus.mockRejectedValue(new ApiError({ status: 403, code: null, message: 'Forbidden', data: null }));
        const { failure } = attach();
        await settled();

        expect(failure.value).toBeNull();
        expect(store.container('base').status).toBeNull();
    });

    it('reports any other failure', async () => {
        const error = new ApiError({ status: 500, code: null, message: 'Server error', data: null });
        loadStatus.mockRejectedValue(error);
        const { failure } = attach();
        await settled();

        expect(failure.value).toBe(error);
    });

    it('does not ask for the status of a read-only form', async () => {
        base.context.readOnly.value = true;
        attach();
        await settled();

        expect(loadStatus).not.toHaveBeenCalled();
        expect(store.container('base').reference).toBe('entry::a');
    });

    it('stops following the container once stopped', async () => {
        const { stop } = attach();
        await settled();
        stop();

        base.context.reference.value = 'entry::b';
        await settled();

        expect(loadStatus).toHaveBeenCalledOnce();
        expect(store.container('base').reference).toBe('entry::a');
    });
});

describe('leaving a form while it translates', () => {
    class PipelineStopped extends Error {}

    let events;
    let registry;
    let posted;
    let answers;
    let translations;
    let saving;
    let toasts;
    let dirty;

    const plan = {
        plan: 'p1',
        units: ['u1', 'u2', 'u3', 'u4', 'u5', 'u6'].map((id) => ({ id, fields: ['intro'], chars: 10 })),
        fields: ['intro'],
        skipped: [],
        ready: [],
    };

    const unitResponse = () => ({ fields: { intro: { value: '[el] Intro', meta: null, incomplete: false, warnings: [] } }, spawned: [], failed: [], warnings: [], usage: {} });

    beforeEach(() => {
        vi.useFakeTimers({ now: 100_000 });
        toasts = [];
        dirty = new Set();
        vi.stubGlobal('Statamic', {
            ...Statamic,
            $dirty: { add: (name) => dirty.add(name), remove: (name) => dirty.delete(name), has: (name) => dirty.has(name) },
            $toast: { error: (message) => toasts.push(message) },
        });
        vi.stubGlobal('__', (key) => key);
        events = bus();
        registry = createRegistry();
        registry.listen(events);
        posted = [];
        answers = [];

        const api = {
            post: vi.fn((path, data) => {
                if (path === 'editor/plan') {
                    return Promise.resolve(plan);
                }

                posted.push(data.unit);

                return new Promise((resolve) => answers.push(resolve));
            }),
        };

        translations = createTranslations({ store: () => store, registry, api, concurrency: () => 2 });
        createBanners({ abort: (name) => translations.abort(name) }).listen(events);
        saving = createSaveHooks({ PipelineStopped, store: () => store, registry, running: (name) => translations.running(name) }).saving;
    });

    // The German page of an entry with its banner attached, translating with two units in flight.
    async function translating() {
        const entry = container('base', 'entry::a', { title: 'Titel', intro: 'Einleitung' });
        const ctx = {
            ...entry.context,
            originValues: ref({ title: 'Title', intro: 'Intro' }),
            meta: ref({ title: null, intro: null }),
            setFieldMeta: vi.fn(),
            setFieldValue: vi.fn((handle, value) => (ctx.values.value[handle] = value)),
            desyncField: vi.fn(),
            withoutDirtying: (callback) => callback(),
        };

        const loadStatus = async () => ({ translatable: true, localization: { fields: { intro: { status: 'outdated' } } } });

        events.$emit('publish-container-created', entry.payload);
        attachBanner({ name: 'base', ctx, store, registry, loadStatus, onStatus: () => {} });
        await vi.advanceTimersByTimeAsync(SETTLE_MS);

        translations.translate('base');
        await vi.advanceTimersByTimeAsync(0);

        expect(store.phaseOf('base')).toBe('working');
        expect(posted).toEqual(['u1', 'u2']);

        return ctx;
    }

    function save() {
        return new Promise((resolve) => saving(() => resolve(true), () => resolve(false), {}));
    }

    async function answerAll() {
        while (answers.length) {
            answers.shift()(unitResponse());
            await vi.advanceTimersByTimeAsync(SETTLE_MS);
        }
    }

    it('lets the create page that replaces it save, and posts no further unit', async () => {
        const ctx = await translating();

        events.$emit('publish-container-created', container('base', '').payload);
        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(await save()).toBe(true);
        expect(toasts).toEqual([]);

        await answerAll();

        expect(posted).toEqual(['u1', 'u2']);
        expect(ctx.values.value.intro).toBe('Einleitung');
        expect(dirty.size).toBe(0);
    });

    it('stops when the editor leaves for a page without a form', async () => {
        const ctx = await translating();

        events.$emit('publish-container-destroyed', { name: 'base' });
        await answerAll();

        expect(posted).toEqual(['u1', 'u2']);
        expect(ctx.values.value.intro).toBe('Einleitung');
        expect(translations.running('base')).toBe(false);
        expect(store.containers.base).toBeUndefined();
    });
});
