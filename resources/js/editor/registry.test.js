import { beforeEach, describe, expect, it } from 'vitest';
import { computed, ref } from 'vue';
import { comparable, createRegistry, equalValues } from './registry.js';

function bus() {
    const handlers = {};

    return {
        $on: (event, handler) => (handlers[event] ??= []).push(handler),
        $emit: (event, payload) => (handlers[event] ?? []).forEach((handler) => handler(payload)),
        count: (event) => (handlers[event] ?? []).length,
    };
}

// A publish container as far as the registry sees it: the event payload and the injected context share the refs.
function container(name, values, reference = 'entry::a') {
    const state = { reference: ref(reference), site: ref('el'), values: ref(values), meta: ref({}) };

    return {
        payload: { name, ...state, setFieldValue: (handle, value) => (state.values.value[handle] = value) },
        context: { name: ref(name), ...state, localizedFields: ref([]) },
    };
}

const stored = () => ({
    title: 'Τίτλος',
    intro: 'Εισαγωγή',
    body: [
        { _id: 'r1', type: 'text', text: 'Πρώτο' },
        { _id: 'r2', type: 'text', text: 'Δεύτερο' },
    ],
});

let events;
let registry;

beforeEach(() => {
    events = bus();
    registry = createRegistry();
    registry.listen(events);
});

describe('comparable', () => {
    it('drops _id keys at any depth', () => {
        const value = {
            _id: 'top',
            rows: [{ _id: 'a', text: 'A', set: { _id: 'b', grid: [{ _id: 'c', cell: 'C' }] } }],
        };

        expect(comparable(value)).toEqual({ rows: [{ text: 'A', set: { grid: [{ cell: 'C' }] } }] });
    });

    it('keeps other ids and leaves the value it copies alone', () => {
        const value = [{ _id: 'a', id: 'stored', attrs: { id: 'set' } }];

        expect(comparable(value)).toEqual([{ id: 'stored', attrs: { id: 'set' } }]);
        expect(value[0]._id).toBe('a');
    });

    it('returns scalars and null as they are', () => {
        expect([comparable('text'), comparable(3), comparable(null), comparable(undefined)]).toEqual(['text', 3, null, undefined]);
    });

    it('makes rows that differ only in their random _id equal', () => {
        const loaded = [{ _id: 'x1', type: 'text', text: 'A' }];
        const reloaded = [{ type: 'text', text: 'A', _id: 'y9' }];

        expect(equalValues(loaded, reloaded)).toBe(true);
        expect(equalValues(loaded, [{ _id: 'x1', type: 'text', text: 'B' }])).toBe(false);
    });

    it('ignores key order and keys holding undefined', () => {
        expect(equalValues({ a: 1, b: { c: 2, d: 3 } }, { b: { d: 3, c: 2 }, a: 1, e: undefined })).toBe(true);
        expect(equalValues({ a: 1 }, { a: 1, b: null })).toBe(false);
        expect(equalValues([1, 2], [2, 1])).toBe(false);
        expect(equalValues({ 0: 'a' }, ['a'])).toBe(false);
    });
});

