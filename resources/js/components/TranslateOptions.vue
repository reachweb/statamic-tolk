<script setup>
import { computed, ref } from 'vue';
import { Button, Checkbox, Combobox, Description, Field, Heading, Modal, Text } from '@statamic/cms/ui';
import { isTermReference } from '../editor/banner.js';
import { offersEnabled } from '../editor/offer.js';
import { initialModel, modelOptions, options, rememberedModel, REVIEWER_PREFERENCE, stateActions } from '../editor/options.js';
import { useEditorStore } from '../editor/store.js';
import { reviewable } from '../editor/translate.js';
import { createModal } from '../modal.js';

const props = defineProps({
    containerName: { type: String, required: true },
    status: { type: Object, default: null },
});

const store = useEditorStore();
const term = computed(() => isTermReference(store.containers[props.containerName]?.reference));
const modal = createModal();
const loading = ref(false);
const failure = ref(null);
const models = ref(null);
const model = ref(null);
const reviewer = ref(null);
const reviewAfter = ref(false);
const retranslate = ref(false);
const overwriteManual = ref(false);
const offering = ref(true);
const changingState = ref(false);

// Users who may edit the settings may also use a model outside the catalog (§A14).
const mayType = Boolean(Statamic.$config.get('tolk.settingsUrl'));

const defaultModel = computed(() => models.value?.defaults?.translator ?? props.status?.defaults?.translator ?? null);
const defaultReviewer = computed(() => models.value?.defaults?.reviewer ?? null);
const remembered = computed(() => rememberedModel());
const rememberedReviewer = computed(() => rememberedModel(Statamic.$preferences, REVIEWER_PREFERENCE));
// The catalog's models, plus the default, the remembered and the chosen model for users who may type one.
function choicesFor(defaultChoice, rememberedChoice, current) {
    return modelOptions(models.value?.catalog, {
        fakeEngine: props.status?.defaults?.fake_engine === true,
        defaultModel: defaultChoice,
        extra: mayType ? [defaultChoice, rememberedChoice, current] : [defaultChoice],
    });
}

const choices = computed(() => choicesFor(defaultModel.value, remembered.value, model.value));
const reviewerChoices = computed(() => choicesFor(defaultReviewer.value, rememberedReviewer.value, reviewer.value));
// Review has work while the form holds machine output (T7.5).
const canReview = computed(() => reviewable(store.containers[props.containerName]));
const actions = computed(() => stateActions(props.status));
const offerSetting = computed(() => props.status?.offer === true);

async function show() {
    retranslate.value = false;
    overwriteManual.value = false;
    reviewAfter.value = props.status?.defaults?.review === true;
    failure.value = null;
    offering.value = offersEnabled();
    modal.show();
    loading.value = true;

    try {
        models.value = await options.loadModels();
    } catch (error) {
        failure.value = error.message;
    } finally {
        loading.value = false;
    }

    model.value = initialModel(choices.value, { remembered: remembered.value, defaultModel: defaultModel.value });
    reviewer.value = initialModel(reviewerChoices.value, { remembered: rememberedReviewer.value, defaultModel: defaultReviewer.value });
}

function translate() {
    modal.hide();
    options.translate(props.containerName, {
        model: model.value,
        defaultModel: defaultModel.value,
        retranslate: retranslate.value,
        overwriteManual: overwriteManual.value,
        review: reviewAfter.value,
        reviewer: reviewer.value,
        defaultReviewer: defaultReviewer.value,
    });
}

function review() {
    modal.hide();
    options.review(props.containerName, {
        reviewer: reviewer.value,
        defaultReviewer: defaultReviewer.value,
        overwriteManual: overwriteManual.value,
    });
}

async function changeState(protect) {
    changingState.value = true;

    try {
        if ((await options.baseline(props.containerName, { protect })) !== null) {
            modal.hide();
        }
    } finally {
        changingState.value = false;
    }
}

