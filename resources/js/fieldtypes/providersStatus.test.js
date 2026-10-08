import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api.js';
import { describeResult, providerRows, testConnection } from './providersStatus.js';

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

function status(provider, label, source) {
    const variable = `${provider.toUpperCase()}_API_KEY`;

    return { provider, label, configured: source !== null, source, env: { ai: variable, addon: `TOLK_${variable}` } };
}

const meta = {
    providers: [status('anthropic', 'Anthropic', 'ai'), status('openai', 'OpenAI', 'addon'), status('gemini', 'Google Gemini', null)],
    fake_engine: false,
};

beforeEach(() => {
    vi.stubGlobal('__', translate);
});

describe('providerRows', () => {
    it('shows a configured provider with its key source and a test', () => {
        const [anthropic, openai] = providerRows(meta);

        expect(anthropic).toEqual({
            provider: 'anthropic',
            label: 'Anthropic',
            badge: { text: 'Configured', color: 'green' },
            source: 'The key comes from config/ai.php, which Tolk shares with the rest of the site.',
            hint: null,
            testable: true,
        });
        expect(openai.source).toBe("The key is Tolk's own, from the addon's config.");
    });

    it('shows the variables to set for a provider without a key, and no test', () => {
        const gemini = providerRows(meta)[2];

        expect(gemini).toMatchObject({
            badge: { text: 'No API key', color: 'default' },
            source: null,
            hint: 'Add GEMINI_API_KEY to the .env file, or TOLK_GEMINI_API_KEY to give Tolk a key of its own.',
            testable: false,
        });
    });

    it('offers the test for every provider when the fake engine answers', () => {
        expect(providerRows({ ...meta, fake_engine: true }).map((row) => row.testable)).toEqual([true, true, true]);
    });

    it('has no rows without providers', () => {
        expect(providerRows(null)).toEqual([]);
        expect(providerRows({ providers: {} })).toEqual([]);
    });
});

describe('describeResult', () => {
    it('names the model and the time of a success', () => {
        expect(describeResult({ ok: true, model: 'anthropic:claude-haiku-4-5', label: 'Claude Haiku 4.5', latency_ms: 412, fake: false })).toEqual({
            ok: true,
            text: 'Claude Haiku 4.5 answered in 412 ms.',
        });
    });

    it('says when the fake engine answered', () => {
        expect(describeResult({ ok: true, model: 'gemini:gemini-3.5-flash-lite', label: '', latency_ms: 301, fake: true }).text).toBe(
            'The fake engine answered for gemini:gemini-3.5-flash-lite in 301 ms, without calling the provider.',
        );
    });

    it('shows the server\'s message for a failure', () => {
        expect(describeResult({ ok: false, error: 'Anthropic refused the API key (HTTP 401): invalid x-api-key' })).toEqual({
            ok: false,
            text: 'Anthropic refused the API key (HTTP 401): invalid x-api-key',
        });
        expect(describeResult({ ok: false })).toEqual({ ok: false, text: 'The test failed.' });
        expect(describeResult(undefined).ok).toBe(false);
    });
});

describe('testConnection', () => {
    it('posts the provider and returns the answer', async () => {
        const answer = { ok: true, model: 'openai:gpt-6-luna', label: 'GPT-6 Luna', latency_ms: 90, fake: false };
        const api = { post: vi.fn().mockResolvedValue(answer) };

        await expect(testConnection('openai', api)).resolves.toBe(answer);
        expect(api.post).toHaveBeenCalledWith('providers/test', { provider: 'openai' });
    });

    it('turns a refused request into a failed result with its message', async () => {
        const error = new ApiError({ status: 422, code: 'not_configured', message: 'The AI provider [gemini] has no API key.', data: null });
        const api = { post: vi.fn().mockRejectedValue(error) };

        await expect(testConnection('gemini', api)).resolves.toEqual({ ok: false, error: 'The AI provider [gemini] has no API key.' });
    });
});
