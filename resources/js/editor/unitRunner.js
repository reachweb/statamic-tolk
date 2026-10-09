import * as defaultApi from '../api.js';
import { asMap } from '../api.js';

// How often a unit waits for a paused or unreachable provider (429, 503) before it counts as failed.
export const MAX_WAITS = 5;

// Another request holds the unit's lock (409 `busy`). The lock expires after the web timeout plus 10 seconds.
export const BUSY_MS = 2_000;
export const MAX_BUSY_WAITS = 60;

// Seconds to wait when a 429 or 503 arrives without `retry_after`.
const DEFAULT_RETRY_AFTER = 5;

// Resolves after `ms`, or at once when the signal aborts, so Cancel does not sit out a provider's pause.
export function wait(ms, signal) {
    return new Promise((resolve) => {
        if (signal?.aborted) {
            return resolve();
        }

        const done = () => {
            clearTimeout(timer);
            signal?.removeEventListener('abort', done);
            resolve();
        };
        const timer = setTimeout(done, ms);

        signal?.addEventListener('abort', done, { once: true });
    });
}

// What a failed `editor/unit` request leads to.
export function classify(error) {
    const status = error?.status ?? 0;

    if (status === 429 || status === 503) {
        return 'wait';
    }

    if (status === 409 && error.code === 'busy') {
        return 'busy';
    }

    if ((status === 409 && error.code === 'source_changed') || status === 410 || status === 502) {
        return 'stop';
    }

    return 'fail';
}

// The error the banner shows: the status, the server's code and its message.
export function describeError(error, fallback = '') {
    return { status: error?.status ?? 0, code: error?.code ?? null, message: error?.message || fallback };
}

const entries = (map) => Object.entries(asMap(map));

const brief = ({ id, fields }) => ({ id, fields });

// Runs the units of a plan from `editor/plan` in parallel (D24). The queue grows with each response's `spawned`, and
// each response's `fields` go to `onFields` at once, so the form fills while other units still run. `onFields` may
// return (a promise of) the result of `applyFields()`; the summary then counts what was really filled.
export async function runPlan({
    api = defaultApi,
    plan,
    concurrency = 1,
    onFields = () => {},
    onProgress = () => {},
    signal = null,
    sleep = wait,
    maxWaits = MAX_WAITS,
    busyMs = BUSY_MS,
    maxBusyWaits = MAX_BUSY_WAITS,
}) {
    const limit = Math.max(1, Math.floor(Number(concurrency)) || 1);
    const queue = [...(plan.units ?? [])];
    const progress = { total: queue.length, done: 0, waiting: 0 };
    const received = new Map();
    const fills = [];
    const failedUnits = [];
    const cancelledUnits = [];
    const failed = {};
    const warnings = new Set();
    const unchanged = new Set();
    const reviewed = new Set();
    const reviewSkipped = new Set();
    const usage = {};
    let stopped = null;

    const halted = () => stopped !== null || Boolean(signal?.aborted);
    const report = () => onProgress({ ...progress });

    function deliver(fields) {
        const handles = entries(fields).map(([handle, payload]) => {
            received.set(handle, payload);

            return handle;
        });

        if (!handles.length) {
            return;
        }

        fills.push(
            Promise.resolve()
                .then(() => onFields(fields))
                .then(
                    (result) => ({ handles, result }),
                    (error) => ({ handles, error }),
                ),
        );
    }

    function record(unit, response) {
        deliver(response?.fields);

        for (const [handle, reason] of entries(response?.failed)) {
            failed[handle] = reason;
        }

        (Array.isArray(response?.warnings) ? response.warnings : []).forEach((warning) => warnings.add(warning));
        (Array.isArray(response?.unchanged) ? response.unchanged : []).forEach((handle) => unchanged.add(handle));

        if (response?.reviewed === true) {
            reviewed.add(unit.id);
        }

        if (response?.review_skipped === true) {
            reviewSkipped.add(unit.id);
        }

        for (const [model, calls] of entries(response?.usage)) {
            usage[model] = addUsage(usage[model] ?? {}, calls);
        }

        const spawned = Array.isArray(response?.spawned) ? response.spawned : [];

        queue.push(...spawned);
        progress.total += spawned.length;
    }

    async function run(unit) {
        let waits = 0;
        let busy = 0;

        for (;;) {
            try {
                return record(unit, await api.post('editor/unit', { plan: plan.plan, unit: unit.id }));
            } catch (error) {
                const action = classify(error);
                const repeat =
                    (action === 'wait' && waits < maxWaits) || (action === 'busy' && busy < maxBusyWaits);

                if (action === 'stop') {
                    stopped ??= describeError(error);

                    return;
                }

                if (!repeat || halted()) {
                    // A unit that was waiting when everything stopped did not fail; it was not run again.
                    if (repeat) {
                        cancelledUnits.push(brief(unit));
                    } else {
                        failedUnits.push({ ...brief(unit), error: describeError(error) });
                    }

                    return;
                }

                if (action === 'wait') {
                    waits++;
                } else {
                    busy++;
                }

                const seconds = Number(error.data?.retry_after) > 0 ? Number(error.data.retry_after) : DEFAULT_RETRY_AFTER;

                progress.waiting++;
                report();
                await sleep(action === 'wait' ? seconds * 1000 : busyMs, signal);
                progress.waiting--;
                report();

                if (halted()) {
                    cancelledUnits.push(brief(unit));

                    return;
                }
            }
        }
    }

    deliver(plan.ready);
    report();

    await new Promise((resolve) => {
        let active = 0;

        const pump = () => {
            while (active < limit && queue.length && !halted()) {
                const unit = queue.shift();

                active++;
                run(unit).finally(() => {
                    active--;
                    progress.done++;
                    report();
                    pump();
                });
            }

            if (active === 0) {
                resolve();
            }
        };

        pump();
    });

    cancelledUnits.push(...queue.map(brief));

    return summarize(await Promise.all(fills), {
        received,
        failed,
        failedUnits,
        cancelledUnits,
        warnings: [...warnings],
        unchanged,
        reviewedUnits: [...reviewed],
        reviewSkipped: [...reviewSkipped],
        usage,
        stopped,
        aborted: Boolean(signal?.aborted),
        progress,
    });
}

