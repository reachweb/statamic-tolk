<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Badge, Button, Card, Description, Icon, Text, injectPublishContext } from '@statamic/cms/ui';
import ChangesModal from './ChangesModal.vue';
import OriginPush from './OriginPush.vue';
import ProgressBar from './ProgressBar.vue';
import TranslateOffer from './TranslateOffer.vue';
import TranslateOptions from './TranslateOptions.vue';
import { localizableUrl } from '../audit/checks.js';
import { attachBanner, isTermReference } from '../editor/banner.js';
import { registry } from '../editor/registry.js';
import { notSavedMessage } from '../editor/saveHooks.js';
import { describe, describeSummary, fieldLabels } from '../editor/statuses.js';
import { useEditorStore } from '../editor/store.js';
import { keptMessage, RESTART, reviewable, translations, undoable } from '../editor/translate.js';
import { BANNER_MODALS, createFocusKeeper } from '../focus.js';
import { createModal } from '../modal.js';

const props = defineProps({
    containerName: { type: String, required: true },
});

const ctx = injectPublishContext();
const store = useEditorStore();
const name = props.containerName;
const { failure, stop } = attachBanner({ name, ctx, store });

onBeforeUnmount(stop);

const state = computed(() => store.containers[name] ?? null);
const phase = computed(() => state.value?.phase ?? 'idle');
const auditLink = localizableUrl(Statamic.$config.get('tolk.auditUrl'));
const banner = computed(() => describe(state.value?.status ?? null));
const summary = computed(() => describeSummary(state.value?.summary ?? null, state.value?.status ?? null));
const canUndo = computed(() => undoable(state.value));
const restart = computed(() => RESTART.includes(state.value?.error?.code));
const notSaved = computed(() => (state.value?.notSaved?.length ? notSavedMessage(state.value.notSaved, state.value.status) : null));
// The options are there whenever a model is: "Retranslate everything" and the state actions also have work on an entry that
// is up to date. After a translation the counts are those of the stored entry until Save, so only Review and the options
// stay, for a review of the filled form with the same reviewer or another one (D8).
const actions = computed(() => ['idle', 'done'].includes(phase.value) && Boolean(banner.value.localization) && !banner.value.setup);
const canReview = computed(() => reviewable(state.value));
// A translation of one field, such as the field action's, names it.
const workingField = computed(() => {
    const request = state.value?.request;

    return request?.kind === 'translate' && request.fields?.length === 1 ? fieldLabels(request.fields, state.value.status) : null;
});
const working = computed(() => {
    const site = banner.value.localization?.target?.name ?? '';

    if (state.value?.progress?.cancelling) {
        return __('Cancelling. Fields already on their way are still filled in.');
    }

    if (state.value?.request?.kind === 'review') {
        return __('Reviewing the translation…');
    }

    return workingField.value ? __('Translating :field into :site…', { field: workingField.value, site }) : __('Translating into :site…', { site });
});
// What a screen reader announces as the banner's work moves on; the banner itself changes too much to be a live region.
const announcement = computed(() => {
    switch (phase.value) {
        case 'planning':
            return __('Preparing the translation…');
        case 'working':
            return working.value;
        case 'error':
            return state.value?.error?.message ?? '';
        case 'done':
            return [summary.value?.translated, summary.value?.reviewed].filter(Boolean).join(' ');
        default:
            return '';
    }
});
const changes = createModal();

// The list belongs to one result: a new translation or review closes it.
watch(
    () => state.value?.summary,
    () => changes.hide(),
);
const shown = computed(() => !ctx.readOnly.value && (banner.value.visible || failure.value !== null));
// Text the editor typed and has not saved, as the translations decide it (D27). Core's dirty flag is no guide: fieldtypes
// re-emit normalised values after its 500 ms window, so it flags most forms right after they load.
const dirty = computed(() => registry.dirtyHandles(name).length > 0);
const settingsUrl = Statamic.$config.get('tolk.settingsUrl');
const term = computed(() => isTermReference(state.value?.reference));
const card = ref(null);
const focus = createFocusKeeper(() => card.value?.$el ?? null, { modals: BANNER_MODALS });

