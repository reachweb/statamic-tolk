<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Checkbox, Description, Modal, Text } from '@statamic/cms/ui';
import { describeOffer, offers } from '../editor/offer.js';
import { modelName } from '../editor/statuses.js';

const props = defineProps({
    containerName: { type: String, required: true },
    status: { type: Object, default: null },
    open: { type: Boolean, default: false },
});

const dontOfferAgain = ref(false);
const offer = computed(() => describeOffer(props.status));

watch(
    () => props.open,
    (open) => {
        if (open) {
            dontOfferAgain.value = false;
        }
    },
);

function translate() {
    offers.accept(props.containerName, { dontOfferAgain: dontOfferAgain.value });
}

function notNow() {
    offers.decline(props.containerName, { dontOfferAgain: dontOfferAgain.value });
}
</script>

<template>
    <Modal :open="open" :title="__('Translate this entry?')" icon="dictionary-language-book" data-tolk-offer @dismissed="notNow">
        <Text data-tolk-offer-text>
            {{
                __n(
                    'Translate :count field from :source into :target. Nothing is saved until you press Save.|Translate :count fields from :source into :target. Nothing is saved until you press Save.',
                    offer.count,
                    { source: offer.source, target: offer.target },
                )
            }}
        </Text>
        <Description v-if="offer.translator" data-tolk-offer-model>{{ __('Model: :model', { model: modelName(offer.translator) }) }}</Description>
        <Checkbox v-model="dontOfferAgain" :label="__('Don\'t offer again')" />

        <template #footer>
            <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                <Button variant="ghost" :text="__('Not now')" data-tolk-offer-action="decline" @click="notNow" />
                <Button variant="primary" :text="__('Translate')" data-tolk-offer-action="translate" @click="translate" />
            </div>
        </template>
    </Modal>
</template>
