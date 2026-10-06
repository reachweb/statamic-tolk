import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    blueprintKey,
    checkDetail,
    chosenFields,
    confirmation,
    fieldName,
    importedNote,
    isBlueprintItem,
    level,
    levelLabel,
    localizableUrl,
    resultMessage,
    splitFields,
    summary,
} from './checks.js';

// Core's translator, without translation files: replacements in order, then the plural form, `one|other`.
function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

beforeEach(() => {
    vi.stubGlobal('__', translate);
    vi.stubGlobal('__n', (key, count, replacements = {}) => {
        const [one, other] = translate(key, { ...replacements, count }).split('|');

        return count === 1 ? one : (other ?? one);
    });
});

const ARTICLE = {
    level: 'warning',
    text: 'Collection Articles › Article: Internal note (internal_note), Seo Description (seo_description, from the fieldset seo).',
    type: 'collection',
    container: 'articles',
    namespace: 'collections.articles',
    blueprint: 'article',
    name: 'Collection Articles › Article',
    fields: [
        { handle: 'title', label: 'Title', type: 'text', fieldset: null },
        { handle: 'internal_note', label: 'Internal note', type: 'text', fieldset: null },
        { handle: 'seo_description', label: 'Seo Description', type: 'textarea', fieldset: 'seo' },
    ],
};

const check = (id, items, detail = 'Detail.') => ({ id, level: 'ok', title: id, detail, items });

describe('levels', () => {
    it('labels each level and gives it a colour and an icon, a warning\'s for one it does not know', () => {
        expect(['ok', 'warning', 'error'].map(levelLabel)).toEqual(['OK', 'Warning', 'Error']);
        expect(level('ok')).toMatchObject({ label: 'OK', color: 'green', icon: 'checkmark' });
        expect(level('error')).toMatchObject({ label: 'Error', color: 'red', icon: 'warning-diamond' });
        expect(level('notice')).toMatchObject({ label: 'notice', color: 'amber' });
    });
});

describe('the summary', () => {
    it('counts the items that are errors and warnings over every check', () => {
        const checks = [
            check('sites', [{ level: 'ok', text: '3 sites.' }]),
            check('providers', [{ level: 'error', text: 'No key.' }, { level: 'warning', text: 'Fake.' }]),
            check('localizable', [ARTICLE, { ...ARTICLE, blueprint: 'review' }]),
        ];

        expect(summary(checks)).toEqual({
            level: 'error',
            text: 'The audit found 1 error, 3 warnings. Errors keep Tolk from translating; warnings leave something untranslated or slower than it could be.',
        });
        expect(summary([check('state', [{ level: 'warning', text: 'Untracked.' }])])).toMatchObject({ level: 'warning', text: expect.stringContaining('found 1 warning.') });
        expect(summary([check('state', [{ level: 'error', text: 'Not writable.' }])]).text).toContain('found 1 error.');
    });

    it('says that everything is set up when no item is a problem', () => {
        expect(summary([check('sites', [{ level: 'ok', text: '3 sites.' }]), check('information', [])])).toEqual({
            level: 'ok',
            text: 'Everything is set up. Tolk found nothing to change.',
        });
        expect(summary(null).level).toBe('ok');
    });
});

describe('a check', () => {
    it('keeps its own explanation, except the localizable check, which this page acts on', () => {
        expect(checkDetail(check('queue', [], 'Runs need a worker.'), true)).toBe('Runs need a worker.');
        expect(checkDetail(check('localizable', [], 'Run php please tolk:doctor --fix-localizable.'), true)).toBe(
            'A text field that is not localizable is shared by every site, so Tolk does not translate it. Choose the fields that hold text in each language and make them localizable.',
        );
        expect(checkDetail(check('localizable', []), false)).toContain('Someone who may edit the Tolk settings and blueprints can make them localizable here');
    });

    it('tells the items that name a blueprint from plain ones', () => {
        expect(isBlueprintItem(ARTICLE)).toBe(true);
        expect(isBlueprintItem({ level: 'ok', text: '3 sites.' })).toBe(false);
        expect(isBlueprintItem({ ...ARTICLE, fields: undefined })).toBe(false);
        expect(blueprintKey(ARTICLE)).toBe('collections.articles|article');
    });
});

describe('fields that are not localizable', () => {
    it('separates the fields of an imported fieldset, which only the fieldset can change', () => {
        const { editable, imported } = splitFields(ARTICLE);

        expect(editable.map((field) => field.handle)).toEqual(['title', 'internal_note']);
        expect(imported.map((field) => field.handle)).toEqual(['seo_description']);
        expect(splitFields({})).toEqual({ editable: [], imported: [] });
    });

    it('names a field by its label and handle', () => {
        expect(fieldName(ARTICLE.fields[1])).toBe('Internal note (internal_note)');
        expect(fieldName({ handle: 'teaser', label: '' })).toBe('teaser');
        expect(fieldName({ handle: 'teaser', label: 'teaser' })).toBe('teaser');
    });

    it('keeps the chosen fields that can change, in the blueprint\'s order', () => {
        expect(chosenFields(ARTICLE, ['internal_note', 'seo_description', 'title', 'unknown'])).toEqual(['title', 'internal_note']);
        expect(chosenFields(ARTICLE, undefined)).toEqual([]);
    });

    it('asks before the blueprint file is changed, naming the blueprint and the fields', () => {
        expect(confirmation(ARTICLE, ['internal_note'])).toBe(
            'This changes the blueprint file of Collection Articles › Article: Internal note (internal_note) becomes localizable, so each site keeps its own value and Tolk translates it. Existing localizations keep the value they inherit until they are translated or edited.',
        );
        expect(confirmation(ARTICLE, ['title', 'internal_note'])).toContain(': Title (title), Internal note (internal_note) become localizable');
    });

    it('reports how many fields changed', () => {
        expect(resultMessage(1)).toBe('1 field is now localizable.');
        expect(resultMessage(2)).toBe('2 fields are now localizable.');
        expect(resultMessage(0)).toBe('Nothing changed: the fields were already localizable.');
    });

    it('explains why a field of an imported fieldset cannot be changed here', () => {
        expect(importedNote({ handle: 'seo_description', fieldset: 'seo' })).toBe(
            'From the fieldset seo: make it localizable in the fieldset, which changes every blueprint that imports it.',
        );
    });

    it('links the hints about fields that are not localizable to the check on the Audit page', () => {
        expect(localizableUrl('/cp/tolk/audit')).toBe('/cp/tolk/audit#tolk-audit-localizable');
        expect(localizableUrl(null)).toBeNull();
    });
});
