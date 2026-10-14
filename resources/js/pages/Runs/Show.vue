<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Select,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
    Text,
} from '@statamic/cms/ui';
import { localizableUrl } from '../../audit/checks.js';
import ChangesModal from '../../components/ChangesModal.vue';
import ProgressBar from '../../components/ProgressBar.vue';
import { pushes } from '../../editor/push.js';
import { createFocusKeeper } from '../../focus.js';
import { createModal } from '../../modal.js';
import {
    describeBreakdown,
    describeEstimate,
    describeItem,
    describeLeftOut,
    describeOptions,
    describeProgress,
    describeResult,
    describeScope,
    describeUsage,
    formatCost,
    formatDate,
    formatNumber,
    ITEM_STATUSES,
    itemStatusLabel,
    itemsPage,
    notLocalizableHint,
    runActions,
    runStatusColor,
    runStatusLabel,
    siteNames,
} from '../../runs/describe.js';
import { createFollower } from '../../runs/follow.js';

const props = defineProps({
    state: { type: Object, required: true },
    urls: { type: Object, required: true },
    models: { type: Object, default: () => ({}) },
    lanes: { type: Number, default: 1 },
});

const current = ref(props.state);
const estimate = ref(props.state.run?.estimate ?? null);
const failures = ref([]);
const busy = ref(null);
const page = ref(props.state.pagination?.page ?? 1);
const perPage = props.state.pagination?.per_page ?? 50;
const filter = ref('all');
const siteName = siteNames(Statamic.$config.get('sites'));

// A push from the entry editor that follows this run in the same page load drives it again once a visit inside the CP
// leaves the page (`pushes.lend()`), so only closing the tab pauses it.
const pushed = pushes.following(props.state.run?.id);

const follower = createFollower({
    urls: props.urls,
    lanes: props.lanes,
    // Leaving the page pauses a run it drives: the CP asks first, as it does for unsaved changes.
    dirty: pushed ? null : 'tolk-run',
    onState: (state) => {
        current.value = state;
        estimate.value = state.run?.estimate ?? estimate.value;
        page.value = itemsPage(page.value, state.pagination);
    },
    onEstimate: (value) => (estimate.value = value),
    onFailures: (list) => (failures.value = list),
    query: () => ({ page: page.value, per_page: perPage, ...(filter.value === 'all' ? {} : { status: filter.value }) }),
});

// Closing or reloading the tab stops a push too, so the page asks itself, whatever the CP's Confirm Dirty Navigation
// preference says.
function warn(event) {
    if (follower.driving) {
        event.preventDefault();
        event.returnValue = '';
    }
}

// A push from the entry editor that follows this run in the same page load stops while the page drives it.
let giveBack = () => {};

onMounted(() => {
    giveBack = pushes.lend(props.state.run?.id);
    follower.follow(props.state);
    window.addEventListener('beforeunload', warn);
});

onBeforeUnmount(() => {
    follower.stop();
    giveBack(current.value);
    window.removeEventListener('beforeunload', warn);
});

const run = computed(() => current.value.run ?? {});
const status = computed(() => run.value.status);
const progress = computed(() => describeProgress(current.value));
const actions = computed(() => runActions(current.value));
const result = computed(() => describeResult(current.value));
const scope = computed(() => describeScope(run.value.scope, siteName));
const breakdown = computed(() => describeBreakdown(current.value.breakdown, siteName));
const leftOut = computed(() => describeLeftOut(run.value.scope));
const auditLink = localizableUrl(Statamic.$config.get('tolk.auditUrl'));
const hint = computed(() => notLocalizableHint(run.value.scope));
const options = computed(() => describeOptions(run.value.options, props.models));
const estimated = computed(() => describeEstimate(estimate.value, progress.value.total));
const usage = computed(() => describeUsage(run.value, props.models));
const items = computed(() => (current.value.items ?? []).map((item) => describeItem(item, siteName)));
const pagination = computed(() => current.value.pagination ?? { page: 1, last_page: 1, total: 0 });
const driving = computed(() => status.value === 'running' && run.value.driver === 'browser');
const stalled = computed(() => status.value === 'running' && Boolean(current.value.stalled));
const countParts = computed(() =>
    ITEM_STATUSES.filter((key) => progress.value.counts[key] > 0).map((key) => ({ key, text: `${formatNumber(progress.value.counts[key])} ${itemStatusLabel(key).toLowerCase()}` })),
);
const filters = computed(() => [{ label: __('All items'), value: 'all' }, ...ITEM_STATUSES.map((key) => ({ label: itemStatusLabel(key), value: key }))]);
const root = ref(null);
const focus = createFocusKeeper(() => root.value);
const changes = createModal();
const shown = ref([]);

