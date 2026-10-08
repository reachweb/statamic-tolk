<script setup>
import { computed } from 'vue';
import { Button, Description, Heading, Modal } from '@statamic/cms/ui';
import { diffWords } from '../editor/diff.js';
import { modelName } from '../editor/statuses.js';

const props = defineProps({
    // `createModal()`: the banner mounts this component while it is open.
    modal: { type: Object, required: true },
    corrections: { type: Array, default: () => [] },
    reviewer: { type: String, default: null },
});

const changes = computed(() =>
    props.corrections.map((correction) => ({ ...correction, key: `${correction.handle}:${correction.id}`, diff: diffWords(correction.before, correction.after) })),
);

// Placeholders stand for formatting the reviewer kept or moved; they are shown muted, as the reviewer saw them. Changed
// parts are `del` and `ins`, struck through and underlined, so the colour is not all that tells them apart. The dark
// backgrounds are `!`: Statamic ranks addon utilities below its own, and its stylesheet has the light ones.
function classes(part, side) {
    if (part.kind === 'placeholder') {
        return ['font-mono text-xs text-gray-500 dark:text-gray-400', part.changed && 'rounded-sm bg-amber-100 dark:bg-amber-900/40!'];
    }

    if (!part.changed) {
        return null;
    }

    return side === 'before'
        ? 'rounded-sm bg-red-100 text-red-900 line-through dark:bg-red-900/40! dark:text-red-100'
        : 'rounded-sm bg-green-100 text-green-900 underline decoration-green-700/60 dark:bg-green-900/40! dark:text-green-100 dark:decoration-green-300/60';
}
</script>

<template>
    <Modal
        open
        :title="__('Changes by the reviewer')"
        icon="dictionary-language-book"
        data-tolk-changes
        @dismissed="modal.dismissed()"
        @update:open="modal.closed($event)"
    >
        <div class="space-y-6">
            <Description v-if="reviewer">{{ __('Corrected by :model. The corrections are in the form; nothing is saved until you press Save.', { model: modelName(reviewer) }) }}</Description>

            <div v-for="change in changes" :key="change.key" class="space-y-2" data-tolk-change>
                <Heading data-tolk-change-label>{{ change.label }}</Heading>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Before') }}</dt>
                        <dd class="whitespace-pre-wrap" data-tolk-change-before>
                            <component
                                :is="part.changed ? 'del' : 'span'"
                                v-for="(part, index) in change.diff.before"
                                :key="index"
                                :class="classes(part, 'before')"
                                :data-tolk-removed="part.changed && part.kind !== 'space' ? '' : null"
                            >{{ part.text }}</component>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('After') }}</dt>
                        <dd class="whitespace-pre-wrap" data-tolk-change-after>
                            <component
                                :is="part.changed ? 'ins' : 'span'"
                                v-for="(part, index) in change.diff.after"
                                :key="index"
                                :class="classes(part, 'after')"
                                :data-tolk-added="part.changed && part.kind !== 'space' ? '' : null"
                            >{{ part.text }}</component>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Reason') }}</dt>
                        <dd class="whitespace-pre-wrap" data-tolk-change-reason>{{ change.reason }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                <Button variant="primary" :text="__('Close')" data-tolk-changes-action="close" @click="modal.hide()" />
            </div>
        </template>
    </Modal>
</template>
