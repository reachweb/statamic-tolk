export const PHASES = ['idle', 'offer', 'planning', 'working', 'done', 'error'];

export function initialState() {
    return {
        // The item the container showed when the state was last reset; a remounted banner compares it with its own.
        reference: null,
        status: null,
        phase: 'idle',
        progress: { total: 0, done: 0, waiting: 0, cancelling: false },
        summary: null,
        snapshot: null,
        delivered: {},
        lastChangeAt: 0,
        planReference: null,
        error: null,
        // What the last translation asked for, so Retry and Start again can ask once more.
        request: null,
        // Delivered handles the last save did not store, from `editor/confirm`.
        notSaved: [],
        // The localization was created in this form (`localization.created`), so its slug is put back to the origin's
        // before the first translation fills it (T5.9).
        created: false,
        slugKept: false,
    };
}

let useStore = null;

// Defined on first use: `Statamic.$pinia` hands out core's `defineStore`, and a store can only be used once the CP app
// has installed Pinia.
export function useEditorStore() {
    useStore ??= Statamic.$pinia.defineStore('tolk-editor', {
        state: () => ({ containers: {} }),

        getters: {
            phaseOf: (state) => (name) => state.containers[name]?.phase ?? 'idle',
        },

        actions: {
            container(name) {
                this.containers[name] ??= initialState();

                return this.containers[name];
            },

            switched(name, reference, at = Date.now()) {
                this.containers[name] = { ...initialState(), reference, lastChangeAt: at };
            },

            // Returns the handles delivered since the previous save, for `editor/confirm`.
            saved(name, at = Date.now()) {
                const container = this.container(name);
                const delivered = Object.keys(container.delivered);

                container.lastChangeAt = Math.max(container.lastChangeAt, at);
                container.delivered = {};

                return delivered;
            },

            setPhase(name, phase, error = null) {
                if (!PHASES.includes(phase)) {
                    throw new Error(`Unknown editor phase [${phase}].`);
                }

                const container = this.container(name);

                container.phase = phase;
                container.error = phase === 'error' ? error : null;
            },

            forget(name) {
                delete this.containers[name];
            },
        },
    });

    return useStore();
}
