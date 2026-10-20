<script setup>
import { computed, ref } from 'vue';
import { Badge, Button, Checkbox, Description, Field, Icon, Modal, Radio, RadioGroup, Text } from '@statamic/cms/ui';
import ProgressBar from './ProgressBar.vue';
import { isTermReference } from '../editor/banner.js';
import { describePush, PUSHING, pushChoices, pushes, pushLead, saveFirstNote, updateSites } from '../editor/push.js';
import { useEditorStore } from '../editor/store.js';
import { createModal } from '../modal.js';

const props = defineProps({
    containerName: { type: String, required: true },
    reference: { type: String, required: true },
    // `describe(status).origin`
    origin: { type: Object, required: true },
    status: { type: Object, default: null },
    // The run translates the saved origin (with revisions, the published one), not what is in the form.
    dirty: { type: Boolean, default: false },
});

const store = useEditorStore();
const push = computed(() => store.containers[props.containerName]?.push ?? null);
const shown = computed(() => describePush(push.value, props.status));
const pushing = computed(() => PUSHING.includes(push.value?.phase));
const buttons = computed(() => !pushing.value && (props.origin.translateInto || props.origin.update));
const choices = computed(() => pushChoices(props.origin));
const modal = createModal();
const selected = ref([]);
const newStatus = ref('draft');
const review = ref(false);
const creates = computed(() => choices.value.some((choice) => choice.missing && selected.value.includes(choice.site)));
const term = computed(() => isTermReference(props.reference));
const saveFirst = computed(() => saveFirstNote(props.origin, { dirty: props.dirty, term: term.value }));
const lead = computed(() => pushLead(props.origin, { term: term.value }));

function choose() {
    selected.value = choices.value.map((choice) => choice.site);
    newStatus.value = props.status?.defaults?.new_status === 'mirror' ? 'mirror' : 'draft';
    review.value = props.status?.defaults?.review === true;
    modal.show();
}

function toggle(site, checked) {
    selected.value = checked ? [...new Set([...selected.value, site])] : selected.value.filter((handle) => handle !== site);
}

function translateInto() {
    modal.hide();
    pushes.start(props.containerName, {
        reference: props.reference,
        sites: choices.value.map((choice) => choice.site).filter((site) => selected.value.includes(site)),
        mode: 'missing',
        review: review.value,
        newStatus: creates.value ? newStatus.value : null,
    });
}

// Review follows the setting, as the localization's Update does.
function update() {
    pushes.start(props.containerName, { reference: props.reference, sites: updateSites(props.origin), mode: 'update' });
}

const working = computed(() =>
    shown.value?.automatic
        ? __('Updating the translations automatically into :sites…', { sites: shown.value.sites })
        : __('Translating into :sites…', { sites: shown.value.sites }),
);

const updated = computed(() => __('The translations into :sites were updated automatically.', { sites: shown.value?.sites ?? '' }));

// What a screen reader announces as the run moves on.
const announcement = computed(() => {
    switch (push.value?.phase) {
        case 'starting':
            return __('Starting the translation…');
        case 'expecting':
            return working.value;
        case 'updated':
            return updated.value;
        case 'running':
            if (shown.value.failure) {
                return __('The translations stopped: :message', { message: shown.value.failure });
            }

            return shown.value.stalled ?? working.value;
        case 'error':
            return shown.value?.error ?? '';
        default:
            return shown.value?.result?.text ?? '';
    }
});

function reload() {
    window.location.reload();
}
</script>