describe('dirtyHandles', () => {
    let base;

    beforeEach(() => {
        base = container('base', stored());
        events.$emit('publish-container-created', base.payload);
        registry.attachContext('base', base.context);
    });

    it('is empty right after the context is attached', () => {
        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('lists the top-level handles changed in the form', () => {
        base.context.values.value.intro = 'Άλλη εισαγωγή';
        base.context.values.value.body[1].text = 'Αλλαγμένο';

        expect(registry.dirtyHandles('base')).toEqual(['intro', 'body']);
    });

    it('lists added and removed handles', () => {
        base.context.values.value.seo_title = 'SEO';
        delete base.context.values.value.title;

        expect(registry.dirtyHandles('base')).toEqual(['seo_title', 'title']);
    });

    it('does not count new random row ids', () => {
        base.context.values.value = { ...stored(), body: stored().body.map((row, i) => ({ ...row, _id: `new${i}` })) };

        expect(registry.dirtyHandles('base')).toEqual([]);
    });

    it('is empty after a save until the next change', () => {
        base.context.values.value.title = 'Νέος τίτλος';
        base.context.values.value.body.push({ _id: 'r3', type: 'text', text: 'Τρίτο' });
        expect(registry.dirtyHandles('base')).toEqual(['title', 'body']);

        // The save response replaces the values with what was stored, rows with ids of their own.
        base.context.values.value = {
            ...stored(),
            title: 'Νέος τίτλος',
            body: [...stored().body, { _id: 'r3', type: 'text', text: 'Τρίτο' }].map((row, i) => ({ ...row, id: `s${i}` })),
        };
        registry.refreshClean('base');

        expect(registry.dirtyHandles('base')).toEqual([]);

        base.context.values.value.intro = 'Μετά την αποθήκευση';

        expect(registry.dirtyHandles('base')).toEqual(['intro']);
    });

    it('is empty after a site switch until the next change', () => {
        base.context.values.value.intro = 'Unsaved Greek';

        // An in-place switch keeps the container and swaps its reference, site and values.
        base.context.reference.value = 'entry::b';
        base.context.site.value = 'de';
        base.context.values.value = { title: 'Titel', intro: 'Einleitung', body: [] };
        expect(registry.dirtyHandles('base')).toEqual(['title', 'intro', 'body']);

        registry.refreshClean('base');

        expect(registry.dirtyHandles('base')).toEqual([]);

        base.context.values.value.title = 'Neuer Titel';

        expect(registry.dirtyHandles('base')).toEqual(['title']);
    });

    it('keeps the clean values when the context is attached again', () => {
        base.context.values.value.title = 'Typed before Live Preview opened';

        // Live Preview mounts a new banner, which injects the same container again.
        registry.attachContext('base', { ...base.context });

        expect(registry.dirtyHandles('base')).toEqual(['title']);
    });

    it('is empty for a container without clean values', () => {
        const stack = container('relate-fieldtype-inline-1', stored());
        events.$emit('publish-container-created', stack.payload);
        stack.payload.values.value.title = 'Changed';

        expect(registry.dirtyHandles('relate-fieldtype-inline-1')).toEqual([]);
        expect(registry.dirtyHandles('unknown')).toEqual([]);
    });

    it('takes clean values from the payload when no context is attached', () => {
        const stack = container('relate-fieldtype-inline-1', stored());
        events.$emit('publish-container-created', stack.payload);
        registry.refreshClean('relate-fieldtype-inline-1');
        stack.payload.values.value.title = 'Changed';

        expect(registry.dirtyHandles('relate-fieldtype-inline-1')).toEqual(['title']);
    });
});

describe('containers', () => {
    it('listens once', () => {
        registry.listen(events);
        registry.listen(events);

        expect(events.count('publish-container-created')).toBe(1);
        expect(events.count('publish-container-destroyed')).toBe(1);
    });

    it('keeps the payload and the attached context per container', () => {
        const base = container('base', stored());
        events.$emit('publish-container-created', base.payload);

        expect(registry.payload('base')).toBe(base.payload);
        expect(registry.context('base')).toBeNull();
        expect(registry.base()).toBeNull();

        registry.attachContext('base', base.context);

        expect(registry.context('base')).toBe(base.context);
        expect(registry.base()).toBe(base.context);
    });

    it('lists the containers other than base', () => {
        events.$emit('publish-container-created', container('base', {}).payload);
        expect(registry.others()).toEqual([]);

        events.$emit('publish-container-created', container('relate-fieldtype-inline-7', {}).payload);
        expect(registry.others()).toEqual(['relate-fieldtype-inline-7']);

        events.$emit('publish-container-destroyed', { name: 'relate-fieldtype-inline-7' });
        expect(registry.others()).toEqual([]);
    });

    it('forgets a destroyed container', () => {
        const base = container('base', stored());
        events.$emit('publish-container-created', base.payload);
        registry.attachContext('base', base.context);

        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(registry.payload('base')).toBeNull();
        expect(registry.base()).toBeNull();
        expect(registry.dirtyHandles('base')).toEqual([]);
        expect(() => events.$emit('publish-container-destroyed', { name: 'base' })).not.toThrow();
    });

    it('keeps a new container whose predecessor of the same name is destroyed after it was created', () => {
        const first = container('base', stored(), 'entry::a');
        const second = container('base', { title: 'Other' }, 'entry::b');
        events.$emit('publish-container-created', first.payload);
        registry.attachContext('base', first.context);

        events.$emit('publish-container-created', second.payload);
        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(registry.payload('base')).toBe(second.payload);
        expect(registry.base()).toBeNull();

        registry.attachContext('base', second.context);
        second.context.values.value.title = 'Changed';

        expect(registry.dirtyHandles('base')).toEqual(['title']);

        events.$emit('publish-container-destroyed', { name: 'base' });

        expect(registry.payload('base')).toBeNull();
    });

    it('is reactive for computed values that read it', () => {
        const alone = computed(() => registry.others().length === 0);
        const attached = computed(() => registry.base() !== null);
        const base = container('base', {});
        events.$emit('publish-container-created', base.payload);

        expect([alone.value, attached.value]).toEqual([true, false]);

        registry.attachContext('base', base.context);
        events.$emit('publish-container-created', container('relate-fieldtype-inline-2', {}).payload);

        expect([alone.value, attached.value]).toEqual([false, true]);

        events.$emit('publish-container-destroyed', { name: 'relate-fieldtype-inline-2' });

        expect(alone.value).toBe(true);
    });
});