function summarize(fills, { received, failed, failedUnits, cancelledUnits, warnings, unchanged, reviewedUnits, reviewSkipped, usage, stopped, aborted, progress }) {
    const delivered = [];
    const skipped = {};
    let stale = false;

    for (const { handles, result, error } of fills) {
        if (error) {
            handles.forEach((handle) => (failed[handle] ??= 'not_filled'));
            continue;
        }

        stale ||= result?.stale === true;

        for (const handle of result?.applied ?? (result ? [] : handles)) {
            if (!delivered.includes(handle)) {
                delivered.push(handle);
            }
        }

        Object.assign(skipped, result?.skipped ?? {});
    }

    // The fields of a unit that failed were never completed, so the server could not deliver them.
    for (const unit of failedUnits) {
        for (const handle of unit.fields ?? []) {
            if (!delivered.includes(handle) && !(handle in skipped)) {
                failed[handle] ??= unit.error?.code ?? 'failed';
            }
        }
    }

    delivered.forEach((handle) => delete failed[handle]);

    return {
        delivered,
        incomplete: delivered.filter((handle) => received.get(handle)?.incomplete === true),
        skipped,
        failed,
        failedUnits,
        cancelledUnits,
        warnings,
        // The reviewer's corrections of the fields that were filled, each with its field's handle.
        corrections: delivered.flatMap((handle) => {
            const corrections = received.get(handle)?.corrections;

            return Array.isArray(corrections) ? corrections.map((correction) => ({ handle, ...correction })) : [];
        }),
        // Reviewed without a correction, so the server did not deliver them.
        unchanged: [...unchanged].filter((handle) => !delivered.includes(handle)),
        // The review units that called the reviewer.
        reviewedUnits,
        reviewSkipped,
        usage,
        models: Object.keys(usage),
        stopped,
        aborted,
        stale,
        units: { total: progress.total, done: progress.done },
    };
}

function addUsage(total, calls) {
    const sum = { ...total };

    for (const [key, value] of entries(calls)) {
        if (typeof value === 'number') {
            sum[key] = (sum[key] ?? 0) + value;
        }
    }

    return sum;
}
