<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Badge, Button, Card, Checkbox, ConfirmationModal, Description, Header, Heading, Icon, Text } from '@statamic/cms/ui';
import { post } from '../api.js';
import {
    LOCALIZABLE,
    blueprintKey,
    checkAnchor,
    checkDetail,
    chosenFields,
    confirmation,
    fieldName,
    importedNote,
    isBlueprintItem,
    level,
    resultMessage,
    splitFields,
    summary,
} from '../audit/checks.js';

const props = defineProps({
    checks: { type: Array, default: () => [] },
    canMakeLocalizable: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const checks = ref(props.checks);
const chosen = reactive({});
const confirming = ref(null);
const saving = ref(null);

const overall = computed(() => summary(checks.value));

watch(
    () => props.checks,
    (value) => (checks.value = value),
);

function isChosen(item, handle) {
    return (chosen[blueprintKey(item)] ?? []).includes(handle);
}

function choose(item, handle, on) {
    const key = blueprintKey(item);
    const others = (chosen[key] ?? []).filter((chosenHandle) => chosenHandle !== handle);

    chosen[key] = on ? [...others, handle] : others;
}

function ask(item) {
    const fields = chosenFields(item, chosen[blueprintKey(item)]);

    if (fields.length > 0) {
        confirming.value = { item, fields, text: confirmation(item, fields) };
    }
}

async function makeLocalizable() {
    const { item, fields } = confirming.value;
    const key = blueprintKey(item);

    confirming.value = null;
    saving.value = key;

    try {
        const result = await post(props.urls.localizable, { namespace: item.namespace, blueprint: item.blueprint, fields });

        checks.value = result.checks;
        delete chosen[key];
        Statamic.$toast.success(resultMessage(result.changed));
    } catch (error) {
        Statamic.$toast.error(error.message);
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <div class="mx-auto max-w-page space-y-6" data-tolk-audit>
        <Head :title="__('Audit')" />

        <Header :title="__('Setup audit')" icon="clipboard-check">
            <Link :href="urls.overview" class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
                {{ __('Overview') }}
            </Link>
            <Button :text="__('Check again')" icon="sync" data-tolk-audit-reload @click="router.reload()" />
        </Header>

        <Card class="flex items-start gap-3" data-tolk-audit-summary :data-tolk-audit-level="overall.level">
            <Icon :name="level(overall.level).icon" :class="['mt-0.5 size-5 shrink-0', level(overall.level).class]" />
            <Text>{{ overall.text }}</Text>
        </Card>

        <Card
            v-for="check in checks"
            :id="checkAnchor(check.id)"
            :key="check.id"
            class="space-y-3"
            :data-tolk-check="check.id"
            :data-tolk-check-level="check.level"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Icon :name="level(check.level).icon" :class="['size-4 shrink-0', level(check.level).class]" />
                <Heading size="lg" :level="2">{{ check.title }}</Heading>
                <Badge :text="level(check.level).label" :color="level(check.level).color" />
            </div>
            <Description>{{ checkDetail(check, canMakeLocalizable) }}</Description>

            <Text v-if="check.items.length === 0" data-tolk-audit-nothing>
                {{ check.id === LOCALIZABLE ? __('Every text field Tolk could translate is localizable.') : __('Nothing to report.') }}
            </Text>

            <ul v-else class="space-y-3">
                <li v-for="(item, index) in check.items" :key="index" data-tolk-item :data-tolk-item-level="item.level">
                    <div
                        v-if="isBlueprintItem(item)"
                        class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                        :data-tolk-blueprint="blueprintKey(item)"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <Icon :name="level(item.level).icon" :class="['size-4 shrink-0', level(item.level).class]" />
                                <Text class="font-medium">{{ item.name }}</Text>
                            </div>
                            <a v-if="item.edit_url" :href="item.edit_url" class="text-sm text-blue-600 hover:underline dark:text-blue-400" data-tolk-blueprint-link>
                                {{ __('Edit blueprint') }}
                            </a>
                        </div>

                        <template v-if="check.id === LOCALIZABLE">
                            <div class="space-y-2">
                                <div v-for="field in splitFields(item).editable" :key="field.handle" :data-tolk-field="field.handle">
                                    <Checkbox
                                        v-if="canMakeLocalizable"
                                        :model-value="isChosen(item, field.handle)"
                                        :label="fieldName(field)"
                                        :disabled="saving !== null"
                                        @update:model-value="choose(item, field.handle, $event)"
                                    />
                                    <Text v-else>{{ fieldName(field) }}</Text>
                                </div>
                                <div v-for="field in splitFields(item).imported" :key="field.handle" :data-tolk-field-imported="field.handle">
                                    <Checkbox
                                        v-if="canMakeLocalizable"
                                        :model-value="false"
                                        :label="fieldName(field)"
                                        :description="importedNote(field)"
                                        disabled
                                    />
                                    <template v-else>
                                        <Text>{{ fieldName(field) }}</Text>
                                        <Description>
                                            {{ importedNote(field) }}
                                        </Description>
                                    </template>
                                    <a v-if="field.fieldset_url" :href="field.fieldset_url" class="ms-6 text-sm text-blue-600 hover:underline dark:text-blue-400" data-tolk-fieldset-link>
                                        {{ __('Edit fieldset') }}
                                    </a>
                                </div>
                            </div>
                            <Button
                                v-if="canMakeLocalizable && splitFields(item).editable.length > 0"
                                size="sm"
                                variant="primary"
                                :text="__('Make localizable')"
                                :loading="saving === blueprintKey(item)"
                                :disabled="saving !== null || chosenFields(item, chosen[blueprintKey(item)]).length === 0"
                                data-tolk-make-localizable
                                @click="ask(item)"
                            />
                        </template>

                        <ul v-else class="space-y-1 ps-6">
                            <li v-for="field in item.fields" :key="field.path ?? field.handle" :data-tolk-field="field.path ?? field.handle">
                                <Text>
                                    {{ field.label }}
                                    <code class="text-xs text-gray-600 dark:text-gray-400">{{ field.path ?? field.handle }}</code>
                                </Text>
                            </li>
                        </ul>
                    </div>

                    <div v-else class="flex items-start gap-2">
                        <Icon :name="level(item.level).icon" :class="['mt-0.5 size-4 shrink-0', level(item.level).class]" />
                        <Text>{{ item.text }}</Text>
                    </div>
                </li>
            </ul>
        </Card>

        <ConfirmationModal
            v-if="confirming"
            open
            :title="__('Make these fields localizable?')"
            :button-text="__('Make localizable')"
            data-tolk-localizable-confirm
            @update:open="confirming = $event ? confirming : null"
            @confirm="makeLocalizable"
        >
            <Text>{{ confirming.text }}</Text>
        </ConfirmationModal>
    </div>
</template>
