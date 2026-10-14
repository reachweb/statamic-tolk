import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFollower } from './follow.js';
import { latest } from './ticker.js';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const base = 'https://cp.test/cp/tolk/runs/01RUN';
const urls = Object.fromEntries(
    ['state', 'estimate', 'start', 'tick', 'apply', 'cancel', 'resume', 'retry', 'take_over'].map((action) => [action, `${base}/${action}`]),
);

const time = (second) => `2026-10-05T10:00:${String(second).padStart(2, '0')}Z`;

const state = ({ status = 'running', driver = 'browser', second = 0, estimate = null, extra = {} } = {}) => ({
    run: { id: '01RUN', status, driver, progressed_at: time(second), estimate },
    counts: { pending: 1 },
    items: [],
    stalled: false,
    ...extra,
});

// A ticker whose loops only record how they were started; each loop's promise settles when the test says so.
function fakeTicker() {
    const started = [];
    const settle = {};

    const loop = (name) =>
        vi.fn((options) => {
            started.push({ name, options });

            return new Promise((resolve, reject) => {
                settle[name] = { resolve, reject };
            });
        });

    const listeners = new Set();
    const handle = {
        active: 0,
        subscribe: (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        // Every lane ends, as the real handle tells its subscribers.
        end: () => {
            handle.active = 0;
            listeners.forEach((listener) => listener({ lane: 1, ended: 'finished' }));
        },
    };

    return {
        started,
        settle,
        handle,
        latest,
        startLanes: vi.fn((options) => {
            started.push({ name: 'lanes', options });
            handle.active = options.lanes;

            return handle;
        }),
        startApplyLoop: loop('apply'),
        pollState: loop('poll'),
        estimateLoop: loop('estimate'),
        names: () => started.map(({ name }) => name),
        last: (name) => started.filter((loop) => loop.name === name).at(-1)?.options,
    };
}

function fakeApi(answers = {}) {
    const requests = [];
    const answer = (method) => (url, data) => {
        requests.push({ method, url, data });
        const route = url.split('/').pop();

        return Promise.resolve(typeof answers[route] === 'function' ? answers[route](data) : answers[route]);
    };

    return { requests, get: vi.fn(answer('get')), post: vi.fn(answer('post')) };
}

function follower({ api = fakeApi(), ticker = fakeTicker(), query = () => ({ page: 2, per_page: 25 }), dirty = undefined } = {}) {
    const states = [];
    const estimates = [];
    const failures = [];
    const follow = createFollower({
        urls,
        lanes: 2,
        api,
        ticker,
        query,
        dirty,
        onState: (value) => states.push(value),
        onEstimate: (value) => estimates.push(value),
        onFailures: (value) => failures.push(value),
    });

    return { follow, api, ticker, states, estimates, failures };
}

describe('follow', () => {
    it('estimates a pending run from its stored estimate, then reads the state with the skipped items', async () => {
        const fresh = state({ status: 'pending', extra: { counts: { pending: 1, skipped: 2 } } });
        const { follow, api, ticker, states } = follower({ api: fakeApi({ state: fresh }) });
        const stored = { segments: 3, next: 4 };

        follow.follow(state({ status: 'pending', estimate: stored }));

        expect(ticker.names()).toEqual(['estimate']);
        expect(ticker.last('estimate')).toMatchObject({ url: urls.estimate, estimate: stored });
        expect(states).toHaveLength(1);

        ticker.settle.estimate.resolve({ estimate: { next: null }, error: null });
        await flush();

        expect(api.requests).toEqual([{ method: 'get', url: urls.state, data: { page: 2, per_page: 25 } }]);
        expect(states.at(-1)).toBe(fresh);
        expect(follow.active).toBe(false);
    });

    it('does not read the state after an estimate that failed, and reports the failure', async () => {
        const { follow, api, ticker, failures } = follower();

        follow.follow(state({ status: 'pending' }));
        ticker.last('estimate').onFailure({ error: { message: 'Boom' } });
        ticker.settle.estimate.resolve({ estimate: null, error: { message: 'Boom' } });
        await flush();

        expect(api.requests).toEqual([]);
        expect(failures.at(-1)).toEqual([{ error: { message: 'Boom' } }]);
        expect(follow.failures).toEqual([{ error: { message: 'Boom' } }]);
    });

    it('drives a running browser run with its lanes and the apply loop, active until both are done', async () => {
        const { follow, ticker } = follower();

        follow.follow(state());

        expect(ticker.names()).toEqual(['lanes', 'apply']);
        expect(ticker.last('lanes')).toMatchObject({ urls, lanes: 2 });
        expect(ticker.last('lanes').query()).toEqual({ page: 2, per_page: 25 });
        expect(ticker.last('apply').lanes).toBe(ticker.handle);
        expect(follow.active).toBe(true);

        ticker.handle.active = 0;
        expect(follow.active).toBe(true);

        ticker.settle.apply.resolve({ state: null, error: null });
        await flush();

        expect(follow.active).toBe(false);
    });

    it('polls a running run that a queue or the CLI drives, and does not drive it', () => {
        for (const driver of ['queue', 'cli']) {
            const { follow, ticker } = follower();

            follow.follow(state({ driver }));

            expect(ticker.names()).toEqual(['poll']);
            expect(ticker.last('poll').url).toBe(urls.state);
            expect(follow.active).toBe(false);
        }
    });

    it('starts nothing for a finished run', () => {
        for (const status of ['completed', 'completed_with_errors', 'cancelled', 'aborted']) {
            const { follow, ticker, states } = follower();

            follow.follow(state({ status }));

            expect(ticker.names()).toEqual([]);
            expect(states).toHaveLength(1);
        }
    });

    it('starts a pending run: stops the estimate and drives the run from the answer', async () => {
        const { follow, api, ticker, states } = follower({ api: fakeApi({ start: state({ second: 1 }) }) });

        follow.follow(state({ status: 'pending' }));
        const estimating = ticker.last('estimate').signal;

        await follow.start();

        expect(api.requests).toEqual([{ method: 'post', url: urls.start, data: { page: 2, per_page: 25 } }]);
        expect(estimating.aborted).toBe(true);
        expect(ticker.names()).toEqual(['estimate', 'lanes', 'apply']);
        expect(states.at(-1).run.status).toBe('running');
    });

    it('cancels without stopping the loops, which end on the cancelled state', async () => {
        const cancelled = state({ status: 'cancelled', second: 2 });
        const { follow, ticker, states } = follower({ api: fakeApi({ cancel: cancelled }) });

        follow.follow(state({ second: 1 }));
        await follow.cancel();

        expect(states.at(-1)).toBe(cancelled);
        expect(ticker.last('lanes').signal.aborted).toBe(false);

        // A tick that read the run before the cancel is older and is dropped.
        ticker.last('lanes').onState(state({ second: 2 }));
        expect(states.at(-1)).toBe(cancelled);
    });

    it('resumes a cancelled run with new lanes, although the answer is of the same second as the cancel', async () => {
        const resumed = state({ second: 2 });
        const { follow, ticker, states } = follower({ api: fakeApi({ resume: resumed }) });

        follow.follow(state({ status: 'cancelled', second: 2 }));
        await follow.resume();

        expect(states.at(-1)).toBe(resumed);
        expect(ticker.names()).toEqual(['lanes', 'apply']);
    });

    it('retries, and moves a stalled queue run to this browser', async () => {
        const { follow, api, ticker, states } = follower({
            api: fakeApi({ retry: { ...state({ second: 3 }), retried: 2 }, take_over: state({ second: 4 }) }),
        });

        follow.follow(state({ status: 'completed_with_errors', second: 2 }));
        const retried = await follow.retry();

        expect(retried.retried).toBe(2);
        expect(ticker.names()).toEqual(['lanes', 'apply']);

        follow.follow(state({ driver: 'queue', second: 5, extra: { stalled: true } }));
        const polling = ticker.last('poll').signal;
        await follow.takeOver();

        expect(polling.aborted).toBe(true);
        expect(api.requests.map(({ url }) => url)).toEqual([urls.retry, urls.take_over]);
        expect(ticker.names()).toEqual(['lanes', 'apply', 'poll', 'lanes', 'apply']);
        expect(states.at(-1).run.driver).toBe('browser');
    });

    it('reads another page of items, and passes nothing on once it was stopped', async () => {
        const page = { ...state({ status: 'completed', second: 1 }), pagination: { page: 2 } };
        const { follow, states } = follower({ api: fakeApi({ state: page }) });

        follow.follow(state({ status: 'completed' }));
        await follow.refresh();

        expect(states.at(-1)).toBe(page);

        follow.stop();
        await follow.refresh();

        expect(states).toHaveLength(2);
    });

    it('drives a pending run that another tab started, once the state read after the estimate shows it running', async () => {
        const started = state({ second: 1 });
        const { follow, api, ticker, states } = follower({ api: fakeApi({ state: started }) });

        follow.follow(state({ status: 'pending' }));
        ticker.settle.estimate.resolve({ estimate: { next: null }, error: null });
        await flush();

        expect(api.requests.map(({ url }) => url)).toEqual([urls.state]);
        expect(ticker.names()).toEqual(['estimate', 'lanes', 'apply']);
        expect(ticker.last('lanes').onState).toBe(ticker.last('apply').onState);
        expect(follow.active).toBe(true);
        expect(states).toEqual([state({ status: 'pending' }), started]);
    });

    it('follows a run that was started, resumed or taken over elsewhere when another page of items shows it', async () => {
        const cases = [
            { from: state({ status: 'pending' }), to: state({ driver: 'queue', second: 1 }), loops: ['estimate', 'poll'] },
            { from: state({ status: 'cancelled' }), to: state({ second: 1 }), loops: ['lanes', 'apply'] },
            { from: state({ status: 'completed_with_errors' }), to: state({ driver: 'queue', second: 1 }), loops: ['poll'] },
            { from: state({ driver: 'queue' }), to: state({ second: 1 }), loops: ['poll', 'lanes', 'apply'] },
        ];

        for (const { from, to, loops } of cases) {
            const { follow, ticker, states } = follower({ api: fakeApi({ state: to }) });

            follow.follow(from);
            const before = ticker.started.map(({ options }) => options.signal);
            await follow.refresh();

            expect(ticker.names()).toEqual(loops);
            expect(before.every((signal) => signal.aborted)).toBe(true);
            expect(states).toEqual([from, to]);
        }
    });

    it('drives a run again that was cancelled while it drove it and resumed elsewhere', async () => {
        const resumed = state({ second: 3 });
        const { follow, ticker, states } = follower({ api: fakeApi({ state: resumed }) });

        follow.follow(state({ second: 1 }));
        ticker.last('lanes').onState(state({ status: 'cancelled', second: 2 }));
        ticker.handle.end();
        ticker.settle.apply.resolve({ state: null, error: null });
        await flush();

        expect(follow.active).toBe(false);

        await follow.refresh();

        expect(ticker.names()).toEqual(['lanes', 'apply', 'lanes', 'apply']);
        expect(follow.active).toBe(true);
        expect(states.at(-1)).toBe(resumed);
    });

    it('drives a queue run it polls once another tab took it over', () => {
        const { follow, ticker, states } = follower();

        follow.follow(state({ driver: 'queue' }));
        const polling = ticker.last('poll').signal;
        ticker.last('poll').onState(state({ second: 1 }));

        expect(polling.aborted).toBe(true);
        expect(ticker.names()).toEqual(['poll', 'lanes', 'apply']);
        expect(states).toHaveLength(2);
    });

    it('keeps its loops while the state needs the same ones, and lets them end on a final state', async () => {
        const answers = [state({ second: 1 }), state({ status: 'completed', second: 2 })];
        const { follow, ticker, states } = follower({ api: fakeApi({ state: () => answers.shift() }) });

        follow.follow(state());
        ticker.last('lanes').onFailure({ lane: 1, error: { message: 'Gone' } });
        await follow.refresh();

        expect(ticker.names()).toEqual(['lanes', 'apply']);
        expect(follow.failures).toHaveLength(1);

        await follow.refresh();

        expect(ticker.names()).toEqual(['lanes', 'apply']);
        expect(ticker.last('lanes').signal.aborted).toBe(false);
        expect(states.at(-1).run.status).toBe('completed');

        const pending = follower({ api: fakeApi({ state: state({ status: 'pending', second: 1 }) }) });

        pending.follow.follow(state({ status: 'pending' }));
        await pending.follow.refresh();

        expect(pending.ticker.names()).toEqual(['estimate']);
        expect(pending.ticker.last('estimate').signal.aborted).toBe(false);
    });

    it('starts nothing for a running state older than the final one it shows', async () => {
        const { follow, ticker, states } = follower({ api: fakeApi({ state: state({ second: 2 }) }) });

        follow.follow(state({ second: 1 }));
        ticker.last('lanes').onState(state({ status: 'completed', second: 3 }));
        await follow.refresh();

        expect(ticker.names()).toEqual(['lanes', 'apply']);
        expect(states.at(-1).run.status).toBe('completed');
    });

    it('stops the loops it started when it follows another state, and forgets their failures', () => {
        const { follow, ticker, failures } = follower();

        follow.follow(state());
        const first = ticker.last('lanes').signal;
        ticker.last('lanes').onFailure({ lane: 1, error: { message: 'Gone' } });

        expect(failures.at(-1)).toEqual([{ lane: 1, error: { message: 'Gone' } }]);

        follow.follow(state({ second: 1 }));

        expect(first.aborted).toBe(true);
        expect(failures.at(-1)).toEqual([]);
        expect(ticker.last('lanes').signal.aborted).toBe(false);
    });

    it('reports a loop that threw instead of leaving the promise rejected', async () => {
        const { follow, ticker, failures } = follower();

        follow.follow(state());
        ticker.handle.active = 0;
        ticker.settle.apply.reject(new Error('onState threw'));
        await flush();

        expect(failures.at(-1)).toEqual([{ error: { status: 0, code: null, message: 'onState threw' } }]);
        expect(follow.active).toBe(false);
    });
});

// Leaving the run page pauses a run it drives, also for a visit inside the CP, which unmounts the page without a
// `beforeunload`: the page names itself in core's dirty state, which asks before a visit, Back or closing the tab.
describe('leaving the page', () => {
    let names;

    beforeEach(() => {
        names = new Set();
        vi.stubGlobal('Statamic', { $dirty: { add: (name) => names.add(name), remove: (name) => names.delete(name), has: (name) => names.has(name) } });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('marks the page dirty while it drives a running browser run, until the lanes and the apply loop are done', async () => {
        const { follow, ticker } = follower({ dirty: 'tolk-run' });

        follow.follow(state());

        expect([...names]).toEqual(['tolk-run']);
        expect(follow.driving).toBe(true);

        ticker.handle.end();

        expect([...names]).toEqual(['tolk-run']);

        ticker.settle.apply.resolve({ state: null, error: null });
        await flush();

        expect([...names]).toEqual([]);
        expect(follow.driving).toBe(false);
    });

    it('stops asking once the run is cancelled or complete, while its loops still end', async () => {
        const cancelled = state({ status: 'cancelled', second: 2 });
        const { follow, ticker } = follower({ dirty: 'tolk-run', api: fakeApi({ cancel: cancelled, resume: state({ second: 3 }) }) });

        follow.follow(state({ second: 1 }));
        await follow.cancel();

        expect(follow.active).toBe(true);
        expect(follow.driving).toBe(false);
        expect([...names]).toEqual([]);

        await follow.resume();

        expect([...names]).toEqual(['tolk-run']);

        ticker.last('lanes').onState(state({ status: 'completed', second: 4 }));

        expect(follow.active).toBe(true);
        expect([...names]).toEqual([]);
    });

    it('stops asking when the page stops, and when the lanes end after the apply loop gave up', async () => {
        const { follow, ticker } = follower({ dirty: 'tolk-run' });

        follow.follow(state());
        follow.stop();

        expect([...names]).toEqual([]);

        follow.follow(state({ second: 1 }));
        ticker.settle.apply.resolve({ state: null, error: { message: 'Down' } });
        await flush();

        expect([...names]).toEqual(['tolk-run']);

        ticker.handle.end();

        expect([...names]).toEqual([]);
    });

    it('asks nothing for a run it does not drive, or without a name: the push follows its run on after a visit', () => {
        for (const run of [state({ status: 'pending' }), state({ driver: 'queue' }), state({ driver: 'cli' }), state({ status: 'completed' })]) {
            const { follow } = follower({ dirty: 'tolk-run' });

            follow.follow(run);

            expect(follow.driving).toBe(false);
        }

        const { follow } = follower();
        follow.follow(state());

        expect(follow.driving).toBe(true);
        expect([...names]).toEqual([]);
    });
});
