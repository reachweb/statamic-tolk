<script setup>
import { computed, onBeforeUnmount } from 'vue';
import { Badge, Button, Card, Description, Icon, Text, injectPublishContext } from '@statamic/cms/ui';
import ProgressBar from './ProgressBar.vue';
import { attachBanner } from '../editor/banner.js';
import { notSavedMessage } from '../editor/saveHooks.js';
import { describe, describeSummary, fieldLabels } from '../editor/statuses.js';
import { useEditorStore } from '../editor/store.js';
import { RESTART, translations, undoable } from '../editor/translate.js';

const props = defineProps({
    containerName: { type: String, required: true },
});

const ctx = injectPublishContext();
const store = useEditorStore();
const name = props.containerName;
const { failure, stop } = attachBanner({ name, ctx, store, onSwitch: translations.abort });

onBeforeUnmount(stop);

const state = computed(() => store.containers[name] ?? null);
const phase = computed(() => state.value?.phase ?? 'idle');
const banner = computed(() => describe(state.value?.status ?? null));
const summary = computed(() => describeSummary(state.value?.summary ?? null, state.value?.status ?? null));
const canUndo = computed(() => undoable(state.value));
const restart = computed(() => RESTART.includes(state.value?.error?.code));
const notSaved = computed(() => (state.value?.notSaved?.length ? notSavedMessage(state.value.notSaved, state.value.status) : null));
const actions = computed(() => phase.value === 'idle' && (banner.value.localization?.translate || banner.value.localization?.update));
const shown = computed(() => !ctx.readOnly.value && (banner.value.visible || failure.value !== null));
const settingsUrl = Statamic.$config.get('tolk.settingsUrl');

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
        Statamic.$toast.info(
            __n(
                ':fields was changed after the translation and was kept.|:fields were changed after the translation and were kept.',
                result.kept.length,
                { fields: fieldLabels(result.kept, state.value?.status) },
            ),
        );
    }
}
</script>

<template>
    <Card v-if="shown" class="mb-6 space-y-3" data-tolk-banner>
        <div v-if="failure" class="flex items-center gap-2">
            <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" />
            <Text variant="danger">{{ __('The translation status could not be loaded: :message', { message: failure.message }) }}</Text>
        </div>

        <template v-else>
            <div v-if="banner.localization" class="space-y-2" data-tolk-localization>
                <div class="flex flex-wrap items-center gap-2">
                    <Icon name="dictionary-language-book" class="size-4 shrink-0 text-gray-500" />
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
                <Description v-if="banner.localization.hint">{{ banner.localization.hint }}</Description>

                <div v-if="actions" class="flex flex-wrap gap-2 pt-1" data-tolk-actions>
                    <Button v-if="banner.localization.translate" variant="primary" size="sm" data-tolk-action="translate" @click="translate('missing')">
                        {{ __('Translate') }}
                    </Button>
                    <Button v-if="banner.localization.update" size="sm" data-tolk-action="update" @click="translate('update')">
                        {{ __('Update') }}
                    </Button>
                </div>
            </div>

            <div v-if="phase === 'planning'" class="flex items-center gap-2" data-tolk-phase="planning">
                <Text>{{ __('Preparing the translation…') }}</Text>
            </div>

            <div v-if="phase === 'working'" class="space-y-2" data-tolk-phase="working">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Text v-if="state.progress.cancelling">{{ __('Cancelling. Fields already on their way are still filled in.') }}</Text>
                    <Text v-else>{{ __('Translating into :site…', { site: banner.localization?.target?.name ?? '' }) }}</Text>
                    <Button size="sm" variant="ghost" :disabled="state.progress.cancelling" data-tolk-action="cancel" @click="translations.cancel(name)">
                        {{ __('Cancel') }}
                    </Button>
                </div>
                <ProgressBar :value="state.progress.done" :max="state.progress.total" :label="__('Translation progress')" />
                <Description v-if="state.progress.waiting">{{ __('The provider asked to wait. Trying again shortly.') }}</Description>
            </div>

            <div v-if="phase === 'error'" class="space-y-1" data-tolk-phase="error">
                <div class="flex items-center gap-2">
                    <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" />
                    <Text variant="danger" data-tolk-error>{{ state.error?.message }}</Text>
                </div>
            </div>

            <div v-if="summary && (phase === 'done' || phase === 'error')" class="space-y-1" data-tolk-summary>
                <Text data-tolk-summary-translated>{{ summary.translated }}</Text>
                <Description v-if="summary.cancelled">{{ summary.cancelled }}</Description>
                <Description v-if="summary.incomplete" data-tolk-summary-incomplete>{{ summary.incomplete }}</Description>
                <Description v-if="summary.edited" data-tolk-summary-edited>{{ summary.edited }}</Description>
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
                <Icon name="warning-diamond" class="size-4 shrink-0 text-amber-600" />
                <Text>{{ notSaved }}</Text>
            </div>

            <div v-if="banner.origin" class="space-y-2" data-tolk-origin>
                <div class="flex items-center gap-2">
                    <Icon name="dictionary-language-book" class="size-4 shrink-0 text-gray-500" />
                    <Text>{{ __('Translations of this entry:') }}</Text>
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
                        <Text v-if="localization.exists" variant="subtle">{{ localization.summary }}</Text>
                    </li>
                </ul>
            </div>

            <Description v-if="banner.setup" data-tolk-setup>
                {{ __('Translation is not set up yet.') }}
                <a v-if="settingsUrl" :href="settingsUrl">{{ __('Open the Tolk settings') }}</a>
                <template v-else>{{ __('Ask an administrator to configure a provider in the Tolk settings.') }}</template>
            </Description>
        </template>
    </Card>
</template>