function viewChanges(item) {
    shown.value = item.corrections;
    changes.show();
}

// Cancel leaves with the running run.
watch(status, () => focus.changing());

const created = computed(() => {
    const by = run.value.created_by?.name;
    const at = formatDate(run.value.created_at);

    if (scope.value.automatic) {
        return by
            ? __('Started automatically on :date, when :name saved the entry.', { name: by, date: at })
            : __('Started automatically on :date, when the entry was saved.', { date: at });
    }

    return by ? __('Created by :name on :date', { name: by, date: at }) : __('Created on :date from the command line', { date: at });
});

async function act(name, action) {
    const focused = focus.holds();
    busy.value = name;

    try {
        const answer = await action();

        if (name === 'retry' && answer?.retried === 0) {
            Statamic.$toast.info(__('No item failed, so nothing was retried.'));
        }
    } catch (error) {
        Statamic.$toast.error(error.message);
    } finally {
        busy.value = null;

        if (focused) {
            nextTick(focus.restore);
        }
    }
}

async function showPage(number) {
    page.value = Math.max(1, number);

    try {
        await follower.refresh();
    } catch (error) {
        Statamic.$toast.error(error.message);
    }
}

function filterBy(value) {
    filter.value = value ?? 'all';
    showPage(1);
}
</script>

