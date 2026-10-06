import * as defaultApi from '../api.js';
import { describeCounts, describeItem, describeProgress, describeResult } from '../runs/describe.js';
import { createFollower as defaultCreateFollower } from '../runs/follow.js';
import { isFinal } from '../runs/ticker.js';
import { useEditorStore } from './store.js';
import { describeError } from './unitRunner.js';

// While a push is starting or running, the origin's buttons make way for its progress.
export const PUSHING = ['starting', 'running'];

// The sites Translate into… offers: missing, or with untranslated fields (`describe(status).origin`).
export function pushChoices(origin) {
    return (origin?.localizations ?? [])
        .filter((localization) => localization.translate)
        .map(({ site, name, exists, summary }) => ({ site, name, missing: !exists, summary }));
}

// The sites Update translations updates: those with outdated fields.
export function updateSites(origin) {
    return (origin?.localizations ?? []).filter((localization) => localization.update).map((localization) => localization.site);
}

// The sites of the origin block that have no localization yet; after the run, those that exist were created by it.
export function missingSites(status) {
    return (status?.origin?.localizations ?? []).filter((localization) => !localization.exists).map((localization) => localization.site);
}

// What the banner shows of a push: the sites it translates into, its progress, and once it is final, the result with
// a line per site and whether a reload would show new localizations in the Sites panel.
export function describePush(push, status) {
    if (!push) {
        return null;
    }

    const localizations = new Map((status?.origin?.localizations ?? []).map((localization) => [localization.site, localization]));
    const name = (site) => localizations.get(site)?.name ?? site;
    const state = push.state;
    const sites = (push.sites?.length ? push.sites : (state?.run?.scope?.sites ?? [])).map(name);
    const progress = describeProgress(state);
    const final = state ? isFinal(state) : false;
    const result = final ? describeResult(state) : null;
    const created = final ? (push.missing ?? []).filter((site) => localizations.get(site)?.exists) : [];

    return {
        sites: sites.join(', '),
        progress,
        keepOpen: push.phase === 'running' && push.driver === 'browser',
        failure: push.failures?.length ? push.failures.at(-1).error?.message ?? null : null,
        error: push.phase === 'error' ? push.error?.message ?? null : null,
        result: result && {
            ...result,
            text: pushResultText(result.status, progress.counts),
            items: (state.items ?? []).map((item) => {
                const row = describeItem(item, name);

                return { ...row, handle: item.target_site, editUrl: localizations.get(item.target_site)?.edit_url ?? row.editUrl };
            }),
        },
        reload: created.length > 0,
    };
}

function pushResultText(status, counts) {
    const items = describeCounts(counts, __n(':count saved|:count saved', counts.done));

    return {
        completed: __('The translations are done: :items.', { items }),
        completed_with_errors: __('The translations are done, with errors: :items.', { items }),
        cancelled: __('The translations were cancelled: :items.', { items }),
        aborted: __('The translations were stopped: :items.', { items }),
    }[status];
}

