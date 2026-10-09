<script setup>
import { onMounted } from 'vue';
import { Button, Modal, Text } from '@statamic/cms/ui';
import { createModal } from '../modal.js';

// The confirmation of "Translate from origin", appended by the field action (`editor/fieldActions.js`), which removes it
// once it answered and puts the focus back where it belongs. It answers once.
const emit = defineEmits(['confirm', 'cancel']);
const modal = createModal();
let answered = false;

onMounted(() => modal.show());

function answer(event) {
    if (!answered) {
        answered = true;
        emit(event);
    }
}

function choose(event) {
    modal.hide();
    answer(event);
}

// Esc or a click outside, once core's close has finished.
function closed(open) {
    modal.closed(open);

    if (!open) {
        answer('cancel');
    }
}
</script>

<template>
    <Modal
        v-if="modal.open"
        open
        :title="__('Translate from origin')"
        data-tolk-field-confirm
        @dismissed="modal.dismissed()"
        @update:open="closed"
    >
        <Text as="p">{{ __('The current content of this field will be replaced.') }}</Text>

        <template #footer>
            <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                <Button variant="ghost" :text="__('Cancel')" data-tolk-field-confirm-action="cancel" @click="choose('cancel')" />
                <Button variant="primary" :text="__('Translate')" data-tolk-field-confirm-action="translate" @click="choose('confirm')" />
            </div>
        </template>
    </Modal>
</template>
