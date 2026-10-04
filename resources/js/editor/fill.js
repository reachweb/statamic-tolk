import { registry as sharedRegistry, clone, equalValues } from './registry.js';

export { clone };

// Core keeps dirty tracking off for 500 ms after a site switch, a localization's creation and a save (R3 §1.3).
// Inside that window `desyncField` does nothing, and Save drops a filled field silently (T2.2 check 6).
export const SETTLE_MS = 600;

// Fieldtypes pass changes up through a 150 ms debounce; Bard, lists and arrays re-emit their own value after a fill.
export const REEMIT_MS = 300;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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
// once the values the form holds have been recorded in `delivered`, keyed by handle with the plan's id.
export async function applyFields(
    ctx,
    fields,
    { reference, plan, lastChangeAt = 0, planValues = {}, delivered, now = Date.now, sleep = wait },
) {
    const handles = Object.keys(fields ?? {});
    const result = { stale: false, applied: [], skipped: {}, repeated: [] };
    const stale = () => {
        result.stale = true;
        result.applied = [];
        result.skipped = Object.fromEntries(handles.map((handle) => [handle, 'stale']));

        return result;
    };

    // A translation for the Greek form must never land in the German one.
    if (ctx.reference.value !== reference) {
        return stale();
    }

    const since = typeof lastChangeAt === 'function' ? lastChangeAt : () => lastChangeAt;
    const remaining = () => SETTLE_MS - (now() - since());

    for (let left = remaining(); left > 0; left = remaining()) {
        await sleep(left);
    }

    if (ctx.reference.value !== reference) {
        return stale();
    }

    const synced = [];

    for (const handle of handles) {
        const payload = fields[handle];

        // Two units running at once can both complete the same field.
        if (delivered[handle]?.plan === plan) {
            result.repeated.push(handle);
            continue;
        }

        // The editor typed into the field while the model worked; their text wins.
        if (!equalValues(ctx.values.value[handle], planValues[handle])) {
            result.skipped[handle] = 'edited';
            continue;
        }

        // Recorded at once, so a repeated delivery during the read-back below is recognised.
        delivered[handle] = { plan, value: undefined, pending: true, incomplete: Boolean(payload.incomplete), sync: Boolean(payload.sync) };

        if (payload.meta !== undefined) {
            ctx.setFieldMeta(handle, clone(payload.meta));
        }

        ctx.setFieldValue(handle, clone(payload.value));

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

    // Bard adds attributes such as `textAlign: null` when it re-emits, so what the form holds afterwards is what
    // counts as delivered, not the payload. A re-emit also desyncs the field again, which a synced field must undo.
    await sleep(REEMIT_MS);

    if (ctx.reference.value !== reference) {
        result.applied.forEach((handle) => delete delivered[handle]);

        return stale();
    }

    synced.forEach((handle) => setLocalized(ctx, handle, false));

    for (const handle of result.applied) {
        delivered[handle] = { ...delivered[handle], value: clone(ctx.values.value[handle]), pending: false };
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

        if (!record || record.pending || record.plan === before.delivered?.plan) {
            continue;
        }

        if (!equalValues(ctx.values.value[handle], record.value)) {
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
            ctx.setFieldValue(handle, clone(before.value));
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

function setLocalized(ctx, handle, localized) {
    const fields = ctx.localizedFields.value;
    const index = fields.indexOf(handle);

    if (localized && index === -1) {
        fields.push(handle);
    } else if (!localized && index !== -1) {
        fields.splice(index, 1);
    }
}
