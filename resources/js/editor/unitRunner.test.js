import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api.js';
import { BUSY_MS, classify, MAX_WAITS, runPlan, wait } from './unitRunner.js';

afterEach(() => {
    vi.useRealTimers();
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const error = (status, code = null, data = null, message = 'Failed.') => new ApiError({ status, code, message, data });

const payload = (handle, extra = {}) => ({ value: `[de] ${handle}`, meta: null, incomplete: false, warnings: [], ...extra });

const response = (fields = {}, extra = {}) => ({ fields, spawned: [], failed: [], warnings: [], usage: [], remaining: 0, ...extra });

const unit = (id, fields = [id]) => ({ id, fields, chars: 10 });

// An `editor/unit` endpoint whose answers the test decides. Each request waits until the test answers it, unless the
// unit has a scripted list of answers (a response, an ApiError to throw, or a function returning either).
function fakeApi(script = {}) {
    const pending = [];
    const requests = [];
    let inFlight = 0;
    let maxInFlight = 0;

    const api = {
        post: vi.fn((path, data) => {
            requests.push(data.unit);
            inFlight++;
            maxInFlight = Math.max(maxInFlight, inFlight);

            const scripted = script[data.unit]?.shift();
            const settle = (promise) => promise.finally(() => inFlight--);

            if (scripted !== undefined) {
                const answer = typeof scripted === 'function' ? scripted(data) : scripted;

                return settle(answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer));
            }

            return settle(
                new Promise((resolve, reject) => {
                    pending.push({ unit: data.unit, plan: data.plan, resolve, reject });
                }),
            );
        }),
    };

    return {
        api,
        requests,
        pending,
        maxInFlight: () => maxInFlight,
        inFlight: () => inFlight,
        async answer(unitId, value) {
            const index = pending.findIndex((request) => request.unit === unitId);

            if (index === -1) {
                throw new Error(`No pending request for unit ${unitId}.`);
            }

            const [request] = pending.splice(index, 1);
            value instanceof Error ? request.reject(value) : request.resolve(value);
            await flush();
        },
    };
}

const plan = (units, extra = {}) => ({ plan: 'p1', units, fields: units.flatMap((u) => u.fields), skipped: [], ready: [], ...extra });

describe('runPlan', () => {
    it('runs no more units at once than the concurrency allows', async () => {
        const server = fakeApi();
        const done = runPlan({ api: server.api, plan: plan(['a', 'b', 'c', 'd', 'e'].map((id) => unit(id))), concurrency: 2 });

        await flush();
        expect(server.requests).toEqual(['a', 'b']);

        await server.answer('a', response({ a: payload('a') }));
        expect(server.requests).toEqual(['a', 'b', 'c']);

        await server.answer('b', response());
        await server.answer('c', response());
        await server.answer('d', response());
        await server.answer('e', response());

        const summary = await done;

        expect(server.maxInFlight()).toBe(2);
        expect(server.requests).toEqual(['a', 'b', 'c', 'd', 'e']);
        expect(summary.units).toEqual({ total: 5, done: 5 });
    });

    it('sends the plan id with every unit', async () => {
        const server = fakeApi({ a: [response()] });

        await runPlan({ api: server.api, plan: plan([unit('a')]) });

        expect(server.api.post).toHaveBeenCalledWith('editor/unit', { plan: 'p1', unit: 'a' });
    });

    it.each([0, -3, undefined, 'x'])('runs one unit at a time for a concurrency of %s', async (concurrency) => {
        const server = fakeApi();
        const done = runPlan({ api: server.api, plan: plan([unit('a'), unit('b')]), concurrency });

        await flush();
        expect(server.requests).toEqual(['a']);
        await server.answer('a', response());
        await server.answer('b', response());
        await done;
    });

    it('hands each response to onFields at once, while other units still run', async () => {
        const server = fakeApi();
        const onFields = vi.fn();
        const done = runPlan({ api: server.api, plan: plan([unit('title'), unit('intro')]), concurrency: 2, onFields });

        await flush();
        await server.answer('title', response({ title: payload('title') }));

        expect(onFields).toHaveBeenCalledWith({ title: payload('title') });
        expect(server.pending.map((request) => request.unit)).toEqual(['intro']);

        await server.answer('intro', response({ intro: payload('intro') }));
        await done;

        expect(onFields).toHaveBeenCalledTimes(2);
    });

    it('delivers the plan\'s ready fields first', async () => {
        const server = fakeApi({ a: [response({ a: payload('a') })] });
        const calls = [];

        await runPlan({
            api: server.api,
            plan: plan([unit('a')], { ready: { gone: payload('gone', { value: null }) } }),
            onFields: (fields) => calls.push(Object.keys(fields)),
        });

        expect(calls).toEqual([['gone'], ['a']]);
    });

    it('calls onFields for no empty map', async () => {
        const server = fakeApi({ a: [response([])] });
        const onFields = vi.fn();

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')], { ready: [] }), onFields });

        expect(onFields).not.toHaveBeenCalled();
        expect(summary.delivered).toEqual([]);
    });

    it('runs spawned units and counts them in the progress', async () => {
        const server = fakeApi({
            a: [response({ title: payload('title') }, { spawned: [unit('r1', ['intro']), unit('r2', ['intro'])] })],
            r1: [response()],
            r2: [response({ intro: payload('intro') })],
        });
        const progress = [];

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title', 'intro'])]), onProgress: (p) => progress.push(p) });

        expect(server.requests).toEqual(['a', 'r1', 'r2']);
        expect(summary.delivered).toEqual(['title', 'intro']);
        expect(progress[0]).toEqual({ total: 1, done: 0, waiting: 0 });
        expect(progress.at(-1)).toEqual({ total: 3, done: 3, waiting: 0 });
    });

    it('waits for a spawned unit when the queue was empty before the response', async () => {
        const server = fakeApi();
        const done = runPlan({ api: server.api, plan: plan([unit('a'), unit('b')]), concurrency: 4 });

        await flush();
        await server.answer('a', response({}, { spawned: [unit('r1', ['a'])] }));
        expect(server.requests).toEqual(['a', 'b', 'r1']);

        await server.answer('r1', response({ a: payload('a') }));
        await server.answer('b', response({ b: payload('b') }));

        expect((await done).delivered).toEqual(['a', 'b']);
    });

    it.each([
        [429, 'provider_paused'],
        [503, 'provider_unavailable'],
    ])('waits retry_after after a %s and runs the same unit again', async (status, code) => {
        const server = fakeApi({ a: [error(status, code, { retry_after: 7 }), response({ a: payload('a') })] });
        const sleep = vi.fn(() => Promise.resolve());
        const progress = [];

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')]), sleep, onProgress: (p) => progress.push(p) });

        expect(sleep).toHaveBeenCalledTimes(1);
        expect(sleep.mock.calls[0][0]).toBe(7_000);
        expect(server.requests).toEqual(['a', 'a']);
        expect(summary.delivered).toEqual(['a']);
        expect(summary.failedUnits).toEqual([]);
        expect(progress.some((p) => p.waiting === 1)).toBe(true);
    });

    it('waits five seconds when a 429 names no time', async () => {
        const server = fakeApi({ a: [error(429, 'provider_paused'), response()] });
        const sleep = vi.fn(() => Promise.resolve());

        await runPlan({ api: server.api, plan: plan([unit('a')]), sleep });

        expect(sleep.mock.calls[0][0]).toBe(5_000);
    });

    it('gives up on a unit after five waits and goes on with the others', async () => {
        const paused = () => error(429, 'provider_paused', { retry_after: 1 }, 'Paused.');
        const server = fakeApi({
            a: Array.from({ length: MAX_WAITS + 1 }, paused),
            b: [response({ intro: payload('intro') })],
        });
        const sleep = vi.fn(() => Promise.resolve());

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title']), unit('b', ['intro'])]), sleep });

        expect(sleep).toHaveBeenCalledTimes(MAX_WAITS);
        expect(server.requests.filter((id) => id === 'a')).toHaveLength(MAX_WAITS + 1);
        expect(summary.failedUnits).toEqual([{ id: 'a', fields: ['title'], error: { status: 429, code: 'provider_paused', message: 'Paused.' } }]);
        expect(summary.failed).toEqual({ title: 'provider_paused' });
        expect(summary.delivered).toEqual(['intro']);
        expect(summary.stopped).toBeNull();
    });

    it('waits two seconds when the unit is busy and runs it again', async () => {
        const server = fakeApi({ a: [error(409, 'busy'), error(409, 'busy'), response({ a: payload('a') })] });
        const sleep = vi.fn(() => Promise.resolve());

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')]), sleep });

        expect(sleep.mock.calls.map(([ms]) => ms)).toEqual([BUSY_MS, BUSY_MS]);
        expect(summary.delivered).toEqual(['a']);
    });

    it('gives up on a unit that stays busy', async () => {
        const server = fakeApi({ a: Array.from({ length: 4 }, () => error(409, 'busy')) });
        const sleep = vi.fn(() => Promise.resolve());

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')]), sleep, maxBusyWaits: 3 });

        expect(sleep).toHaveBeenCalledTimes(3);
        expect(summary.failed).toEqual({ a: 'busy' });
    });

    it.each([
        [409, 'source_changed'],
        [410, 'plan_expired'],
        [410, 'target_missing'],
        [502, 'provider_rejected'],
    ])('stops everything after a %s %s and reports it', async (status, code) => {
        const server = fakeApi();
        const done = runPlan({ api: server.api, plan: plan(['a', 'b', 'c', 'd'].map((id) => unit(id))), concurrency: 2 });

        await flush();
        await server.answer('a', error(status, code, null, 'The provider said no.'));

        // b was already running: it finishes and its fields are still delivered; c and d never start.
        expect(server.requests).toEqual(['a', 'b']);
        await server.answer('b', response({ b: payload('b') }));

        const summary = await done;

        expect(server.requests).toEqual(['a', 'b']);
        expect(summary.stopped).toEqual({ status, code, message: 'The provider said no.' });
        expect(summary.delivered).toEqual(['b']);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['c', 'd']);
        expect(summary.failed).toEqual({});
    });

    it('keeps the first reason when two units stop everything', async () => {
        const server = fakeApi();
        const done = runPlan({ api: server.api, plan: plan([unit('a'), unit('b')]), concurrency: 2 });

        await flush();
        await server.answer('a', error(409, 'source_changed'));
        await server.answer('b', error(410, 'plan_expired'));

        expect((await done).stopped.code).toBe('source_changed');
    });

    it('does not run a waiting unit again once everything stopped', async () => {
        let release;
        const server = fakeApi({ a: [error(503, 'provider_unavailable', { retry_after: 3 })] });
        const sleep = vi.fn(() => new Promise((resolve) => (release = resolve)));
        const done = runPlan({ api: server.api, plan: plan([unit('a'), unit('b')]), concurrency: 2, sleep });

        await flush();
        await server.answer('b', error(502, 'provider_rejected'));
        release();

        const summary = await done;

        expect(server.requests).toEqual(['a', 'b']);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['a']);
        expect(summary.failedUnits).toEqual([]);
    });

    it.each([
        ['an unknown unit', error(404, 'unknown_unit')],
        ['a forbidden unit', error(403)],
        ['a server error', error(500)],
        ['no response', error(0)],
        ['another conflict', error(409, 'something_else')],
    ])('marks %s failed and goes on', async (_, failure) => {
        const server = fakeApi({ a: [failure], b: [response({ intro: payload('intro') })] });

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title', 'seo_title']), unit('b', ['intro'])]) });

        expect(server.requests).toEqual(['a', 'b']);
        expect(summary.failedUnits).toEqual([{ id: 'a', fields: ['title', 'seo_title'], error: { status: failure.status, code: failure.code, message: 'Failed.' } }]);
        expect(summary.failed).toEqual({ title: failure.code ?? 'failed', seo_title: failure.code ?? 'failed' });
        expect(summary.delivered).toEqual(['intro']);
        expect(summary.stopped).toBeNull();
    });

    it('does not count a field as failed when another unit delivered it', async () => {
        const server = fakeApi({ a: [error(500)], b: [response({ title: payload('title') })] });

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title']), unit('b', ['title'])]) });

        expect(summary.failed).toEqual({});
        expect(summary.delivered).toEqual(['title']);
    });

    it('does not count a field as failed once a repair unit delivered it', async () => {
        const server = fakeApi({
            a: [response({}, { failed: { title: 'invalid_output' }, spawned: [unit('r1', ['title'])] })],
            r1: [response({ title: payload('title') })],
        });

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title'])]) });

        expect(summary.failed).toEqual({});
        expect(summary.delivered).toEqual(['title']);
    });

    it('stops starting units on abort and still applies the units in flight', async () => {
        const server = fakeApi();
        const controller = new AbortController();
        const onFields = vi.fn();
        const done = runPlan({ api: server.api, plan: plan(['a', 'b', 'c'].map((id) => unit(id))), concurrency: 2, signal: controller.signal, onFields });

        await flush();
        controller.abort();
        await server.answer('a', response({ a: payload('a') }));
        await server.answer('b', response({ b: payload('b') }));

        const summary = await done;

        expect(server.requests).toEqual(['a', 'b']);
        expect(onFields).toHaveBeenCalledTimes(2);
        expect(summary.aborted).toBe(true);
        expect(summary.delivered).toEqual(['a', 'b']);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['c']);
    });

    it('starts nothing when aborted before it runs', async () => {
        const server = fakeApi();
        const controller = new AbortController();
        controller.abort();

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')]), signal: controller.signal });

        expect(server.requests).toEqual([]);
        expect(summary.aborted).toBe(true);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['a']);
    });

    it('does not repeat a unit that was waiting when the abort came', async () => {
        vi.useFakeTimers();
        const server = fakeApi({ a: [error(429, 'provider_paused', { retry_after: 60 })] });
        const controller = new AbortController();
        const done = runPlan({ api: server.api, plan: plan([unit('a')]), signal: controller.signal });

        await vi.advanceTimersByTimeAsync(1_000);
        controller.abort();
        await vi.advanceTimersByTimeAsync(0);

        const summary = await done;

        expect(server.requests).toEqual(['a']);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['a']);
        expect(summary.failedUnits).toEqual([]);
    });

    it('still runs what the units in flight spawn after an abort, so their fields are filled, and starts no other unit', async () => {
        const server = fakeApi({
            'b1~s2': [response({}, { spawned: [unit('b1~s2.v', ['title'])] })],
            'b1.v': [response({ intro: payload('intro') })],
            'b1~s2.v': [response({ title: payload('title') })],
        });
        const controller = new AbortController();
        const onFields = vi.fn();
        const done = runPlan({ api: server.api, plan: plan([unit('b1', ['title', 'intro']), unit('b2', ['body'])]), signal: controller.signal, onFields });

        await flush();
        // With review on, a translation unit delivers nothing itself: its review, and here a repair, hold its fields.
        controller.abort();
        await server.answer('b1', response({}, { spawned: [unit('b1~s2', ['title']), unit('b1.v', ['intro'])] }));

        const summary = await done;

        expect(server.requests).toEqual(['b1', 'b1~s2', 'b1.v', 'b1~s2.v']);
        expect(onFields).toHaveBeenCalledTimes(2);
        expect(summary.delivered).toEqual(['intro', 'title']);
        expect(summary.aborted).toBe(true);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['b2']);
    });

    it('also runs a spawned unit that was still waiting for a lane when the abort came', async () => {
        const server = fakeApi({ 'a.v': [response({ a: payload('a') })] });
        const controller = new AbortController();
        const done = runPlan({ api: server.api, plan: plan([unit('a'), unit('b'), unit('c')]), signal: controller.signal });

        await flush();
        await server.answer('a', response({}, { spawned: [unit('a.v', ['a'])] }));
        controller.abort();
        await server.answer('b', response({ b: payload('b') }));

        const summary = await done;

        expect(server.requests).toEqual(['a', 'b', 'a.v']);
        expect(summary.delivered).toEqual(['b', 'a']);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['c']);
    });

    it('runs nothing more after an abort it is not to finish, such as a switch', async () => {
        const server = fakeApi();
        const controller = new AbortController();
        const done = runPlan({ api: server.api, plan: plan([unit('b1', ['title']), unit('b2', ['body'])]), signal: controller.signal, finishing: () => false });

        await flush();
        controller.abort();
        await server.answer('b1', response({}, { spawned: [unit('b1.v', ['title'])] }));

        const summary = await done;

        expect(server.requests).toEqual(['b1']);
        expect(summary.delivered).toEqual([]);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['b2', 'b1.v']);
    });

    it('runs no spawned unit once everything stopped', async () => {
        const server = fakeApi();
        const done = runPlan({ api: server.api, plan: plan([unit('a'), unit('b')]), concurrency: 2 });

        await flush();
        await server.answer('a', error(409, 'source_changed'));
        await server.answer('b', response({}, { spawned: [unit('b.v', ['b'])] }));

        const summary = await done;

        expect(server.requests).toEqual(['a', 'b']);
        expect(summary.cancelledUnits.map((u) => u.id)).toEqual(['b.v']);
    });

    it('resolves only once every fill has resolved, and counts what was filled', async () => {
        const server = fakeApi({
            a: [response({ title: payload('title'), intro: payload('intro', { incomplete: true }) })],
            b: [response({ seo_title: payload('seo_title', { incomplete: true }) })],
        });
        let finishFill;
        const onFields = vi.fn((fields) =>
            'title' in fields
                ? new Promise((resolve) => (finishFill = () => resolve({ stale: false, applied: ['intro'], skipped: { title: 'edited' }, repeated: [] })))
                : { stale: false, applied: [], skipped: {}, repeated: ['seo_title'] },
        );
        let resolved = false;
        const done = runPlan({ api: server.api, plan: plan([unit('a', ['title', 'intro']), unit('b', ['seo_title'])]), onFields }).then((summary) => {
            resolved = true;

            return summary;
        });

        await flush();
        await flush();
        expect(resolved).toBe(false);

        finishFill();
        const summary = await done;

        expect(summary.delivered).toEqual(['intro']);
        expect(summary.skipped).toEqual({ title: 'edited' });
        expect(summary.incomplete).toEqual(['intro']);
    });

    it('reports a switch the fills saw as stale', async () => {
        const server = fakeApi({ a: [response({ title: payload('title') })] });
        const onFields = () => ({ stale: true, applied: [], skipped: { title: 'stale' }, repeated: [] });

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')]), onFields });

        expect(summary.stale).toBe(true);
        expect(summary.delivered).toEqual([]);
    });

    it('counts the fields of a fill that threw as not filled', async () => {
        const server = fakeApi({ a: [response({ title: payload('title') })] });

        const summary = await runPlan({ api: server.api, plan: plan([unit('a')]), onFields: () => Promise.reject(new Error('boom')) });

        expect(summary.failed).toEqual({ title: 'not_filled' });
        expect(summary.delivered).toEqual([]);
    });

    it('sums the usage per model and collects failures and warnings', async () => {
        const server = fakeApi({
            a: [
                response(
                    { title: payload('title') },
                    {
                        warnings: ['Part of Title was left in English.'],
                        usage: { 'anthropic:claude-sonnet-5-5': { input: 100, output: 40, cache_read: 0, cache_write: 0, reasoning: 0, calls: 1 } },
                    },
                ),
            ],
            b: [
                response(
                    {},
                    {
                        failed: { intro: 'refused' },
                        warnings: ['Part of Title was left in English.', 'Intro could not be translated.'],
                        usage: {
                            'anthropic:claude-sonnet-5-5': { input: 50, output: 10, cache_read: 20, cache_write: 0, reasoning: 0, calls: 1 },
                            'openai:gpt-6-luna': { input: 5, output: 5, cache_read: 0, cache_write: 0, reasoning: 0, calls: 1 },
                        },
                    },
                ),
            ],
        });

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title']), unit('b', ['intro'])]) });

        expect(summary.usage).toEqual({
            'anthropic:claude-sonnet-5-5': { input: 150, output: 50, cache_read: 20, cache_write: 0, reasoning: 0, calls: 2 },
            'openai:gpt-6-luna': { input: 5, output: 5, cache_read: 0, cache_write: 0, reasoning: 0, calls: 1 },
        });
        expect(summary.models).toEqual(['anthropic:claude-sonnet-5-5', 'openai:gpt-6-luna']);
        expect(summary.failed).toEqual({ intro: 'refused' });
        expect(summary.warnings).toEqual(['Part of Title was left in English.', 'Intro could not be translated.']);
    });

    it('collects the corrections of the filled fields with their handles', async () => {
        const correction = (id) => ({ id, label: 'Title', before: '[de] a', after: '[de] b', reason: 'Better.' });
        const server = fakeApi({
            a: [response({ title: payload('title', { corrections: [correction('s1'), correction('s2')] }), intro: payload('intro', { corrections: [] }) })],
            b: [response({ body: payload('body', { corrections: [correction('s5')] }) })],
        });
        const onFields = vi.fn(async (fields) => ({ stale: false, applied: Object.keys(fields).filter((handle) => handle !== 'body'), skipped: { body: 'edited' }, repeated: [] }));

        const summary = await runPlan({ api: server.api, plan: plan([unit('a', ['title', 'intro']), unit('b', ['body'])]), onFields });

        expect(summary.corrections).toEqual([
            { handle: 'title', ...correction('s1') },
            { handle: 'title', ...correction('s2') },
        ]);
    });

    it('lists the fields a review left unchanged, the review units that reviewed and those that were skipped', async () => {
        const server = fakeApi({
            'b1.v': [response({ title: payload('title') }, { unchanged: ['intro'], review_skipped: false, reviewed: true })],
            'b2.v': [response({}, { unchanged: ['body', 'title'], review_skipped: true, reviewed: false })],
        });

        const summary = await runPlan({ api: server.api, plan: plan([unit('b1.v', ['title', 'intro']), unit('b2.v', ['body'])]) });

        expect(summary.unchanged).toEqual(['intro', 'body']);
        expect(summary.reviewedUnits).toEqual(['b1.v']);
        expect(summary.reviewSkipped).toEqual(['b2.v']);
        expect(summary.corrections).toEqual([]);
    });

    it('resolves at once for a plan without units', async () => {
        const server = fakeApi();
        const onProgress = vi.fn();

        const summary = await runPlan({ api: server.api, plan: plan([]), onProgress });

        expect(server.requests).toEqual([]);
        expect(onProgress).toHaveBeenLastCalledWith({ total: 0, done: 0, waiting: 0 });
        expect(summary).toMatchObject({
            delivered: [],
            corrections: [],
            unchanged: [],
            reviewedUnits: [],
            reviewSkipped: [],
            stopped: null,
            aborted: false,
            units: { total: 0, done: 0 },
        });
    });
});

