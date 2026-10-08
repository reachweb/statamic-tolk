<script setup>
import { Head } from '@statamic/cms/inertia';
import { Card, Header, Text } from '@statamic/cms/ui';
import RunsTable from '../../components/RunsTable.vue';

defineProps({
    runs: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="mx-auto max-w-page space-y-6" data-tolk-runs>
        <Head :title="__('Translation runs')" />
        <Header :title="__('Translation runs')" icon="dictionary-language-book" />

        <Card v-if="runs.length === 0" data-tolk-runs-empty>
            <Text>{{ __('No translation runs yet. Runs started from a listing, the overview or the command line appear here.') }}</Text>
        </Card>

        <Card v-else>
            <RunsTable :runs="runs" />
        </Card>
    </div>
</template>
