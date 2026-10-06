import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AUTOMATIC, chosen, isReference, selectOptions, stored } from './modelSelect.js';

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

const catalog = {
    anthropic: [
        { value: 'anthropic:claude-sonnet-5-5', label: 'Claude Sonnet 5.5', tier: 'quality', configured: true },
        { value: 'anthropic:claude-haiku-4-5', label: 'Claude Haiku 4.5', tier: 'fast', configured: true },
    ],
    openai: [{ value: 'openai:gpt-6.1-sol', label: 'GPT-6.1 Sol', tier: 'quality', configured: false }],
};

const meta = { catalog, automatic: 'Automatic (Claude Sonnet 5.5)', fake_engine: false };

beforeEach(() => {
    vi.stubGlobal('__', translate);
});

describe('stored', () => {
    it('keeps a reference and turns empty values into null', () => {
        expect(stored(' openai:gpt-6.1-sol ')).toBe('openai:gpt-6.1-sol');
        expect(stored('')).toBeNull();
        expect(stored('  ')).toBeNull();
        expect(stored(null)).toBeNull();
        expect(stored(['anthropic:claude-sonnet-5-5'])).toBeNull();
    });
});

describe('isReference', () => {
    it('accepts provider:model only', () => {
        expect(isReference('anthropic:claude-opus-5-5')).toBe(true);
        expect(isReference('claude')).toBe(false);
        expect(isReference(':claude')).toBe(false);
        expect(isReference('anthropic:')).toBe(false);
        expect(isReference('anthropic:claude opus')).toBe(false);
    });
});

describe('selectOptions', () => {
    it('lists the automatic choice, then the catalog in its order', () => {
        expect(selectOptions(meta, null)).toEqual([
            { value: AUTOMATIC, label: 'Automatic (Claude Sonnet 5.5)', note: null, disabled: false },
            { value: 'anthropic:claude-sonnet-5-5', label: 'Claude Sonnet 5.5', note: 'Quality', disabled: false },
            { value: 'anthropic:claude-haiku-4-5', label: 'Claude Haiku 4.5', note: 'Fast', disabled: false },
            { value: 'openai:gpt-6.1-sol', label: 'GPT-6.1 Sol', note: 'No API key', disabled: true },
        ]);
    });

    it('enables every model while the fake engine answers', () => {
        const option = selectOptions({ ...meta, fake_engine: true }, null).find((option) => option.value === 'openai:gpt-6.1-sol');

        expect(option).toMatchObject({ note: 'No API key', disabled: false });
    });

    it('adds a stored model the catalog lacks', () => {
        expect(selectOptions(meta, 'anthropic:claude-opus-5-5').at(-1)).toEqual({
            value: 'anthropic:claude-opus-5-5',
            label: 'anthropic:claude-opus-5-5',
            note: 'Custom model',
            disabled: false,
        });
        expect(selectOptions(meta, 'openai:gpt-7').at(-1)).toMatchObject({ value: 'openai:gpt-7', note: 'No API key' });
        expect(selectOptions(meta, 'openai:gpt-6.1-sol')).toHaveLength(4);
    });

    it('falls back to a plain label and an empty catalog', () => {
        expect(selectOptions({ catalog: [] }, null)).toEqual([{ value: AUTOMATIC, label: 'Automatic', note: null, disabled: false }]);
        expect(selectOptions(undefined, null)).toHaveLength(1);
    });
});

describe('chosen', () => {
    let options;

    beforeEach(() => {
        options = selectOptions(meta, null);
    });

    it('stores null for the automatic choice', () => {
        expect(chosen(AUTOMATIC, options)).toBeNull();
        expect(chosen(null, options)).toBeNull();
    });

    it('stores a model of the catalog', () => {
        expect(chosen('anthropic:claude-haiku-4-5', options)).toBe('anthropic:claude-haiku-4-5');
    });

    it('ignores a model without an API key', () => {
        expect(chosen('openai:gpt-6.1-sol', options)).toBeUndefined();
    });

    it('stores a typed model and ignores other text', () => {
        expect(chosen(' anthropic:claude-opus-5-5 ', options)).toBe('anthropic:claude-opus-5-5');
        expect(chosen('sonnet', options)).toBeUndefined();
    });
});
