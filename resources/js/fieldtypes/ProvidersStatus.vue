<script setup>
import { computed, reactive } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Badge, Button, Description, Text } from '@statamic/cms/ui';
import { describeResult, providerRows, testConnection } from './providersStatus.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose } = Fieldtype.use(emit, props);

const rows = computed(() => providerRows(props.meta));
const testing = reactive({});
const results = reactive({});

async function test(provider) {
    testing[provider] = true;
    results[provider] = null;

    try {
        results[provider] = describeResult(await testConnection(provider));
    } finally {
        testing[provider] = false;
    }
}

defineExpose(expose);
</script>

<template>
    <div class="divide-y divide-gray-200 dark:divide-gray-700" data-tolk-providers>
        <div v-for="row in rows" :key="row.provider" class="space-y-1 py-3 first:pt-0 last:pb-0" :data-tolk-provider="row.provider">
            <div class="flex flex-wrap items-center gap-2">
                <Text variant="strong">{{ row.label }}</Text>
                <Badge :text="row.badge.text" :color="row.badge.color" data-tolk-provider-status />
                <Button
                    v-if="row.testable"
                    class="ms-auto"
                    size="sm"
                    :text="__('Test connection')"
                    :loading="Boolean(testing[row.provider])"
                    :disabled="Boolean(testing[row.provider])"
                    data-tolk-provider-test
                    @click="test(row.provider)"
                />
            </div>
            <Description v-if="row.source">{{ row.source }}</Description>
            <Description v-if="row.hint">{{ row.hint }}</Description>
            <Text
                v-if="results[row.provider]"
                :variant="results[row.provider].ok ? 'success' : 'danger'"
                :data-tolk-provider-result="results[row.provider].ok ? 'ok' : 'failed'"
            >
                {{ results[row.provider].text }}
            </Text>
        </div>
    </div>
</template>
