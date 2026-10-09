import { asMap } from '../api.js';
import { CORE_CLOSE_MS } from '../modal.js';
import { isTermReference } from './banner.js';
import { BASE, registry as sharedRegistry } from './registry.js';
import { describeSummary, needsSetup } from './statuses.js';
import { useEditorStore } from './store.js';
import { BUSY, translations as sharedTranslations } from './translate.js';

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

// "Translate from origin" on the top-level fields of a localization's own form (§A13 2). Core registers field actions
// per fieldtype, so the action also renders in stacked editors, global sets and the addon's settings form.
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

    // Evaluated inside core's cached computed, so everything it reads is reactive: the registry's containers, the
    // store's status and phase. A `text` field's payload does not say which form it belongs to (R3 §3.3), so while any
    // container other than `base` is mounted the action shows nowhere.
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
            !BUSY.includes(container.phase) &&
            Object.hasOwn(ctx.originValues?.value ?? {}, handle) &&
            Boolean(inScope) &&
            inScope.status !== 'not_applicable'
        );
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

            toast().error(summary.failed ?? summary.edited ?? summary.translated);
        }
    }

    // The field's actions menu: core's dropdown trigger in the header of the field its label names.
    function trigger(handle) {
        const label = [...(doc()?.querySelectorAll('label[for]') ?? [])].find((element) => element.getAttribute('for') === `field_${handle}`);

        return label?.closest('[data-ui-input-group]')?.querySelector('[data-ui-field-header] [data-ui-dropdown-trigger]') ?? null;
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

    // Not async: core reads a returned promise from `payload.vm`, which composition-API fieldtypes such as `text` do
    // not have (R3 §3.4). Progress shows in the banner. The action asks first itself: core's confirmation leaves the
    // focus on the page's body when it closes.
    function run(payload) {
        const confirmation = components().append(CONFIRM, { props: {} });

        confirmation.on('confirm', () => {
            confirmation.destroy();
            translateField(payload).catch(() => toast().error(__('Something went wrong.')));
            moveFocus(() => progress() ?? trigger(payload.handle));
        });
        confirmation.on('cancel', () => {
            confirmation.destroy();
            moveFocus(() => trigger(payload.handle));
        });
    }

    const action = {
        get title() {
            return __('Translate from origin');
        },
        visible,
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

    return { register, action, visible, run };
}

export const fieldActions = createFieldActions();
