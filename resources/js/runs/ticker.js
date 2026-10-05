import * as defaultApi from '../api.js';
import { asMap } from '../api.js';
import { describeError, wait } from '../editor/unitRunner.js';

// Run statuses after which nothing drives the run any more (§A12).
export const FINAL_STATUSES = ['completed', 'completed_with_errors', 'cancelled', 'aborted'];

// Failed requests in a row a loop repeats, after 1, 2, 4, 8 and 16 seconds, before it stops and reports the error.
export const MAX_RETRIES = 5;
export const BACKOFF_MS = 1_000;
export const MAX_BACKOFF_MS = 30_000;

// A report's `wait` is null while work remains that nothing can claim now: another lane holds the last items, or they
// wait for their parent page to be saved.
export const IDLE_MS = 1_000;

export const POLL_MS = 2_000;

let pageNonce = null;

// The page's part of the browser worker ids (`browser-{user}-{nonce}-{lane}`), made once per page load: a lane gets its
// own claimed item back after a visit inside the CP, and two tabs never share a worker. Letters and digits only, from
// getRandomValues(), because randomUUID() does not exist on a CP served over plain HTTP.
export function nonce() {
    pageNonce ??= Array.from(crypto.getRandomValues(new Uint8Array(12)), (byte) => byte.toString(16).padStart(2, '0'))
        .join('');

    return pageNonce;
}

export function isFinal(state) {
    return FINAL_STATUSES.includes(state?.run?.status);
}

// Lanes and the apply loop answer in parallel, so a state can arrive after a newer one: a tick that read the run before
// the apply that completed it. Wrap the page's callback once and give the same function to every loop; it drops a state
// older than the last one it passed on, by `run.progressed_at`, and a running state of the same second as a final one.
export function latest(onState) {
    let last = null;

    return (state) => {
        if (last !== null && isOlder(state, last)) {
            return;
        }

        last = state;
        onState(state);
    };
}

function isOlder(state, than) {
    const at = progressedAt(state);
    const lastAt = progressedAt(than);

    return at < lastAt || (at === lastAt && isFinal(than) && !isFinal(state));
}

function progressedAt(state) {
    return Date.parse(state?.run?.progressed_at ?? '') || 0;
}

// Whether repeating a failed request can help: no answer, a timeout, throttling or a server error. A lost permission,
// a deleted run, an invalid request or an expired session stays as it is.
export function retryable(error) {
    const status = error?.status ?? 0;

    return status === 0 || status === 408 || status === 429 || status >= 500;
}

export function backoff(failures, baseMs = BACKOFF_MS) {
    return Math.min(baseMs * 2 ** Math.max(0, failures - 1), MAX_BACKOFF_MS);
}

// A report's `wait` in milliseconds: its seconds, or IDLE_MS when it is null.
function delay(seconds, idleMs) {
    return seconds === null || seconds === undefined ? idleMs : Math.max(0, Number(seconds) || 0) * 1000;
}

// Sends a request, repeating it after a retryable failure at most `retries` times. Resolves with `{ response }`, with
// `{ error }` once it gives up, or with nothing when the signal aborted meanwhile.
async function send(request, { retries, backoffMs, sleep, signal }) {
    for (let failures = 1; ; failures++) {
        try {
            return { response: await request() };
        } catch (error) {
            if (signal?.aborted) {
                return {};
            }

            if (failures > retries || !retryable(error)) {
                return { error: describeError(error) };
            }

            await sleep(backoff(failures, backoffMs), signal);

            if (signal?.aborted) {
                return {};
            }
        }
    }
}

// Starts the browser driver's lanes (§A12): each posts `tick` until the run is final or nothing remains to translate,
// waiting the seconds each report asks for. A lane whose requests keep failing stops and goes to `onFailure`. `query`
// gives the item page the state answers carry (`page`, `per_page`, `status`). Answers go to `onState` and to the
// subscribers: the apply loop follows the lanes through the returned handle.
export function startLanes({
    api = defaultApi,
    urls,
    lanes = 1,
    nonce: laneNonce = nonce(),
    onState = () => {},
    onFailure = () => {},
    signal = null,
    query = () => ({}),
    sleep = wait,
    retries = MAX_RETRIES,
    backoffMs = BACKOFF_MS,
    idleMs = IDLE_MS,
}) {
    const count = Math.max(1, Math.floor(Number(lanes)) || 1);
    const outcomes = new Map();
    const listeners = new Set();
    const idlers = [];
    const failed = [];

    const aborted = () => Boolean(signal?.aborted);
    const emit = (event) => listeners.forEach((listener) => listener(event));
    const active = () => [...outcomes.values()].filter((outcome) => outcome === 'running').length;

    function fail(lane, error) {
        const failure = { lane, error };

        failed.push(failure);
        onFailure(failure);

        return 'failed';
    }

    const tick = (lane) => api.post(urls.tick, { ...query(), nonce: laneNonce, lane });

    async function run(lane) {
        while (!aborted()) {
            const { response, error } = await send(() => tick(lane), { retries, backoffMs, sleep, signal });

            if (aborted()) {
                break;
            }

            if (error) {
                return fail(lane, error);
            }

            onState(response);
            emit({ lane, state: response });

            if (isFinal(response) || response?.report?.remains !== true) {
                return 'finished';
            }

            await sleep(delay(response.report.wait, idleMs), signal);
        }

        return 'aborted';
    }

    function end(lane, outcome) {
        outcomes.set(lane, outcome);
        emit({ lane, ended: outcome });

        if (active() === 0) {
            idlers.splice(0).forEach((resolve) => resolve());
        }
    }

    function launch(lane) {
        outcomes.set(lane, 'running');
        run(lane)
            .catch((error) => fail(lane, describeError(error)))
            .then((outcome) => end(lane, outcome));
    }

    for (let lane = 1; lane <= count; lane++) {
        launch(lane);
    }

    return {
        get active() {
            return active();
        },
        // `{ lane, error }` of each lane that stopped after its retries.
        failed,
        // Starts the lanes again that ended because nothing remained, not those that failed; returns how many.
        restart() {
            if (aborted()) {
                return 0;
            }

            const finished = [...outcomes].filter(([, outcome]) => outcome === 'finished').map(([lane]) => lane);
            finished.forEach(launch);

            return finished.length;
        },
        // Resolves once no lane runs.
        idle() {
            return active() === 0 ? Promise.resolve() : new Promise((resolve) => idlers.push(resolve));
        },
        // The listener gets `{ lane, state }` after each answer and `{ lane, ended }` when a lane ends.
        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
    };
}

