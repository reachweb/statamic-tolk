<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    max: { type: Number, default: 0 },
    label: { type: String, required: true },
});

const percent = computed(() => (props.max > 0 ? Math.min(100, Math.round((props.value / props.max) * 100)) : 0));
</script>

<template>
    <div
        role="progressbar"
        :aria-label="label"
        aria-valuemin="0"
        :aria-valuemax="max"
        :aria-valuenow="value"
        class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
        data-tolk-progress
    >
        <!-- `!`: Statamic ranks addon utilities below its own, and its stylesheet has bg-blue-600 but not the dark variant. -->
        <div class="h-full rounded-full bg-blue-600 transition-[width] duration-300 dark:bg-blue-400!" :style="{ width: `${percent}%` }" />
    </div>
</template>
