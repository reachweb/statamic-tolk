<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Button, Card, ConfirmationModal, Header, Text } from '@statamic/cms/ui';
import { post } from '../../api.js';
import RunsTable from '../../components/RunsTable.vue';
import { FINAL_STATUSES } from '../../runs/ticker.js';

const props = defineProps({
    runs: { type: Array, default: () => [] },
    canReset: { type: Boolean, default: false },
    urls: { type: Object, default: () => ({}) },
});

const confirming = ref(false);
const resetting = ref(false);
const finished = computed(() => props.runs.some((run) => FINAL_STATUSES.includes(run.status)));

async function reset() {
    confirming.value = false;
    resetting.value = true;

    try {
        const { deleted } = await post(props.urls.reset);
        Statamic.$toast.success(__n(':count run was deleted.|:count runs were deleted.', deleted));
        router.reload({ only: ['runs'] });
    } catch (error) {
        Statamic.$toast.error(error.message);
    } finally {
        resetting.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-page space-y-6" data-tolk-runs>
        <Head :title="__('Translation runs')" />
        <Header :title="__('Translation runs')" icon="dictionary-language-book">
            <Button
                v-if="canReset && finished"
                :text="__('Reset')"
                :loading="resetting"
                data-tolk-runs-reset
                @click="confirming = true"
            />
        </Header>

        <Card v-if="runs.length === 0" data-tolk-runs-empty>
            <Text>{{ __('No translation runs yet. Runs started from a listing, the overview or the command line appear here.') }}</Text>
        </Card>

        <Card v-else>
            <RunsTable :runs="runs" />
        </Card>

        <!-- Mounted afresh each time: a modal reopened while its last close is pending (300 ms) closes again. -->
        <ConfirmationModal
            v-if="confirming"
            open
            danger
            :title="__('Delete the finished runs?')"
            :body-text="__('Every completed, cancelled and aborted run is deleted with its results, and can no longer be resumed or retried. Pending and running runs stay. The translations they saved stay too.')"
            :button-text="__('Delete')"
            data-tolk-runs-reset-confirm
            @update:open="confirming = $event"
            @confirm="reset"
        />
    </div>
</template>
