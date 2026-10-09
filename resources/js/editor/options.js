import * as defaultApi from '../api.js';
import { asMap } from '../api.js';
import { OFFER_PREFERENCE, savePreference } from './offer.js';
import { registry as sharedRegistry } from './registry.js';
import { useEditorStore } from './store.js';
import { BUSY, translations as sharedTranslations } from './translate.js';

// The models a user picked in the options, remembered per user. Null (or nothing stored) means the settings' default.
export const TRANSLATOR_PREFERENCE = 'tolk.editor.translator';
export const REVIEWER_PREFERENCE = 'tolk.editor.reviewer';

export function rememberedModel(preferences = Statamic.$preferences, key = TRANSLATOR_PREFERENCE) {
    const value = preferences.get(key, null);

    return typeof value === 'string' && value !== '' ? value : null;
}

// The models the select offers, in catalog order: those of configured providers, or all of them while the fake engine
// answers every call. `extra` adds values outside that list, such as a default outside the catalog or a model a user
// who may edit the settings typed, so the select can show them.
export function modelOptions(catalog, { fakeEngine = false, defaultModel = null, extra = [] } = {}) {
    const options = [];
    for (const model of Object.values(asMap(catalog)).flat()) {
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

// A chosen model, or null for the default, so the request uses the settings as the banner's buttons do.
function chosen(model, defaultModel) {
    return model && model !== defaultModel ? model : null;
}

// The reviewer the request names. The default reviewer is the one the settings resolve for the settings' translator;
// for another translator the server resolves its own Automatic reviewer, so the reviewer shown is named.
function chosenReviewer(reviewer, defaultReviewer, translator) {
    return translator ? reviewer || null : chosen(reviewer, defaultReviewer);
}

// Translate in the options: everything that needs work (mode `update`), or everything (`all`). "Review after
// translating" sends `review` (null leaves it to the settings), and the reviewer only while it is on.
export function optionsRequest({
    model = null,
    defaultModel = null,
    retranslate = false,
    overwriteManual = false,
    review = null,
    reviewer = null,
    defaultReviewer = null,
} = {}) {
    const translator = chosen(model, defaultModel);

    return {
        mode: retranslate ? 'all' : 'update',
        overwriteManual,
        translator,
        review,
        reviewer: review === true ? chosenReviewer(reviewer, defaultReviewer, translator) : null,
    };
}

// Review in the options: the form's machine output, with "Overwrite manual edits" its manual fields too (D8).
export function reviewRequest({ reviewer = null, defaultReviewer = null, overwriteManual = false } = {}) {
    return { overwriteManual, reviewer: chosen(reviewer, defaultReviewer) };
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

// The options menu (T5.10): translate with another model or mode, review with a chosen reviewer (T7.5), the two state
// actions and the offer preference.
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

    // Stores a model choice only when it changed; the default is stored as null.
    function remember(key, model) {
        if (model !== rememberedModel(preferences(), key)) {
            savePreference(key, model, { preferences, toast });
        }
    }

    function translate(name, choice = {}) {
        const request = optionsRequest(choice);

        if (BUSY.includes(store().phaseOf(name))) {
            return null;
        }

        remember(TRANSLATOR_PREFERENCE, request.translator);

        if (request.review === true) {
            remember(REVIEWER_PREFERENCE, chosen(choice.reviewer, choice.defaultReviewer));
        }

        return translations.translate(name, request);
    }

    function review(name, choice = {}) {
        const request = reviewRequest(choice);

        if (BUSY.includes(store().phaseOf(name))) {
            return null;
        }

        remember(REVIEWER_PREFERENCE, request.reviewer);

        return translations.review(name, request);
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
        return savePreference(OFFER_PREFERENCE, enabled, { preferences, toast });
    }

    return { loadModels, translate, review, baseline, setOffers };
}

export const options = createOptions();
