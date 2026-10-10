import { registry as sharedRegistry, clone, sameText } from './registry.js';

export { clone };

// Core keeps dirty tracking off for 500 ms after a site switch, a localization's creation and a save (R3 §1.3).
// Inside that window `desyncField` does nothing, and Save drops a filled field silently (T2.2 check 6).
export const SETTLE_MS = 600;

// Fieldtypes pass changes up through a 150 ms debounce; Bard, lists and arrays re-emit their own value after a fill.
export const REEMIT_MS = 300;

// Core's slug reset after `localization.created` slugifies asynchronously, about a second later in the sandbox.
export const SLUG_RESET_TIMEOUT_MS = 2000;

// A slugify request that was still running when the slug was put back answers late and overwrites it (T2.2 check 9).
export const SLUG_RECHECK_MS = 1000;

const SLUG_POLL_MS = 100;

// Long enough for the slug fieldtype to see a value before the next one, short of its 150 ms update debounce.
const SLUG_TOGGLE_MS = 50;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// `lastChangeAt` may be a number or a function, read again after each wait.
const getter = (value) => (typeof value === 'function' ? value : () => value);

// Whether a fill may still land: the form shows the same item and the translation still runs.
const isCurrent = (ctx, reference, active) => () => ctx.reference.value === reference && active();

async function settled(since, now, sleep) {
    const remaining = () => SETTLE_MS - (now() - since());

    for (let left = remaining(); left > 0; left = remaining()) {
        await sleep(left);
    }
}

// Text alone counts: a Bard field that re-emits its value with attributes of its own, or desyncs itself while the
// form loads, was not edited. A handle the plan values lack counts as edited unless the form lacks it too.
function editedSince(ctx, planValues, handle) {
    if (!Object.hasOwn(planValues, handle)) {
        return ctx.values.value[handle] !== undefined;
    }

    return !sameText(ctx.values.value[handle], planValues[handle]);
}

// Core's Markdown fieldtype hands every new value to CodeMirror, which cannot take null and keeps showing the old text,
// so a field the form holds text of is emptied with an empty string. A field put back to inherit from an empty origin
// arrives as null, because the Markdown fieldtype has no `preProcess()`.
function formValue(ctx, handle, value) {
    return value === null && typeof ctx.values.value[handle] === 'string' ? '' : clone(value);
}

// Whether `restore()` puts a handle back: it holds a fill delivered since the snapshot was taken.
export function filledSince(before, record) {
    return Boolean(record) && !record.pending && record.plan !== before.delivered?.plan;
}

// What `restore()` puts back. `delivered` (the container's delivered map) keeps the record an earlier plan left for
// a handle, so undoing a second translation brings the first one's record back with its value.
export function snapshot(ctx, handles, delivered = {}) {
    const localized = ctx.localizedFields.value;

    return {
        reference: ctx.reference.value,
        dirty: Statamic.$dirty.has(ctx.name.value),
        fields: Object.fromEntries(
            handles.map((handle) => [
                handle,
                {
                    value: clone(ctx.values.value[handle]),
                    meta: clone(ctx.meta.value[handle]),
                    localized: localized.includes(handle),
                    delivered: delivered[handle] ? clone(delivered[handle]) : null,
                },
            ]),
        ),
    };
}

