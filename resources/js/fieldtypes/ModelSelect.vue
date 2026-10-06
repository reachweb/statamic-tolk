<script setup>
import { computed } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Combobox } from '@statamic/cms/ui';
import { AUTOMATIC, chosen, selectOptions, stored } from './modelSelect.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, isReadOnly, update } = Fieldtype.use(emit, props);

const options = computed(() => selectOptions(props.meta, props.value));
const selected = computed(() => stored(props.value) ?? AUTOMATIC);

function select(choice) {
    const value = chosen(choice, options.value);

    // Unchanged values are not emitted, so opening the settings never marks the form as changed.
    if (value !== undefined && value !== stored(props.value)) {
        update(value);
    }
}

defineExpose(expose);
</script>

<template>
    <Combobox
        :id="id"
        :model-value="selected"
        :options="options"
        :read-only="isReadOnly"
        :placeholder="__('Choose a model')"
        taggable
        data-tolk-model-select
        @update:model-value="select"
    >
        <template #option="option">
            <span class="truncate" :class="{ 'opacity-50': option.disabled }">{{ option.label }}</span>
            <span
                v-if="option.note"
                class="ms-auto shrink-0 text-xs text-gray-500 dark:text-gray-400"
                :class="{ 'opacity-50': option.disabled }"
                :data-tolk-model-note="option.value"
            >
                {{ option.note }}
            </span>
        </template>
    </Combobox>
</template>
