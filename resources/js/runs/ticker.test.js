import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api.js';
import {
    backoff,
    estimateLoop,
    IDLE_MS,
    isFinal,
    latest,
    MAX_RETRIES,
    nonce,
    pollState,
    POLL_MS,
    retryable,
    startApplyLoop,
    startLanes,
} from './ticker.js';

afterEach(() => {
    vi.useRealTimers();
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const error = (status, code = null) => new ApiError({ status, code, message: `Failed with ${status}.`, data: null });

const base = 'https://cp.test/cp/tolk/runs/01RUN';
const urls = { state: `${base}/state`, estimate: `${base}/estimate`, tick: `${base}/tick`, apply: `${base}/apply` };

const time = (second) => `2026-10-05T10:00:${String(second).padStart(2, '0')}Z`;

const state = ({ status = 'running', driver = 'browser', counts = {}, second = 0, report } = {}) => ({
    run: { id: '01RUN', status, driver, progressed_at: time(second) },
    counts: { pending: 0, translating: 0, translated: 0, applying: 0, done: 0, skipped: 0, failed: 0, ...counts },
    items: [],
    pagination: { page: 1, per_page: 50, total: 0, last_page: 1 },
    stalled: false,
    ...(report ? { report } : {}),
});

const ticked = (remains, wait = 0, extra = {}) => state({ ...extra, report: { units: 1, items: 0, remains, wait: remains ? wait : null } });

const applied = (remains, wait = null, extra = {}, returned = 0) =>
    state({ ...extra, report: { items: 0, returned, remains, wait: remains ? wait : null } });

const immediately = () => vi.fn(() => Promise.resolve());

// The run routes, answered by `handlers` (`tick`, `apply`, `state`, `estimate`): a function of the request data and the
// route's call number that returns an answer, an ApiError to throw, or undefined to leave the request pending until
// the test answers it.
function fakeApi(handlers = {}) {
    const requests = [];
    const pending = [];
    const calls = {};
    const inFlight = {};
    const maxInFlight = {};

    function request(method, url, data) {
        const route = url.split('/').pop();
        const call = (calls[route] = (calls[route] ?? 0) + 1);

        requests.push({ method, url, route, data });
        inFlight[route] = (inFlight[route] ?? 0) + 1;
        maxInFlight[route] = Math.max(maxInFlight[route] ?? 0, inFlight[route]);

        const settle = (promise) => promise.finally(() => inFlight[route]--);
        const answer = handlers[route]?.(data, call);

        if (answer !== undefined) {
            return settle(answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer));
        }

        return settle(new Promise((resolve, reject) => pending.push({ route, data, resolve, reject })));
    }

    return {
        api: {
            post: vi.fn((url, data) => request('post', url, data)),
            get: vi.fn((url, params) => request('get', url, params)),
        },
        requests,
        sent: (route) => requests.filter((request) => request.route === route).map((request) => request.data),
        pending,
        maxInFlight: (route) => maxInFlight[route] ?? 0,
        async answer(route, value) {
            const index = pending.findIndex((request) => request.route === route);

            if (index === -1) {
                throw new Error(`No pending ${route} request.`);
            }

            const [request] = pending.splice(index, 1);
            value instanceof Error ? request.reject(value) : request.resolve(value);
            await flush();
        },
    };
}

// What startApplyLoop() needs of the lanes, driven by the test.
function fakeLanes(active = 1) {
    const listeners = new Set();

    return {
        active,
        restart: vi.fn(() => 0),
        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        emit: (event) => listeners.forEach((listener) => listener(event)),
        listeners,
    };
}

// A browser run of `size` items on a fake server: a tick translates the next pending item, an apply saves every
// translated item (the first apply sends the items in `returnFirst` back to `pending` instead), and the run completes
// when every item is done.
function simulatedRun(size, { returnFirst = [] } = {}) {
    const items = Array(size).fill('pending');
    let status = 'running';
    let second = 0;
    let applies = 0;

    const counts = () => items.reduce((counts, item) => ({ ...counts, [item]: (counts[item] ?? 0) + 1 }), {});
    const snapshot = (report) => state({ status, counts: counts(), second, report });

    return {
        items,
        tick() {
            const index = items.indexOf('pending');

            if (index !== -1) {
                items[index] = 'translated';
                second++;
            }

            const remains = status === 'running' && items.includes('pending');

            return snapshot({ units: index === -1 ? 0 : 1, items: index === -1 ? 0 : 1, remains, wait: remains ? 0 : null });
        },
        apply() {
            let done = 0;
            let returned = 0;

            applies++;
            items.forEach((item, index) => {
                if (item !== 'translated') {
                    return;
                }

                if (applies === 1 && returnFirst.includes(index)) {
                    items[index] = 'pending';
                    returned++;
                } else {
                    items[index] = 'done';
                    done++;
                }

                second++;
            });

            if (items.every((item) => item === 'done')) {
                status = 'completed';
            }

            return snapshot({ items: done, returned, remains: false, wait: null });
        },
    };
}

describe('nonce', () => {
    it('is made once per page load, of letters and digits only', () => {
        expect(nonce()).toMatch(/^[A-Za-z0-9]{8,64}$/);
        expect(nonce()).toBe(nonce());
    });
});

describe('helpers', () => {
    it('knows the final run statuses', () => {
        expect(['completed', 'completed_with_errors', 'cancelled', 'aborted'].every((status) => isFinal(state({ status })))).toBe(true);
        expect(isFinal(state({ status: 'running' }))).toBe(false);
        expect(isFinal(state({ status: 'pending' }))).toBe(false);
        expect(isFinal(null)).toBe(false);
    });

    it('retries what a repeated request can fix', () => {
        expect([0, 408, 429, 500, 502, 503, 504].every((status) => retryable(error(status)))).toBe(true);
        expect([401, 403, 404, 409, 419, 422].some((status) => retryable(error(status)))).toBe(false);
    });

    it('doubles the backoff with each failure', () => {
        expect([1, 2, 3, 4, 5].map((failures) => backoff(failures))).toEqual([1_000, 2_000, 4_000, 8_000, 16_000]);
        expect(backoff(10)).toBe(30_000);
    });
});

describe('latest', () => {
    it('drops a state that is older than the last one passed on', () => {
        const seen = [];
        const onState = latest((value) => seen.push(value.run.progressed_at));

        onState(state({ second: 2 }));
        onState(state({ second: 1 }));
        onState(state({ second: 2 }));
        onState(state({ second: 3 }));

        expect(seen).toEqual([time(2), time(2), time(3)]);
    });

    it('keeps a final status over a running one of the same second', () => {
        const seen = [];
        const onState = latest((value) => seen.push(value.run.status));

        onState(state({ status: 'completed', second: 4 }));
        onState(state({ status: 'running', second: 4 }));
        onState(state({ status: 'running', second: 5 }));

        expect(seen).toEqual(['completed', 'running']);
    });
});

describe('startLanes', () => {
    it('ticks with each lane until nothing remains, then stops', async () => {
        const answers = { 1: [true, true, false], 2: [false] };
        const server = fakeApi({ tick: ({ lane }) => ticked(answers[lane].shift()) });
        const onState = vi.fn();
        const lanes = startLanes({
            api: server.api,
            urls,
            lanes: 2,
            nonce: 'abc12345',
            onState,
            sleep: immediately(),
            query: () => ({ page: 2, per_page: 25 }),
        });

        expect(lanes.active).toBe(2);
        await lanes.idle();

        expect(lanes.active).toBe(0);
        expect(lanes.failed).toEqual([]);
        expect(server.sent('tick').filter(({ lane }) => lane === 1)).toHaveLength(3);
        expect(server.sent('tick').filter(({ lane }) => lane === 2)).toHaveLength(1);
        expect(server.sent('tick')[0]).toEqual({ page: 2, per_page: 25, nonce: 'abc12345', lane: 1 });
        expect(server.requests.every(({ url, method }) => url === urls.tick && method === 'post')).toBe(true);
        expect(onState).toHaveBeenCalledTimes(4);
    });

    it('stops when the run is final, even when the report says work remains', async () => {
        const server = fakeApi({ tick: () => ticked(true, 0, { status: 'cancelled' }) });
        const lanes = startLanes({ api: server.api, urls, lanes: 2, sleep: immediately() });

        await lanes.idle();

        expect(server.sent('tick')).toHaveLength(2);
    });

    it('uses the page nonce by default and makes at least one lane', async () => {
        const server = fakeApi({ tick: () => ticked(false) });
        const lanes = startLanes({ api: server.api, urls, lanes: 0, sleep: immediately() });

        await lanes.idle();

        expect(server.sent('tick')).toEqual([{ nonce: nonce(), lane: 1 }]);
    });

    it('honours the wait of each report', async () => {
        const reports = [ticked(true, 3), ticked(true, 0), ticked(true, null), ticked(false)];
        reports[2].report.wait = null;
        const server = fakeApi({ tick: () => reports.shift() });
        const sleep = immediately();
        const lanes = startLanes({ api: server.api, urls, sleep });

        await lanes.idle();

        expect(sleep.mock.calls.map(([ms]) => ms)).toEqual([3_000, 0, IDLE_MS]);
    });

    it('does not tick again before the wait has passed', async () => {
        vi.useFakeTimers();
        const reports = [ticked(true, 2), ticked(false)];
        const server = fakeApi({ tick: () => reports.shift() });
        const lanes = startLanes({ api: server.api, urls });

        await vi.advanceTimersByTimeAsync(1_999);
        expect(server.sent('tick')).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(server.sent('tick')).toHaveLength(2);
        await lanes.idle();
    });

    it('stops a lane whose requests keep failing after its retries, and reports it', async () => {
        const server = fakeApi({ tick: ({ lane }) => (lane === 1 ? error(500) : ticked(false)) });
        const sleep = immediately();
        const onFailure = vi.fn();
        const lanes = startLanes({ api: server.api, urls, lanes: 2, sleep, onFailure });

        await lanes.idle();

        expect(server.sent('tick').filter(({ lane }) => lane === 1)).toHaveLength(1 + MAX_RETRIES);
        expect(sleep.mock.calls.map(([ms]) => ms)).toEqual([1_000, 2_000, 4_000, 8_000, 16_000]);
        expect(onFailure).toHaveBeenCalledTimes(1);
        expect(onFailure).toHaveBeenCalledWith({ lane: 1, error: { status: 500, code: null, message: 'Failed with 500.' } });
        expect(lanes.failed).toEqual([{ lane: 1, error: { status: 500, code: null, message: 'Failed with 500.' } }]);
        expect(server.sent('tick').filter(({ lane }) => lane === 2)).toHaveLength(1);
    });

    it('stops a lane at once on a failure that a retry cannot fix', async () => {
        const server = fakeApi({ tick: () => error(403) });
        const sleep = immediately();
        const onFailure = vi.fn();
        const lanes = startLanes({ api: server.api, urls, sleep, onFailure });

        await lanes.idle();

        expect(server.sent('tick')).toHaveLength(1);
        expect(sleep).not.toHaveBeenCalled();
        expect(onFailure).toHaveBeenCalledWith({ lane: 1, error: expect.objectContaining({ status: 403 }) });
    });

    it('counts only failures in a row', async () => {
        const answers = [error(502), error(502), ticked(true), ...Array(MAX_RETRIES).fill(error(503)), ticked(false)];
        const total = answers.length;
        const server = fakeApi({ tick: () => answers.shift() });
        const onFailure = vi.fn();
        const lanes = startLanes({ api: server.api, urls, sleep: immediately(), onFailure });

        await lanes.idle();

        expect(server.sent('tick')).toHaveLength(total);
        expect(onFailure).not.toHaveBeenCalled();
        expect(lanes.failed).toEqual([]);
    });

    it('stops on abort without reporting the answer of a request in flight', async () => {
        const server = fakeApi();
        const controller = new AbortController();
        const onState = vi.fn();
        const lanes = startLanes({ api: server.api, urls, onState, signal: controller.signal });

        controller.abort();
        await server.answer('tick', ticked(true));
        await lanes.idle();

        expect(onState).not.toHaveBeenCalled();
        expect(server.sent('tick')).toHaveLength(1);
    });

    it('stops on abort while it waits', async () => {
        const server = fakeApi({ tick: () => ticked(true, 60) });
        const controller = new AbortController();
        const lanes = startLanes({ api: server.api, urls, signal: controller.signal });

        await flush();
        controller.abort();
        await lanes.idle();

        expect(server.sent('tick')).toHaveLength(1);
    });

    it('stops on abort while it backs off', async () => {
        const server = fakeApi({ tick: () => error(500) });
        const controller = new AbortController();
        const onFailure = vi.fn();
        const lanes = startLanes({ api: server.api, urls, signal: controller.signal, onFailure });

        await flush();
        controller.abort();
        await lanes.idle();

        expect(server.sent('tick')).toHaveLength(1);
        expect(onFailure).not.toHaveBeenCalled();
    });

    it('reports a lane whose state callback throws as failed', async () => {
        const server = fakeApi({ tick: () => ticked(true) });
        const onFailure = vi.fn();
        const lanes = startLanes({
            api: server.api,
            urls,
            sleep: immediately(),
            onFailure,
            onState: () => {
                throw new Error('Broken page.');
            },
        });

        await lanes.idle();

        expect(onFailure).toHaveBeenCalledWith({ lane: 1, error: { status: 0, code: null, message: 'Broken page.' } });
        expect(lanes.active).toBe(0);
    });

    it('restarts the lanes that finished, not those that failed', async () => {
        const server = fakeApi({ tick: ({ lane }) => (lane === 2 ? error(403) : ticked(false)) });
        const lanes = startLanes({ api: server.api, urls, lanes: 2, sleep: immediately() });

        await lanes.idle();
        expect(lanes.restart()).toBe(1);
        expect(lanes.active).toBe(1);
        await lanes.idle();

        expect(server.sent('tick').map(({ lane }) => lane)).toEqual([1, 2, 1]);
    });

    it('restarts nothing after an abort', async () => {
        const server = fakeApi({ tick: () => ticked(false) });
        const controller = new AbortController();
        const lanes = startLanes({ api: server.api, urls, signal: controller.signal, sleep: immediately() });

        await lanes.idle();
        controller.abort();

        expect(lanes.restart()).toBe(0);
        expect(server.sent('tick')).toHaveLength(1);
    });

    it('tells subscribers about each answer and each lane that ended', async () => {
        const answers = [ticked(true), ticked(false)];
        const server = fakeApi({ tick: () => answers.shift() });
        const events = [];
        const lanes = startLanes({ api: server.api, urls, sleep: immediately() });
        const unsubscribe = lanes.subscribe((event) => events.push(event));

        await lanes.idle();
        unsubscribe();

        expect(events.map((event) => event.ended ?? event.state.report.remains)).toEqual([true, false, 'finished']);
        expect(events.every((event) => event.lane === 1)).toBe(true);
    });

    // The server gives a worker its own item back with a new claim, so the saves of a request still working on it would
    // fail: its waits, attempts and usage would be lost, and a provider that keeps failing would never fail the item.
    it('never has two ticks of one worker id in flight, whichever lanes of the page send them', async () => {
        const server = fakeApi();
        const other = Object.fromEntries(Object.entries(urls).map(([action, url]) => [action, url.replace('01RUN', '01OTHER')]));
        const sent = () => server.pending.map(({ data }) => `${data.lane}`);
        const pushed = vi.fn();
        const onState = vi.fn();

        // The editor's push and the run page of the same run in one page load.
        const push = startLanes({ api: server.api, urls, lanes: 2, nonce: 'abc12345', onState: pushed, sleep: immediately() });
        const page = startLanes({ api: server.api, urls, lanes: 2, nonce: 'abc12345', onState, sleep: immediately() });
        // Another run is not held back.
        const elsewhere = startLanes({ api: server.api, urls: other, nonce: 'abc12345', sleep: immediately() });
        await flush();

        expect(sent()).toEqual(['1', '2', '1']);
        expect(server.pending.map(({ data }) => data.nonce)).toEqual(['abc12345', 'abc12345', 'abc12345']);

        await server.answer('tick', ticked(false));

        expect(pushed).toHaveBeenCalledOnce();
        expect(sent()).toEqual(['2', '1', '1']);

        while (server.pending.length) {
            await server.answer('tick', ticked(false));
        }

        await Promise.all([push.idle(), page.idle(), elsewhere.idle()]);

        expect(server.sent('tick')).toHaveLength(5);
        expect(onState).toHaveBeenCalledTimes(2);
    });

    it('sends the tick of a restarted lane once the stopped loop has the answer to its own', async () => {
        const server = fakeApi();
        const stopped = new AbortController();
        const onState = vi.fn();

        startLanes({ api: server.api, urls, signal: stopped.signal, onState });
        stopped.abort();

        const lanes = startLanes({ api: server.api, urls, onState, sleep: immediately() });
        await flush();

        expect(server.sent('tick')).toHaveLength(1);

        await server.answer('tick', ticked(true));

        expect(onState).not.toHaveBeenCalled();
        expect(server.sent('tick')).toHaveLength(2);

        await server.answer('tick', ticked(false));
        await lanes.idle();

        expect(onState).toHaveBeenCalledOnce();
    });

    it('sends nothing for a lane stopped while it waited for the tick before', async () => {
        const server = fakeApi();
        const controller = new AbortController();
        const first = startLanes({ api: server.api, urls, sleep: immediately() });
        const waiting = startLanes({ api: server.api, urls, signal: controller.signal, sleep: immediately() });

        await flush();
        controller.abort();
        await server.answer('tick', ticked(false));

        expect(server.sent('tick')).toHaveLength(1);

        await Promise.all([first.idle(), waiting.idle()]);

        expect(waiting.failed).toEqual([]);
    });
});

describe('startApplyLoop', () => {
    it('applies while translated items remain, honouring the wait, and returns the last state', async () => {
        const answers = [applied(true, 1), applied(true, 0), applied(false)];
        const server = fakeApi({ apply: () => answers.shift() });
        const sleep = immediately();
        const onState = vi.fn();

        const result = await startApplyLoop({ api: server.api, urls, sleep, onState, query: () => ({ page: 3 }) });

        expect(server.sent('apply')).toEqual([{ page: 3 }, { page: 3 }, { page: 3 }]);
        expect(sleep.mock.calls.map(([ms]) => ms)).toEqual([1_000, 0]);
        expect(onState).toHaveBeenCalledTimes(3);
        expect(result).toEqual({ state: applied(false), error: null });
    });

    it('keeps only one apply request in flight at a time', async () => {
        const server = fakeApi();
        const lanes = fakeLanes(2);
        const done = startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });

        await flush();
        lanes.emit({ lane: 1, state: ticked(true, 0, { counts: { translated: 1 } }) });
        lanes.emit({ lane: 2, state: ticked(true, 0, { counts: { translated: 2 } }) });
        lanes.emit({ lane: 2, ended: 'finished' });
        await flush();
        expect(server.pending).toHaveLength(1);

        await server.answer('apply', applied(true, 0));
        await server.answer('apply', applied(false));
        expect(server.pending).toHaveLength(0);

        lanes.emit({ lane: 1, state: ticked(true, 0, { counts: { translated: 1 } }) });
        lanes.emit({ lane: 1, state: ticked(true, 0, { counts: { translated: 2 } }) });
        await flush();
        expect(server.pending).toHaveLength(1);

        await server.answer('apply', applied(false, null, { status: 'completed' }));
        await done;

        expect(server.sent('apply')).toHaveLength(3);
        expect(server.maxInFlight('apply')).toBe(1);
    });

    it('waits for the lanes while nothing is translated, then applies what they translated', async () => {
        const server = fakeApi({ apply: () => applied(false) });
        const lanes = fakeLanes();
        const done = startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });

        await flush();
        expect(server.sent('apply')).toHaveLength(1);

        lanes.emit({ lane: 1, state: ticked(true) });
        await flush();
        expect(server.sent('apply')).toHaveLength(1);

        lanes.emit({ lane: 1, state: ticked(true, 0, { counts: { translated: 1 } }) });
        await flush();
        expect(server.sent('apply')).toHaveLength(2);

        lanes.active = 0;
        lanes.emit({ lane: 1, ended: 'finished' });
        await done;

        expect(server.sent('apply')).toHaveLength(3);
        expect(lanes.listeners.size).toBe(0);
    });

    it('applies again at once when the lanes translated items during its request', async () => {
        const server = fakeApi();
        const lanes = fakeLanes();
        const done = startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });

        await flush();
        lanes.emit({ lane: 1, state: ticked(true, 0, { counts: { translated: 1 } }) });
        await server.answer('apply', applied(false));
        expect(server.sent('apply')).toHaveLength(2);

        await server.answer('apply', applied(false, null, { status: 'completed' }));
        await done;
    });

    it('ends when the run is final', async () => {
        const server = fakeApi({ apply: () => applied(true, 0, { status: 'aborted' }) });
        const lanes = fakeLanes();

        const result = await startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });

        expect(server.sent('apply')).toHaveLength(1);
        expect(result.state.run.status).toBe('aborted');
    });

    it('restarts the lanes when an apply sent items back to be translated again', async () => {
        const answers = [applied(false, null, { counts: { pending: 1 } }, 1), applied(false, null, { status: 'completed' })];
        const server = fakeApi({ apply: () => answers.shift() });
        const lanes = fakeLanes(0);
        lanes.restart.mockImplementation(() => {
            lanes.active = 1;
            queueMicrotask(() => lanes.emit({ lane: 1, ended: 'finished' }));

            return 1;
        });

        await startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });

        expect(lanes.restart).toHaveBeenCalledTimes(1);
        expect(server.sent('apply')).toHaveLength(2);
    });

    it('restarts the lanes when pending items wait and no lane runs', async () => {
        const server = fakeApi({ apply: () => applied(false, null, { counts: { pending: 2 } }) });
        const lanes = fakeLanes(0);

        await startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });

        expect(lanes.restart).toHaveBeenCalledTimes(1);
    });

    it('does not restart the lanes of a run that is not running in the browser', async () => {
        const lanes = fakeLanes(0);

        for (const extra of [{ driver: 'queue' }, { status: 'pending' }]) {
            const server = fakeApi({ apply: () => applied(false, null, { ...extra, counts: { pending: 2 } }, 1) });

            await startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });
        }

        expect(lanes.restart).not.toHaveBeenCalled();
    });

    it('stops after its retries and reports the error', async () => {
        const server = fakeApi({ apply: () => error(500) });
        const sleep = immediately();
        const onFailure = vi.fn();
        const lanes = fakeLanes();

        const result = await startApplyLoop({ api: server.api, urls, lanes, sleep, onFailure });

        expect(server.sent('apply')).toHaveLength(1 + MAX_RETRIES);
        expect(sleep).toHaveBeenCalledTimes(MAX_RETRIES);
        expect(onFailure).toHaveBeenCalledWith({ error: { status: 500, code: null, message: 'Failed with 500.' } });
        expect(result).toEqual({ state: null, error: { status: 500, code: null, message: 'Failed with 500.' } });
        expect(lanes.listeners.size).toBe(0);
    });

    it('ends on abort while it waits for the lanes', async () => {
        const server = fakeApi({ apply: () => applied(false) });
        const lanes = fakeLanes();
        const controller = new AbortController();
        const done = startApplyLoop({ api: server.api, urls, lanes, signal: controller.signal, sleep: immediately() });

        await flush();
        controller.abort();

        await expect(done).resolves.toEqual({ state: applied(false), error: null });
        expect(server.sent('apply')).toHaveLength(1);
        expect(lanes.listeners.size).toBe(0);
    });
});

