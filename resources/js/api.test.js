import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, get, normalizeError, post } from './api.js';

function axiosError(status, data) {
    return Object.assign(new Error(`Request failed with status code ${status}`), { response: { status, data } });
}

let axios;

beforeEach(() => {
    axios = { get: vi.fn(), post: vi.fn() };

    vi.stubGlobal('cp_url', (path) => `/admin/${path}`);
    vi.stubGlobal('__', (key, replacements = {}) =>
        Object.entries(replacements).reduce((text, [name, value]) => text.replace(`:${name}`, value), key),
    );
    vi.stubGlobal('Statamic', { $app: { config: { globalProperties: { $axios: axios } } } });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('get', () => {
    it('sends the params to the addon route and resolves with the body', async () => {
        axios.get.mockResolvedValue({ status: 200, data: { translatable: true } });

        await expect(get('editor/status', { reference: 'entry::a' })).resolves.toEqual({ translatable: true });
        expect(axios.get).toHaveBeenCalledWith('/admin/tolk/editor/status', { params: { reference: 'entry::a' } });
    });

    it('accepts a path with a leading slash', async () => {
        axios.get.mockResolvedValue({ status: 200, data: {} });

        await get('/models');

        expect(axios.get).toHaveBeenCalledWith('/admin/tolk/models', { params: {} });
    });
});

describe('post', () => {
    it('sends the data to the addon route and resolves with the body', async () => {
        axios.post.mockResolvedValue({ status: 200, data: { plan: 'p1' } });

        await expect(post('editor/plan', { mode: 'missing' })).resolves.toEqual({ plan: 'p1' });
        expect(axios.post).toHaveBeenCalledWith('/admin/tolk/editor/plan', { mode: 'missing' });
    });

    it('sends a full URL as it is', async () => {
        axios.post.mockResolvedValue({ status: 200, data: {} });

        await post('https://example.test/cp/tolk/runs/01J/tick', { lane: 1 });
        await post('HTTP://example.test/cp/tolk/runs/01J/apply');

        expect(axios.post).toHaveBeenNthCalledWith(1, 'https://example.test/cp/tolk/runs/01J/tick', { lane: 1 });
        expect(axios.post).toHaveBeenNthCalledWith(2, 'HTTP://example.test/cp/tolk/runs/01J/apply', {});
    });
});

it('looks up core axios on every call, not when the module is loaded', async () => {
    const later = { get: vi.fn().mockResolvedValue({ data: 'later' }), post: vi.fn() };
    vi.stubGlobal('Statamic', { $app: { config: { globalProperties: { $axios: later } } } });

    await expect(get('models')).resolves.toBe('later');
    expect(axios.get).not.toHaveBeenCalled();
});

describe('errors', () => {
    it('rejects with the status, code, message and body of the response', async () => {
        const body = { code: 'plan_expired', message: 'The plan has expired.' };
        axios.post.mockRejectedValue(axiosError(410, body));

        const error = await post('editor/unit', { plan: 'p1', unit: 'u1' }).catch((e) => e);

        expect(error).toBeInstanceOf(ApiError);
        expect(error).toBeInstanceOf(Error);
        expect(error).toMatchObject({ status: 410, code: 'plan_expired', message: 'The plan has expired.', data: body });
    });

    it('keeps extra fields of the body, such as retry_after', () => {
        const error = normalizeError(axiosError(429, { message: 'Slow down.', retry_after: 12 }));

        expect(error).toMatchObject({ status: 429, code: null, message: 'Slow down.', data: { message: 'Slow down.', retry_after: 12 } });
    });

    it('keeps the validation errors of a 422', () => {
        const body = { message: 'The reference field is required.', errors: { reference: ['The reference field is required.'] } };

        expect(normalizeError(axiosError(422, body))).toMatchObject({ status: 422, code: null, message: body.message, data: body });
    });

    it('falls back to a message with the status when the body has none', () => {
        expect(normalizeError(axiosError(500, '<html>Server Error</html>'))).toMatchObject({
            status: 500,
            code: null,
            message: 'The request failed with status 500.',
            data: null,
        });
        expect(normalizeError(axiosError(502, { message: '' })).message).toBe('The request failed with status 502.');
    });

    it('reports status 0 when there is no response', async () => {
        axios.get.mockRejectedValue(new Error('Network Error'));

        await expect(get('models')).rejects.toMatchObject({
            status: 0,
            code: null,
            message: 'The server could not be reached.',
            data: null,
        });
    });

    it('ignores a code that is not a string', () => {
        expect(normalizeError(axiosError(409, { code: 7, message: 'Busy.' })).code).toBeNull();
    });
});
