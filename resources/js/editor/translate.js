import * as defaultApi from '../api.js';
import { asMap } from '../api.js';
import { applyFields, clone, filledSince, keepOriginSlug, recheckOriginSlug, restore, snapshot } from './fill.js';
import { equalValues, registry as sharedRegistry } from './registry.js';
import { fieldLabels } from './statuses.js';
import { initialState, useEditorStore } from './store.js';
import { describeError, runPlan } from './unitRunner.js';

// While a translation is under way, Save is refused and no second translation starts.
export const BUSY = ['planning', 'working'];

// After these the plan cannot run any more, so the banner offers Start again instead of Retry.
export const RESTART = ['source_changed', 'plan_expired', 'target_missing'];

const EDITED_IN_FORM = 'edited_in_form';

// A field this addon filled and the editor has not touched since is not an edit of theirs.
function untouchedFill(ctx, record, handle) {
    return Boolean(record) && !record.pending && equalValues(ctx.values.value[handle], record.value);
}

// The handles the editor has unsaved changes in. The server only sees stored content and would otherwise plan them
// and overwrite what was typed: fields whose value differs from the clean values, and fields localized in the form
// since then although the status says nothing is stored for them. A field the form localized by itself while it
// loaded is not one of them: an inherited Bard field can desync itself on load.
export function skipHandles(ctx, status, { name, registry, delivered = {} }) {
    const inScope = asMap(status?.localization?.fields);
    const handles = new Set(registry.dirtyHandles(name));

    for (const handle of registry.newlyLocalized(name)) {
        if (inScope[handle]?.status === 'untranslated') {
            handles.add(handle);
        }
    }

    return [...handles].filter((handle) => Object.hasOwn(inScope, handle) && !untouchedFill(ctx, delivered[handle], handle));
}

// The planned handles a stopped translation did not fill and did not skip.
export function remainingHandles(summary) {
    const finished = new Set([...(summary?.delivered ?? []), ...Object.keys(summary?.skipped ?? {})]);

    return (summary?.planned ?? []).filter((handle) => !finished.has(handle));
}

// The origin's slug while the open localization was created in this form and its slug has not been kept yet (T5.9).
export function slugToKeep(container) {
    return container?.created && !container.slugKept ? (container.status?.localization?.origin_slug ?? null) : null;
}

// Whether Undo has something to put back: a handle filled since the snapshot was taken.
export function undoable(container) {
    const fields = container?.snapshot?.fields;

    if (!fields) {
        return false;
    }

    return Object.entries(fields).some(([handle, before]) => filledSince(before, container.delivered?.[handle]));
}

// The toast after Undo for the fields it left alone.
export function keptMessage(handles, status) {
    return __n(
        ':fields was changed after the translation and was kept.|:fields were changed after the translation and were kept.',
        handles.length,
        { fields: fieldLabels(handles, status) },
    );
}

function planSkips(skipped) {
    return Object.entries(asMap(skipped));
}

function editedInForm(skipped) {
    return Object.fromEntries(
        planSkips(skipped)
            .filter(([, reason]) => reason === EDITED_IN_FORM)
            .map(([handle]) => [handle, 'edited']),
    );
}

// The plan's other reasons (`manual`, `current`, `not_requested`, …), for the summary's line about hand-written text.
function otherPlanSkips(skipped) {
    return Object.fromEntries(planSkips(skipped).filter(([, reason]) => reason !== EDITED_IN_FORM));
}

// Retry and Start again continue a translation, so Undo puts back what the form held before the first of them.
function carryOver(snap, container) {
    const previous = container.snapshot;
    let carried = false;

    if (!previous || previous.reference !== snap.reference) {
        return snap;
    }

    for (const [handle, before] of Object.entries(previous.fields)) {
        if (filledSince(before, container.delivered[handle])) {
            snap.fields[handle] = before;
            carried = true;
        }
    }

    if (carried) {
        snap.dirty = previous.dirty;
    }

    return snap;
}

