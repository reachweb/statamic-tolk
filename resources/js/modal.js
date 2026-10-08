import { nextTick, reactive } from 'vue';

// How long core's `Modal` takes to close once it starts to.
export const CORE_CLOSE_MS = 300;

// The open state of one of the addon's modals. Core's `Modal` finishes a close `CORE_CLOSE_MS` after it starts, and one
// opened again meanwhile closes itself when that stale close finishes (T10.3), so each opening mounts a fresh modal:
//
//     <Modal v-if="modal.open" open @dismissed="modal.dismissed()" @update:open="modal.closed($event)">
//
// The addon's own buttons call `hide()`, which unmounts the modal at once. Esc and a click outside start core's close,
// which is left to finish: unmounting the modal before it does would release core's Esc binding twice
// (`keys.bindGlobal()` pops a stack), taking another modal's with it. An opening meanwhile waits for that close.
export function createModal() {
    let closing = false;
    let reopen = false;

    const modal = reactive({
        open: false,

        show() {
            if (closing) {
                reopen = true;
            } else {
                modal.open = true;
            }
        },

        hide() {
            reopen = false;

            if (!closing) {
                modal.open = false;
            }
        },

        // Esc or a click outside: core closes the modal in `CORE_CLOSE_MS` and then reports it through `update:open`.
        dismissed() {
            closing = true;
        },

        closed(open) {
            if (open) {
                return;
            }

            closing = false;
            modal.open = false;

            if (reopen) {
                reopen = false;
                nextTick(() => (modal.open = true));
            }
        },
    });

    return modal;
}