// Translates the saved origin open in the editor into its localizations as a run that starts at once (T8.13), and
// follows the run inline with the run page's follower: lanes and the apply loop for the browser driver, polling
// otherwise. The open form is not touched; the run saves server-side (D4). A follower belongs to its run, not to the
// form: it keeps driving when the editor switches site or leaves for another page, and a banner that shows the item
// again (a switch back, a reload) picks the run up from `editor/status`'s `active_run`.
export function createPushes({ store = useEditorStore, api = defaultApi, createFollower = defaultCreateFollower } = {}) {
    // Run id → follower.
    const followers = new Map();

    // Every container that shows the run.
    function showing(runId) {
        return Object.values(store().containers).filter((container) => container.push?.run === runId);
    }

    function received(runId, state) {
        for (const container of showing(runId)) {
            container.push.state = state;

            if (isFinal(state) && container.push.phase === 'running') {
                container.push.phase = 'finished';
                container.push.cancelling = false;
                refreshStatus(container.reference);
            }
        }

        if (isFinal(state)) {
            followers.delete(runId);
        }
    }

    function failed(runId, failures) {
        for (const container of showing(runId)) {
            container.push.failures = failures;
        }
    }

    // The counts in the origin block change, and so do the links of the new localizations.
    async function refreshStatus(reference) {
        try {
            const status = await api.get('editor/status', { reference });

            for (const container of Object.values(store().containers)) {
                if (container.reference === reference) {
                    container.status = status;
                }
            }
        } catch (error) {
            if (error?.status !== 403) {
                Statamic.$toast.error(__('The translation status could not be updated: :message', { message: error?.message ?? '' }));
            }
        }
    }

    // The store's `push` of a container (`initialState()` in store.js).
    function pushState(phase, { run = null, driver = null, sites = null, missing = [] }) {
        return { phase, run, driver, sites, state: null, failures: [], missing, cancelling: false, error: null };
    }

    function show(name, run, missing, sites = null) {
        store().container(name).push = pushState('running', { run: run.run, driver: run.driver, sites, missing });
    }

    function followerOf(container) {
        return container?.push?.run ? (followers.get(container.push.run) ?? null) : null;
    }

    // Follows the run from `state`. A follower already driving it starts its loops again from there, which also
    // restarts lanes that gave up.
    function follow(run, state) {
        let follower = followers.get(run.run);

        if (!follower) {
            follower = createFollower({
                urls: run.urls,
                lanes: run.lanes,
                onState: (next) => received(run.run, next),
                onFailures: (failures) => failed(run.run, failures),
            });
            followers.set(run.run, follower);
        }

        follower.follow(state);
    }

    async function start(name, { reference, sites, mode, overwriteManual = false, review = null, newStatus = null }) {
        const container = store().container(name);

        if (PUSHING.includes(container.push?.phase) || container.reference !== reference) {
            return null;
        }

        const missing = missingSites(container.status).filter((site) => sites.includes(site));

        container.push = pushState('starting', { sites, missing });

        try {
            const answer = await api.post('editor/push', {
                reference,
                sites,
                mode,
                overwrite_manual: overwriteManual,
                review,
                new_status: newStatus,
            });

            if (store().containers[name]?.reference === reference) {
                show(name, answer, missing, sites);
            }

            follow(answer, answer.state);

            return answer;
        } catch (error) {
            const current = store().containers[name];

            // The container shows another item now: its own banner says what there is to say.
            if (current?.reference !== reference) {
                return null;
            }

            // Another page started one meanwhile: follow that instead.
            if (error?.code === 'run_active' && error.data?.active_run) {
                current.push = null;
                await adopt(name, { active_run: error.data.active_run, origin: current.status?.origin });

                return null;
            }

            current.push = { ...current.push, phase: 'error', error: describeError(error) };

            return null;
        }
    }

    // `editor/status` names a run this user pushed from the item that still runs: follow it again.
    async function adopt(name, status) {
        const run = status?.active_run;
        const container = store().containers[name];

        if (!run || !container || container.push?.run === run.run) {
            return;
        }

        const reference = container.reference;

        show(name, run, missingSites(status));

        try {
            const state = await api.get(run.urls.state);

            follow(run, state);
        } catch (error) {
            const current = store().containers[name];

            if (current?.reference === reference && current.push?.run === run.run) {
                current.push.failures = [{ error: describeError(error) }];
            }
        }
    }

    // Starts the loops again after they gave up, from a fresh state.
    async function resume(name) {
        const container = store().containers[name];
        const follower = followerOf(container);

        if (!follower) {
            return;
        }

        container.push.failures = [];

        try {
            follower.follow(await follower.refresh());
        } catch (error) {
            container.push.failures = [{ error: describeError(error) }];
        }
    }

    async function cancel(name) {
        const container = store().containers[name];
        const follower = followerOf(container);

        if (!follower) {
            return;
        }

        container.push.cancelling = true;

        try {
            await follower.cancel();
        } catch (error) {
            container.push.cancelling = false;
            Statamic.$toast.error(error.message);
        }
    }

    // Closes the result or the error.
    function dismiss(name) {
        const container = store().containers[name];

        if (container && !PUSHING.includes(container.push?.phase)) {
            container.push = null;
        }
    }

    return {
        start,
        adopt,
        resume,
        cancel,
        dismiss,
        following: (runId) => followers.has(runId),
    };
}

export const pushes = createPushes();
