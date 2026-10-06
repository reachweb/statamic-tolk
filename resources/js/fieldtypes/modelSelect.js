import { asMap } from '../api.js';

// The value of the empty option inside the select; the field stores null for it. A model reference always has a colon.
export const AUTOMATIC = 'automatic';

// What the field stores: a model reference, or null for the automatic choice.
export function stored(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

// Whether text typed into the select names a model as provider:model; anything else is search text.
export function isReference(text) {
    return /^[^\s:]+:\S+$/.test(text);
}

function tierLabel(tier) {
    return { quality: __('Quality'), fast: __('Fast') }[tier] ?? null;
}

// A model outside the catalog: its provider's key decides the note, as for the catalog's models.
function customOption(value, catalog) {
    const configured = asMap(catalog)[value.split(':')[0]]?.[0]?.configured;

    return { value, label: value, note: configured === false ? __('No API key') : __('Custom model'), disabled: false };
}

// The automatic choice, the catalog in its order, and a stored model the catalog lacks. Models of providers without an
// API key are disabled, unless the fake engine answers every call.
export function selectOptions(meta, value) {
    const options = [{ value: AUTOMATIC, label: meta?.automatic || __('Automatic'), note: null, disabled: false }];
    const fakeEngine = meta?.fake_engine === true;

    for (const model of Object.values(asMap(meta?.catalog)).flat()) {
        options.push({
            value: model.value,
            label: model.label,
            note: model.configured ? tierLabel(model.tier) : __('No API key'),
            disabled: !model.configured && !fakeEngine,
        });
    }

    const current = stored(value);

    if (current && !options.some((option) => option.value === current)) {
        options.push(customOption(current, meta?.catalog));
    }

    return options;
}

// The value to store for a choice in the select, or undefined to keep the field as it is: a disabled model, or typed
// text that does not name a model.
export function chosen(choice, options) {
    if (choice === null || choice === AUTOMATIC) {
        return null;
    }

    const option = options.find((option) => option.value === choice);

    if (option) {
        return option.disabled ? undefined : option.value;
    }

    const text = typeof choice === 'string' ? choice.trim() : '';

    return isReference(text) ? text : undefined;
}
