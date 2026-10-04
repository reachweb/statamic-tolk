import { ref, watch } from 'vue';
import * as api from '../api.js';
import { SETTLE_MS } from './fill.js';
import { registry as sharedRegistry } from './registry.js';
import { useEditorStore } from './store.js';

export const BANNER = 'tolk-banner';

// Only the full-page form of a saved entry gets a banner. A stacked inline editor is named
// `relate-fieldtype-inline-{n}` and also shows `entry::` references; the create page has no reference yet.
export function shouldPush(payload) {
    const reference = payload?.reference?.value;

    return (
        payload?.name === 'base' &&
        typeof reference === 'string' &&
        reference.startsWith('entry::') &&
        Statamic.$config.get('tolk.multisite') === true &&
        Statamic.$permissions.has('translate content')
    );
}

export function createBanners({ store = useEditorStore } = {}) {
    // Every container of a name, oldest first, with the banner pushed into it or null. A container that replaces
    // another of the same name can be created before the old one is destroyed, and the destroy event only names it.
    const containers = new Map();
    let listening = false;

    function listen(events = Statamic.$events) {
        if (listening) {
            return;
        }

        listening = true;
        events.$on('publish-container-created', created);
        events.$on('publish-container-destroyed', destroyed);
    }

    function created(payload) {
        let banner = null;

        if (shouldPush(payload)) {
            // A new container starts afresh; only a banner re-mounted inside the same container keeps the state.
            store().forget(payload.name);
            banner = payload.pushComponent(BANNER, { props: { containerName: payload.name } });
        }

        containers.set(payload.name, [...(containers.get(payload.name) ?? []), banner]);
    }

    function destroyed({ name }) {
        const [banner, ...rest] = containers.get(name) ?? [];

        banner?.destroy();

        if (rest.length) {
            containers.set(name, rest);
        } else {
            containers.delete(name);
        }
    }

    return {
        listen,
        pushed: (name) => (containers.get(name) ?? []).filter(Boolean).length,
    };
}

export const banners = createBanners();

// Connects a mounted banner to its container. Live Preview mounts a new banner each time it opens or closes, in the
// same container, so everything lives in the store and the registry: a mount that finds the store on the same item
// only re-attaches the context. A site or entry switch happens in place and is seen by the watcher alone.
export function attachBanner({
    name,
    ctx,
    store = useEditorStore(),
    registry = sharedRegistry,
    loadStatus = (reference) => api.get('editor/status', { reference }),
    now = Date.now,
    settleMs = SETTLE_MS,
}) {
    const failure = ref(null);
    const remount = Boolean(ctx.reference.value) && store.container(name).reference === ctx.reference.value;

    registry.attachContext(name, ctx);

    const current = (reference) => ctx.reference.value === reference && store.containers[name]?.reference === reference;

    async function load(reference) {
        failure.value = null;

        if (ctx.readOnly?.value) {
            return;
        }

        try {
            const status = await loadStatus(reference);

            if (current(reference)) {
                store.container(name).status = status;
            }
        } catch (error) {
            // A user who may not edit the item gets 403; the banner then shows nothing.
            if (current(reference) && error?.status !== 403) {
                failure.value = error;
            }
        }
    }

    function switched(reference) {
        const at = now();

        store.switched(name, reference, at);
        registry.refreshClean(name);

        // Fieldtypes that re-emit a normalised value after the swap would otherwise count as edited. Not cancelled on
        // unmount, so a banner re-mounted in the meantime still gets it; a later switch or save makes it a no-op.
        setTimeout(() => {
            if (store.containers[name]?.lastChangeAt === at && current(reference)) {
                registry.refreshClean(name);
            }
        }, settleMs);

        load(reference);
    }

    const stop = watch(
        () => [ctx.reference.value, ctx.site.value],
        ([reference], previous) => {
            if (!reference) {
                return;
            }

            if (previous === undefined && remount) {
                if (store.container(name).status === null) {
                    load(reference);
                }

                return;
            }

            switched(reference);
        },
        { immediate: true, flush: 'post' },
    );

    return { failure, stop };
}
