export class ApiError extends Error {
    constructor({ status, code, message, data }) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.data = data;
    }
}

// PHP encodes an empty map as `[]`.
export function asMap(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

export function get(path, params = {}) {
    return send('get', path, { params });
}

export function post(path, data = {}) {
    return send('post', path, data);
}

// Core's instance sends the CSRF header and shows the server's toasts. Statamic.$app does not exist yet when this
// module is loaded or when Statamic.booting() runs, so it is looked up on every call.
function coreAxios() {
    return Statamic.$app.config.globalProperties.$axios;
}

async function send(method, path, payload) {
    const axios = coreAxios();
    const url = cp_url(`tolk/${path.replace(/^\/+/, '')}`);

    try {
        const response = await axios[method](url, payload);

        return response.data;
    } catch (error) {
        throw normalizeError(error);
    }
}

export function normalizeError(error) {
    const response = error?.response;
    const data = response?.data !== null && typeof response?.data === 'object' ? response.data : null;
    const status = response?.status ?? 0;

    return new ApiError({
        status,
        code: typeof data?.code === 'string' ? data.code : null,
        message: typeof data?.message === 'string' && data.message !== '' ? data.message : fallbackMessage(status),
        data,
    });
}

function fallbackMessage(status) {
    return status === 0
        ? __('The server could not be reached.')
        : __('The request failed with status :status.', { status });
}
