import { createPinia, defineStore, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api.js';
import { createPushes, describePush, missingSites, pushChoices, updateSites } from './push.js';
import { useEditorStore } from './store.js';

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

let store;
let toasts;

beforeEach(() => {
    toasts = [];
    vi.stubGlobal('__', translate);
    vi.stubGlobal('__n', (key, count, replacements = {}) => {
        const [one, other] = translate(key, { ...replacements, count }).split('|');

        return count === 1 ? one : (other ?? one);
    });
    vi.stubGlobal('Statamic', {
        $pinia: { defineStore },
        $toast: { error: (message) => toasts.push(message) },
    });
    setActivePinia(createPinia());
    store = useEditorStore();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const REFERENCE = 'entry::hello';

const runUrls = (id = '01RUN') =>
    Object.fromEntries(['state', 'tick', 'apply', 'cancel'].map((action) => [action, `https://cp.test/cp/tolk/runs/${id}/${action}`]));

const runState = ({ id = '01RUN', status = 'running', driver = 'browser', counts = { pending: 2 }, items = [], sites = ['el', 'de'] } = {}) => ({
    run: { id, status, driver, progressed_at: '2026-10-05T10:00:00Z', scope: { sites }, error: null },
    counts,
    items,
    stalled: false,
});

const pushAnswer = ({ id = '01RUN', driver = 'browser', state = runState({ id, driver }) } = {}) => ({
    run: id,
    driver,
    lanes: 2,
    urls: runUrls(id),
    state,
});

const localization = (site, name, exists, extra = {}) => ({
    site,
    name,
    exists,
    status: exists ? 'current' : 'missing',
    counts: {},
    edit_url: exists ? `https://cp.test/cp/collections/articles/entries/${site}` : null,
    ...extra,
});

const status = (localizations = [localization('el', 'Greek', false), localization('de', 'German', true)], extra = {}) => ({
    translatable: true,
    origin: { localizations },
    active_run: null,
    defaults: { review: false, new_status: 'draft' },
    ...extra,
});

// A follower that records how it was made and driven; `onState` and `onFailures` reach the push module.
function fakeFollowers() {
    const made = [];

    const create = vi.fn((options) => {
        const follower = {
            options,
            follow: vi.fn(),
            cancel: vi.fn(async () => {
                const answer = runState({ status: 'cancelled', counts: { done: 1, pending: 1 } });
                await flush();
                options.onState(answer);

                return answer;
            }),
            refresh: vi.fn(async () => runState()),
        };

        made.push(follower);

        return follower;
    });

    return { made, create };
}

function fakeApi({ post, get } = {}) {
    const requests = [];

    return {
        requests,
        post: vi.fn(async (url, data) => {
            requests.push({ method: 'post', url, data });

            return typeof post === 'function' ? post(url, data) : post;
        }),
        get: vi.fn(async (url, data) => {
            requests.push({ method: 'get', url, data });

            return typeof get === 'function' ? get(url, data) : get;
        }),
    };
}

function setup({ api = fakeApi({ post: pushAnswer(), get: status() }), followers = fakeFollowers() } = {}) {
    store.switched('base', REFERENCE);
    store.container('base').status = status();

    return { api, followers, pushes: createPushes({ store: () => store, api, createFollower: followers.create }) };
}

describe('choices', () => {
    const origin = {
        localizations: [
            { site: 'el', name: 'Greek', exists: false, summary: 'Not created yet.', translate: true, update: false },
            { site: 'de', name: 'German', exists: true, summary: '1 field outdated', translate: false, update: true },
            { site: 'fr', name: 'French', exists: true, summary: '2 fields untranslated', translate: true, update: true },
        ],
    };

    it('offers the missing and untranslated sites to Translate into…', () => {
        expect(pushChoices(origin)).toEqual([
            { site: 'el', name: 'Greek', missing: true, summary: 'Not created yet.' },
            { site: 'fr', name: 'French', missing: false, summary: '2 fields untranslated' },
        ]);
        expect(pushChoices(null)).toEqual([]);
    });

    it('updates the outdated sites', () => {
        expect(updateSites(origin)).toEqual(['de', 'fr']);
        expect(updateSites(undefined)).toEqual([]);
    });

    it('lists the sites without a localization', () => {
        expect(missingSites(status())).toEqual(['el']);
        expect(missingSites(null)).toEqual([]);
    });
});

describe('describePush', () => {
    it('describes nothing without a push', () => {
        expect(describePush(null, status())).toBeNull();
    });

    it('names the sites of a running push, its progress and the keep-open note of the browser driver', () => {
        const shown = describePush(
            { phase: 'running', driver: 'browser', sites: ['el', 'de'], state: runState({ counts: { done: 1, pending: 1 } }), failures: [] },
            status(),
        );

        expect(shown).toMatchObject({ sites: 'Greek, German', keepOpen: true, failure: null, result: null, reload: false });
        expect(shown.progress).toMatchObject({ total: 2, finished: 1 });
        expect(describePush({ phase: 'running', driver: 'queue', sites: ['el'], state: null }, status()).keepOpen).toBe(false);
    });

    it('takes the sites from the run before it knows them, and reports the last failure', () => {
        const shown = describePush(
            { phase: 'running', driver: 'browser', sites: null, state: runState({ sites: ['de', 'fr'] }), failures: [{ error: { message: 'One' } }, { lane: 1, error: { message: 'Two' } }] },
            status(),
        );

        expect(shown.sites).toBe('German, fr');
        expect(shown.failure).toBe('Two');
    });

    it('describes a finished push per site, with links and the reload for a new localization', () => {
        const items = [
            { index: 0, target_site: 'el', status: 'done', reason: null, error: null, fields: { title: 'applied' }, edit_url: null },
            { index: 1, target_site: 'de', status: 'failed', reason: null, error: 'The provider refused.', fields: {}, edit_url: null },
        ];
        const push = { phase: 'finished', driver: 'browser', sites: ['el', 'de'], missing: ['el'], state: runState({ status: 'completed_with_errors', counts: { done: 1, failed: 1 }, items }) };
        const after = status([localization('el', 'Greek', true), localization('de', 'German', true)]);
        const shown = describePush(push, after);

        expect(shown.result).toMatchObject({ status: 'completed_with_errors', text: 'The translations are done, with errors: 1 saved, 1 failed.' });
        expect(shown.result.items).toMatchObject([
            { handle: 'el', site: 'Greek', status: 'done', statusLabel: 'Done', editUrl: 'https://cp.test/cp/collections/articles/entries/el', error: null },
            { handle: 'de', site: 'German', status: 'failed', error: 'The provider refused.' },
        ]);
        expect(shown.reload).toBe(true);
        expect(describePush(push, status()).reload).toBe(false);
        expect(describePush({ ...push, missing: [] }, after).reload).toBe(false);
    });

    it('words every end of a push', () => {
        const text = (runStatus, counts) => describePush({ phase: 'finished', state: runState({ status: runStatus, counts }) }, status()).result.text;

        expect(text('completed', { done: 2 })).toBe('The translations are done: 2 saved.');
        expect(text('completed', { done: 1, skipped: 1 })).toBe('The translations are done: 1 saved, 1 skipped.');
        expect(text('cancelled', { done: 0, pending: 2 })).toBe('The translations were cancelled: 0 saved.');
        expect(text('aborted', { done: 1, failed: 1 })).toBe('The translations were stopped: 1 saved, 1 failed.');
    });

    it('reports the error of a push that did not start', () => {
        expect(describePush({ phase: 'error', error: { message: 'No model' } }, status()).error).toBe('No model');
    });
});

describe('start', () => {
    it('creates the run and follows it from the answer', async () => {
        const { api, followers, pushes } = setup();

        const answer = await pushes.start('base', { reference: REFERENCE, sites: ['el', 'de'], mode: 'missing', review: true, newStatus: 'mirror' });

        expect(answer.run).toBe('01RUN');
        expect(api.requests[0]).toEqual({
            method: 'post',
            url: 'editor/push',
            data: { reference: REFERENCE, sites: ['el', 'de'], mode: 'missing', overwrite_manual: false, review: true, new_status: 'mirror' },
        });
        expect(followers.create).toHaveBeenCalledOnce();
        expect(followers.made[0].options).toMatchObject({ urls: runUrls(), lanes: 2 });
        expect(followers.made[0].follow).toHaveBeenCalledWith(answer.state);
        expect(store.container('base').push).toMatchObject({ phase: 'running', run: '01RUN', driver: 'browser', sites: ['el', 'de'], missing: ['el'], failures: [] });
        expect(pushes.following('01RUN')).toBe(true);
    });

    it('sends the settings defaults as null', async () => {
        const { api, pushes } = setup();

        await pushes.start('base', { reference: REFERENCE, sites: ['de'], mode: 'update' });

        expect(api.requests[0].data).toEqual({ reference: REFERENCE, sites: ['de'], mode: 'update', overwrite_manual: false, review: null, new_status: null });
        expect(store.container('base').push.missing).toEqual([]);
    });

    it('shows that it is starting, and starts nothing more meanwhile or for another item', async () => {
        let answer;
        const { api, pushes } = setup({ api: fakeApi({ post: () => new Promise((resolve) => (answer = resolve)) }) });

        const first = pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });

        expect(store.container('base').push.phase).toBe('starting');
        expect(await pushes.start('base', { reference: REFERENCE, sites: ['de'], mode: 'missing' })).toBeNull();

        answer(pushAnswer());
        await first;

        expect(await pushes.start('base', { reference: REFERENCE, sites: ['de'], mode: 'missing' })).toBeNull();
        expect(await pushes.start('base', { reference: 'entry::other', sites: ['de'], mode: 'missing' })).toBeNull();
        expect(api.post).toHaveBeenCalledOnce();
    });

    it('starts nothing for an item the form does not show', async () => {
        const { api, pushes } = setup();

        expect(await pushes.start('base', { reference: 'entry::other', sites: ['de'], mode: 'missing' })).toBeNull();
        expect(api.post).not.toHaveBeenCalled();
        expect(store.container('base').push).toBeNull();
    });

    it('keeps driving the run when the form switched to another item meanwhile', async () => {
        let answer;
        const { followers, pushes } = setup({ api: fakeApi({ post: () => new Promise((resolve) => (answer = resolve)) }) });

        const started = pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });
        store.switched('base', 'entry::hello-el');
        answer(pushAnswer());
        await started;

        expect(store.container('base').push).toBeNull();
        expect(followers.made[0].follow).toHaveBeenCalledOnce();

        followers.made[0].options.onState(runState({ counts: { done: 1, pending: 1 } }));

        expect(store.container('base').push).toBeNull();
    });

    it('reports a refusal', async () => {
        const { pushes } = setup({
            api: fakeApi({
                post: () => {
                    throw new ApiError({ status: 422, code: 'invalid_sites', message: 'This entry cannot be translated into fr from here.' });
                },
            }),
        });

        await pushes.start('base', { reference: REFERENCE, sites: ['fr'], mode: 'missing' });

        expect(store.container('base').push).toMatchObject({ phase: 'error', error: { status: 422, code: 'invalid_sites' } });
        expect(describePush(store.container('base').push, status()).error).toBe('This entry cannot be translated into fr from here.');

        pushes.dismiss('base');

        expect(store.container('base').push).toBeNull();
    });

    it('follows the run another page started instead', async () => {
        const active = { run: '01OTHER', driver: 'queue', lanes: 2, urls: runUrls('01OTHER') };
        const { api, followers, pushes } = setup({
            api: fakeApi({
                post: () => {
                    throw new ApiError({ status: 409, code: 'run_active', message: 'Already', data: { active_run: active } });
                },
                get: runState({ id: '01OTHER', driver: 'queue' }),
            }),
        });

        await pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });

        expect(api.requests[1]).toEqual({ method: 'get', url: active.urls.state, data: undefined });
        expect(followers.made[0].options.urls).toEqual(active.urls);
        expect(store.container('base').push).toMatchObject({ phase: 'running', run: '01OTHER', driver: 'queue', missing: ['el'] });
    });

    it('leaves the run another page started alone when the form switched to another item meanwhile', async () => {
        const active = { run: '01OTHER', driver: 'queue', lanes: 2, urls: runUrls('01OTHER') };
        let refuse;
        const { api, followers, pushes } = setup({
            api: fakeApi({ post: () => new Promise((resolve, reject) => (refuse = reject)) }),
        });

        const started = pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });
        store.switched('base', 'entry::hello-el');
        refuse(new ApiError({ status: 409, code: 'run_active', message: 'Already', data: { active_run: active } }));
        await started;

        expect(api.get).not.toHaveBeenCalled();
        expect(followers.made).toEqual([]);
        expect(store.container('base').push).toBeNull();
    });
});

