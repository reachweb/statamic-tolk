import { watch } from 'vue';
import { CORE_CLOSE_MS } from '../modal.js';
import { BASE, registry as sharedRegistry } from './registry.js';
import { describe } from './statuses.js';
import { useEditorStore } from './store.js';
import { translations as sharedTranslations } from './translate.js';

// The user preference behind "Don't offer again".
export const OFFER_PREFERENCE = 'tolk.offer';

// The preference being stored, while one is. Core saves all of a user's preferences with each one it stores, from the
// user as it was when the request arrived, so of two stores at once only the later one's value would be kept.
let storing = null;

// Stores a user preference once the one before has been stored, failed or not. Resolves whether it was stored; a
// failure shows a toast.
export function savePreference(key, value, { preferences = () => Statamic.$preferences, toast = () => Statamic.$toast } = {}) {
    const store = () =>
        new Promise((resolve) => resolve(preferences().set(key, value))).then(
            () => true,
            () => {
                toast().error(__('Your choice could not be saved.'));

                return false;
            },
        );
    const stored = storing ? storing.then(store) : store();

    storing = stored;
    stored.then(() => {
        if (storing === stored) {
            storing = null;
        }
    });

    return stored;
}

export function offersEnabled(preferences = Statamic.$preferences) {
    return preferences.get(OFFER_PREFERENCE, true) !== false;
}

// The offer needs the `offer_on_create` setting, the user's preference, a model to translate with and untranslated
// fields.
export function shouldOffer(status, preferences = Statamic.$preferences) {
    return status?.offer === true && offersEnabled(preferences) && describe(status).localization?.translate === true;
}

// What the offer says: how many fields, from which site into which, with which model.
export function describeOffer(status) {
    const banner = describe(status);
    const count = status?.localization?.counts?.untranslated ?? 0;

    return {
        count,
        source: banner.localization?.source?.name ?? '',
        target: banner.localization?.target?.name ?? '',
        translator: banner.translator,
    };
}

// How long after `localization.created` the offer opens at the earliest. Core's origin picker starts its close
// (`CORE_CLOSE_MS`) before it creates the localization, and a modal that opens before that close has finished loses its
// Esc key: core keeps the Esc bindings in a stack, and a closing modal pops whatever is on top (T12.4).
export const PICKER_CLOSE_MS = CORE_CLOSE_MS + 50;

// The offer after creating a localization in the publish form (§A13). Core emits `localization.created` once the form
// shows the new localization, and the banner's watcher has already run its switch, which resets the store. So the
// offer waits for the new item's status, and it waits for the switch too in case the event came first.
export function createOffers({
    store = useEditorStore,
    registry = sharedRegistry,
    translations = sharedTranslations,
    preferences = () => Statamic.$preferences,
    toast = () => Statamic.$toast,
    openAfterMs = PICKER_CLOSE_MS,
    clock = () => Date.now(),
    wait = (callback, ms) => setTimeout(callback, ms),
} = {}) {
    let waiter = null;
    let listening = false;

    function listen(events = Statamic.$events) {
        if (listening) {
            return;
        }

        listening = true;
        events.$on('localization.created', created);
    }

    // The payload's `container` is the container's exposed instance; `name` is a plain string there.
    function created(payload) {
        const name = payload?.container?.name;
        const reference = registry.context(name)?.reference?.value;

        if (name !== BASE || !reference) {
            return;
        }

        stopWaiting();

        const editor = store();
        const notBefore = clock() + openAfterMs;

        if (settle(editor, name, reference, notBefore)) {
            return;
        }

        const stop = watch(
            () => {
                const container = editor.containers[name];

                return [container?.reference, container?.status];
            },
            () => {
                if (settle(editor, name, reference, notBefore)) {
                    stop();

                    if (waiter === stop) {
                        waiter = null;
                    }
                }
            },
        );

        waiter = stop;
    }

    // True once there is nothing left to wait for.
    function settle(editor, name, reference, notBefore) {
        const container = editor.containers[name];

        if (container?.reference !== reference) {
            // Before the switch to the new localization, or after the editor moved on.
            return registry.context(name)?.reference?.value !== reference;
        }

        container.created = true;

        if (container.status === null) {
            return false;
        }

        offer(editor, name, reference, notBefore);

        return true;
    }

    // Opens the offer once core's picker has closed, while the form still shows the new localization and nothing runs.
    function offer(editor, name, reference, notBefore) {
        const delay = notBefore - clock();

        if (delay > 0) {
            wait(() => offer(editor, name, reference, notBefore), delay);

            return;
        }

        const container = editor.containers[name];

        if (container?.reference === reference && container.phase === 'idle' && shouldOffer(container.status, preferences())) {
            editor.setPhase(name, 'offer');
        }
    }

    function stopWaiting() {
        waiter?.();
        waiter = null;
    }

    // Closes the offer, false when it was not open.
    function close(name, dontOfferAgain) {
        const editor = store();

        if (editor.phaseOf(name) !== 'offer') {
            return false;
        }

        if (dontOfferAgain) {
            savePreference(OFFER_PREFERENCE, false, { preferences, toast });
        }

        editor.setPhase(name, 'idle');

        return true;
    }

    function accept(name, { dontOfferAgain = false } = {}) {
        return close(name, dontOfferAgain) ? translations.translate(name, { mode: 'missing' }) : null;
    }

    function decline(name, { dontOfferAgain = false } = {}) {
        close(name, dontOfferAgain);
    }

    return { listen, created, accept, decline, waiting: (name) => name === BASE && waiter !== null };
}

export const offers = createOffers();
