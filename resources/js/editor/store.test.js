import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';
import { initialState, useEditorStore } from './store.js';

let store;

beforeEach(() => {
    vi.stubGlobal('Statamic', { $pinia: { defineStore } });
    setActivePinia(createPinia());
    store = useEditorStore();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

it('starts a container idle', () => {
    expect(store.container('base')).toEqual({
        reference: null,
        status: null,
        phase: 'idle',
        progress: { total: 0, done: 0, waiting: 0, cancelling: false },
        summary: null,
        snapshot: null,
        delivered: {},
        lastChangeAt: 0,
        planReference: null,
        error: null,
        request: null,
        notSaved: [],
        created: false,
        slugKept: false,
    });
    expect(store.phaseOf('base')).toBe('idle');
    expect(store.phaseOf('unknown')).toBe('idle');
});

it('keeps containers apart', () => {
    store.container('base').delivered.title = { value: 'Τίτλος' };
    store.setPhase('relate-fieldtype-inline-1', 'working');

    expect(store.container('base').phase).toBe('idle');
    expect(store.container('relate-fieldtype-inline-1').delivered).toEqual({});
});

it('is shared by every caller', () => {
    store.setPhase('base', 'offer');

    expect(useEditorStore().phaseOf('base')).toBe('offer');
});

describe('switched', () => {
    it('resets the container to the new item and records the time', () => {
        const base = store.container('base');
        Object.assign(base, {
            status: { translatable: true },
            phase: 'done',
            delivered: { title: {} },
            snapshot: {},
            planReference: 'entry::a',
            created: true,
            slugKept: true,
        });

        store.switched('base', 'entry::b', 1_000);

        expect(store.container('base')).toEqual({ ...initialState(), reference: 'entry::b', lastChangeAt: 1_000 });
    });

    it('defaults to the current time', () => {
        vi.useFakeTimers({ now: 5_000 });

        store.switched('base', 'entry::a');

        expect(store.container('base').lastChangeAt).toBe(5_000);
        vi.useRealTimers();
    });
});

describe('saved', () => {
    it('returns and clears the delivered handles and records the time', () => {
        store.switched('base', 'entry::a', 1_000);
        store.container('base').delivered = { title: { value: 'Τίτλος' }, intro: { value: 'Εισαγωγή' } };
        store.container('base').created = true;

        expect(store.saved('base', 2_000)).toEqual(['title', 'intro']);
        expect(store.container('base').delivered).toEqual({});
        expect(store.container('base').lastChangeAt).toBe(2_000);
        expect(store.container('base').reference).toBe('entry::a');
        // The localization is still the one created in this form; its slug is kept before a later first translation.
        expect(store.container('base').created).toBe(true);
    });

    it('keeps a later switch as the last change', () => {
        store.switched('base', 'entry::a', 3_000);

        expect(store.saved('base', 2_000)).toEqual([]);
        expect(store.container('base').lastChangeAt).toBe(3_000);
    });
});

describe('setPhase', () => {
    it('sets the error only in the error phase', () => {
        store.setPhase('base', 'error', { code: 'plan_expired', message: 'The plan has expired.' });

        expect(store.container('base')).toMatchObject({ phase: 'error', error: { code: 'plan_expired' } });

        store.setPhase('base', 'planning', { ignored: true });

        expect(store.container('base')).toMatchObject({ phase: 'planning', error: null });
    });

    it('refuses an unknown phase', () => {
        expect(() => store.setPhase('base', 'translating')).toThrow('Unknown editor phase [translating].');
        expect(store.phaseOf('base')).toBe('idle');
    });
});

it('forgets a container', () => {
    store.setPhase('base', 'done');
    store.forget('base');

    expect(store.containers).toEqual({});
    expect(store.phaseOf('base')).toBe('idle');
});

// Pinia is a dev dependency for these tests only. The CP takes it from core, so no module may bundle its own (rule 10).
it('is the only Pinia the CP code uses', () => {
    const sources = readdirSync('resources/js', { recursive: true })
        .filter((file) => /\.(js|vue)$/.test(file) && !file.endsWith('.test.js'))
        .filter((file) => /from\s+['"]pinia['"]|import\(\s*['"]pinia['"]/.test(readFileSync(join('resources/js', file), 'utf8')));

    expect(sources).toEqual([]);
});

it('is reactive for computed values that read it', () => {
    const working = computed(() => store.phaseOf('base') === 'working');
    const delivered = computed(() => Object.keys(store.containers.base?.delivered ?? {}).length);

    expect([working.value, delivered.value]).toEqual([false, 0]);

    store.setPhase('base', 'working');
    store.container('base').delivered.title = { value: 'Τίτλος' };

    expect([working.value, delivered.value]).toEqual([true, 1]);

    store.switched('base', 'entry::b');

    expect([working.value, delivered.value]).toEqual([false, 0]);
});
