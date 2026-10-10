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
        update(name, { context, clean: entries.get(name)?.clean ?? clone(context.values.value) });
    }

    // After a site or entry switch and after a save, the values in the form are what is stored.
    function refreshClean(name) {
        const values = currentValues(name);

        if (values !== null) {
            update(name, { clean: clone(values) });
        }
    }

    function currentValues(name) {
        const entry = entries.get(name);

        return (entry?.context ?? entry?.payload)?.values?.value ?? null;
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

// The keys of a ProseMirror node, the shape of a Bard value.
const NODE_KEYS = new Set(['type', 'attrs', 'content', 'marks', 'text']);

// The texts of a value, null when it has none: a string as it is, a map's texts by key without the keys above, a
// list's by position without the empty items at its end. Numbers, booleans and nulls do not count, so what Bard does
// when it re-emits a value (`textAlign: null`, a list's `start: 1`, a table cell's `colspan: 1`) changes nothing. In
// Bard's nodes a text runs from one node boundary to the next, so adjacent runs joined, or a block image wrapped in a
// paragraph, change nothing either, while text moved to another paragraph, row or field does. Whether a field holds an
// edit is decided by its texts alone, as the server decides whether a field is machine output (D27).
export function textsOf(value) {
    if (typeof value === 'string') {
        return value === '' ? null : value;
    }

    if (Array.isArray(value)) {
        return value.length > 0 && value.every(isNode) ? runsOf(value) : listOf(value);
    }

    if (value === null || typeof value !== 'object') {
        return null;
    }

    const entries = Object.keys(value)
        .filter((key) => !NOT_TEXT.has(key))
        .sort()
        .map((key) => [key, textsOf(value[key])])
        .filter(([, texts]) => texts !== null);

    return entries.length ? Object.fromEntries(entries) : null;
}

function listOf(items) {
    const texts = items.map(textsOf);

    while (texts.length && texts[texts.length - 1] === null) {
        texts.pop();
    }

    return texts.length ? texts : null;
}

function isNode(value) {
    return value !== null && typeof value === 'object' && typeof value.type === 'string' && Object.keys(value).every((key) => NODE_KEYS.has(key));
}

// The runs of text in document order, and on their own the texts of a node's attributes (an image's alt, a set's
// values).
function runsOf(nodes) {
    const texts = [];
    let run = '';

    const add = (value) => {
        if (run !== '') {
            texts.push(run);
            run = '';
        }

        if (value !== null) {
            texts.push(value);
        }
    };

    const walk = (node) => {
        if (!isNode(node)) {
            add(textsOf(node));
        } else if (node.type === 'text') {
            run += typeof node.text === 'string' ? node.text : '';
        } else {
            add(textsOf(node.attrs));
            (Array.isArray(node.content) ? node.content : []).forEach(walk);
            add(null);
        }
    };

    nodes.forEach(walk);
    add(null);

    return texts.length ? texts : null;
}

export function sameText(a, b) {
    return JSON.stringify(textsOf(a)) === JSON.stringify(textsOf(b));
}
