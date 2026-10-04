import * as defaultApi from '../api.js';
import { OFFER_PREFERENCE } from './offer.js';
import { registry as sharedRegistry } from './registry.js';
import { useEditorStore } from './store.js';
import { BUSY, translations as sharedTranslations } from './translate.js';

// The model a user picked in the options, remembered per user. Null (or nothing stored) means the settings' default.
export const TRANSLATOR_PREFERENCE = 'tolk.editor.translator';

export function rememberedModel(preferences = Statamic.$preferences) {
    const value = preferences.get(TRANSLATOR_PREFERENCE, null);

    return typeof value === 'string' && value !== '' ? value : null;
}

// The models the select offers, in catalog order: those of configured providers, or all of them while the fake engine
// answers every call. `extra` adds values outside that list, such as a default outside the catalog or a model a user
// who may edit the settings typed, so the select can show them.
export function modelOptions(catalog, { fakeEngine = false, defaultModel = null, extra = [] } = {}) {
    const options = [];
    const groups = catalog && !Array.isArray(catalog) ? Object.values(catalog) : [];

    for (const model of groups.flat()) {
        if (model.configured || fakeEngine) {
            options.push({ value: model.value, label: model.value === defaultModel ? __(':model (default)', { model: model.label }) : model.label });
        }
    }

    for (const value of extra) {
        if (value && !options.some((option) => option.value === value)) {
            options.push({ value, label: value === defaultModel ? __(':model (default)', { model: value }) : value });
        }
    }

    return options;
}

// The remembered model while the select still offers it, else the default, else the first model.
export function initialModel(options, { remembered = null, defaultModel = null } = {}) {
    const offered = (value) => value !== null && options.some((option) => option.value === value);

    if (offered(remembered)) {
        return remembered;
    }

    return offered(defaultModel) ? defaultModel : (options[0]?.value ?? null);
}

// Translate in the options: everything that needs work (mode `update`), or everything (`all`). The default model is
// sent as null, so the plan uses the settings as the banner's buttons do.
export function optionsRequest({ model = null, defaultModel = null, retranslate = false, overwriteManual = false } = {}) {
    return {
        mode: retranslate ? 'all' : 'update',
        overwriteManual,
        translator: model && model !== defaultModel ? model : null,
    };
}

// Whether the state actions have work: manual fields whose source changed or that are untracked, and fields holding
// machine output.
export function stateActions(status) {
    const counts = status?.localization?.counts ?? {};

    return {
        markCurrent: (counts.attention ?? 0) + (counts.untracked ?? 0) > 0,
        protect: (counts.current ?? 0) + (counts.outdated ?? 0) > 0,
    };
}

export function baselineMessage(count, protect) {
    if (count === 0) {
        return __('No field needed changing.');
    }

    return protect
        ? __n(':count field is now protected from updates.|:count fields are now protected from updates.', count)
        : __n(':count field marked as up to date.|:count fields marked as up to date.', count);
}

// The options menu (T5.10): translate with another model or mode, the two state actions and the offer preference.
export function createOptions({
    store = useEditorStore,
    registry = sharedRegistry,
    api = defaultApi,
    translations = sharedTranslations,
    preferences = () => Statamic.$preferences,
    toast = () => Statamic.$toast,
} = {}) {
    let models = null;

    // Loaded once per page; a failed load is tried again the next time.
    function loadModels() {
        models ??= api.get('models').catch((error) => {
            models = null;

            throw error;
        });

        return models;
    }

    function savePreference(key, value) {
        return Promise.resolve(preferences().set(key, value)).then(
            () => true,
            () => {
                toast().error(__('Your choice could not be saved.'));

                return false;
            },
        );
    }

    function translate(name, choice = {}) {
        const request = optionsRequest(choice);

        if (BUSY.includes(store().phaseOf(name))) {
            return null;
        }

        if (request.translator !== rememberedModel(preferences())) {
            savePreference(TRANSLATOR_PREFERENCE, request.translator);
        }

        return translations.translate(name, request);
    }

    // `editor/baseline` for the open localization; the returned status replaces the banner's.
    async function baseline(name, { protect = false } = {}) {
        const container = store().containers[name];
        const reference = registry.context(name)?.reference?.value ?? null;

        if (!container || !reference || container.reference !== reference || BUSY.includes(container.phase)) {
            return null;
        }

        try {
            const { baselined = 0, ...status } = await api.post('editor/baseline', { reference, protect });

            // A switch replaces the store's entry, so an answer that comes late changes only the entry it was asked for.
            container.status = status;

            toast().success(baselineMessage(baselined, protect));

            return baselined;
        } catch (error) {
            toast().error(error?.message || __('Something went wrong.'));

            return null;
        }
    }

    function setOffers(enabled) {
        return savePreference(OFFER_PREFERENCE, enabled);
    }

    return { loadModels, translate, baseline, setOffers };
}

export const options = createOptions();
