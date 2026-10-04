export class ApiError extends Error {
    constructor({ status, code, message, data }) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.data = data;
    }
}

export function get(path, params = {}) {
    return send((axios, url) => axios.get(url, { params }), path);
}

export function post(path, data = {}) {
    return send((axios, url) => axios.post(url, data), path);
}

// Core's instance sends the CSRF header and shows the server's toasts. Statamic.$app does not exist yet when this
// module is loaded or when Statamic.booting() runs, so it is looked up on every call.
function coreAxios() {
    return Statamic.$app.config.globalProperties.$axios;
}

async function send(request, path) {
    const axios = coreAxios();
    const url = cp_url(`tolk/${path.replace(/^\/+/, '')}`);

    try {
        const response = await request(axios, url);

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
