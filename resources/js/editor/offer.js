import { watch } from 'vue';
import { BASE, registry as sharedRegistry } from './registry.js';
import { describe } from './statuses.js';
import { useEditorStore } from './store.js';
import { translations as sharedTranslations } from './translate.js';

// The user preference behind "Don't offer again".
export const OFFER_PREFERENCE = 'tolk.offer';

// Stores a user preference. Resolves whether it was stored; a failure shows a toast.
export function savePreference(key, value, { preferences = () => Statamic.$preferences, toast = () => Statamic.$toast } = {}) {
    return Promise.resolve(preferences().set(key, value)).then(
        () => true,
        () => {
            toast().error(__('Your choice could not be saved.'));

            return false;
        },
    );
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

// The offer after creating a localization in the publish form (§A13). Core emits `localization.created` once the form
// shows the new localization, and the banner's watcher has already run its switch, which resets the store. So the
// offer waits for the new item's status, and it waits for the switch too in case the event came first.
export function createOffers({
    store = useEditorStore,
    registry = sharedRegistry,
    translations = sharedTranslations,
    preferences = () => Statamic.$preferences,
    toast = () => Statamic.$toast,
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

        if (settle(editor, name, reference)) {
            return;
        }

        const stop = watch(
            () => {
                const container = editor.containers[name];

                return [container?.reference, container?.status];
            },
            () => {
                if (settle(editor, name, reference)) {
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
    function settle(editor, name, reference) {
        const container = editor.containers[name];

        if (container?.reference !== reference) {
            // Before the switch to the new localization, or after the editor moved on.
            return registry.context(name)?.reference?.value !== reference;
        }

        container.created = true;

        if (container.status === null) {
            return false;
        }

        if (container.phase === 'idle' && shouldOffer(container.status, preferences())) {
            editor.setPhase(name, 'offer');
        }

        return true;
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
