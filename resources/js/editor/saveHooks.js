import * as defaultApi from '../api.js';
import { leaveOutHidden, savedFills, takeBack } from './fill.js';
import { pushes } from './push.js';
import { BASE, registry as sharedRegistry } from './registry.js';
import { fieldLabels } from './statuses.js';
import { useEditorStore } from './store.js';
import { BUSY, translations } from './translate.js';

export function notSavedMessage(handles, status) {
    return __n(
        ':fields was not saved because it was hidden when you saved. Show it and translate it again.|:fields were not saved because they were hidden when you saved. Show them and translate them again.',
        handles.length,
        { fields: fieldLabels(handles, status) },
    );
}

// The save hooks of the entry and term forms. `entry.saving` and `term.saving` refuse a save while a translation fills
// the form: the entry form's save response replaces the form's values, and a field filled in between would stay
// localized and be stored in the origin's language on the next save (T2.2 check 12); in either form it would be left
// out of the save. Otherwise they leave a filled field that a condition hides out of `_localized` (`leaveOutHidden()`):
// core's request step reads that array afterwards. `entry.saved` and `term.saved` tell the server which fields were
// saved (`editor/confirm`). `entry.published` reads the status again after Publish, which neither saving hook sees,
// and discards a translation the form has not saved (below). `PipelineStopped` comes from
// `@statamic/cms/save-pipeline`; core stays silent for a save stopped with it.
export function createSaveHooks({
    PipelineStopped,
    store = useEditorStore,
    registry = sharedRegistry,
    api = defaultApi,
    now = Date.now,
    // The translation running in a container, the same object for as long as it runs, or null.
    running = (name) => translations.current(name),
    // Stops the translation running in a container; nothing it still delivers is filled.
    stop = (name) => translations.abort(name),
    onConfirmed = (name, status, options) => pushes.afterSave(name, status, options),
}) {
    let listening = false;
    // The translation the refusal's toast was last shown for. Core's autosave (experimental, opt-in per collection)
    // tries to save a dirty form every few seconds, the fills keep the form dirty, and the hook's payload does not tell
    // an autosave from a Save the editor pressed: each translation shows the toast once.
    let refusedFor = null;

    function listen(hooks = Statamic.$hooks) {
        if (listening) {
            return;
        }

        listening = true;

        for (const type of ['entry', 'term']) {
            hooks.on(`${type}.saving`, saving);
            hooks.on(`${type}.saved`, saved);
        }

        hooks.on('entry.published', published);
    }

    // Hook callbacks get `(resolve, reject, payload)`. A stacked editor saves through the same hook; it is refused too
    // while the page's translation runs. A busy phase with no translation behind it refuses nothing: there would be no
    // Cancel to end it, and nothing left to fill the form.
    function saving(resolve, reject) {
        const editor = store();
        const translation = running(BASE);

        if (BUSY.includes(editor.phaseOf(BASE)) && translation) {
            if (refusedFor !== translation) {
                refusedFor = translation;
                Statamic.$toast.error(__('A translation is in progress. Wait for it to finish, or cancel it.'));
            }

            reject(new PipelineStopped());

            return;
        }

        const ctx = registry.context(BASE);
        const container = editor.containers[BASE];

        if (ctx && container?.reference && container.reference === ctx.reference.value) {
            leaveOutHidden(ctx, container.delivered);
        }

        resolve();
    }

    // Resolves only after `editor/confirm` returned: with the default "after saving" preference the form leaves for the
    // listing right after these hooks. Never rejects, which would turn the finished save into a failed one.
    async function saved(resolve, reject, payload) {
        try {
            await afterSave(payload);
        } catch (error) {
            console.error(error);
        } finally {
            resolve();
        }
    }

    async function afterSave(payload) {
        const editor = store();
        const container = editor.containers[BASE];
        const reference = savedReference(payload, container);

        // A form without a banner, or a stacked editor saving another entry.
        if (!container?.reference || reference !== container.reference) {
            return;
        }

        const ctx = registry.context(BASE);
        const records = editor.saved(BASE, now());
        // `localizedFields` is what the save posted as `_localized`; core keeps it after a save.
        const delivered = ctx?.reference.value === reference ? savedFills(ctx, records) : Object.keys(records);

        // Core's request step has already put the response's values into the form (SavePipeline's `Request`, before the
        // `*.saved` hooks); with revisions core sets them again after the hooks. No later refresh: text typed after the
        // save is an edit, which a translation must leave alone, and a fieldtype that re-emits the saved values with
        // other text reads as edited until the next switch (a field that keeps inheriting, again after each save), so
        // its field is skipped rather than overwritten.
        registry.refreshClean(BASE);

        // What was filled is saved now; the summary and Undo no longer apply.
        if (container.phase === 'done' || container.phase === 'error') {
            editor.clearResult(BASE);
        }

        try {
            const { not_saved: notSaved = [], ...status } = await api.post('editor/confirm', { reference, delivered });
            const latest = editor.containers[BASE];

            if (latest?.reference === reference) {
                latest.status = status;
                latest.notSaved = notSaved;
                // Not awaited: the save hooks would wait for an automatic update to start.
                onConfirmed(BASE, status);
            }

            if (notSaved.length) {
                Statamic.$toast.error(notSavedMessage(notSaved, status), { duration: 10_000 });
            }
        } catch (error) {
            if (error?.status !== 403) {
                Statamic.$toast.error(__('The translation status could not be updated: :message', { message: error?.message ?? '' }));
            }
        }
    }

    // Publish, offered only in a collection with revisions, makes the working copy the entry without the save hooks:
    // core's PublishActions runs `entry.published` after its request. The origin's localizations now read outdated
    // against it, the publish starts an automatic update (T11.1), and a localization's update stops waiting in the
    // working copy. Publish posts no form values (core offers it only for a clean form), so there is nothing to confirm.
    // Resolves at once: core finishes the publish, or leaves for the listing, only after these hooks, and a rejection
    // would leave its form saving.
    function published(resolve, reject, payload) {
        try {
            discardTranslation(payload);
        } catch (error) {
            console.error(error);
        }

        afterPublish(payload).catch((error) => console.error(error));
        resolve();
    }

    // After these hooks core puts the published values into the form ("continue editing") but keeps its localized
    // fields and meta. Its Publish dialog stays open while a translation that started before fills the form, so what
    // was filled would be replaced while staying localized and delivered: the banner would offer Undo for text the form
    // no longer shows, and the next save would store the stored text (an inherited field's is the origin's) as the
    // localization's own and confirm it as translated. Not in `entry.publishing`: a publish that fails would stop the
    // translation for nothing, and a fill being read back when its translation stops drops its record.
    function discardTranslation(payload) {
        const editor = store();
        const container = editor.containers[BASE];

        if (!container?.reference || savedReference(payload, container) !== container.reference) {
            return;
        }

        const phase = container.phase;
        const translating = BUSY.includes(phase) && Boolean(running(BASE));
        const handles = Object.keys(container.delivered);
        const ctx = registry.context(BASE);

        stop(BASE);

        if (ctx && handles.length) {
            takeBack(ctx, container.snapshot, handles);
        }

        // The form holds the stored values again, and core's dirty tracking is off for a while, as after a save.
        editor.saved(BASE, now());

        if (handles.length || !['idle', 'offer'].includes(phase)) {
            editor.clearResult(BASE);
        }

        if (handles.length || translating) {
            Statamic.$toast.info(__('Publishing discarded the unsaved translation. Translate again to fill in the form.'));
        }
    }

    async function afterPublish(payload) {
        const container = store().containers[BASE];
        const reference = savedReference(payload, container);

        // A form without a banner, or a stacked editor publishing another entry.
        if (!container?.reference || reference !== container.reference) {
            return;
        }

        try {
            const status = await api.get('editor/status', { reference });
            const latest = store().containers[BASE];

            if (latest?.reference === reference) {
                latest.status = status;
                onConfirmed(BASE, status, { published: true });
            }
        } catch (error) {
            if (error?.status !== 403) {
                Statamic.$toast.error(__('The translation status could not be updated: :message', { message: error?.message ?? '' }));
            }
        }
    }

    return { listen, saving, saved, published };
}

// The response names the saved entry. A term's response has only its id, which is the same in every site, so the term
// open in the form is the one saved when its reference has that id. The hook's own `reference` is the one the form was
// opened with, also after an in-place site switch.
function savedReference(payload, container) {
    const saved = payload?.response?.data?.data;

    if (typeof saved?.reference === 'string') {
        return saved.reference;
    }

    if (typeof saved?.id === 'string' && container?.reference?.startsWith(`term::${saved.id}::`)) {
        return container.reference;
    }

    return payload?.reference ?? null;
}