// Fills the payloads of `editor/plan` (`ready`) and `editor/unit` (`fields`) into the open form, unsaved. Resolves
// once the values the form holds have been recorded in `delivered`, keyed by handle with the plan's id. `active` says
// whether the translation still runs: one aborted by a switch must not fill when the editor switches back.
export async function applyFields(
    ctx,
    fields,
    { reference, plan, lastChangeAt = 0, planValues = {}, delivered, now = Date.now, sleep = wait, active = () => true },
) {
    const handles = Object.keys(fields ?? {});
    const result = { stale: false, applied: [], skipped: {}, repeated: [] };
    const stale = () => {
        result.stale = true;
        result.applied = [];
        result.skipped = Object.fromEntries(handles.map((handle) => [handle, 'stale']));

        return result;
    };
    const current = isCurrent(ctx, reference, active);

    // A translation for the Greek form must never land in the German one.
    if (!current()) {
        return stale();
    }

    await settled(getter(lastChangeAt), now, sleep);

    if (!current()) {
        return stale();
    }

    const synced = [];
    const filled = {};

    for (const handle of handles) {
        const payload = fields[handle];

        // Two units running at once can both complete the same field.
        if (delivered[handle]?.plan === plan) {
            result.repeated.push(handle);
            continue;
        }

        // The editor typed into the field while the model worked; their text wins.
        if (editedSince(ctx, planValues, handle)) {
            result.skipped[handle] = 'edited';
            continue;
        }

        // Recorded at once, so a repeated delivery during the read-back below is recognised.
        delivered[handle] = { plan, value: undefined, pending: true, incomplete: Boolean(payload.incomplete), sync: Boolean(payload.sync) };

        if (payload.meta !== undefined) {
            ctx.setFieldMeta(handle, clone(payload.meta));
        }

        filled[handle] = formValue(ctx, handle, payload.value);
        ctx.setFieldValue(handle, clone(filled[handle]));

        if (payload.sync) {
            // Back to inheriting from the origin: an unsaved change even when the value looks the same.
            setLocalized(ctx, handle, false);
            Statamic.$dirty.add(ctx.name.value);
            synced.push(handle);
        } else {
            ctx.desyncField(handle);

            // `desyncField` is a no-op while dirty tracking is off, so make sure the field is posted on Save.
            if (!ctx.localizedFields.value.includes(handle)) {
                setLocalized(ctx, handle, true);
                Statamic.$dirty.add(ctx.name.value);
            }
        }

        result.applied.push(handle);
    }

    if (!result.applied.length) {
        return result;
    }

    // A fieldtype such as Bard re-emits what was filled, which desyncs the field again; a synced field must undo that.
    // What the form holds afterwards is recorded as delivered while it has the filled text. Other text was typed by the
    // editor meanwhile, so what was filled is recorded, and Undo keeps their text.
    await sleep(REEMIT_MS);

    if (!current()) {
        result.applied.forEach((handle) => delete delivered[handle]);

        return stale();
    }

    synced.forEach((handle) => setLocalized(ctx, handle, false));

    for (const handle of result.applied) {
        const value = ctx.values.value[handle];

        delivered[handle] = { ...delivered[handle], value: sameText(value, filled[handle]) ? clone(value) : filled[handle], pending: false };
    }

    return result;
}

// One level of undo. A handle goes back to its snapshot only while it still holds what was delivered; later edits
// stay. `syncField` is never called, because it opens a native confirm dialog.
export async function restore(ctx, snap, delivered, { registry = sharedRegistry, sleep = wait } = {}) {
    const result = { restored: [], kept: [] };

    if (!snap || ctx.reference.value !== snap.reference) {
        return result;
    }

    for (const [handle, before] of Object.entries(snap.fields)) {
        const record = delivered[handle];

        if (!filledSince(before, record)) {
            continue;
        }

        if (!sameText(ctx.values.value[handle], record.value)) {
            result.kept.push(handle);
            continue;
        }

        result.restored.push(handle);
    }

    if (!result.restored.length) {
        return result;
    }

    // Without dirtying, so the values watcher does not flag the form again after the flag is cleared below.
    ctx.withoutDirtying(() => {
        for (const handle of result.restored) {
            const before = snap.fields[handle];

            ctx.setFieldMeta(handle, clone(before.meta));
            ctx.setFieldValue(handle, formValue(ctx, handle, before.value));
            setLocalized(ctx, handle, before.localized);

            if (before.delivered) {
                delivered[handle] = clone(before.delivered);
            } else {
                delete delivered[handle];
            }
        }
    });

    const settle = () => {
        if (ctx.reference.value !== snap.reference) {
            return;
        }

        result.restored.forEach((handle) => setLocalized(ctx, handle, snap.fields[handle].localized));

        if (!snap.dirty && registry.dirtyHandles(ctx.name.value).length === 0) {
            Statamic.$dirty.remove(ctx.name.value);
        }
    };

    settle();

    // A field that re-emits the restored value also desyncs itself again.
    await sleep(REEMIT_MS);

    settle();

    return result;
}