describe('following', () => {
    it('passes states on, and once the run is final refreshes the status', async () => {
        const after = status([localization('el', 'Greek', true), localization('de', 'German', true)]);
        const { api, followers, pushes } = setup({ api: fakeApi({ post: pushAnswer(), get: after }) });
        await pushes.start('base', { reference: REFERENCE, sites: ['el', 'de'], mode: 'missing' });
        const { onState } = followers.made[0].options;

        onState(runState({ counts: { done: 1, pending: 1 } }));

        expect(store.container('base').push.state.counts).toEqual({ done: 1, pending: 1 });
        expect(api.get).not.toHaveBeenCalled();

        onState(runState({ status: 'completed', counts: { done: 2 } }));
        await flush();

        expect(store.container('base').push.phase).toBe('finished');
        expect(api.requests.at(-1)).toEqual({ method: 'get', url: 'editor/status', data: { reference: REFERENCE } });
        expect(store.container('base').status).toEqual(after);
        expect(describePush(store.container('base').push, store.container('base').status).reload).toBe(true);
        expect(pushes.following('01RUN')).toBe(false);

        // A late state of the finished run refreshes nothing more.
        onState(runState({ status: 'completed', counts: { done: 2 } }));
        await flush();

        expect(api.get).toHaveBeenCalledOnce();
    });

    it('reports a failed status refresh, except a 403', async () => {
        const get = vi.fn(async () => {
            throw new ApiError({ status: 500, message: 'Boom' });
        });
        const { followers, pushes } = setup({ api: { post: vi.fn(async () => pushAnswer()), get } });
        await pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });

        followers.made[0].options.onState(runState({ status: 'completed', counts: { done: 1 } }));
        await flush();

        expect(toasts).toEqual(['The translation status could not be updated: Boom']);
    });

    it('stays silent when the user may no longer see the status', async () => {
        const get = vi.fn(async () => {
            throw new ApiError({ status: 403, message: 'Forbidden' });
        });
        const { followers, pushes } = setup({ api: { post: vi.fn(async () => pushAnswer()), get } });
        await pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });

        followers.made[0].options.onState(runState({ status: 'completed', counts: { done: 1 } }));
        await flush();

        expect(get).toHaveBeenCalledOnce();
        expect(toasts).toEqual([]);
    });

    it('records the failures of loops that gave up, and tries again from a fresh state', async () => {
        const { followers, pushes } = setup();
        await pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });
        const follower = followers.made[0];

        follower.options.onFailures([{ lane: 1, error: { message: 'The server could not be reached.' } }]);

        expect(describePush(store.container('base').push, status()).failure).toBe('The server could not be reached.');

        await pushes.resume('base');

        expect(follower.refresh).toHaveBeenCalledOnce();
        expect(follower.follow).toHaveBeenLastCalledWith(runState());
        expect(store.container('base').push.failures).toEqual([]);
    });

    it('cancels through the follower', async () => {
        const { followers, pushes } = setup();
        await pushes.start('base', { reference: REFERENCE, sites: ['el', 'de'], mode: 'missing' });

        const cancelled = pushes.cancel('base');

        expect(store.container('base').push.cancelling).toBe(true);
        await cancelled;
        await pushes.cancel('base');

        expect(followers.made[0].cancel).toHaveBeenCalledOnce();
        expect(store.container('base').push).toMatchObject({ phase: 'finished', cancelling: false });
        expect(store.container('base').push.state.run.status).toBe('cancelled');
    });

    it('reports a cancel that failed', async () => {
        const { followers, pushes } = setup();
        await pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });
        followers.made[0].cancel.mockRejectedValueOnce(new ApiError({ status: 500, message: 'Nope' }));

        await pushes.cancel('base');

        expect(toasts).toEqual(['Nope']);
        expect(store.container('base').push).toMatchObject({ phase: 'running', cancelling: false });
    });

    it('keeps a running push open', async () => {
        const { pushes } = setup();
        await pushes.start('base', { reference: REFERENCE, sites: ['el'], mode: 'missing' });

        pushes.dismiss('base');

        expect(store.container('base').push.phase).toBe('running');
    });
});

