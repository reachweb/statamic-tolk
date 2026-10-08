import { nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import { createModal } from './modal.js';

describe('createModal', () => {
    it('opens on show and closes at once on hide', () => {
        const modal = createModal();

        expect(modal.open).toBe(false);

        modal.show();
        expect(modal.open).toBe(true);

        modal.hide();
        expect(modal.open).toBe(false);

        modal.show();
        expect(modal.open).toBe(true);
    });

    it('stays mounted while core closes it after Esc, and unmounts when core reports the close', () => {
        const modal = createModal();
        modal.show();

        modal.dismissed();
        expect(modal.open).toBe(true);

        // A click on a button of the fading modal must not unmount it before core's close has finished.
        modal.hide();
        expect(modal.open).toBe(true);

        modal.closed(false);
        expect(modal.open).toBe(false);
    });

    it('opens again once core has finished a close that an opening met', async () => {
        const modal = createModal();
        modal.show();
        modal.dismissed();

        modal.show();
        expect(modal.open).toBe(true);

        modal.closed(false);
        expect(modal.open).toBe(false);

        await nextTick();
        expect(modal.open).toBe(true);

        // The next close is an ordinary one again.
        modal.hide();
        expect(modal.open).toBe(false);
        await nextTick();
        expect(modal.open).toBe(false);
    });

    it('ignores core reporting the modal as open', () => {
        const modal = createModal();
        modal.show();

        modal.closed(true);
        expect(modal.open).toBe(true);
    });
});
