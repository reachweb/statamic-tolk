import { describe, expect, it } from 'vitest';
import { diffWords, MAX_CELLS, tokenize } from './diff.js';

// The changed pieces of one side, joined, with `|` between runs that are not next to each other.
function changed(parts) {
    return parts
        .map((part) => (part.changed ? part.text : '|'))
        .join('')
        .split('|')
        .filter((run) => run !== '');
}

const text = (parts) => parts.map((part) => part.text).join('');

describe('tokenize', () => {
    it('splits words, spaces and placeholders, and gives the text back when joined', () => {
        const source = 'Book <1>a sea view</1> suite.<br/>Call <x1/>  now';

        expect(tokenize(source).map((piece) => [piece.text, piece.kind])).toEqual([
            ['Book', 'word'],
            [' ', 'space'],
            ['<1>', 'placeholder'],
            ['a', 'word'],
            [' ', 'space'],
            ['sea', 'word'],
            [' ', 'space'],
            ['view', 'word'],
            ['</1>', 'placeholder'],
            [' ', 'space'],
            ['suite.', 'word'],
            ['<br/>', 'placeholder'],
            ['Call', 'word'],
            [' ', 'space'],
            ['<x1/>', 'placeholder'],
            ['  ', 'space'],
            ['now', 'word'],
        ]);
        expect(text(tokenize(source))).toBe(source);
    });

    it('keeps text that only looks like a tag as words', () => {
        expect(tokenize('a <b> c<12').map((piece) => piece.kind)).toEqual(['word', 'space', 'word', 'space', 'word']);
    });

    it('is empty for an empty or missing text', () => {
        expect(tokenize('')).toEqual([]);
        expect(tokenize(null)).toEqual([]);
    });
});

describe('diffWords', () => {
    it('marks the replaced word on both sides', () => {
        const { before, after } = diffWords('The rooms have a fine view.', 'The rooms have a lovely view.');

        expect(changed(before)).toEqual(['fine']);
        expect(changed(after)).toEqual(['lovely']);
        expect(text(before)).toBe('The rooms have a fine view.');
        expect(text(after)).toBe('The rooms have a lovely view.');
    });

    it('marks added and removed words, and a changed phrase as one run', () => {
        const { before, after } = diffWords('Breakfast is served until ten.', 'A full breakfast is served from seven until ten.');

        expect(changed(before)).toEqual(['Breakfast']);
        expect(changed(after)).toEqual(['A full breakfast', 'from seven']);
    });

    it('treats a placeholder as a word of its own, so a moved mark shows as moved tags', () => {
        const { before, after } = diffWords('Book <1>a sea view</1> suite.', 'Book a <1>sea view</1> suite.');

        expect(changed(before)).toEqual(['<1>']);
        expect(changed(after)).toEqual(['<1>']);
        expect(after.find((part) => part.text === '<1>')).toMatchObject({ kind: 'placeholder', changed: true });
        expect(after.filter((part) => part.kind === 'word' && part.changed)).toEqual([]);
    });

    it('marks nothing for equal texts and everything for texts without a common word', () => {
        expect(changed(diffWords('Same text.', 'Same text.').after)).toEqual([]);
        expect(changed(diffWords('one two', 'three four').after)).toEqual(['three four']);
        expect(changed(diffWords('', 'new text').after)).toEqual(['new text']);
        expect(diffWords('old text', '').after).toEqual([]);
    });

    it('treats a word that differs only in case or punctuation as changed', () => {
        expect(changed(diffWords('the view', 'The view.').after)).toEqual(['The view.']);
    });

    it('counts very long texts as replaced whole instead of building a huge table', () => {
        const words = Math.ceil(Math.sqrt(MAX_CELLS)) + 1;
        const long = Array.from({ length: words }, (_, index) => `w${index}`).join(' ');
        const { before, after } = diffWords(long, `${long} more`);

        expect(before.every((part) => part.kind === 'space' || part.changed)).toBe(true);
        expect(after.every((part) => part.kind === 'space' || part.changed)).toBe(true);
    });
});
