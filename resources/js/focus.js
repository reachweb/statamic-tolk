import { CORE_CLOSE_MS } from './modal.js';

// The modals the editor banner opens. Core teleports them out of the banner, so they are found by these attributes.
export const BANNER_MODALS = '[data-tolk-offer], [data-tolk-options], [data-tolk-changes], [data-tolk-push-modal]';

const ACTIONS = '[data-tolk-action]:not([disabled])';
// Where the focus waits while the part has no action, such as the banner while it plans a translation: its first
// control is a status badge, whose tooltip would cover the page's header.
const TARGET = '[data-tolk-focus]';
const FOCUSABLE = 'button:not([disabled]), [href], [tabindex="0"]';

// Keeps the keyboard focus in a part of the page when a change removes the control that had it: Translate or Start
// leave with the state they start, and a modal that closes hands the focus back to a trigger that may be gone too, so
// the focus would fall back to the start of the page. Call `changing()` before such a change is rendered: when the
// focus is in the part (`root()`) or in one of its `modals`, and is lost once the change is rendered or a closing modal
// has finished (`CORE_CLOSE_MS`), it moves to the part's first action, else its focus target, else its first control.
// A focus left on such a fallback moves on to the first action that appears at a later change, such as Cancel.
//
// A button that disables itself while its request runs loses the focus at once (the browser drops the focus of a
// disabled control): ask `holds()` before the request, and call `restore()` once its result has been rendered.
export function createFocusKeeper(root, { modals = null, doc = globalThis.document, wait = setTimeout, delays = [0, CORE_CLOSE_MS + 100] } = {}) {
    let parked = null;

    function holds() {
        const active = doc?.activeElement;
        const element = root();

        return Boolean(active && element && (element.contains(active) || (modals && active.closest?.(modals))));
    }

    function lost() {
        return !doc.activeElement || doc.activeElement === doc.body || doc.activeElement === parked;
    }

    function restore() {
        const element = root();

        if (!element?.isConnected || !lost()) {
            return;
        }

        const action = element.querySelector(ACTIONS);
        const target = action ?? element.querySelector(TARGET) ?? element.querySelector(FOCUSABLE);

        target?.focus();
        parked = action ? null : target;
    }

    return {
        holds,
        restore,
        changing() {
            if (holds()) {
                delays.forEach((delay) => wait(restore, delay));
            }
        },
    };
}