describe('adopt', () => {
    const active = { run: '01RUN', driver: 'browser', lanes: 2, urls: runUrls() };

    it('follows the run the status names again', async () => {
        const { api, followers, pushes } = setup({ api: fakeApi({ get: runState({ counts: { done: 1, translating: 1 } }) }) });

        await pushes.adopt('base', status(undefined, { active_run: active }));

        expect(api.requests).toEqual([{ method: 'get', url: active.urls.state, data: undefined }]);
        expect(followers.made[0].options).toMatchObject({ urls: active.urls, lanes: 2 });
        expect(followers.made[0].follow).toHaveBeenCalledWith(runState({ counts: { done: 1, translating: 1 } }));
        expect(store.container('base').push).toMatchObject({ phase: 'running', run: '01RUN', missing: ['el'] });
    });

    it('does nothing without a running push, for a push it shows, or for an unknown container', async () => {
        const { api, pushes } = setup({ api: fakeApi({ get: runState() }) });

        await pushes.adopt('base', status());
        await pushes.adopt('stack', status(undefined, { active_run: active }));
        await pushes.adopt('base', status(undefined, { active_run: active }));
        await pushes.adopt('base', status(undefined, { active_run: active }));

        expect(api.get).toHaveBeenCalledOnce();
    });

    it('restarts the follower it still has for the run, after a switch away and back', async () => {
        const { followers, pushes } = setup({ api: fakeApi({ post: pushAnswer(), get: runState({ counts: { done: 1, pending: 1 } }) }) });
        await pushes.start('base', { reference: REFERENCE, sites: ['el', 'de'], mode: 'missing' });

        store.switched('base', 'entry::hello-el');
        store.switched('base', REFERENCE);
        await pushes.adopt('base', status(undefined, { active_run: active }));

        expect(followers.create).toHaveBeenCalledOnce();
        expect(followers.made[0].follow).toHaveBeenCalledTimes(2);

        followers.made[0].options.onState(runState({ counts: { done: 2 } }));

        expect(store.container('base').push.state.counts).toEqual({ done: 2 });
    });

    it('reports a state that could not be read', async () => {
        const { followers, pushes } = setup({
            api: fakeApi({
                get: () => {
                    throw new ApiError({ status: 500, message: 'Down' });
                },
            }),
        });

        await pushes.adopt('base', status(undefined, { active_run: active }));

        expect(followers.create).not.toHaveBeenCalled();
        expect(describePush(store.container('base').push, status()).failure).toBe('Down');
    });
});