<template>
    <div ref="root" class="mx-auto max-w-page space-y-6" data-tolk-run :data-tolk-run-status="status">
        <Head :title="__('Translation run')" />

        <Header :title="__('Translation run')" icon="dictionary-language-book">
            <Link :href="urls.index" class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200" data-tolk-runs-link>
                {{ __('All runs') }}
            </Link>
            <Button
                v-if="actions.cancel"
                :text="__('Cancel')"
                :loading="busy === 'cancel'"
                :disabled="busy !== null"
                data-tolk-action="cancel"
                @click="act('cancel', follower.cancel)"
            />
            <Button
                v-if="actions.start"
                variant="primary"
                :text="__('Start')"
                :loading="busy === 'start'"
                :disabled="busy !== null"
                data-tolk-action="start"
                @click="act('start', follower.start)"
            />
        </Header>

        <Card class="space-y-3" data-tolk-run-summary>
            <div class="flex flex-wrap items-center gap-2">
                <Badge :text="runStatusLabel(status)" :color="runStatusColor(status)" data-tolk-run-status-badge />
                <Text>{{ scope.sites ? __(':what into :sites', { what: scope.what, sites: scope.sites }) : scope.what }}</Text>
            </div>
            <Description>{{ created }}</Description>

            <template v-if="status !== 'pending'">
                <ProgressBar :value="progress.finished" :max="progress.total" :label="__('Run progress')" />
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1" data-tolk-run-counts>
                    <Text>{{ __(':finished of :total items finished', { finished: formatNumber(progress.finished), total: formatNumber(progress.total) }) }}</Text>
                    <Description v-for="part in countParts" :key="part.key" :data-tolk-count="part.key">{{ part.text }}</Description>
                </div>
                <Description data-tolk-run-usage>
                    {{
                        __n(':input input and :output output tokens in :calls call.|:input input and :output output tokens in :calls calls.', usage.calls, {
                            input: formatNumber(usage.input),
                            output: formatNumber(usage.output),
                            calls: formatNumber(usage.calls),
                        })
                    }}
                    {{ usage.cost === null ? __('The cost is unknown: a model without a price was called.') : __('Estimated cost so far: :cost.', { cost: formatCost(usage.cost) }) }}
                </Description>
            </template>
        </Card>

        <Alert v-if="driving" variant="default" icon="info" data-tolk-keep-open>
            {{
                pushed
                    ? __('Keep this tab open while the run is in progress. Closing it pauses the run until you open it again.')
                    : __('Keep this page open while the run is in progress. Leaving it pauses the run until you open it again.')
            }}
        </Alert>

        <Alert v-if="stalled" variant="warning" :heading="__('This run is not moving')" data-tolk-stalled>
            <div class="space-y-3">
                <Text v-if="run.driver === 'cli' && scope.automatic">{{
                    __('The process that updates these translations has not worked on them for a while and may have stopped. Resume continues the run from here.')
                }}</Text>
                <Text v-else-if="run.driver === 'cli'">{{ __('The command running this run has not worked on it for a while and may have stopped. Resume continues the run from here.') }}</Text>
                <Text v-else>{{ __('No queue worker has worked on this run for a while. Start a worker for the queue, or run it in this browser instead.') }}</Text>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" :text="__('Resume')" :loading="busy === 'resume'" :disabled="busy !== null" data-tolk-action="resume" @click="act('resume', follower.resume)" />
                    <Button
                        v-if="actions.takeOver"
                        size="sm"
                        variant="primary"
                        :text="__('Run in this browser instead')"
                        :loading="busy === 'take-over'"
                        :disabled="busy !== null"
                        data-tolk-action="take-over"
                        @click="act('take-over', follower.takeOver)"
                    />
                </div>
            </div>
        </Alert>

        <Alert v-for="(failure, index) in failures" :key="index" variant="error" data-tolk-run-failure>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Text>{{ __('Following the run stopped: :message', { message: failure.error?.message ?? '' }) }}</Text>
                <Button v-if="index === 0" size="sm" :text="__('Try again')" data-tolk-action="follow-again" @click="follower.follow(current)" />
            </div>
        </Alert>

        <Alert v-if="result" :variant="result.variant" data-tolk-run-result>
            <div class="space-y-3">
                <Text as="p">{{ result.text }}</Text>
                <Text v-if="result.error" as="p" variant="danger">{{ result.error }}</Text>
                <div v-if="actions.retry || actions.resume" class="flex flex-wrap gap-2">
                    <Button
                        v-if="actions.retry"
                        size="sm"
                        :text="__('Retry failed')"
                        :loading="busy === 'retry'"
                        :disabled="busy !== null"
                        data-tolk-action="retry"
                        @click="act('retry', follower.retry)"
                    />
                    <Button v-if="actions.resume" size="sm" :text="__('Resume')" :loading="busy === 'resume'" :disabled="busy !== null" data-tolk-action="resume" @click="act('resume', follower.resume)" />
                </div>
            </div>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card class="space-y-3" data-tolk-breakdown>
                <Heading size="lg" :text="__('What it translates')" />
                <div class="overflow-x-auto">
                    <Table>
                        <TableColumns>
                            <TableColumn>{{ __('Content') }}</TableColumn>
                            <TableColumn>{{ __('Site') }}</TableColumn>
                            <TableColumn>{{ __('Items') }}</TableColumn>
                            <TableColumn>{{ __('Skipped') }}</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="row in breakdown" :key="row.key" :data-tolk-breakdown-row="row.key">
                                <TableCell>{{ row.type }}</TableCell>
                                <TableCell>{{ row.site }}</TableCell>
                                <TableCell data-tolk-breakdown-total>{{ formatNumber(row.total) }}</TableCell>
                                <TableCell data-tolk-breakdown-skipped>{{ formatNumber(row.skipped) }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </div>
                <Description v-if="breakdown.length === 0">{{ __('This run has nothing to translate.') }}</Description>
                <Description v-for="note in leftOut" :key="note.reason" :data-tolk-left-out="note.reason">{{ note.text }}</Description>
                <Description v-if="hint" data-tolk-not-localizable>
                    {{ hint }}
                    <Link v-if="auditLink" :href="auditLink" class="text-blue-600 hover:underline dark:text-blue-400" data-tolk-audit-link>
                        {{ __('Open the setup audit') }}
                    </Link>
                    <template v-else>{{ __('Run php please tolk:doctor for details.') }}</template>
                </Description>
            </Card>

            <Card class="space-y-3" data-tolk-settings>
                <Heading size="lg" :text="__('Settings')" />
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <template v-for="row in options" :key="row.key">
                        <dt class="text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                        <dd :data-tolk-option="row.key">{{ row.value }}</dd>
                    </template>
                </dl>
            </Card>
        </div>

        <Card v-if="status === 'pending' || estimated" class="space-y-3" data-tolk-estimate :data-tolk-estimate-finished="estimated?.finished ? 'true' : 'false'">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <Heading size="lg" :text="__('Estimate')" />
                <Description v-if="!estimated">{{ __('Estimating…') }}</Description>
                <Description v-else-if="!estimated.finished && status === 'pending'">{{ __('Estimating… :progress', { progress: estimated.progress }) }}</Description>
                <Description v-else-if="!estimated.finished" data-tolk-estimate-partial>{{ estimated.partial }}</Description>
            </div>
            <dl v-if="estimated" class="flex flex-wrap gap-x-10 gap-y-2 text-sm">
                <div>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Segments') }}</dt>
                    <dd data-tolk-estimate-segments>{{ formatNumber(estimated.segments) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Characters') }}</dt>
                    <dd data-tolk-estimate-chars>{{ formatNumber(estimated.chars) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Input tokens') }}</dt>
                    <dd data-tolk-estimate-input>{{ formatNumber(estimated.inputTokens) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Output tokens') }}</dt>
                    <dd data-tolk-estimate-output>{{ formatNumber(estimated.outputTokens) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Cost') }}</dt>
                    <dd data-tolk-estimate-cost>{{ estimated.cost === null ? __('Unknown') : formatCost(estimated.cost) }}</dd>
                </div>
            </dl>
            <Description>
                {{ __('An estimate: the tokens a language takes vary, and so does what a model writes.') }}
                <template v-if="estimated && estimated.cost === null">{{ __('The cost is unknown because a model has no price.') }}</template>
            </Description>
        </Card>

        <Card class="space-y-3" data-tolk-items>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Heading size="lg" :text="__('Items')" />
                <div class="w-56">
                    <Select :model-value="filter" :options="filters" size="sm" data-tolk-items-filter @update:model-value="filterBy" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <Table>
                    <TableColumns>
                        <TableColumn>{{ __('Title') }}</TableColumn>
                        <TableColumn>{{ __('Site') }}</TableColumn>
                        <TableColumn>{{ __('Status') }}</TableColumn>
                        <TableColumn>{{ __('Result') }}</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="item in items" :key="item.index" data-tolk-item :data-tolk-item-status="item.status">
                            <TableCell>
                                <a v-if="item.editUrl" :href="item.editUrl" class="text-blue-600 hover:underline dark:text-blue-400">{{ item.title }}</a>
                                <span v-else>{{ item.title }}</span>
                            </TableCell>
                            <TableCell>{{ item.site }}</TableCell>
                            <TableCell><Badge :text="item.statusLabel" :color="item.statusColor" /></TableCell>
                            <TableCell class="space-y-1">
                                <Text v-if="item.applied" size="sm">{{ item.applied }}</Text>
                                <div v-if="item.corrections.length" class="flex flex-wrap items-center gap-2">
                                    <Text size="sm">{{ item.corrected }}</Text>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        :text="__('View changes')"
                                        :aria-label="__('View changes to :title in :site', { title: item.title, site: item.site })"
                                        data-tolk-item-changes
                                        @click="viewChanges(item)"
                                    />
                                </div>
                                <Description v-if="item.incomplete.length">{{ __('Left incomplete: :fields', { fields: item.incomplete.join(', ') }) }}</Description>
                                <Description v-if="item.failed.length" data-tolk-item-failed>
                                    {{ __('Could not be translated: :fields', { fields: item.failed.map((field) => `${field.handle} (${field.reason})`).join(', ') }) }}
                                </Description>
                                <Description v-if="item.notReviewed.length">{{ __('Not reviewed in full: :fields', { fields: item.notReviewed.join(', ') }) }}</Description>
                                <Description v-if="item.kept.length">
                                    {{ __('Not changed: :fields', { fields: item.kept.map((field) => `${field.handle} (${field.reason})`).join(', ') }) }}
                                </Description>
                                <Description v-if="item.reason">{{ item.reason }}</Description>
                                <Text v-if="item.error" size="sm" variant="danger" data-tolk-item-error>
                                    {{ item.error }}
                                    <a v-if="item.rootPage" :href="item.rootPage.url" class="text-blue-600 hover:underline dark:text-blue-400" data-tolk-root-page>{{
                                        __('Open :title', { title: item.rootPage.title })
                                    }}</a>
                                </Text>
                                <Description v-for="(warning, index) in item.warnings" :key="index">{{ warning }}</Description>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>
            <Description v-if="items.length === 0">{{ __('No items.') }}</Description>
            <div v-if="pagination.last_page > 1" class="flex items-center justify-end gap-2" data-tolk-items-pages>
                <Description>{{ __('Page :page of :pages', { page: pagination.page, pages: pagination.last_page }) }}</Description>
                <Button size="sm" icon="chevron-left" :aria-label="__('Previous page')" :disabled="pagination.page <= 1" @click="showPage(pagination.page - 1)" />
                <Button
                    size="sm"
                    icon="chevron-right"
                    :aria-label="__('Next page')"
                    :disabled="pagination.page >= pagination.last_page"
                    @click="showPage(pagination.page + 1)"
                />
            </div>
            <ChangesModal v-if="changes.open" :modal="changes" :corrections="shown" />
        </Card>
    </div>
</template>