// Translates into the open form (§A13): plan, fill the ready fields, run the units in parallel and fill each response.
// Everything the banner shows lives in the store; the AbortControllers live here.
export function createTranslations({
    store = useEditorStore,
    registry = sharedRegistry,
    api = defaultApi,
    concurrency = () => Statamic.$config.get('tolk.concurrency'),
    run = runPlan,
    fill = applyFields,
    undoFill = restore,
    keepSlug = keepOriginSlug,
    recheckSlug = recheckOriginSlug,
} = {}) {
    const runs = new Map();
    const undoing = new Set();

    // A site or entry switch, or a new container: fills still in flight are discarded.
    function abort(name) {
        const current = runs.get(name);

        if (current) {
            runs.delete(name);
            current.controller.abort();
        }
    }

    // Cancel: no new unit starts, and units in flight are still filled.
    function cancel(name) {
        const current = runs.get(name);
        const container = store().containers[name];

        if (!current || current.controller.signal.aborted || container?.phase !== 'working') {
            return;
        }

        current.controller.abort();
        container.progress = { ...container.progress, cancelling: true };
    }

    async function translate(name, { mode = 'missing', fields = null, overwriteManual = false, translator = null } = {}, { continuing = false } = {}) {
        const editor = store();
        const ctx = registry.context(name);
        const container = editor.container(name);

        if (!ctx || BUSY.includes(container.phase) || runs.has(name) || !ctx.reference.value) {
            return null;
        }

        const reference = ctx.reference.value;
        const token = {};
        const controller = new AbortController();
        const mine = () => runs.get(name)?.token === token;
        const current = () => mine() && editor.containers[name] === container && ctx.reference.value === reference;

        const lastChangeAt = () => editor.containers[name]?.lastChangeAt ?? 0;
        const originSlug = slugToKeep(container);
        const snap = snapshot(ctx, fields ?? Object.keys(ctx.values.value), container.delivered);
        const planValues = clone(ctx.values.value);
        const skip = skipHandles(ctx, container.status, { name, registry, delivered: container.delivered });

        if (continuing) {
            carryOver(snap, container);
        }

        runs.set(name, { token, controller });
        Object.assign(container, {
            request: { mode, fields, overwriteManual, translator },
            snapshot: snap,
            summary: null,
            notSaved: [],
            planReference: reference,
            progress: initialState().progress,
        });
        editor.setPhase(name, 'planning');

        // The slug of a new localization follows its title until the origin's slug is put back; before the first fill.
        const keeping = originSlug ? keepSlug(ctx, originSlug, { reference, lastChangeAt, active: mine }) : null;

        // Not awaited when the plan request fails; it then stops by itself, since the translation is no longer active.
        keeping?.catch(() => {});

        try {
            const plan = await api.post('editor/plan', { reference, mode, overwrite_manual: overwriteManual, fields, skip, translator });

            if (!current()) {
                return null;
            }

            if (keeping) {
                await keeping;

                if (!current()) {
                    return null;
                }

                container.slugKept = true;
            }

            editor.setPhase(name, 'working');
            container.progress = { ...initialState().progress, total: plan.units?.length ?? 0 };

            const options = {
                reference,
                plan: plan.plan,
                lastChangeAt,
                planValues,
                delivered: container.delivered,
                active: mine,
            };

            const summary = await run({
                api,
                plan,
                concurrency: concurrency(),
                signal: controller.signal,
                onFields: (payloads) => fill(ctx, payloads, options),
                onProgress: (progress) => {
                    if (current()) {
                        container.progress = { ...progress, cancelling: controller.signal.aborted };
                    }
                },
            });

            if (keeping && summary.delivered.length && current()) {
                await recheckSlug(ctx, originSlug, { reference, active: mine });
            }

            if (!current()) {
                return null;
            }

            const result = {
                ...summary,
                plan: plan.plan,
                planned: Array.isArray(plan.fields) ? plan.fields : [],
                skipped: { ...editedInForm(plan.skipped), ...summary.skipped },
                planSkipped: otherPlanSkips(plan.skipped),
                cancelled: controller.signal.aborted,
            };

            container.summary = result;
            editor.setPhase(name, summary.stopped ? 'error' : 'done', summary.stopped);

            return result;
        } catch (error) {
            // A phase left on `planning` or `working` would refuse every Save.
            if (current()) {
                editor.setPhase(name, 'error', describeError(error, __('Something went wrong.')));
            }

            return null;
        } finally {
            if (mine()) {
                runs.delete(name);
            }
        }
    }

    // Retry and Start again: the same request, limited to the fields not filled yet once part of it ran.
    function retry(name) {
        const container = store().containers[name];

        if (!container?.request || BUSY.includes(container.phase)) {
            return null;
        }

        const fields = container.summary ? remainingHandles(container.summary) : container.request.fields;

        if (fields !== null && fields.length === 0) {
            store().setPhase(name, 'done');

            return null;
        }

        return translate(name, { ...container.request, fields }, { continuing: true });
    }

    async function undo(name) {
        const editor = store();
        const container = editor.containers[name];
        const ctx = registry.context(name);

        if (!ctx || !container?.snapshot || BUSY.includes(container.phase) || undoing.has(name)) {
            return null;
        }

        undoing.add(name);

        try {
            const result = await undoFill(ctx, container.snapshot, container.delivered, { registry });

            if (editor.containers[name] === container) {
                editor.clearResult(name);
            }

            return result;
        } finally {
            undoing.delete(name);
        }
    }

    return {
        translate,
        retry,
        cancel,
        abort,
        undo,
        running: (name) => runs.has(name),
    };
}

export const translations = createTranslations();
