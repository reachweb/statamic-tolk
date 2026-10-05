import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { ApiError } from '../api.js';
import { attachBanner, createBanners, shouldPush } from './banner.js';
import { SETTLE_MS } from './fill.js';
import { createRegistry } from './registry.js';
import { useEditorStore } from './store.js';

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

describe('shouldPush', () => {
    it('pushes into the base container of an entry', () => {
        expect(shouldPush(container().payload)).toBe(true);
    });

    it.each([
        ['an inline editor', container('relate-fieldtype-inline-3').payload],
        ['a term', container('base', 'term::topics::food::el').payload],
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
