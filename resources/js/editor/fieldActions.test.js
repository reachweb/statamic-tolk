import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, nextTick, ref } from 'vue';
import { createFieldActions } from './fieldActions.js';
import { createRegistry } from './registry.js';
import { useEditorStore } from './store.js';
import { createTranslations } from './translate.js';

let store;
let registry;
let events;
let permissions;
let toast;
let added;

beforeEach(() => {
    permissions = new Set(['translate content']);
    toast = { error: vi.fn() };
    added = [];
    vi.stubGlobal('Statamic', {
        $pinia: { defineStore },
        $config: { get: (key) => ({ 'tolk.fieldtypes': ['text', 'bard', 'replicator'], 'tolk.concurrency': 2 })[key] },
        $dirty: { add: () => {}, remove: () => {}, has: () => false },
    });
    vi.stubGlobal('__', (key) => key);
    vi.stubGlobal('__n', (key, count, replacements = {}) =>
        Object.entries(replacements).reduce((text, [name, value]) => text.replace(`:${name}`, value), key.split('|')[count === 1 ? 0 : 1]),
    );
    setActivePinia(createPinia());
    store = useEditorStore();
    registry = createRegistry();
    events = {};
    registry.listen({ $on: (event, callback) => (events[event] = callback) });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const field = (status, label) => ({ status, label, source_changed: null, incomplete: false });

const status = (fields = {}, extra = {}) => ({
    translatable: true,
    localization: {
        fields: {
            title: field('manual', 'Title'),
            intro: field('untranslated', 'Intro'),
            content: field('untranslated', 'Content'),
            empty: field('not_applicable', 'Empty'),
            ...fields,
        },
        counts: { untranslated: 2, manual: 1 },
    },
    origin: null,
    defaults: { translator: 'anthropic:claude-sonnet-5-5', providers_configured: true, fake_engine: false },
    ...extra,
});

const values = () => ({ title: 'Startseite', intro: 'A family-run hotel.', content: '[{"type":"paragraph"}]', empty: null, css_class: 'hero' });

// The German home page's base container, created and attached as the banner attaches it, with its status loaded.
function mount({ reference = 'entry::de', loaded = status(), readOnly = false, originValues = values() } = {}) {
    const ctx = {
        name: ref('base'),
        reference: ref(reference),
        site: ref('de'),
        values: ref(values()),
        originValues: ref(originValues),
        meta: ref({}),
        localizedFields: ref(['title']),
        readOnly: ref(readOnly),
    };

    events['publish-container-created']({ name: 'base', reference: ctx.reference, values: ctx.values });
    registry.attachContext('base', ctx);
    store.switched('base', reference, 0);
    store.container('base').status = loaded;

    return ctx;
}

// The payload core passes for a top-level field of a composition-API fieldtype such as `text` (no `vm`).
const payload = (handle = 'title', overrides = {}) => ({
    fieldPathPrefix: undefined,
    handle,
    value: values()[handle],
    config: { type: 'text', handle, localizable: true },
    meta: null,
    update: vi.fn(),
    updateMeta: vi.fn(),
    isReadOnly: false,
    ...overrides,
});

// By default every requested field is filled.
function fakeTranslations(result = null) {
    return {
        translate: vi.fn(async (name, { fields }) => {
            store.setPhase('base', 'done');

            return result ?? { delivered: fields, incomplete: [], skipped: {}, failed: {}, cancelled: false, models: [] };
        }),
        running: vi.fn(() => false),
    };
}

function actions(overrides = {}) {
    return createFieldActions({
        registry,
        store: () => store,
        translations: fakeTranslations(),
        fieldActions: () => ({ add: (binding, action) => added.push([binding, action]) }),
        permissions: () => ({ has: (permission) => permissions.has(permission) }),
        toast: () => toast,
        ...overrides,
    });
}

describe('register', () => {
    it('adds the action for every supported fieldtype by its handle, once', () => {
        const fa = actions();

        fa.register();
        fa.register();

        expect(added.map(([binding]) => binding)).toEqual(['text-fieldtype', 'bard-fieldtype', 'replicator-fieldtype']);
        expect(added.every(([, action]) => action === fa.action)).toBe(true);
    });

    it('adds nothing without supported fieldtypes', () => {
        vi.stubGlobal('Statamic', { $config: { get: () => undefined } });

        actions({ config: () => Statamic.$config }).register();

        expect(added).toEqual([]);
    });

    it('is titled and asks for confirmation', () => {
        const { action } = actions();

        expect(action.title).toBe('Translate from origin');
        expect(action.confirm).toEqual({ text: 'The current content of this field will be replaced.', buttonText: 'Translate' });
        expect(action.visibleWhenReadOnly).toBeUndefined();
        expect(action.quick).toBeUndefined();
    });
});

describe('visible', () => {
    it('shows on the top-level localizable fields of the base form of a localization', () => {
        mount();
        const { visible } = actions();

        expect(visible(payload('title'))).toBe(true);
        expect(visible(payload('intro', { config: { type: 'textarea', localizable: true } }))).toBe(true);
        expect(visible(payload('content', { config: { type: 'bard', localizable: true } }))).toBe(true);
    });

    it('does not show on nested, read-only or non-localizable fields', () => {
        mount();
        const { visible } = actions();

        expect(visible(payload('title', { fieldPathPrefix: 'content.2.attrs.values' }))).toBe(false);
        expect(visible(payload('title', { fieldPathPrefix: 'body.0' }))).toBe(false);
        expect(visible(payload('title', { isReadOnly: true }))).toBe(false);
        expect(visible(payload('css_class', { config: { type: 'text', localizable: false } }))).toBe(false);
        expect(visible(payload('title', { config: { type: 'text' } }))).toBe(false);
    });

    it('needs the permission', () => {
        mount();
        permissions.clear();

        expect(actions().visible(payload())).toBe(false);
    });

    it('does not show while another container is mounted, such as a stacked editor', () => {
        mount();
        const { visible } = actions();

        events['publish-container-created']({ name: 'relate-fieldtype-inline-7', reference: ref('entry::other') });

        expect(visible(payload())).toBe(false);

        events['publish-container-destroyed']({ name: 'relate-fieldtype-inline-7' });

        expect(visible(payload())).toBe(true);
    });

    it('needs a `vm` to belong to the base container when the payload has one', () => {
        mount();
        const { visible } = actions();

        expect(visible(payload('content', { vm: { publishContainer: { name: 'base' } } }))).toBe(true);
        expect(visible(payload('content', { vm: { publishContainer: { name: 'relate-fieldtype-inline-7' } } }))).toBe(false);
        expect(visible(payload('content', { vm: {} }))).toBe(false);
    });

    it('needs an attached base form that is not read-only', () => {
        const { visible } = actions();

        expect(visible(payload())).toBe(false);

        mount({ readOnly: true });

        expect(visible(payload())).toBe(false);
    });

    it('does not show on an origin, before the status arrived, or for a status left by another item', () => {
        const { visible } = actions();

        mount({ loaded: status({}, { localization: null, origin: { localizations: [] } }) });
        expect(visible(payload())).toBe(false);

        mount({ loaded: null });
        expect(visible(payload())).toBe(false);

        // A global set's form after the CP navigated away from the German home page keeps the name `base`.
        const ctx = mount();
        ctx.reference.value = 'globals::site_settings::de';
        expect(visible(payload())).toBe(false);
    });

    it('does not show without a model to translate with', () => {
        mount({ loaded: status({}, { defaults: { translator: null, providers_configured: false, fake_engine: false } }) });

        expect(actions().visible(payload())).toBe(false);
    });

    it('needs the field in scope, in the origin and with text there', () => {
        mount({ originValues: { ...values(), intro: undefined } });
        delete registry.base().originValues.value.intro;
        const { visible } = actions();

        expect(visible(payload('intro'))).toBe(false);
        expect(visible(payload('css_class'))).toBe(false);
        expect(visible(payload('empty'))).toBe(false);
        expect(visible(payload('title'))).toBe(true);
    });

    it('does not show while a translation is under way', () => {
        mount();
        const { visible } = actions();

        for (const [phase, shown] of [['planning', false], ['working', false], ['offer', true], ['done', true], ['error', true], ['idle', true]]) {
            store.setPhase('base', phase);
            expect(visible(payload())).toBe(shown);
        }
    });

    it('is re-evaluated by a computed when a stack opens, the status arrives or the phase changes', async () => {
        mount({ loaded: null });
        const { visible } = actions();
        const shown = computed(() => visible(payload()));

        expect(shown.value).toBe(false);

        store.container('base').status = status();
        await nextTick();
        expect(shown.value).toBe(true);

        events['publish-container-created']({ name: 'relate-fieldtype-inline-7', reference: ref('entry::other') });
        expect(shown.value).toBe(false);

        events['publish-container-destroyed']({ name: 'relate-fieldtype-inline-7' });
        expect(shown.value).toBe(true);

        store.setPhase('base', 'working');
        expect(shown.value).toBe(false);
    });
});

describe('run', () => {
    it('returns no promise and translates the field into the base form, replacing it', async () => {
        mount();
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        expect(run(payload('title'))).toBeUndefined();
        await flush();

        expect(translations.translate).toHaveBeenCalledExactlyOnceWith('base', { mode: 'all', fields: ['title'], overwriteManual: true, replace: true });
        expect(toast.error).not.toHaveBeenCalled();
    });

    it('does nothing but report it when the value is not the base form\'s', async () => {
        mount();
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        run(payload('title', { value: 'Another form\'s title' }));
        await flush();

        expect(translations.translate).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledExactlyOnceWith('This field is not part of the entry being translated.');
    });

    it('compares structured values without regard to key order', async () => {
        const ctx = mount();
        ctx.values.value.body = [{ _id: 'a', type: 'text', heading: 'Hi' }];
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        run(payload('body', { value: [{ heading: 'Hi', type: 'text', _id: 'a' }] }));
        await flush();
        expect(translations.translate).toHaveBeenCalledOnce();

        run(payload('body', { value: [{ heading: 'Hi', type: 'text', _id: 'b' }] }));
        run(payload('body', { value: [{ heading: 'Hi', type: 'text' }] }));
        run(payload('body', { value: [] }));
        await flush();
        expect(translations.translate).toHaveBeenCalledOnce();
        expect(toast.error).toHaveBeenCalledTimes(3);
    });

    it('reports a translation already under way', async () => {
        mount();
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        store.setPhase('base', 'working');
        run(payload());
        await flush();

        store.setPhase('base', 'done');
        translations.running.mockReturnValue(true);
        run(payload());
        await flush();

        expect(translations.translate).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledTimes(2);
        expect(toast.error).toHaveBeenCalledWith('A translation is already running. Try again when it has finished.');
    });

    it('reports an error the translation ended with', async () => {
        mount();
        const translations = {
            translate: vi.fn(async () => {
                store.setPhase('base', 'error', { status: 422, code: null, message: 'The plan could not be made.' });

                return null;
            }),
            running: () => false,
        };

        actions({ translations }).run(payload());
        await flush();

        expect(toast.error).toHaveBeenCalledExactlyOnceWith('The plan could not be made.');
    });

    it('reports a field that was not filled, by its label', async () => {
        mount();
        const failed = fakeTranslations({ delivered: [], incomplete: [], skipped: {}, failed: { title: 'invalid_output' }, cancelled: false, models: [] });
        const edited = fakeTranslations({ delivered: [], incomplete: [], skipped: { title: 'edited' }, failed: {}, cancelled: false, models: [] });
        const nothing = fakeTranslations({ delivered: [], incomplete: [], skipped: {}, failed: {}, cancelled: false, models: [] });

        actions({ translations: failed }).run(payload());
        actions({ translations: edited }).run(payload());
        actions({ translations: nothing }).run(payload());
        await flush();

        expect(toast.error.mock.calls).toEqual([
            ['Title could not be translated.'],
            ['Title was skipped because you edited it.'],
            ['No fields were translated.'],
        ]);
    });

    it('says nothing after a cancelled translation or a switch', async () => {
        mount();
        const cancelled = fakeTranslations({ delivered: [], incomplete: [], skipped: {}, failed: {}, cancelled: true, models: [] });
        const switched = {
            translate: vi.fn(async () => {
                store.switched('base', 'entry::el');
                store.setPhase('base', 'error', { message: 'Gone.' });

                return { delivered: [], incomplete: [], skipped: { title: 'stale' }, failed: {}, cancelled: false, models: [] };
            }),
            running: () => false,
        };

        actions({ translations: cancelled }).run(payload());
        await flush();
        actions({ translations: switched }).run(payload());
        await flush();

        expect(toast.error).not.toHaveBeenCalled();
    });

    it('reports a failure that throws', async () => {
        mount();
        const translations = { translate: vi.fn(() => Promise.reject(new Error('Boom'))), running: () => false };

        actions({ translations }).run(payload());
        await flush();

        expect(toast.error).toHaveBeenCalledExactlyOnceWith('Something went wrong.');
    });

    it('plans the field with the real translations although the editor typed into it', async () => {
        const ctx = mount();
        ctx.values.value.title = 'Mein Titel';
        ctx.values.value.intro = 'Mein eigener Text';
        const post = vi.fn(async () => ({ plan: 'p1', units: [], fields: ['title'], skipped: {}, ready: {} }));
        const run = vi.fn(async () => ({ delivered: ['title'], incomplete: [], skipped: {}, failed: {}, models: [], stopped: null }));
        const translations = createTranslations({ store: () => store, registry, api: { post }, run, fill: vi.fn() });

        actions({ translations }).run(payload('title', { value: 'Mein Titel' }));
        await flush();

        expect(post).toHaveBeenCalledExactlyOnceWith('editor/plan', {
            reference: 'entry::de',
            mode: 'all',
            overwrite_manual: true,
            fields: ['title'],
            skip: ['intro'],
            translator: null,
            review: null,
            reviewer: null,
        });
        expect(store.containers.base.snapshot.fields).toEqual({ title: expect.objectContaining({ value: 'Mein Titel' }) });
        expect(store.containers.base.phase).toBe('done');
        expect(toast.error).not.toHaveBeenCalled();
    });
});