// Slugs are never translated (D16). On `localization.created` core resets the new localization's slug to follow the
// title, so filling a translated title would give it a translated slug. Once the reset has finished, which turns
// `meta.slug.auto` on, setting the origin's slug stops the following. Waits for the 600 ms guard and gives up waiting
// for the reset 2 seconds after `lastChangeAt`, then sets the slug anyway; `recheckOriginSlug()` catches a reset that
// answers later. Never call it for a localization that existed before the form opened.
export async function keepOriginSlug(
    ctx,
    originSlug,
    { reference, lastChangeAt = 0, now = Date.now, sleep = wait, active = () => true } = {},
) {
    const since = getter(lastChangeAt);
    const current = isCurrent(ctx, reference, active);
    const left = () => since() + SLUG_RESET_TIMEOUT_MS - now();

    if (!current()) {
        return { stale: true, kept: false, reset: false };
    }

    if (!hasSlug(ctx, originSlug)) {
        return { stale: false, kept: false, reset: false };
    }

    await settled(since, now, sleep);

    while (current() && !followsTitle(ctx) && left() > 0) {
        await sleep(Math.min(SLUG_POLL_MS, left()));
    }

    const reset = followsTitle(ctx);

    if (!current() || !(await putOriginSlug(ctx, originSlug, { sleep, current }))) {
        return { stale: true, kept: false, reset };
    }

    return { stale: false, kept: true, reset };
}

// Run once the title has been filled. Resolves whether the slug had to be set again.
export async function recheckOriginSlug(ctx, originSlug, { reference, sleep = wait, active = () => true } = {}) {
    const current = isCurrent(ctx, reference, active);

    if (!hasSlug(ctx, originSlug)) {
        return false;
    }

    await sleep(SLUG_RECHECK_MS);

    if (!current() || (ctx.values.value.slug === originSlug && !followsTitle(ctx))) {
        return false;
    }

    return putOriginSlug(ctx, originSlug, { sleep, current });
}

function hasSlug(ctx, originSlug) {
    return typeof originSlug === 'string' && originSlug !== '' && Object.hasOwn(ctx.values.value ?? {}, 'slug');
}

function followsTitle(ctx) {
    return ctx.meta.value?.slug?.auto === true;
}

// Core's slug stops following the title when its value changes to one it did not generate. Setting the origin's slug
// changes nothing while the form still shows it: when the title gave that slug again, and for the 150 ms after the
// reset turned `auto` on, before the fieldtype's debounced update brings the new slug into the form. So another value
// comes first; the debounced update then carries the origin's slug as the last value.
async function putOriginSlug(ctx, originSlug, { sleep, current }) {
    if (followsTitle(ctx) && ctx.values.value.slug === originSlug) {
        ctx.setFieldValue('slug', '');
        await sleep(SLUG_TOGGLE_MS);

        if (!current()) {
            return false;
        }
    }

    if (ctx.values.value.slug !== originSlug) {
        ctx.setFieldValue('slug', originSlug);
    }

    return true;
}

function setLocalized(ctx, handle, localized) {
    const fields = ctx.localizedFields.value;
    const index = fields.indexOf(handle);

    if (localized && index === -1) {
        fields.push(handle);
    } else if (!localized && index !== -1) {
        fields.splice(index, 1);
    }
}
