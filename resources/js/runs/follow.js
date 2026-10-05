import * as defaultApi from '../api.js';
import { describeError } from '../editor/unitRunner.js';
import * as defaultTicker from './ticker.js';

// Drives and follows one run for the run page (§A13): the estimate while it is pending, the browser driver's lanes and
// apply loop, or polling for a run something else drives, and the page's buttons. Each call of follow() aborts the
// loops it started before and starts those the state needs, with a new `latest()` guard: the aborted loops pass on
// nothing more, and an answer to Resume or Retry may carry the same `progressed_at` as the cancelled state before it.
export function createFollower({
    urls,
    lanes = 1,
    onState = () => {},
    onEstimate = () => {},
    onFailures = () => {},
    query = () => ({}),
    api = defaultApi,
    ticker = defaultTicker,
}) {
    let controller = null;
    let deliver = () => {};
    let laneHandle = null;
    let applying = null;
    let failures = [];

    function fail(failure) {
        failures = [...failures, failure];
        onFailures(failures);
    }

    function stop() {
        controller?.abort();
        controller = null;
        laneHandle = null;
        applying = null;
    }

    function follow(state) {
        stop();

        const own = new AbortController();
        controller = own;
        deliver = ticker.latest(onState);
        failures = [];
        onFailures(failures);
        deliver(state);

        const run = state?.run ?? {};
        const options = { onState: deliver, onFailure: fail, signal: own.signal, query };

        if (run.status === 'pending') {
            estimate(run.estimate ?? null, own.signal);
        } else if (run.status === 'running' && run.driver === 'browser') {
            laneHandle = ticker.startLanes({ api, urls, lanes, ...options });

            const settled = ticker
                .startApplyLoop({ api, urls, lanes: laneHandle, ...options })
                .catch((error) => fail({ error: describeError(error) }))
                .finally(() => {
                    if (applying === settled) {
                        applying = null;
                    }
                });

            applying = settled;
        } else if (run.status === 'running') {
            ticker.pollState({ api, url: urls.state, ...options }).catch((error) => fail({ error: describeError(error) }));
        }
    }

    // Estimating marks the items with nothing to do `skipped`; the state read afterwards shows them.
    async function estimate(stored, signal) {
        try {
            const { error } = await ticker.estimateLoop({ api, url: urls.estimate, estimate: stored, onEstimate, onFailure: fail, signal });

            if (!error && !signal.aborted) {
                await refresh();
            }
        } catch (error) {
            if (!signal.aborted) {
                fail({ error: describeError(error) });
            }
        }
    }

    // Reads the state again, for another page of items or a filter, or after the estimate. Nothing is passed on once
    // the loops it belongs to were stopped or replaced.
    async function refresh() {
        const signal = controller?.signal;
        const answer = await api.get(urls.state, query());

        if (signal && !signal.aborted) {
            deliver(answer);
        }

        return answer;
    }

    async function act(url, restart = true) {
        const answer = await api.post(url, query());

        if (restart) {
            follow(answer);
        } else {
            deliver(answer);
        }

        return answer;
    }

    return {
        follow,
        refresh,
        stop,
        start: () => act(urls.start),
        // The lanes and the apply loop end on their own once they see the cancelled run, with the counts of what they
        // finished meanwhile.
        cancel: () => act(urls.cancel, false),
        resume: () => act(urls.resume),
        retry: () => act(urls.retry),
        takeOver: () => act(urls.take_over),
        // Whether this page drives the run: a lane runs or the apply loop has not finished.
        get active() {
            return Boolean((laneHandle && laneHandle.active > 0) || applying);
        },
        get failures() {
            return failures;
        },
    };
}
