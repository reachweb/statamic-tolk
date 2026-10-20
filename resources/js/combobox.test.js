import { afterEach, describe, expect, it, vi } from 'vitest';
import { opensOnRequest, shouldOpenDropdown } from './combobox.js';

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('opensOnRequest', () => {
    it('refuses to open while a focus event is dispatched, and opens on a click, a key or typing', () => {
        expect(opensOnRequest({ type: 'focus' })).toBe(false);

        for (const type of ['click', 'pointerdown', 'keydown', 'input']) {
            expect(opensOnRequest({ type })).toBe(true);
        }
    });

    it('opens outside an event, as when core opens the list itself', () => {
        expect(opensOnRequest(undefined)).toBe(true);
    });
});

describe('shouldOpenDropdown', () => {
    // Core passes `open`, which must not be taken for the event.
    it('reads the event being dispatched, whatever core passes', () => {
        vi.stubGlobal('event', { type: 'focus' });
        expect(shouldOpenDropdown(true)).toBe(false);

        vi.stubGlobal('event', { type: 'click' });
        expect(shouldOpenDropdown(true)).toBe(true);
    });
});
