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
// its own. Bard's text nodes are read with their marks in `runsOf()`.
const NOT_TEXT = new Set(['type', 'id', '_id', 'marks']);

// The keys of a ProseMirror node, the shape of a Bard value.
const NODE_KEYS = new Set(['type', 'attrs', 'content', 'marks', 'text']);

// Line breaks read as `\n`: CodeMirror-based fieldtypes (Markdown, code, YAML) pass `\r\n` and `\r` up as `\n`, and no
// CP input lets a person type a carriage return.
const lines = (text) => text.replace(/\r\n?/g, '\n');

const isScalar = (value) => typeof value === 'boolean' || typeof value === 'number';

const isMap = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

// The texts of a value, null when it has none: a string with its line breaks read as `\n`, a map's texts by key without
// the keys above, a list's by position without the empty items at its end. Numbers, booleans and nulls do not count, so
// what Bard does when it re-emits a value (`textAlign: null`, a list's `start: 1`, a table cell's `colspan: 1`) changes
// nothing. In Bard's nodes a text runs from one node boundary to the next, so adjacent runs joined, or a block image
// wrapped in a paragraph, change nothing either, while text moved to another paragraph, row or field does. Where its
// formatting starts and ends, and a link's title, are part of a run's text, as they are of the server's leaves. Whether
// a field holds an edit is decided by its texts alone, as the server decides whether a field is machine output (D27);
// whether Save must post a field Tolk put back is `sameContent()`'s question.
export function textsOf(value) {
    if (typeof value === 'string') {
        return value === '' ? null : lines(value);
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
// values) and the title of a link on another node. A run without marks is its text; one with marks is a list of parts
// that end where the marks change, a part without marks its text and one with marks `{ text, title }`, with its link's
// title if any. The server reads a block's text with each formatted part in a numbered pair and link titles as texts
// of their own (`InlineMarkup`), so to both a word linked or made bold, formatting moved and a link title changed are
// edits, and a link target changed or bold swapped for another mark are not.
function runsOf(nodes) {
    const texts = [];
    let parts = [];

    const add = (value) => {
        if (parts.length) {
            texts.push(parts.length === 1 && parts[0].marks.length === 0 ? lines(parts[0].text) : parts.map(partOf));
            parts = [];
        }

        if (value !== null) {
            texts.push(value);
        }
    };

    const append = (node) => {
        const text = typeof node.text === 'string' ? node.text : '';

        if (text === '') {
            return;
        }

        const marks = canonical(Array.isArray(node.marks) ? node.marks : []);
        const key = JSON.stringify(marks);
        const last = parts[parts.length - 1];

        if (last?.key === key) {
            last.text += text;
        } else {
            parts.push({ key, marks, text, title: linkTitle(node.marks) });
        }
    };

    const walk = (node) => {
        if (!isNode(node)) {
            add(textsOf(node));
        } else if (node.type === 'text') {
            append(node);
        } else {
            add(textsOf(node.attrs));
            add(linkTitle(node.marks));
            (Array.isArray(node.content) ? node.content : []).forEach(walk);
            add(null);
        }
    };

    nodes.forEach(walk);
    add(null);

    return texts.length ? texts : null;
}

function partOf({ marks, text, title }) {
    if (marks.length === 0) {
        return lines(text);
    }

    return title === null ? { text: lines(text) } : { text: lines(text), title };
}

// The title of a link mark among a node's marks, null when it has none or a blank one, which the server does not
// translate.
function linkTitle(marks) {
    const link = (Array.isArray(marks) ? marks : []).find((mark) => isMap(mark) && mark.type === 'link');
    const title = isMap(link?.attrs) ? link.attrs.title : null;

    return typeof title === 'string' && title.trim() !== '' ? lines(title) : null;
}

const isEmpty = (value) => value === null || value === undefined || value === '' || (typeof value === 'object' && Object.keys(value).length === 0);

// Marks as the server compares them (`Fingerprint::canonical()`): keys sorted, empty values left out, booleans and
// numbers as strings, so the `null` attributes Bard adds to a link when it re-emits a value change nothing.
function canonical(value) {
    if (Array.isArray(value)) {
        return value.map(canonical);
    }

    if (isScalar(value)) {
        return String(value);
    }

    if (!isMap(value)) {
        return value ?? null;
    }

    return Object.fromEntries(
        Object.keys(value)
            .sort()
            .map((key) => [key, canonical(value[key])])
            .filter(([, item]) => !isEmpty(item)),
    );
}

export function sameText(a, b) {
    return JSON.stringify(textsOf(a)) === JSON.stringify(textsOf(b));
}

// Whether the booleans and numbers both values hold in one place are the same, by key and position without types, ids
// and marks. A place only one of them has does not count: a fieldtype that re-emits a value adds attributes with their
// defaults (Bard's `start: 1` and `colspan: 1`, a set's `enabled: true`).
function sameScalars(a, b) {
    if (Array.isArray(a) && Array.isArray(b)) {
        return a.every((item, index) => index >= b.length || sameScalars(item, b[index]));
    }

    if (isMap(a) && isMap(b)) {
        return Object.keys(a).every((key) => NOT_TEXT.has(key) || !Object.hasOwn(b, key) || sameScalars(a[key], b[key]));
    }

    return !(isScalar(a) || isScalar(b)) || a === b;
}

// Whether a value still holds what another held, so that Save need not post it: the same texts, and the same booleans
// and numbers where both hold one, so a switched toggle, a changed number or heading level, or a disabled set differs.
// A mark's type and attributes beyond where it starts and ends and its link's title, node and set types, and rows or
// sets without text that only one value has do not, or Bard's re-emit would.
export function sameContent(a, b) {
    return sameText(a, b) && sameScalars(a, b);
}
