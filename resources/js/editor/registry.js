import { shallowReactive } from 'vue';

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
        const clean = entries.get(name)?.clean ?? comparable(context.values.value);

        update(name, { context, clean });
    }

    // After a site or entry switch and after a save, the values in the form are what is stored.
    function refreshClean(name) {
        const values = currentValues(name);

        if (values !== null) {
            update(name, { clean: comparable(values) });
        }
    }

    function currentValues(name) {
        const entry = entries.get(name);
        const values = (entry?.context ?? entry?.payload)?.values?.value;

        return values ?? null;
    }

    // Top-level handles whose value differs from the clean values, in the form's order.
    function dirtyHandles(name) {
        const clean = entries.get(name)?.clean;
        const values = currentValues(name);

        if (!clean || values === null) {
            return [];
        }

        const handles = [...new Set([...Object.keys(values), ...Object.keys(clean)])];

        return handles.filter((handle) => !same(comparable(values[handle]), clean[handle]));
    }

    return {
        listen,
        attachContext,
        refreshClean,
        dirtyHandles,
        context: (name) => entries.get(name)?.context ?? null,
        payload: (name) => entries.get(name)?.payload ?? null,
        base: () => entries.get('base')?.context ?? null,
        others: () => [...entries.keys()].filter((name) => name !== 'base'),
    };
}

export const registry = createRegistry();

// A copy without `_id` keys at any depth. Replicator, Grid and Bard rows that have no stored id get a new random
// `_id` on every load, so ids must not count as changes.
export function comparable(value) {
    if (Array.isArray(value)) {
        return value.map(comparable);
    }

    if (value === null || typeof value !== 'object') {
        return value;
    }

    return Object.fromEntries(
        Object.keys(value)
            .filter((key) => key !== '_id')
            .map((key) => [key, comparable(value[key])]),
    );
}

export function equalValues(a, b) {
    return same(comparable(a), comparable(b));
}

// Deep equality of plain data in which key order does not count and a key holding `undefined` counts as absent.
function same(a, b) {
    if (a === b) {
        return true;
    }

    if (Array.isArray(a) || Array.isArray(b)) {
        return Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((item, i) => same(item, b[i]));
    }

    if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') {
        return false;
    }

    const keys = (object) => Object.keys(object).filter((key) => object[key] !== undefined);
    const aKeys = keys(a);

    return aKeys.length === keys(b).length && aKeys.every((key) => Object.hasOwn(b, key) && same(a[key], b[key]));
}
