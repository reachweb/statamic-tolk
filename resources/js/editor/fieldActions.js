import { asMap } from '../api.js';
import { CORE_CLOSE_MS } from '../modal.js';
import { isTermReference } from './banner.js';
import { BASE, registry as sharedRegistry, sameText, textsOf } from './registry.js';
import { describeSummary, needsSetup } from './statuses.js';
import { useEditorStore } from './store.js';
import { BUSY, savingMessage, translations as sharedTranslations } from './translate.js';

// The confirmation the action opens (`components/TranslateFieldConfirm.vue`), registered under this name in `cp.js`.
export const CONFIRM = 'tolk-translate-field-confirm';

// Deep equality without regard to key order.
function sameValue(a, b) {
    if (a === b) {
        return true;
    }

    if (Array.isArray(a) || Array.isArray(b)) {
        return Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((item, index) => sameValue(item, b[index]));
    }

    if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') {
        return false;
    }

    const keys = Object.keys(a);

    return keys.length === Object.keys(b).length && keys.every((key) => Object.hasOwn(b, key) && sameValue(a[key], b[key]));
}

// "Translate from origin", a button in the header of the top-level fields of a localization's own form (§A13 2). Core
// registers field actions per fieldtype, so the action also renders in stacked editors, global sets and the addon's
// settings form.
export function createFieldActions({
    registry = sharedRegistry,
    store = useEditorStore,
    translations = sharedTranslations,
    fieldActions = () => Statamic.$fieldActions,
    config = () => Statamic.$config,
    permissions = () => Statamic.$permissions,
    toast = () => Statamic.$toast,
    components = () => Statamic.$components,
    doc = () => globalThis.document,
    wait = setTimeout,
} = {}) {
    let registered = false;

    // Evaluated inside core's cached computed, so everything it reads is reactive: the registry's containers and the
    // store's status. A `text` field's payload does not say which form it belongs to (R3 §3.3), so while any container
    // other than `base` is mounted the action shows nowhere.
    function visible({ vm, fieldPathPrefix, handle, config: field, isReadOnly }) {
        if (fieldPathPrefix || isReadOnly || !field?.localizable || !permissions().has('translate content')) {
            return false;
        }

        if ((vm && vm.publishContainer?.name !== BASE) || registry.others().length > 0) {
            return false;
        }

        const ctx = registry.base();
        const container = store().containers[BASE];
        const status = container?.status;

        // A store entry left by an entry page the CP navigated away from belongs to another item.
        if (!ctx || ctx.readOnly?.value || !status?.localization || container.reference !== ctx.reference.value) {
            return false;
        }

        const inScope = asMap(status.localization.fields)[handle];

        return (
            !needsSetup(status) &&
            Object.hasOwn(ctx.originValues?.value ?? {}, handle) &&
            Boolean(inScope) &&
            inScope.status !== 'not_applicable'
        );
    }

    // Read while core renders the button, so the store's phase is reactive here too.
    function disabled() {
        return BUSY.includes(store().containers[BASE]?.phase);
    }

    // Whether the translation could replace text the editor would miss: the field has text, and not the origin's, which
    // an untranslated field shows.
    function replacesText({ handle, value }) {
        const origin = registry.base()?.originValues?.value ?? {};

        return textsOf(value) !== null && !sameText(value, origin[handle]);
    }

    // The field is replaced even when the editor typed into it, so it is not sent as an unsaved edit to skip.
    async function translateField({ handle, value }) {
        const ctx = registry.base();
        const editor = store();

        if (!ctx || !sameValue(value, ctx.values.value[handle])) {
            toast().error(
                isTermReference(editor.containers[BASE]?.reference)
                    ? __('This field is not part of the term being translated.')
                    : __('This field is not part of the entry being translated.'),
            );

            return;
        }

        if (BUSY.includes(editor.phaseOf(BASE)) || translations.running(BASE)) {
            toast().error(__('A translation is already running. Try again when it has finished.'));

            return;
        }

        // Before the translation refuses to start, which would leave an earlier translation's error to be reported below.
        if (translations.saving(BASE)) {
            toast().error(savingMessage());

            return;
        }

        const container = editor.containers[BASE];
        const result = await translations.translate(BASE, { mode: 'all', fields: [handle], overwriteManual: true, replace: true });

        // A switch to another site or entry: the banner of the new one says what there is to say.
        if (editor.containers[BASE] !== container) {
            return;
        }

        if (container.phase === 'error') {
            toast().error(container.error?.message || __('Something went wrong.'));

            return;
        }

        if (result && !result.cancelled && !result.delivered.includes(handle)) {
            const summary = describeSummary(result, container.status);

            toast().error(summary.failed ?? summary.edited ?? summary.hidden ?? summary.translated);
        }
    }

    // The action's button in the header of the field its label names, which core labels with the action's title.
    function trigger(handle) {
        const label = [...(doc()?.querySelectorAll('label[for]') ?? [])].find((element) => element.getAttribute('for') === `field_${handle}`);
        const header = label?.closest('[data-ui-input-group]')?.querySelector('[data-ui-field-header]');

        return [...(header?.querySelectorAll('button[aria-label]') ?? [])].find((button) => button.getAttribute('aria-label') === title()) ?? null;
    }

    // The banner shows the progress, and keeps the focus on its Cancel while the translation runs.
    function progress() {
        const banner = doc()?.querySelector('[data-tolk-banner]');

        return banner?.querySelector('[data-tolk-action="cancel"]') ?? banner?.querySelector('[data-tolk-focus]') ?? null;
    }

    // Once the confirmation has closed, where the focus is lost to the page's body.
    function moveFocus(target) {
        for (const delay of [0, CORE_CLOSE_MS + 100]) {
            wait(() => {
                const active = doc()?.activeElement;

                if (!active || active === doc().body) {
                    target()?.focus();
                }
            }, delay);
        }
    }

    // The confirmation leaves the focus on the page's body, and before Statamic 6.36 a disabled button dropped its focus
    // (since then it stays focusable, `aria-disabled`): a focus lost either way goes to the banner.
    function start(payload) {
        translateField(payload).catch(() => toast().error(__('Something went wrong.')));
        moveFocus(() => progress() ?? trigger(payload.handle));
    }

    // Not async: core reads a returned promise from `payload.vm`, which composition-API fieldtypes such as `text` do
    // not have (R3 §3.4). Progress shows in the banner. The action asks first itself, and only before it replaces text:
    // core's confirmation leaves the focus on the page's body when it closes.
    function run(payload) {
        if (!replacesText(payload)) {
            start(payload);

            return;
        }

        const confirmation = components().append(CONFIRM, { props: {} });

        confirmation.on('confirm', () => {
            confirmation.destroy();
            start(payload);
        });
        confirmation.on('cancel', () => {
            confirmation.destroy();
            moveFocus(() => trigger(payload.handle));
        });
    }

    function title() {
        return __('Translate from origin');
    }

    const action = {
        get title() {
            return title();
        },
        quick: true,
        icon: 'dictionary-language-book',
        visible,
        disabled,
        run,
    };

    function register() {
        if (registered) {
            return;
        }

        registered = true;

        for (const handle of config().get('tolk.fieldtypes') ?? []) {
            fieldActions().add(`${handle}-fieldtype`, action);
        }
    }

    return { register, action, visible, disabled, run };
}

export const fieldActions = createFieldActions();
