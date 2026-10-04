import * as defaultApi from '../api.js';
import { SETTLE_MS } from './fill.js';
import { BASE, registry as sharedRegistry } from './registry.js';
import { fieldLabels } from './statuses.js';
import { useEditorStore } from './store.js';
import { BUSY } from './translate.js';

export function notSavedMessage(handles, status) {
    return __n(
        ':fields was not saved because it was hidden when you saved. Show it and save again.|:fields were not saved because they were hidden when you saved. Show them and save again.',
        handles.length,
        { fields: fieldLabels(handles, status) },
    );
}

// The entry form's save hooks. `entry.saving` refuses a save while a translation fills the form: the save response
// replaces the form's values, and a field filled in between would stay localized and be stored in the origin's
// language on the next save (T2.2 check 12). `entry.saved` tells the server which fields were saved (`editor/confirm`).
// `PipelineStopped` comes from `@statamic/cms/save-pipeline`; core stays silent for a save stopped with it.
export function createSaveHooks({
    PipelineStopped,
    store = useEditorStore,
    registry = sharedRegistry,
    api = defaultApi,
    now = Date.now,
    settleMs = SETTLE_MS,
}) {
    let listening = false;

    function listen(hooks = Statamic.$hooks) {
        if (listening) {
            return;
        }

        listening = true;
        hooks.on('entry.saving', saving);
        hooks.on('entry.saved', saved);
    }

    // Hook callbacks get `(resolve, reject, payload)`. A stacked editor saves through the same hook; it is refused too
    // while the page's translation runs.
    function saving(resolve, reject) {
        if (BUSY.includes(store().phaseOf(BASE))) {
            Statamic.$toast.error(__('A translation is in progress. Wait for it to finish, or cancel it.'));
            reject(new PipelineStopped());

            return;
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
        // The response names the saved entry. The hook's own `reference` is the one the form was opened with, also after
        // an in-place site switch.
        const reference = payload?.response?.data?.data?.reference ?? payload?.reference ?? null;

        // A form without a banner, or a stacked editor saving another entry.
        if (!container?.reference || reference !== container.reference) {
            return;
        }

        const at = now();
        const delivered = editor.saved(BASE, at);

        registry.refreshClean(BASE);

        // Fieldtypes that re-emit a normalised value after the save would otherwise count as edited.
        setTimeout(() => {
            const latest = editor.containers[BASE];

            if (latest?.lastChangeAt === at && latest.reference === reference) {
                registry.refreshClean(BASE);
            }
        }, settleMs);

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

    return { listen, saving, saved };
}
