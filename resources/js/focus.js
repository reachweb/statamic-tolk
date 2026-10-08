import { CORE_CLOSE_MS } from './modal.js';

// The modals the editor banner opens. Core teleports them out of the banner, so they are found by these attributes.
export const BANNER_MODALS = '[data-tolk-offer], [data-tolk-options], [data-tolk-changes], [data-tolk-push-modal]';

const ACTIONS = '[data-tolk-action]:not([disabled])';
const FOCUSABLE = 'button:not([disabled]), [href], [tabindex="0"]';

// Keeps the keyboard focus in a part of the page when a change removes the control that had it: Translate or Start
// leave with the state they start, and a modal that closes hands the focus back to a trigger that may be gone too, so
// the focus would fall back to the start of the page. Call `changing()` before such a change is rendered: when the
// focus is in the part (`root()`) or in one of its `modals`, and is lost once the change is rendered or a closing modal
// has finished (`CORE_CLOSE_MS`), it moves to the part's first action, else its first control.
//
// A button that disables itself while its request runs loses the focus at once (the browser drops the focus of a
// disabled control): ask `holds()` before the request, and call `restore()` once its result has been rendered.
export function createFocusKeeper(root, { modals = null, doc = globalThis.document, wait = setTimeout, delays = [0, CORE_CLOSE_MS + 100] } = {}) {
    function holds() {
        const active = doc?.activeElement;
        const element = root();

        return Boolean(active && element && (element.contains(active) || (modals && active.closest?.(modals))));
    }

    function lost() {
        return !doc.activeElement || doc.activeElement === doc.body;
    }

    function restore() {
        const element = root();

        if (element?.isConnected && lost()) {
            (element.querySelector(ACTIONS) ?? element.querySelector(FOCUSABLE))?.focus();
        }
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
