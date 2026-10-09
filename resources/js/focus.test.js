import { describe, expect, it, vi } from 'vitest';
import { BANNER_MODALS, createFocusKeeper } from './focus.js';

// A minimal DOM: elements know their children, and focusing one makes it the document's active element.
function setup() {
    const doc = { body: { name: 'body' }, activeElement: null };
    const element = (name, { disabled = false, action = false, modal = false, target = false } = {}) => ({
        name,
        disabled,
        action,
        modal,
        target,
        isConnected: true,
        children: [],
        focus() {
            doc.activeElement = this;
        },
        closest(selector) {
            return selector === BANNER_MODALS && this.modal ? this : null;
        },
        contains(other) {
            return other === this || this.children.some((child) => child.contains(other));
        },
        querySelector(selector) {
            const all = this.children.flatMap((child) => [child, ...child.children]);
            const enabled = all.filter((child) => !child.disabled);

            if (selector.startsWith('[data-tolk-action]')) {
                return enabled.find((child) => child.action) ?? null;
            }

            return (selector === '[data-tolk-focus]' ? all.find((child) => child.target) : enabled[0]) ?? null;
        },
    });

    const banner = element('banner');
    const badge = element('badge');
    const cancel = element('cancel', { action: true });
    const translate = element('translate', { action: true });
    banner.children.push(badge, translate);
    doc.activeElement = doc.body;

    const timers = [];
    const wait = vi.fn((callback, delay) => timers.push({ callback, delay }));
    const run = () => timers.splice(0).forEach(({ callback }) => callback());

    return { doc, element, banner, badge, cancel, translate, wait, run };
}

describe('createFocusKeeper', () => {
    it('moves the focus to the first action when the focused control disappears', () => {
        const { doc, banner, badge, cancel, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { modals: BANNER_MODALS, doc, wait });

        translate.focus();
        keeper.changing();

        // The change removes Translate and adds Cancel; the browser drops the focus.
        banner.children.splice(1, 1, cancel);
        doc.activeElement = doc.body;
        run();

        expect(doc.activeElement).toBe(cancel);
        expect(wait.mock.calls.map(([, delay]) => delay)).toEqual([0, 400]);
        expect(banner.contains(badge)).toBe(true);
    });

    it('falls back to the first control without an action', () => {
        const { doc, banner, badge, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { doc, wait });

        translate.focus();
        keeper.changing();
        banner.children.splice(1, 1);
        doc.activeElement = doc.body;
        run();

        expect(doc.activeElement).toBe(badge);
    });

    // A status badge opens its tooltip on focus, over the page's header.
    it('prefers the part\'s focus target to its first control while it has no action', () => {
        const { doc, element, banner, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { doc, wait });
        const planning = element('planning', { target: true });

        translate.focus();
        keeper.changing();
        banner.children.splice(1, 1, planning);
        doc.activeElement = doc.body;
        run();

        expect(doc.activeElement).toBe(planning);
    });

    it('moves a focus left on a fallback to the first action that appears', () => {
        const { doc, banner, badge, cancel, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { modals: BANNER_MODALS, doc, wait });

        // Update is pressed: while the translation is planned the banner has no action, then Cancel appears.
        translate.focus();
        keeper.changing();
        banner.children.splice(1, 1);
        doc.activeElement = doc.body;
        run();

        expect(doc.activeElement).toBe(badge);

        keeper.changing();
        banner.children.push(cancel);
        run();

        expect(doc.activeElement).toBe(cancel);

        // A control the editor moved to themselves is theirs.
        badge.focus();
        keeper.changing();
        run();

        expect(doc.activeElement).toBe(badge);
    });

    it('takes the focus back from a modal of the part that closed', () => {
        const { doc, element, banner, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { modals: BANNER_MODALS, doc, wait });

        element('modal translate', { modal: true }).focus();
        keeper.changing();
        doc.activeElement = doc.body;
        run();

        expect(doc.activeElement).toBe(translate);
    });

    it('leaves the focus alone when it was elsewhere, or is still somewhere', () => {
        const { doc, element, banner, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { modals: BANNER_MODALS, doc, wait });

        element('a field').focus();
        keeper.changing();
        expect(wait).not.toHaveBeenCalled();

        translate.focus();
        keeper.changing();
        const other = element('other');
        other.focus();
        run();

        expect(doc.activeElement).toBe(other);
    });

    it('does nothing once the part is gone', () => {
        const { doc, banner, translate, wait, run } = setup();
        const keeper = createFocusKeeper(() => banner, { doc, wait });

        translate.focus();
        keeper.changing();
        banner.isConnected = false;
        doc.activeElement = doc.body;
        run();

        expect(doc.activeElement).toBe(doc.body);
    });
});

describe('holds and restore', () => {
    it('gives the focus back after a request whose button disabled itself', () => {
        const { doc, banner, cancel, translate, wait } = setup();
        const keeper = createFocusKeeper(() => banner, { doc, wait });

        translate.focus();
        expect(keeper.holds()).toBe(true);

        // The button disables itself and the browser drops its focus; the result replaces it with Cancel.
        doc.activeElement = doc.body;
        banner.children.splice(1, 1, cancel);
        keeper.restore();

        expect(doc.activeElement).toBe(cancel);
        expect(wait).not.toHaveBeenCalled();
    });

    it('leaves a focus that is somewhere', () => {
        const { doc, element, banner, wait } = setup();
        const keeper = createFocusKeeper(() => banner, { doc, wait });
        const field = element('field');

        field.focus();
        expect(keeper.holds()).toBe(false);
        keeper.restore();

        expect(doc.activeElement).toBe(field);
    });
});
