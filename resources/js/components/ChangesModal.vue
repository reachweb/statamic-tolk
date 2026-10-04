<script setup>
import { computed } from 'vue';
import { Button, Heading, Modal, Text } from '@statamic/cms/ui';
import { diffWords } from '../editor/diff.js';
import { modelName } from '../editor/statuses.js';

const props = defineProps({
    corrections: { type: Array, default: () => [] },
    reviewer: { type: String, default: null },
});

const open = defineModel('open', { type: Boolean, default: false });

const changes = computed(() =>
    props.corrections.map((correction) => ({ ...correction, key: `${correction.handle}:${correction.id}`, diff: diffWords(correction.before, correction.after) })),
);

// Placeholders stand for formatting the reviewer kept or moved; they are shown muted, as the reviewer saw them.
function classes(part, side) {
    if (part.kind === 'placeholder') {
        return ['font-mono text-xs text-gray-500', part.changed && 'rounded-sm bg-amber-100 dark:bg-amber-900/40'];
    }

    if (!part.changed) {
        return null;
    }

    return side === 'before'
        ? 'rounded-sm bg-red-100 text-red-900 line-through dark:bg-red-900/40 dark:text-red-100'
        : 'rounded-sm bg-green-100 text-green-900 dark:bg-green-900/40 dark:text-green-100';
}
</script>

<template>
    <Modal v-model:open="open" :title="__('Changes by the reviewer')" icon="dictionary-language-book" data-tolk-changes>
        <div class="space-y-6">
            <Text v-if="reviewer" variant="subtle">{{ __('Corrected by :model. The corrections are in the form; nothing is saved until you press Save.', { model: modelName(reviewer) }) }}</Text>

            <div v-for="change in changes" :key="change.key" class="space-y-2" data-tolk-change>
                <Heading data-tolk-change-label>{{ change.label }}</Heading>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-gray-500">{{ __('Before') }}</dt>
                        <dd class="whitespace-pre-wrap" data-tolk-change-before>
                            <span v-for="(part, index) in change.diff.before" :key="index" :class="classes(part, 'before')" :data-tolk-removed="part.changed && part.kind !== 'space' ? '' : null">{{ part.text }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500">{{ __('After') }}</dt>
                        <dd class="whitespace-pre-wrap" data-tolk-change-after>
                            <span v-for="(part, index) in change.diff.after" :key="index" :class="classes(part, 'after')" :data-tolk-added="part.changed && part.kind !== 'space' ? '' : null">{{ part.text }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500">{{ __('Reason') }}</dt>
                        <dd class="whitespace-pre-wrap" data-tolk-change-reason>{{ change.reason }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                <Button variant="primary" :text="__('Close')" data-tolk-changes-action="close" @click="open = false" />
            </div>
        </template>
    </Modal>
</template>