// The one loop that posts `apply` while translated items remain or the lanes still work, so one request at a time
// saves content. Items an apply sent back to `pending` need the lanes again: it restarts the lanes that ended. Resolves
// with the last state and the error it stopped for, if any.
export async function startApplyLoop({
    api = defaultApi,
    urls,
    lanes = null,
    onState = () => {},
    onFailure = () => {},
    signal = null,
    query = () => ({}),
    sleep = wait,
    retries = MAX_RETRIES,
    backoffMs = BACKOFF_MS,
    idleMs = IDLE_MS,
}) {
    const aborted = () => Boolean(signal?.aborted);
    let state = null;
    // A lane reported translated items, or ended, since the last request was sent.
    let ready = false;
    let wake = null;

    const unsubscribe =
        lanes?.subscribe((event) => {
            if (event.ended || Number(asMap(event.state?.counts).translated) > 0) {
                ready = true;
                wake?.();
            }
        }) ?? (() => {});

    const nextLaneNews = () =>
        new Promise((resolve) => {
            const done = () => {
                wake = null;
                signal?.removeEventListener('abort', done);
                resolve();
            };

            wake = done;
            signal?.addEventListener('abort', done, { once: true });
        });

    try {
        while (!aborted()) {
            ready = false;

            const { response, error } = await send(() => api.post(urls.apply, query()), { retries, backoffMs, sleep, signal });

            if (aborted()) {
                break;
            }

            if (error) {
                onFailure({ error });

                return { state, error };
            }

            state = response;
            onState(response);

            if (isFinal(response)) {
                break;
            }

            if (lanes && needsLanes(response)) {
                lanes.restart();
            }

            if (response?.report?.remains === true) {
                await sleep(delay(response.report.wait, idleMs), signal);
            } else if (!lanes?.active) {
                break;
            } else if (!ready) {
                await nextLaneNews();
            }
        }
    } finally {
        unsubscribe();
    }

    return { state, error: null };
}

// Items wait to be translated on a run this page drives: items an apply sent back (its `returned`), or put back by a
// retry, after the lanes ended.
function needsLanes(state) {
    const run = state?.run;

    return run?.status === 'running' && run?.driver === 'browser' && Number(asMap(state.counts).pending) > 0;
}

// Follows a run that something else drives, a queue run, by fetching its state every `interval` milliseconds until it
// is final. Resolves with the last state and the error it stopped for, if any.
export async function pollState({
    api = defaultApi,
    url,
    interval = POLL_MS,
    onState = () => {},
    onFailure = () => {},
    signal = null,
    query = () => ({}),
    sleep = wait,
    retries = MAX_RETRIES,
    backoffMs = BACKOFF_MS,
}) {
    let state = null;

    while (!signal?.aborted) {
        const { response, error } = await send(() => api.get(url, query()), { retries, backoffMs, sleep, signal });

        if (signal?.aborted) {
            break;
        }

        if (error) {
            onFailure({ error });

            return { state, error };
        }

        state = response;
        onState(response);

        if (isFinal(response)) {
            break;
        }

        await sleep(interval, signal);
    }

    return { state, error: null };
}

// Posts `estimate` until its `next` is null; the server adds each call's items to the run's estimate, so every answer
// holds the total so far. `estimate` is the run's stored `run.estimate`: the loop continues from its `next`, and does
// nothing when it is finished. Resolves with the last estimate and the error it stopped for, if any.
export async function estimateLoop({
    api = defaultApi,
    url,
    estimate = null,
    onEstimate = () => {},
    onFailure = () => {},
    signal = null,
    sleep = wait,
    retries = MAX_RETRIES,
    backoffMs = BACKOFF_MS,
}) {
    let current = estimate;
    let from = estimate === null ? 0 : estimate.next === null ? null : Math.max(0, Math.floor(Number(estimate.next)) || 0);

    while (from !== null && !signal?.aborted) {
        const { response, error } = await send(() => api.post(url, { from }), { retries, backoffMs, sleep, signal });

        if (signal?.aborted) {
            break;
        }

        if (error) {
            onFailure({ error });

            return { estimate: current, error };
        }

        current = response;
        onEstimate(response);

        // The server ignores a call from another index than where the estimate stopped and answers what it has, so the
        // loop always continues from the answer.
        from = Number.isInteger(response?.next) ? response.next : null;
    }

    return { estimate: current, error: null };
}
