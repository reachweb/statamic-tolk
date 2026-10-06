import { describe, expect, it, vi } from 'vitest';
import { appendGlossaryRows, fold, generateProfile, glossaryTerms, hasText, rowId, termRows } from './siteProfile.js';

// The grid meta core preloads for the settings glossary: the defaults of a row, the meta of a new row and of each row.
const meta = {
    defaults: { term: null, site: [], translation: null, do_not_translate: false, note: null },
    new: { site: { data: [], columns: [] } },
    existing: { row1: { site: { data: ['el'], columns: [] } } },
};

function row(term, site = []) {
    return { _id: `id-${term}`, term, site, translation: null, do_not_translate: true, note: null };
}

describe('generateProfile', () => {
    it('sends the translator the form holds, and null for its automatic choice', async () => {
        const api = { post: vi.fn().mockResolvedValue({ profile: 'A website.' }) };

        expect(await generateProfile({ translator_model: 'openai:gpt-6.1-sol', glossary: [] }, api)).toEqual({ profile: 'A website.' });
        await generateProfile({ translator_model: null }, api);
        await generateProfile({ translator_model: '' }, api);

        expect(api.post.mock.calls).toEqual([
            ['site-profile/generate', { model: 'openai:gpt-6.1-sol' }],
            ['site-profile/generate', { model: null }],
            ['site-profile/generate', { model: null }],
        ]);
    });

    it('leaves the model to the saved settings in a form without a translator field', async () => {
        const api = { post: vi.fn().mockResolvedValue({}) };

        await generateProfile({ site_profile: '' }, api);
        await generateProfile(null, api);

        expect(api.post.mock.calls).toEqual([
            ['site-profile/generate', {}],
            ['site-profile/generate', {}],
        ]);
    });

    it('passes a refused request on', async () => {
        const api = { post: vi.fn().mockRejectedValue(new Error('The AI provider [anthropic] has no API key.')) };

        await expect(generateProfile({}, api)).rejects.toThrow('The AI provider [anthropic] has no API key.');
    });
});

describe('hasText', () => {
    it('is true for a text with more than whitespace', () => {
        expect([hasText('Acme'), hasText(' \n '), hasText(''), hasText(null), hasText(['Acme'])]).toEqual([true, false, false, false, false]);
    });
});

describe('fold', () => {
    it('ignores case, accents and runs of spaces, as the glossary does', () => {
        expect(fold('  Café   Ölberg ')).toBe('cafe olberg');
        expect(fold('ＡＣＭＥ')).toBe('acme');
        expect(fold(null)).toBe('');
    });
});

describe('glossaryTerms', () => {
    it('holds the terms with a row for every site', () => {
        const terms = glossaryTerms([row('Acme Tours'), row('Samaria', ['el']), row('Crete', null), row('Knossos', 'el'), row('  ')]);

        expect([...terms]).toEqual(['acme tours', 'crete']);
    });

    it('is empty without rows', () => {
        expect(glossaryTerms(null).size).toBe(0);
    });
});

describe('termRows', () => {
    it('marks the terms the glossary holds and chooses the others unless they were skipped', () => {
        expect(termRows(['Acme Tours', 'White Mountains Trail', 'Samaria'], [row('acme tours'), row('Samaria', ['el'])], { Samaria: true })).toEqual([
            { term: 'Acme Tours', inGlossary: true, chosen: false },
            { term: 'White Mountains Trail', inGlossary: false, chosen: true },
            { term: 'Samaria', inGlossary: false, chosen: false },
        ]);
    });

    it('has no rows without terms', () => {
        expect(termRows(undefined, [])).toEqual([]);
    });
});

describe('appendGlossaryRows', () => {
    it('appends a do-not-translate row per new term, each with an id and the meta of a new row', () => {
        const ids = ['n1', 'n2'];
        const rows = [{ ...row('Samaria', ['el']), _id: 'row1' }];

        const result = appendGlossaryRows(['Acme Tours', 'Samaria'], rows, meta, () => ids.shift());

        expect(result.added).toEqual(['Acme Tours', 'Samaria']);
        expect(result.rows).toEqual([
            rows[0],
            { term: 'Acme Tours', site: [], translation: null, do_not_translate: true, note: null, _id: 'n1' },
            { term: 'Samaria', site: [], translation: null, do_not_translate: true, note: null, _id: 'n2' },
        ]);
        expect(result.meta).toEqual({
            ...meta,
            existing: {
                row1: meta.existing.row1,
                n1: { site: { data: [], columns: [] } },
                n2: { site: { data: [], columns: [] } },
            },
        });
    });

    it('copies the defaults and the new row\'s meta, so rows share nothing', () => {
        const result = appendGlossaryRows(['Acme', 'Crete'], [], meta, (() => {
            let id = 0;

            return () => `n${++id}`;
        })());

        expect(result.rows[0].site).not.toBe(result.rows[1].site);
        expect(result.rows[0].site).not.toBe(meta.defaults.site);
        expect(result.meta.existing.n1).not.toBe(meta.new);
        expect(result.meta.existing.n1.site).not.toBe(result.meta.existing.n2.site);
    });

    it('skips terms the glossary holds for every site, blank terms and repeats', () => {
        const result = appendGlossaryRows(['ACME tours', ' ', 'Crete', 'crete'], [row('Acme Tours')], meta, () => 'n1');

        expect(result.added).toEqual(['Crete']);
        expect(result.rows.map((item) => item.term)).toEqual(['Acme Tours', 'Crete']);
        expect(Object.keys(result.meta.existing)).toEqual(['row1', 'n1']);
    });

    it('leaves rows and meta as they are when nothing is new', () => {
        const rows = [row('Acme')];
        const result = appendGlossaryRows(['acme'], rows, meta);

        expect(result).toEqual({ rows, meta, added: [] });
        expect(result.rows[0]).toBe(rows[0]);
    });

    it('works on meta PHP sent as empty arrays', () => {
        const result = appendGlossaryRows(['Acme'], undefined, { defaults: [], new: [], existing: [] }, () => 'n1');

        expect(result.rows).toEqual([{ term: 'Acme', do_not_translate: true, _id: 'n1' }]);
        expect(result.meta).toEqual({ defaults: [], new: [], existing: { n1: {} } });
    });
});

describe('rowId', () => {
    it('is a fresh lowercase id each time', () => {
        const ids = new Set(Array.from({ length: 50 }, rowId));

        expect(ids.size).toBe(50);
        [...ids].forEach((id) => expect(id).toMatch(/^[a-z0-9]{12}$/));
    });
});