// Translate, Update, Retry and Translate into… leave with the phase they start.
watch(
    () => [phase.value, state.value?.push?.phase],
    () => focus.changing(),
);

const colors = {
    untranslated: 'amber',
    outdated: 'orange',
    manual: 'sky',
    attention: 'red',
    untracked: 'default',
    current: 'green',
};

function translate(mode) {
    translations.translate(name, { mode });
}

async function undo() {
    const result = await translations.undo(name);

    if (result?.kept.length) {
        Statamic.$toast.info(keptMessage(result.kept, state.value?.status));
    }
}
</script>

<template>
    <Card v-if="shown" ref="card" class="mb-6 space-y-3" data-tolk-banner>
        <p class="sr-only" aria-live="polite" data-tolk-announcement>{{ announcement }}</p>

        <div v-if="failure" class="flex items-center gap-2">
            <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" aria-hidden="true" />
            <Text variant="danger">{{ __('The translation status could not be loaded: :message', { message: failure.message }) }}</Text>
        </div>

        <template v-else>
            <div v-if="banner.localization" class="space-y-2" data-tolk-localization>
                <div class="flex flex-wrap items-center gap-2">
                    <Icon name="dictionary-language-book" class="size-4 shrink-0 text-gray-500" aria-hidden="true" />
                    <Text>{{ banner.localization.lead }}</Text>
                    <Badge
                        v-for="part in banner.localization.parts"
                        :key="part.key"
                        v-tooltip="part.description"
                        :text="part.text"
                        :color="colors[part.key]"
                        tabindex="0"
                        :data-tolk-part="part.key"
                    />
                </div>
                <Description v-if="banner.localization.incompleteNote">{{ banner.localization.incompleteNote }}</Description>
                <Description v-if="banner.localization.hint" data-tolk-not-localizable>
                    {{ banner.localization.hint }}
                    <a v-if="auditLink" :href="auditLink" data-tolk-audit-link>{{ __('Open the setup audit') }}</a>
                    <template v-else>{{ __('Run php please tolk:doctor for details.') }}</template>
                </Description>

                <div v-if="actions" class="flex flex-wrap gap-2 pt-1" data-tolk-actions>
                    <template v-if="phase === 'idle'">
                        <Button v-if="banner.localization.translate" variant="primary" size="sm" data-tolk-action="translate" @click="translate('missing')">
                            {{ __('Translate') }}
                        </Button>
                        <Button v-if="banner.localization.update" size="sm" data-tolk-action="update" @click="translate('update')">
                            {{ __('Update') }}
                        </Button>
                    </template>
                    <Button v-if="canReview" size="sm" data-tolk-action="review" @click="translations.review(name)">
                        {{ __('Review') }}
                    </Button>
                    <TranslateOptions :container-name="name" :status="state.status" />
                </div>
            </div>

            <div v-if="phase === 'planning'" class="flex items-center gap-2" data-tolk-phase="planning">
                <Text>{{ __('Preparing the translation…') }}</Text>
            </div>

            <div v-if="phase === 'working'" class="space-y-2" data-tolk-phase="working">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Text :data-tolk-working-field="workingField ? '' : null">{{ working }}</Text>
                    <Button size="sm" variant="ghost" :disabled="state.progress.cancelling" data-tolk-action="cancel" @click="translations.cancel(name)">
                        {{ __('Cancel') }}
                    </Button>
                </div>
                <ProgressBar
                    :value="state.progress.done"
                    :max="state.progress.total"
                    :label="state.request?.kind === 'review' ? __('Review progress') : __('Translation progress')"
                />
                <Description v-if="state.progress.waiting">{{ __('The provider asked to wait. Trying again shortly.') }}</Description>
            </div>

            <div v-if="phase === 'error'" class="space-y-1" data-tolk-phase="error">
                <div class="flex items-center gap-2">
                    <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" aria-hidden="true" />
                    <Text variant="danger" data-tolk-error>{{ state.error?.message }}</Text>
                </div>
            </div>

            <div v-if="summary && (phase === 'done' || phase === 'error')" class="space-y-1" data-tolk-summary>
                <Text v-if="summary.translated" data-tolk-summary-translated>{{ summary.translated }}</Text>
                <div v-if="summary.reviewed" class="flex flex-wrap items-center gap-2">
                    <Text data-tolk-summary-reviewed>{{ summary.reviewed }}</Text>
                    <Button v-if="summary.corrections" size="sm" variant="ghost" data-tolk-action="view-changes" @click="changes.show()">
                        {{ __('View changes') }}
                    </Button>
                </div>
                <Description v-if="summary.reviewSkipped" data-tolk-summary-review-skipped>{{ summary.reviewSkipped }}</Description>
                <Description v-if="summary.cancelled">{{ summary.cancelled }}</Description>
                <Description v-if="summary.incomplete" data-tolk-summary-incomplete>{{ summary.incomplete }}</Description>
                <Description v-if="summary.edited" data-tolk-summary-edited>{{ summary.edited }}</Description>
                <Description v-if="summary.manual" data-tolk-summary-manual>{{ summary.manual }}</Description>
                <Description v-if="summary.failed" data-tolk-summary-failed>{{ summary.failed }}</Description>
                <Description v-if="summary.unsaved" data-tolk-summary-unsaved>{{ summary.unsaved }}</Description>
            </div>

            <div v-if="phase === 'error' || (phase === 'done' && canUndo)" class="flex flex-wrap gap-2">
                <Button v-if="phase === 'error'" size="sm" variant="primary" data-tolk-action="retry" @click="translations.retry(name)">
                    {{ restart ? __('Start again') : __('Retry') }}
                </Button>
                <Button v-if="canUndo" size="sm" data-tolk-action="undo" @click="undo">
                    {{ __('Undo') }}
                </Button>
            </div>

            <div v-if="notSaved" class="flex items-center gap-2" data-tolk-not-saved>
                <Icon name="warning-diamond" class="size-4 shrink-0 text-amber-600" aria-hidden="true" />
                <Text>{{ notSaved }}</Text>
            </div>

            <div v-if="banner.origin" class="space-y-2" data-tolk-origin>
                <div class="flex items-center gap-2">
                    <Icon name="dictionary-language-book" class="size-4 shrink-0 text-gray-500" aria-hidden="true" />
                    <Text>{{ term ? __('Translations of this term:') : __('Translations of this entry:') }}</Text>
                </div>
                <ul class="space-y-1 ps-6">
                    <li
                        v-for="localization in banner.origin.localizations"
                        :key="localization.site"
                        class="flex flex-wrap items-center gap-2"
                        :data-tolk-site="localization.site"
                    >
                        <a v-if="localization.editUrl" :href="localization.editUrl" class="text-sm font-medium underline">{{ localization.name }}</a>
                        <Text v-else variant="strong">{{ localization.name }}</Text>
                        <Badge :text="localization.label" />
                        <Description v-if="localization.exists">{{ localization.summary }}</Description>
                    </li>
                </ul>
                <OriginPush
                    :container-name="name"
                    :reference="state.reference"
                    :origin="banner.origin"
                    :status="state.status"
                    :dirty="dirty"
                />
            </div>

            <TranslateOffer v-if="banner.localization" :container-name="name" :status="state.status" :open="phase === 'offer'" />
            <ChangesModal v-if="changes.open" :modal="changes" :corrections="state.summary?.corrections ?? []" :reviewer="state.summary?.reviewer ?? null" />

            <Description v-if="banner.setup" data-tolk-setup>
                {{ __('Translation is not set up yet.') }}
                <a v-if="settingsUrl" :href="settingsUrl">{{ __('Open the Tolk settings') }}</a>
                <template v-else>{{ __('Ask an administrator to configure a provider in the Tolk settings.') }}</template>
            </Description>
        </template>
    </Card>
</template>