describe('classify', () => {
    it.each([
        [error(429), 'wait'],
        [error(503), 'wait'],
        [error(409, 'busy'), 'busy'],
        [error(409, 'source_changed'), 'stop'],
        [error(410, 'plan_expired'), 'stop'],
        [error(410, 'target_missing'), 'stop'],
        [error(410), 'stop'],
        [error(502, 'provider_rejected'), 'stop'],
        [error(404, 'unknown_unit'), 'fail'],
        [error(403), 'fail'],
        [error(500), 'fail'],
        [error(0), 'fail'],
        [new Error('plain'), 'fail'],
    ])('classifies %o', (failure, action) => {
        expect(classify(failure)).toBe(action);
    });
});

describe('wait', () => {
    it('resolves after the time', async () => {
        vi.useFakeTimers();
        let done = false;
        wait(1_000).then(() => (done = true));

        await vi.advanceTimersByTimeAsync(999);
        expect(done).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        expect(done).toBe(true);
    });

    it('resolves at once when the signal aborts', async () => {
        vi.useFakeTimers();
        const controller = new AbortController();
        let done = false;
        wait(60_000, controller.signal).then(() => (done = true));

        controller.abort();
        await vi.advanceTimersByTimeAsync(0);

        expect(done).toBe(true);
    });

    it('does not wait when the signal has already aborted', async () => {
        const controller = new AbortController();
        controller.abort();

        await expect(wait(60_000, controller.signal)).resolves.toBeUndefined();
    });
});
