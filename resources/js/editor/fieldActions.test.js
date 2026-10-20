import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, nextTick, ref } from 'vue';
import { CONFIRM, createFieldActions } from './fieldActions.js';
import { createRegistry } from './registry.js';
import { useEditorStore } from './store.js';
import { createTranslations } from './translate.js';

let store;
let registry;
let events;
let permissions;
let toast;
let added;
let confirmations;

beforeEach(() => {
    permissions = new Set(['translate content']);
    toast = { error: vi.fn() };
    added = [];
    confirmations = fakeComponents();
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

// The English origin's: the German title is a translation, the other fields are not translated yet.
const originValues = () => ({ ...values(), title: 'Home' });

// The German home page's base container, created and attached as the banner attaches it, with its status loaded.
function mount({ reference = 'entry::de', loaded = status(), readOnly = false, origin = originValues() } = {}) {
    const ctx = {
        name: ref('base'),
        reference: ref(reference),
        site: ref('de'),
        values: ref(values()),
        originValues: ref(origin),
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
        saving: vi.fn(() => false),
    };
}

// Core's `$components`: the confirmations the action appends, each answered with `answer` as soon as the action listens
// for it; with null, the test answers.
function fakeComponents(answer = 'confirm') {
    const appended = [];

    return {
        appended,
        append: vi.fn((name, { props }) => {
            const component = {
                name,
                props,
                handlers: {},
                destroy: vi.fn(),
                on(event, handler) {
                    component.handlers[event] = handler;

                    if (event === answer) {
                        queueMicrotask(handler);
                    }
                },
            };

            appended.push(component);

            return component;
        }),
    };
}

// The page as far as the focus goes: the title field, whose header holds the action's button next to another of core's,
// and the banner.
function page() {
    const doc = { body: { name: 'body' }, activeElement: null };
    const element = (name, find = {}) => ({
        name,
        find,
        focus() {
            doc.activeElement = this;
        },
        querySelector(selector) {
            return this.find[selector] ?? null;
        },
        querySelectorAll(selector) {
            return this.find[`all ${selector}`] ?? [];
        },
        getAttribute(name) {
            return this.find[`@${name}`] ?? null;
        },
    });
    const button = element('action button', { '@aria-label': 'Translate from origin' });
    const fullscreen = element('fullscreen button', { '@aria-label': 'Toggle Fullscreen Mode' });
    const header = element('title header', { 'all button[aria-label]': [fullscreen, button] });
    const field = element('title field', { '[data-ui-field-header]': header });
    const label = (target, group = null) => ({ getAttribute: () => target, closest: (selector) => (selector === '[data-ui-input-group]' ? group : null) });
    const planning = element('planning');
    const cancel = element('cancel');
    const banner = element('banner', { '[data-tolk-focus]': planning });

    doc.querySelectorAll = (selector) => (selector === 'label[for]' ? [label('field_intro'), label('field_title', field)] : []);
    doc.querySelector = (selector) => (selector === '[data-tolk-banner]' ? banner : null);
    doc.activeElement = doc.body;

    return { doc, button, banner, planning, cancel };
}

function actions(overrides = {}) {
    return createFieldActions({
        registry,
        store: () => store,
        translations: fakeTranslations(),
        fieldActions: () => ({ add: (binding, action) => added.push([binding, action]) }),
        permissions: () => ({ has: (permission) => permissions.has(permission) }),
        toast: () => toast,
        components: () => confirmations,
        wait: vi.fn(),
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

    // Core's confirmation leaves the focus on the page's body when it closes; the action asks itself.
    it('is a titled button with the addon\'s icon, and asks for confirmation itself', () => {
        const { action } = actions();

        expect(action.title).toBe('Translate from origin');
        expect(action.quick).toBe(true);
        expect(action.icon).toBe('dictionary-language-book');
        expect(action.confirm).toBeUndefined();
        expect(action.visibleWhenReadOnly).toBeUndefined();
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
        mount({ origin: { ...originValues(), intro: undefined } });
        delete registry.base().originValues.value.intro;
        const { visible } = actions();

        expect(visible(payload('intro'))).toBe(false);
        expect(visible(payload('css_class'))).toBe(false);
        expect(visible(payload('empty'))).toBe(false);
        expect(visible(payload('title'))).toBe(true);
    });

    // Unlike a dropdown item, a quick action's button honours `disabled`, so the field's header keeps its buttons.
    it('stays, disabled, while a translation is under way', () => {
        mount();
        const { visible, disabled } = actions();

        for (const [phase, busy] of [['planning', true], ['working', true], ['offer', false], ['done', false], ['error', false], ['idle', false]]) {
            store.setPhase('base', phase);
            expect(visible(payload())).toBe(true);
            expect(disabled(payload())).toBe(busy);
        }
    });

    it('is enabled without a base form', () => {
        expect(actions().disabled(payload())).toBe(false);
    });

    it('is re-evaluated by a computed when a stack opens or the status arrives, and disabled when the phase changes', async () => {
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

        const { disabled } = actions();
        const off = computed(() => disabled(payload()));

        expect(off.value).toBe(false);

        store.setPhase('base', 'working');
        expect(shown.value).toBe(true);
        expect(off.value).toBe(true);

        store.setPhase('base', 'done');
        expect(off.value).toBe(false);
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

    it('asks first, and translates only once Translate is chosen', async () => {
        mount();
        const translations = fakeTranslations();
        confirmations = fakeComponents(null);
        const { run } = actions({ translations });

        run(payload('title'));
        await flush();
        const [confirmation] = confirmations.appended;

        expect(confirmation.name).toBe(CONFIRM);
        expect(translations.translate).not.toHaveBeenCalled();

        confirmation.handlers.confirm();
        await flush();

        expect(confirmation.destroy).toHaveBeenCalledOnce();
        expect(translations.translate).toHaveBeenCalledOnce();
    });

    it('translates at once, without asking, a field without text or with the origin\'s', async () => {
        const ctx = mount();
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        // Bard's value of an empty field: a paragraph without text.
        ctx.values.value.title = '';
        ctx.values.value.content = [{ type: 'paragraph', attrs: { textAlign: null } }];
        run(payload('title', { value: '' }));
        run(payload('content', { value: [{ type: 'paragraph', attrs: { textAlign: null } }] }));
        await flush();
        ctx.values.value.title = null;
        run(payload('title', { value: null }));
        await flush();
        // An untranslated field shows the origin's text.
        run(payload('intro'));
        await flush();

        expect(confirmations.append).not.toHaveBeenCalled();
        expect(translations.translate.mock.calls.map(([, { fields }]) => fields)).toEqual([['title'], ['content'], ['title'], ['intro']]);
    });

    it('asks first before it replaces text of the localization\'s own, typed text included', async () => {
        const ctx = mount();
        const translations = fakeTranslations();
        confirmations = fakeComponents(null);
        const { run } = actions({ translations });

        run(payload('title'));
        ctx.values.value.intro = 'Ein Familienhotel.';
        run(payload('intro', { value: 'Ein Familienhotel.' }));
        await flush();

        expect(confirmations.appended.map(({ name }) => name)).toEqual([CONFIRM, CONFIRM]);
        expect(translations.translate).not.toHaveBeenCalled();
    });

    it('asks first when the base form has gone, and then reports it', async () => {
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        run(payload('intro'));
        await flush();

        expect(confirmations.append).toHaveBeenCalledOnce();
        expect(translations.translate).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledExactlyOnceWith('This field is not part of the entry being translated.');
    });

    it('moves a focus the disabled button lost into the banner after a translation that did not ask', async () => {
        mount();
        const { doc, button, planning } = page();
        const wait = vi.fn();

        doc.activeElement = button;
        actions({ doc: () => doc, wait }).run(payload('intro'));
        await flush();

        expect(wait.mock.calls.map(([, delay]) => delay)).toEqual([0, 400]);

        // A browser that keeps the focus on the disabled button.
        wait.mock.calls[0][0]();
        expect(doc.activeElement).toBe(button);

        doc.activeElement = doc.body;
        wait.mock.calls[1][0]();
        expect(doc.activeElement).toBe(planning);
    });

    it('translates nothing on Cancel or Esc, and gives the focus back to the action\'s button', async () => {
        mount();
        const translations = fakeTranslations();
        const { doc, button } = page();
        const wait = vi.fn();
        confirmations = fakeComponents('cancel');

        actions({ translations, doc: () => doc, wait }).run(payload('title'));
        await flush();

        expect(confirmations.appended[0].destroy).toHaveBeenCalledOnce();
        expect(wait.mock.calls.map(([, delay]) => delay)).toEqual([0, 400]);

        wait.mock.calls[0][0]();

        expect(doc.activeElement).toBe(button);
        expect(translations.translate).not.toHaveBeenCalled();
    });

    it('moves the focus into the banner after Translate, to its Cancel once the translation runs', async () => {
        mount();
        const { doc, banner, planning, cancel } = page();
        const wait = vi.fn();

        actions({ doc: () => doc, wait }).run(payload('title'));
        await flush();
        wait.mock.calls[0][0]();

        // While the translation is planned, the banner's planning line; its focus keeper moves on to Cancel.
        expect(doc.activeElement).toBe(planning);

        banner.find['[data-tolk-action="cancel"]'] = cancel;
        doc.activeElement = doc.body;
        wait.mock.calls[1][0]();

        expect(doc.activeElement).toBe(cancel);
    });

    it('leaves a focus that is somewhere once the confirmation has closed', async () => {
        mount();
        const { doc, button } = page();
        const wait = vi.fn();
        confirmations = fakeComponents('cancel');

        actions({ doc: () => doc, wait }).run(payload('title'));
        await flush();
        const field = { name: 'another field' };
        doc.activeElement = field;
        wait.mock.calls.forEach(([callback]) => callback());

        expect(doc.activeElement).toBe(field);
        expect(doc.activeElement).not.toBe(button);
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

    it('names a term in a term form', async () => {
        mount({ reference: 'term::topics::travel::de' });
        const translations = fakeTranslations();
        const { run } = actions({ translations });

        run(payload('title', { value: 'Another form\'s title' }));
        await flush();

        expect(translations.translate).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledExactlyOnceWith('This field is not part of the term being translated.');
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

    // Core's save response replaces the form's values. Without the check first, the action would also report the error
    // an earlier translation ended with, which the refused start leaves in place.
    it('reports a save on its way, and not the error an earlier translation ended with', async () => {
        mount();
        const post = vi.fn();
        const translations = createTranslations({ store: () => store, registry, api: { post }, saving: (name) => name === 'base', toast: () => toast });
        store.setPhase('base', 'error', { message: 'Rate limited' });

        actions({ translations }).run(payload());
        await flush();

        expect(post).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledExactlyOnceWith('The form is being saved. Try again when the save has finished.');
    });

    it('reports an error the translation ended with', async () => {
        mount();
        const translations = {
            translate: vi.fn(async () => {
                store.setPhase('base', 'error', { status: 422, code: null, message: 'The plan could not be made.' });

                return null;
            }),
            running: () => false,
            saving: () => false,
        };

        actions({ translations }).run(payload());
        await flush();

        expect(toast.error).toHaveBeenCalledExactlyOnceWith('The plan could not be made.');
    });

    it('reports a field that was not filled, by its label', async () => {
        mount();
        const failed = fakeTranslations({ delivered: [], incomplete: [], skipped: {}, failed: { title: 'invalid_output' }, cancelled: false, models: [] });
        const edited = fakeTranslations({ delivered: [], incomplete: [], skipped: { title: 'edited' }, failed: {}, cancelled: false, models: [] });
        const hidden = fakeTranslations({ delivered: [], incomplete: [], skipped: { title: 'hidden' }, failed: {}, cancelled: false, models: [] });
        const nothing = fakeTranslations({ delivered: [], incomplete: [], skipped: {}, failed: {}, cancelled: false, models: [] });

        actions({ translations: failed }).run(payload());
        actions({ translations: edited }).run(payload());
        actions({ translations: hidden }).run(payload());
        actions({ translations: nothing }).run(payload());
        await flush();

        expect(toast.error.mock.calls).toEqual([
            ['Title could not be translated.'],
            ['Title was skipped because you edited it.'],
            ['Title was skipped because it is hidden. Show it and translate again.'],
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
            saving: () => false,
        };

        actions({ translations: cancelled }).run(payload());
        await flush();
        actions({ translations: switched }).run(payload());
        await flush();

        expect(toast.error).not.toHaveBeenCalled();
    });

    it('reports a failure that throws', async () => {
        mount();
        const translations = { translate: vi.fn(() => Promise.reject(new Error('Boom'))), running: () => false, saving: () => false };

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