describe('lanes and the apply loop together', () => {
    it('run a browser run to completion, one apply at a time', async () => {
        const run = simulatedRun(5);
        const server = fakeApi({ tick: run.tick, apply: run.apply });
        const statuses = [];
        const onState = latest((value) => statuses.push(value.run.status));
        const lanes = startLanes({ api: server.api, urls, lanes: 2, onState, sleep: immediately() });

        const result = await startApplyLoop({ api: server.api, urls, lanes, onState, sleep: immediately() });
        await lanes.idle();

        expect(run.items).toEqual(Array(5).fill('done'));
        expect(result.state.run.status).toBe('completed');
        expect(statuses.at(-1)).toBe('completed');
        expect(server.maxInFlight('apply')).toBe(1);
        expect(new Set(server.sent('tick').map(({ lane }) => lane))).toEqual(new Set([1, 2]));
    });

    it('translate again the items an apply sent back after the lanes ended', async () => {
        const run = simulatedRun(2, { returnFirst: [0, 1] });
        const server = fakeApi({ tick: run.tick, apply: run.apply });
        const lanes = startLanes({ api: server.api, urls, lanes: 2, sleep: immediately() });

        await lanes.idle();
        const result = await startApplyLoop({ api: server.api, urls, lanes, sleep: immediately() });
        await lanes.idle();

        expect(run.items).toEqual(['done', 'done']);
        expect(result.state.run.status).toBe('completed');
        expect(server.sent('apply')).toHaveLength(2);
    });
});

