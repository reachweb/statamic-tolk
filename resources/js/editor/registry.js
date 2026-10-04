import { shallowReactive } from 'vue';

// The page's own publish container; stacked editors have other names.
export const BASE = 'base';

// Publish containers by name. The map is reactive, so a computed that asks which containers are mounted (field-action
// visibility) is re-evaluated when a stacked editor opens or closes. Entries are replaced, never mutated, for the same
// reason; they stay plain objects, because a reactive wrapper would unwrap the payload's refs.
export function createRegistry() {
    const entries = shallowReactive(new Map());
    let listening = false;

    function listen(events = Statamic.$events) {
        if (listening) {
            return;
        }

        listening = true;
        events.$on('publish-container-created', created);
        events.$on('publish-container-destroyed', destroyed);
    }

    // A container that replaces another of the same name can be created before the old one is destroyed, so an entry
    // counts its containers and goes only with the last of them.
    function created(payload) {
        const mounted = (entries.get(payload.name)?.mounted ?? 0) + 1;

        entries.set(payload.name, { payload, context: null, clean: null, mounted });
    }

    function destroyed({ name }) {
        const entry = entries.get(name);

        if (!entry) {
            return;
        }

        if (entry.mounted > 1) {
            entries.set(name, { ...entry, mounted: entry.mounted - 1 });
        } else {
            entries.delete(name);
        }
    }

    function update(name, changes) {
        const entry = entries.get(name) ?? { payload: null, context: null, clean: null, mounted: 0 };

        entries.set(name, { ...entry, ...changes });
    }

    // The context a component injected inside the container. Attaching it again, as a remounted banner does after
    // Live Preview opens or closes, keeps the clean values taken before.
    function attachContext(name, context) {
        const entry = entries.get(name);

        if (entry?.clean) {
            update(name, { context });
        } else {
            update(name, { context, clean: clone(context.values.value) });
        }
    }

    // After a site or entry switch and after a save, the values in the form are what is stored.
    function refreshClean(name) {
        const values = currentValues(name);

        if (values !== null) {
            update(name, { clean: clone(values) });
        }
    }

    function sourceOf(name) {
        const entry = entries.get(name);

        return entry?.context ?? entry?.payload ?? null;
    }

    function currentValues(name) {
        return sourceOf(name)?.values?.value ?? null;
    }

    // Top-level handles whose text differs from the clean values, in the form's order. A value that only changed its
    // shape is not dirty: Bard re-emits its value with attributes of its own.
    function dirtyHandles(name) {
        const clean = entries.get(name)?.clean;
        const values = currentValues(name);

        if (!clean || values === null) {
            return [];
        }

        const handles = [...new Set([...Object.keys(values), ...Object.keys(clean)])];

        return handles.filter((handle) => !sameText(values[handle], clean[handle]));
    }

    return {
        listen,
        attachContext,
        refreshClean,
        dirtyHandles,
        context: (name) => entries.get(name)?.context ?? null,
        payload: (name) => entries.get(name)?.payload ?? null,
        base: () => entries.get(BASE)?.context ?? null,
        others: () => [...entries.keys()].filter((name) => name !== BASE),
    };
}

export const registry = createRegistry();

// Deep copy of form data. Values read from the context are reactive proxies, which `structuredClone` refuses.
export function clone(value) {
    if (Array.isArray(value)) {
        return value.map(clone);
    }

    if (value === null || typeof value !== 'object') {
        return value;
    }

    return Object.fromEntries(Object.keys(value).map((key) => [key, clone(value[key])]));
}

// Keys whose values are not text: node, set and mark types, ids, and the marks, which Bard rewrites with attributes of
// its own.
const NOT_TEXT = new Set(['type', 'id', '_id', 'marks']);

// The text of a value: its strings in key order, without the keys above. Numbers, booleans and nulls do not count,
// so what Bard does when it re-emits a value (`textAlign: null`, a list's `start: 1`, a table cell's `colspan: 1`, a
// block image wrapped in a paragraph, adjacent runs joined) changes nothing. Whether a field holds an edit is decided
// by its text alone, as the server decides whether a field is machine output (D27).
export function textOf(value) {
    if (typeof value === 'string') {
        return value;
    }

    if (Array.isArray(value)) {
        return value.map(textOf).join('');
    }

    if (value === null || typeof value !== 'object') {
        return '';
    }

    return Object.keys(value)
        .filter((key) => !NOT_TEXT.has(key))
        .sort()
        .map((key) => textOf(value[key]))
        .join('');
}

export function sameText(a, b) {
    return textOf(a) === textOf(b);
}
