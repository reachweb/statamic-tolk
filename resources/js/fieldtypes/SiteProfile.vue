<script setup>
import { computed, reactive, ref } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Button, Checkbox, ConfirmationModal, Description, Text, Textarea, injectPublishContext } from '@statamic/cms/ui';
import { asMap } from '../api.js';
import { GLOSSARY, appendGlossaryRows, generateProfile, hasText, termRows } from './siteProfile.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, isReadOnly, update } = Fieldtype.use(emit, props);
const publish = injectPublishContext();

const generating = ref(false);
const confirming = ref(false);
const error = ref(null);
const written = ref(null);
const added = ref(null);
const skipped = reactive({});

const values = computed(() => asMap(publish?.values.value));
const canAddToGlossary = computed(
    () => !isReadOnly.value && Object.hasOwn(values.value, GLOSSARY) && Object.hasOwn(asMap(publish?.meta.value), GLOSSARY),
);
const terms = computed(() => termRows(written.value?.terms, values.value[GLOSSARY], skipped));
const chosen = computed(() => terms.value.filter((row) => row.chosen).map((row) => row.term));

function requestGenerate() {
    if (hasText(props.value)) {
        confirming.value = true;
    } else {
        generate();
    }
}

async function generate() {
    confirming.value = false;
    generating.value = true;
    error.value = null;

    try {
        const answer = await generateProfile(values.value);

        update(answer.profile);
        written.value = { label: answer.label || answer.model, terms: Array.isArray(answer.terms) ? answer.terms : [] };
        added.value = null;
        Object.keys(skipped).forEach((term) => delete skipped[term]);
    } catch (failure) {
        error.value = failure.message;
    } finally {
        generating.value = false;
    }
}

function choose(term, checked) {
    skipped[term] = !checked;
}

function addToGlossary() {
    const result = appendGlossaryRows(chosen.value, values.value[GLOSSARY], publish.meta.value[GLOSSARY]);

    publish.setFieldMeta(GLOSSARY, result.meta);
    publish.setFieldValue(GLOSSARY, result.rows);
    added.value = result.added.length;
}

defineExpose(expose);
</script>

<template>
    <div class="space-y-3" data-tolk-site-profile>
        <Textarea :id="id" :name="handle" :model-value="value" :read-only="isReadOnly" :rows="8" @update:model-value="update" />

        <div v-if="!isReadOnly" class="flex flex-wrap items-center gap-3">
            <Button
                size="sm"
                icon="ai-sparks"
                :text="__('Generate')"
                :loading="generating"
                :disabled="generating"
                data-tolk-site-profile-generate
                @click="requestGenerate"
            />
            <Description>
                {{ __("Has the translator write a profile from the content of the default site. Check it before you save.") }}
            </Description>
        </div>

        <Text v-if="error" variant="danger" data-tolk-site-profile-error>{{ error }}</Text>
        <Description v-if="written" data-tolk-site-profile-written>
            {{ __('Written by :model. It is not saved until you save the settings.', { model: written.label }) }}
        </Description>

        <div v-if="terms.length" class="space-y-2" data-tolk-site-profile-terms>
            <Text variant="strong">{{ __('Names to keep untranslated') }}</Text>

            <template v-if="canAddToGlossary">
                <div class="space-y-1">
                    <div v-for="row in terms" :key="row.term" :data-tolk-site-profile-term="row.term">
                        <Checkbox
                            :model-value="row.chosen"
                            :label="row.term"
                            :description="row.inGlossary ? __('Already in the glossary') : null"
                            :disabled="row.inGlossary"
                            @update:model-value="choose(row.term, $event)"
                        />
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        size="sm"
                        :text="__('Add to glossary')"
                        :disabled="chosen.length === 0"
                        data-tolk-site-profile-add
                        @click="addToGlossary"
                    />
                    <Description v-if="added !== null" data-tolk-site-profile-added>
                        {{
                            __n(
                                'Added :count name to the glossary with Do not translate on. Save the settings to keep it.|Added :count names to the glossary with Do not translate on. Save the settings to keep them.',
                                added,
                            )
                        }}
                    </Description>
                </div>
            </template>

            <template v-else>
                <ul class="list-disc ps-5 text-sm">
                    <li v-for="row in terms" :key="row.term" :data-tolk-site-profile-term="row.term">{{ row.term }}</li>
                </ul>
                <Description>{{ __('Add them to the glossary with Do not translate on, so that every translation keeps them.') }}</Description>
            </template>
        </div>

        <!-- Mounted afresh each time: a modal reopened while its last close is pending (300 ms) closes again. -->
        <ConfirmationModal
            v-if="confirming"
            open
            @update:open="confirming = $event"
            :title="__('Replace the site profile?')"
            :body-text="__('A new profile replaces the text in this field. Nothing is saved until you save the settings.')"
            :button-text="__('Generate')"
            @confirm="generate"
        />
    </div>
</template>