async function toggleOffers(enabled) {
    offering.value = enabled;

    if (!(await options.setOffers(enabled))) {
        offering.value = !enabled;
    }
}
</script>

<template>
    <Button size="sm" variant="ghost" icon="sliders-horizontal" :text="__('Options')" data-tolk-action="options" @click="show" />

    <Modal
        v-if="modal.open"
        open
        :title="__('Translation options')"
        icon="sliders-horizontal"
        data-tolk-options
        @dismissed="modal.dismissed()"
        @update:open="modal.closed($event)"
    >
        <div class="space-y-6">
            <div class="space-y-4">
                <Field
                    id="tolk-options-model"
                    :label="__('Model')"
                    :instructions="mayType ? __('You may also type a model outside the list, as provider:model.') : ''"
                >
                    <Combobox
                        id="tolk-options-model"
                        v-model="model"
                        :options="choices"
                        :taggable="mayType"
                        :disabled="loading"
                        :placeholder="loading ? __('Loading…') : __('Choose a model')"
                        data-tolk-options-model
                    />
                </Field>
                <Text v-if="failure" variant="danger" data-tolk-options-failure>
                    {{ __('The models could not be loaded: :message', { message: failure }) }}
                </Text>
                <Checkbox
                    v-model="retranslate"
                    :label="__('Retranslate everything')"
                    :description="__('Also translates the fields that are up to date again.')"
                />
                <Checkbox v-model="overwriteManual" :label="__('Overwrite manual edits')" :description="__('Also replaces the text of fields edited by hand.')" />
            </div>

            <div class="space-y-4" data-tolk-options-review>
                <Checkbox
                    v-model="reviewAfter"
                    :label="__('Review after translating')"
                    :description="__('A second model checks the translation and corrects it. Review checks the translations in the form without translating.')"
                />
                <Field id="tolk-options-reviewer" :label="__('Reviewer')">
                    <Combobox
                        id="tolk-options-reviewer"
                        v-model="reviewer"
                        :options="reviewerChoices"
                        :taggable="mayType"
                        :disabled="loading"
                        :placeholder="loading ? __('Loading…') : __('Choose a model')"
                        data-tolk-options-reviewer
                    />
                </Field>
            </div>

            <div v-if="actions.markCurrent || actions.protect" class="space-y-3" data-tolk-options-state>
                <Heading size="sm" :text="__('Translation status')" />
                <div v-if="actions.markCurrent" class="flex items-start justify-between gap-4">
                    <Description>
                        {{ __('Records that the fields edited by hand match the current source, so a later change to the source is flagged.') }}
                    </Description>
                    <Button
                        size="sm"
                        class="shrink-0"
                        :text="__('Mark manual fields as up to date')"
                        :disabled="changingState"
                        data-tolk-action="mark-current"
                        @click="changeState(false)"
                    />
                </div>
                <div v-if="actions.protect" class="flex items-start justify-between gap-4">
                    <Description>{{ __('Treats the translated fields as edited by hand, so Translate and Update leave them alone.') }}</Description>
                    <Button
                        size="sm"
                        class="shrink-0"
                        :text="term ? __('Protect this term from updates') : __('Protect this entry from updates')"
                        :disabled="changingState"
                        data-tolk-action="protect"
                        @click="changeState(true)"
                    />
                </div>
            </div>

            <Checkbox
                v-if="offerSetting && !term"
                :model-value="offering"
                :label="__('Offer to translate new localizations')"
                :description="__('Asks whether to translate a localization you create in the editor. This applies to your account only.')"
                @update:model-value="toggleOffers"
            />
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                <Button variant="ghost" :text="__('Close')" data-tolk-options-action="close" @click="modal.hide()" />
                <Button v-if="canReview" :text="__('Review')" :disabled="loading || !reviewer" data-tolk-options-action="review" @click="review" />
                <Button variant="primary" :text="__('Translate')" :disabled="loading || !model" data-tolk-options-action="translate" @click="translate" />
            </div>
        </template>
    </Modal>
</template>
