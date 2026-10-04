<script setup>
import { computed, onBeforeUnmount } from 'vue';
import { Badge, Card, Description, Icon, Text, injectPublishContext } from '@statamic/cms/ui';
import { attachBanner } from '../editor/banner.js';
import { describe } from '../editor/statuses.js';
import { useEditorStore } from '../editor/store.js';

const props = defineProps({
    containerName: { type: String, required: true },
});

const ctx = injectPublishContext();
const store = useEditorStore();
const { failure, stop } = attachBanner({ name: props.containerName, ctx, store });

onBeforeUnmount(stop);

const banner = computed(() => describe(store.containers[props.containerName]?.status ?? null));
const shown = computed(() => !ctx.readOnly.value && (banner.value.visible || failure.value !== null));
const settingsUrl = Statamic.$config.get('tolk.settingsUrl');

const colors = {
    untranslated: 'amber',
    outdated: 'orange',
    manual: 'sky',
    attention: 'red',
    untracked: 'default',
    current: 'green',
};
</script>

<template>
    <Card v-if="shown" class="mb-6 space-y-3" data-tolk-banner>
        <div v-if="failure" class="flex items-center gap-2">
            <Icon name="warning-diamond" class="size-4 shrink-0 text-red-600" />
            <Text variant="danger">{{ __('The translation status could not be loaded: :message', { message: failure.message }) }}</Text>
        </div>

        <template v-else>
            <div v-if="banner.localization" class="space-y-2" data-tolk-localization>
                <div class="flex flex-wrap items-center gap-2">
                    <Icon name="dictionary-language-book" class="size-4 shrink-0 text-gray-500" />
                    <Text>{{ banner.localization.lead }}</Text>
                    <Badge
                        v-for="part in banner.localization.parts"
                        :key="part.key"
                        v-tooltip="part.description"
                        :text="part.text"
                        :color="colors[part.key]"
                        tabindex="0"
                        :data-tolk-part="part.key"
                    />
                </div>
                <Description v-if="banner.localization.incompleteNote">{{ banner.localization.incompleteNote }}</Description>
                <Description v-if="banner.localization.hint">{{ banner.localization.hint }}</Description>
            </div>

            <div v-if="banner.origin" class="space-y-2" data-tolk-origin>
                <div class="flex items-center gap-2">
                    <Icon name="dictionary-language-book" class="size-4 shrink-0 text-gray-500" />
                    <Text>{{ __('Translations of this entry:') }}</Text>
                </div>
                <ul class="space-y-1 ps-6">
                    <li
                        v-for="localization in banner.origin.localizations"
                        :key="localization.site"
                        class="flex flex-wrap items-center gap-2"
                        :data-tolk-site="localization.site"
                    >
                        <a v-if="localization.editUrl" :href="localization.editUrl" class="text-sm font-medium underline">{{ localization.name }}</a>
                        <Text v-else variant="strong">{{ localization.name }}</Text>
                        <Badge :text="localization.label" />
                        <Text v-if="localization.exists" variant="subtle">{{ localization.summary }}</Text>
                    </li>
                </ul>
            </div>

            <Description v-if="banner.setup" data-tolk-setup>
                {{ __('Translation is not set up yet.') }}
                <a v-if="settingsUrl" :href="settingsUrl">{{ __('Open the Tolk settings') }}</a>
                <template v-else>{{ __('Ask an administrator to configure a provider in the Tolk settings.') }}</template>
            </Description>
        </template>
    </Card>
</template>
