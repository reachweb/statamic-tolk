import * as defaultApi from '../api.js';
import { asMap } from '../api.js';

// The settings form's fields this one works with.
export const GLOSSARY = 'glossary';
export const TRANSLATOR = 'translator_model';

// Asks for a profile written by the translator the form holds, saved or not; an empty translator is the automatic
// choice. In a form without that field the saved settings decide.
export function generateProfile(values, api = defaultApi) {
    const form = asMap(values);
    const body = Object.hasOwn(form, TRANSLATOR) ? { model: form[TRANSLATOR] || null } : {};

    return api.post('site-profile/generate', body);
}

export function hasText(value) {
    return typeof value === 'string' && value.trim() !== '';
}

// Compares terms as the glossary does: case, accents and runs of spaces do not count.
export function fold(term) {
    return String(term ?? '')
        .normalize('NFKD')
        .toLowerCase()
        .replace(/\p{Mn}+/gu, '')
        .replace(/[\s\p{Z}]+/gu, ' ')
        .trim();
}

function forEverySite(row) {
    const site = row?.site;

    return Array.isArray(site) ? site.length === 0 : !site;
}

// The folded terms that already have a glossary row for every site. A row for one site does not cover the others.
export function glossaryTerms(rows) {
    return new Set(
        (Array.isArray(rows) ? rows : [])
            .filter(forEverySite)
            .map((row) => fold(row?.term))
            .filter((term) => term !== ''),
    );
}

// The suggested terms with whether each is in the glossary and whether it is chosen to be added.
export function termRows(terms, rows, skipped = {}) {
    const known = glossaryTerms(rows);

    return (Array.isArray(terms) ? terms : []).map((term) => {
        const inGlossary = known.has(fold(term));

        return { term, inGlossary, chosen: !inGlossary && skipped[term] !== true };
    });
}

// A grid row id, as random as core's. crypto.randomUUID() does not exist on a CP served over plain HTTP.
export function rowId() {
    const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

    return Array.from(crypto.getRandomValues(new Uint8Array(12)), (byte) => alphabet[byte % alphabet.length]).join('');
}

function copy(value) {
    return JSON.parse(JSON.stringify(value ?? {}));
}

// Appends a do-not-translate row for each term the glossary does not hold yet, as core's grid adds a row: the defaults
// of its fields and an `_id` whose meta is a copy of `meta.new`.
export function appendGlossaryRows(terms, rows, meta, newId = rowId) {
    const current = Array.isArray(rows) ? rows : [];
    const gridMeta = asMap(meta);
    const known = glossaryTerms(current);
    const existing = { ...asMap(gridMeta.existing) };
    const added = [];

    for (const term of terms) {
        const key = fold(term);

        if (key === '' || known.has(key)) {
            continue;
        }

        const id = newId();

        known.add(key);
        existing[id] = copy(asMap(gridMeta.new));
        added.push({ ...copy(asMap(gridMeta.defaults)), term: term.trim(), do_not_translate: true, _id: id });
    }

    return { rows: [...current, ...added], meta: { ...gridMeta, existing }, added: added.map((row) => row.term) };
}