<template>
    <div class="space-y-2 ps-6" data-tolk-push>
        <p class="sr-only" aria-live="polite" data-tolk-push-announcement>{{ announcement }}</p>
        <div v-if="buttons" class="flex flex-wrap items-center gap-2 pt-1" data-tolk-push-actions>
            <Button v-if="origin.translateInto" variant="primary" size="sm" :disabled="dirty" data-tolk-action="translate-into" @click="choose">
                {{ __('Translate into…') }}
            </Button>
            <Button v-if="origin.update" size="sm" :disabled="dirty" data-tolk-action="update-translations" @click="update">
                {{ __('Update translations') }}
            </Button>
            <Description v-if="saveFirst" data-tolk-save-first>{{ saveFirst }}</Description>
        </div>

        <Text v-if="push?.phase === 'starting'" as="p" data-tolk-push-phase="starting">{{ __('Starting the translation…') }}</Text>

        <Text v-if="push?.phase === 'expecting'" as="p" data-tolk-push-phase="expecting">{{ working }}</Text>

        <div v-if="push?.phase === 'updated'" class="flex flex-wrap items-center gap-2" data-tolk-push-phase="updated">
            <Text>{{ updated }}</Text>
            <Button size="sm" variant="ghost" data-tolk-action="push-close" @click="pushes.dismiss(containerName)">{{ __('Close') }}</Button>
        </div>

        <div v-if="push?.phase === 'running'" class="space-y-2" data-tolk-push-phase="running">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Text v-if="push.cancelling">{{ __('Cancelling. Translations already being saved are still saved.') }}</Text>
                <Text v-else data-tolk-push-working>{{ working }}</Text>
                <Button size="sm" variant="ghost" :disabled="push.cancelling" data-tolk-action="push-cancel" @click="pushes.cancel(containerName)">
                    {{ __('Cancel') }}
                </Button>
            </div>
            <ProgressBar :value="shown.progress.finished" :max="shown.progress.total" :label="__('Translation progress')" />
            <Description data-tolk-push-progress>
                {{ __(':finished of :total translations finished.', { finished: shown.progress.finished, total: shown.progress.total }) }}
            </Description>
            <Description v-if="shown.keepOpen">
                {{
                    term
                        ? __('Keep this page open until the translations are saved. If you leave, they continue when you open this term again.')
                        : __('Keep this page open until the translations are saved. If you leave, they continue when you open this entry again.')
                }}
            </Description>
            <div v-if="shown.stalled" class="flex flex-wrap items-center gap-2" data-tolk-push-stalled>
                <Icon name="warning-diamond" class="size-4 shrink-0 text-amber-600" aria-hidden="true" />
                <Text>{{ shown.stalled }}</Text>
                <Button
                    v-if="shown.takeOver"
                    size="sm"
                    :disabled="push.takingOver || push.cancelling"
                    :loading="push.takingOver"
                    data-tolk-action="push-take-over"
                    @click="pushes.takeOver(containerName)"
                >
                    {{ __('Translate in this page instead') }}
                </Button>
            </div>
            <div v-if="shown.failure" class="flex flex-wrap items-center gap-2" data-tolk-push-failure>
                <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" aria-hidden="true" />
                <Text variant="danger">{{ __('The translations stopped: :message', { message: shown.failure }) }}</Text>
                <Button size="sm" data-tolk-action="push-resume" @click="pushes.resume(containerName)">{{ __('Try again') }}</Button>
            </div>
        </div>

        <div v-if="shown?.result" class="space-y-2" data-tolk-push-result :data-tolk-push-status="shown.result.status">
            <Text as="p">{{ shown.result.text }}</Text>
            <Text v-if="shown.result.error" as="p" variant="danger">{{ shown.result.error }}</Text>
            <ul class="space-y-1">
                <li
                    v-for="item in shown.result.items"
                    :key="item.index"
                    class="flex flex-wrap items-center gap-2"
                    :data-tolk-push-item="item.handle"
                    :data-tolk-push-item-status="item.status"
                >
                    <a v-if="item.editUrl" :href="item.editUrl" class="text-sm font-medium underline">{{ item.site }}</a>
                    <Text v-else variant="strong">{{ item.site }}</Text>
                    <Badge :text="item.statusLabel" :color="item.statusColor" />
                    <Description v-if="item.error || item.reason">
                        {{ item.error ?? item.reason }}
                        <a v-if="item.rootPage" :href="item.rootPage.url" class="font-medium underline" data-tolk-root-page>{{
                            __('Open :title', { title: item.rootPage.title })
                        }}</a>
                    </Description>
                </li>
            </ul>
            <Description v-if="shown.reload">{{ __('Reload the page to see the new localizations in the Sites panel.') }}</Description>
            <div class="flex flex-wrap gap-2">
                <Button v-if="shown.reload" size="sm" variant="primary" data-tolk-action="reload" @click="reload">{{ __('Reload') }}</Button>
                <Button size="sm" variant="ghost" data-tolk-action="push-close" @click="pushes.dismiss(containerName)">{{ __('Close') }}</Button>
            </div>
        </div>

        <div v-if="push?.phase === 'error'" class="flex flex-wrap items-center gap-2" data-tolk-push-phase="error">
            <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" aria-hidden="true" />
            <Text variant="danger" data-tolk-push-error>{{ shown.error }}</Text>
            <Button size="sm" variant="ghost" data-tolk-action="push-close" @click="pushes.dismiss(containerName)">{{ __('Close') }}</Button>
        </div>

        <Modal
            v-if="modal.open"
            open
            :title="__('Translate into other languages')"
            icon="dictionary-language-book"
            data-tolk-push-modal
            @dismissed="modal.dismissed()"
            @update:open="modal.closed($event)"
        >
            <div class="space-y-6">
                <Text as="p">{{ lead }}</Text>
                <!-- Since Statamic 6.36 a checkbox's description has no colour of its own in dark mode, too faint to read there. -->
                <div class="space-y-3">
                    <div v-for="choice in choices" :key="choice.site" :data-tolk-push-site="choice.site">
                        <Checkbox
                            :model-value="selected.includes(choice.site)"
                            :label="choice.name"
                            :description="choice.summary"
                            class="dark:[&_p]:text-gray-400!"
                            @update:model-value="toggle(choice.site, $event)"
                        />
                    </div>
                </div>
                <Field v-if="creates" id="tolk-push-status" :label="__('New localizations')">
                    <RadioGroup v-model="newStatus" appearance="inline" data-tolk-push-new-status>
                        <Radio value="draft" :label="__('Drafts')" />
                        <Radio value="mirror" :label="__('Same as origin')" />
                    </RadioGroup>
                </Field>
                <Checkbox
                    v-model="review"
                    :label="__('Review after translating')"
                    :description="__('A second model checks each translation and corrects it before it is saved.')"
                    class="dark:[&_p]:text-gray-400!"
                />
            </div>

            <template #footer>
                <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                    <Button variant="ghost" :text="__('Cancel')" data-tolk-push-action="close" @click="modal.hide()" />
                    <Button variant="primary" :text="__('Translate')" :disabled="selected.length === 0" data-tolk-push-action="translate" @click="translateInto" />
                </div>
            </template>
        </Modal>
    </div>
</template>