describe('pollState', () => {
    it('fetches the state at the interval until the run is final', async () => {
        const answers = [state(), state({ second: 1 }), state({ status: 'completed', second: 2 })];
        const server = fakeApi({ state: () => answers.shift() });
        const sleep = immediately();
        const onState = vi.fn();

        const result = await pollState({ api: server.api, url: urls.state, sleep, onState, query: () => ({ status: 'failed' }) });

        expect(server.requests.map(({ method, data }) => [method, data])).toEqual(Array(3).fill(['get', { status: 'failed' }]));
        expect(sleep.mock.calls.map(([ms]) => ms)).toEqual([POLL_MS, POLL_MS]);
        expect(onState).toHaveBeenCalledTimes(3);
        expect(result.state.run.status).toBe('completed');
    });

    it('uses the given interval', async () => {
        vi.useFakeTimers();
        const server = fakeApi({ state: () => state() });
        const controller = new AbortController();
        const done = pollState({ api: server.api, url: urls.state, interval: 500, signal: controller.signal });

        await vi.advanceTimersByTimeAsync(499);
        expect(server.requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(server.requests).toHaveLength(2);

        controller.abort();
        await done;
    });

    it('stops after its retries and reports the error', async () => {
        const server = fakeApi({ state: (data, call) => (call === 1 ? state() : error(0)) });
        const onFailure = vi.fn();

        const result = await pollState({ api: server.api, url: urls.state, sleep: immediately(), onFailure });

        expect(server.requests).toHaveLength(2 + MAX_RETRIES);
        expect(onFailure).toHaveBeenCalledTimes(1);
        expect(result).toEqual({ state: state(), error: { status: 0, code: null, message: 'Failed with 0.' } });
    });

    it('stops at once when the run no longer exists', async () => {
        const server = fakeApi({ state: () => error(404, 'run_not_found') });

        const result = await pollState({ api: server.api, url: urls.state, sleep: immediately() });

        expect(server.requests).toHaveLength(1);
        expect(result.error).toMatchObject({ status: 404, code: 'run_not_found' });
    });
});

describe('estimateLoop', () => {
    // An estimate of `size` items, two per call, as RunPlanner::estimate() adds them up on the server.
    function estimating(size) {
        let stored = null;

        return (data) => {
            if (data.from > 0 && data.from !== stored?.next) {
                return stored;
            }

            const total = data.from === 0 ? { segments: 0, chars: 0, input_tokens: 0, output_tokens: 0, cost: 0 } : stored;
            const end = Math.min(size, data.from + 2);
            const count = end - data.from;

            stored = {
                segments: total.segments + 3 * count,
                chars: total.chars + 100 * count,
                input_tokens: total.input_tokens + 50 * count,
                output_tokens: total.output_tokens + 40 * count,
                cost: total.cost + 0.01 * count,
                next: end < size ? end : null,
            };

            return stored;
        };
    }

    it('calls estimate until the next index is null, and the totals accumulate', async () => {
        const server = fakeApi({ estimate: estimating(5) });
        const onEstimate = vi.fn();

        const result = await estimateLoop({ api: server.api, url: urls.estimate, onEstimate });

        expect(server.sent('estimate')).toEqual([{ from: 0 }, { from: 2 }, { from: 4 }]);
        expect(onEstimate.mock.calls.map(([estimate]) => [estimate.segments, estimate.chars, estimate.next])).toEqual([
            [6, 200, 2],
            [12, 400, 4],
            [15, 500, null],
        ]);
        expect(result.estimate).toMatchObject({ segments: 15, chars: 500, input_tokens: 250, output_tokens: 200, next: null });
        expect(result.estimate.cost).toBeCloseTo(0.05);
        expect(result.error).toBeNull();
    });

    it('continues from the next index of the stored estimate', async () => {
        const handler = estimating(5);
        handler({ from: 0 });
        const server = fakeApi({ estimate: handler });

        const result = await estimateLoop({ api: server.api, url: urls.estimate, estimate: { segments: 6, chars: 200, next: 2 } });

        expect(server.sent('estimate')).toEqual([{ from: 2 }, { from: 4 }]);
        expect(result.estimate.segments).toBe(15);
    });

    it('does nothing when the stored estimate is finished', async () => {
        const server = fakeApi({ estimate: estimating(5) });
        const stored = { segments: 15, chars: 500, next: null };
        const onEstimate = vi.fn();

        const result = await estimateLoop({ api: server.api, url: urls.estimate, estimate: stored, onEstimate });

        expect(server.requests).toHaveLength(0);
        expect(onEstimate).not.toHaveBeenCalled();
        expect(result).toEqual({ estimate: stored, error: null });
    });

    it('continues from the index the server answers when it ignored the call', async () => {
        const handler = estimating(7);
        handler({ from: 0 });
        handler({ from: 2 });
        const server = fakeApi({ estimate: handler });

        await estimateLoop({ api: server.api, url: urls.estimate, estimate: { segments: 6, next: 2 } });

        expect(server.sent('estimate')).toEqual([{ from: 2 }, { from: 4 }, { from: 6 }]);
    });

    it('retries a failed call from the same index', async () => {
        const handler = estimating(3);
        const server = fakeApi({ estimate: (data, call) => (call === 2 ? error(503) : handler(data)) });
        const sleep = immediately();

        const result = await estimateLoop({ api: server.api, url: urls.estimate, sleep });

        expect(server.sent('estimate')).toEqual([{ from: 0 }, { from: 2 }, { from: 2 }]);
        expect(sleep).toHaveBeenCalledTimes(1);
        expect(result.estimate).toMatchObject({ segments: 9, next: null });
    });

    it('stops after its retries and reports the error', async () => {
        const handler = estimating(5);
        const server = fakeApi({ estimate: (data, call) => (call === 1 ? handler(data) : error(500)) });
        const onFailure = vi.fn();

        const result = await estimateLoop({ api: server.api, url: urls.estimate, sleep: immediately(), onFailure });

        expect(server.sent('estimate')).toEqual([{ from: 0 }, ...Array(1 + MAX_RETRIES).fill({ from: 2 })]);
        expect(onFailure).toHaveBeenCalledTimes(1);
        expect(result.estimate).toMatchObject({ segments: 6, next: 2 });
        expect(result.error).toMatchObject({ status: 500 });
    });

    it('stops on abort', async () => {
        const server = fakeApi();
        const controller = new AbortController();
        const onEstimate = vi.fn();
        const done = estimateLoop({ api: server.api, url: urls.estimate, signal: controller.signal, onEstimate });

        controller.abort();
        await server.answer('estimate', { segments: 1, next: 1 });

        await expect(done).resolves.toEqual({ estimate: null, error: null });
        expect(onEstimate).not.toHaveBeenCalled();
    });
});
