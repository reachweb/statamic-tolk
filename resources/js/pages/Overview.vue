<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Card,
    ConfirmationModal,
    Description,
    Header,
    Heading,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
    Text,
} from '@statamic/cms/ui';
import { post } from '../api.js';
import RunsTable from '../components/RunsTable.vue';
import { providerBadge } from '../fieldtypes/providersStatus.js';
import { createCellLoader } from '../overview/cells.js';
import {
    cellRun,
    describeCell,
    describeMonth,
    emptyState,
    formatMonth,
    groupRows,
    matrixCells,
    matrixColumns,
    scanCell,
    siteRun,
    sourceLabel,
} from '../overview/matrix.js';
import { formatCost, formatNumber } from '../runs/describe.js';

const props = defineProps({
    multisite: { type: Boolean, default: false },
    sites: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    fakeEngine: { type: Boolean, default: false },
    usage: { type: Object, default: () => ({}) },
    canResetUsage: { type: Boolean, default: false },
    runs: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const cells = reactive({});
const busy = ref(null);
const usage = ref(props.usage);
const confirmingReset = ref(false);
const resetting = ref(false);
const settingsUrl = Statamic.$config.get('tolk')?.settingsUrl ?? null;

const empty = computed(() => emptyState(props));
const columns = computed(() => matrixColumns(props.rows, props.sites));
// The groups' rows with one cell per column: the cell `scan` takes, its status and what it shows, null where the row
// has no cell.
const groups = computed(() =>
    groupRows(props.rows).map((group) => ({
        ...group,
        rows: group.rows.map((row) => ({
            ...row,
            cells: columns.value.map((site) => {
                const cell = scanCell(row, site.handle);
                const scanned = row.sites.includes(site.handle);
                const state = cells[cell.key];

                return { site, cell, status: scanned ? (state?.status ?? 'waiting') : 'none', view: scanned ? describeCell(state) : null };
            }),
        })),
    })),
);
const allCells = computed(() => matrixCells(props.rows));
const month = computed(() => describeMonth(usage.value));
const untracked = computed(() => Object.values(cells).some((cell) => Number(cell?.untracked) > 0));
const unreadable = computed(() => Object.values(cells).some((cell) => Number(cell?.unreadable) > 0));
const siteRuns = computed(() => Object.fromEntries(columns.value.map((site) => [site.handle, siteRun(props.rows, cells, site.handle)])));

const loader = createCellLoader({
    scan: ({ key, ...body }) => post(props.urls.scan, body),
    onChange: (key, state) => (cells[key] = state),
});

onMounted(() => {
    if (empty.value === null) {
        loader.load(allCells.value);
    }
});

onBeforeUnmount(() => loader.stop());

function countAgain() {
    loader.load(allCells.value, { fresh: true });
}

// Creates a pending run and opens its page, where the estimate and Start are (D19).
async function createRun(key, body) {
    busy.value = key;

    try {
        const { url } = await post(props.urls.store, body);
        loader.stop();
        router.visit(url);
    } catch (error) {
        Statamic.$toast.error(error.message);
        busy.value = null;
    }
}

async function resetUsage() {
    const name = formatMonth(month.value.month);

    confirmingReset.value = false;
    resetting.value = true;

    try {
        usage.value = (await post(props.urls.resetUsage, { month: month.value.month })).usage;
        Statamic.$toast.success(__('The usage of :month was reset.', { month: name }));
    } catch (error) {
        Statamic.$toast.error(error.message);
    } finally {
        resetting.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-page space-y-6" data-tolk-overview>
        <Head :title="__('Translations')" />

        <Header :title="__('Translations')" icon="dictionary-language-book">
            <Link :href="urls.runs" class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200" data-tolk-runs-link>
                {{ __('All runs') }}
            </Link>
            <Button v-if="empty === null && allCells.length > 0" :text="__('Count again')" icon="sync" data-tolk-count-again @click="countAgain" />
        </Header>

        <Card v-if="empty === 'single_site'" class="space-y-3" data-tolk-overview-empty="single_site">
            <Heading size="lg" :text="__('This site has one language')" />
            <Text>{{ __('Tolk translates the content of one site into the languages of the others. Add a site for each language in Statamic first; the overview then shows what is translated into each.') }}</Text>
            <Link :href="urls.audit" class="text-sm text-blue-600 hover:underline dark:text-blue-400" data-tolk-audit-link>{{ __('Check the setup') }}</Link>
        </Card>

        <Card v-else-if="empty === 'no_provider'" class="space-y-3" data-tolk-overview-empty="no_provider">
            <Heading size="lg" :text="__('No AI provider is set up')" />
            <Text>{{ __('Tolk needs an API key of Anthropic, OpenAI or Google Gemini in the .env file before it can translate. The settings list the variables to set.') }}</Text>
            <div class="flex flex-wrap gap-4 text-sm">
                <a v-if="settingsUrl" :href="settingsUrl" class="text-blue-600 hover:underline dark:text-blue-400" data-tolk-settings-link>{{ __('Open the settings') }}</a>
                <Link :href="urls.audit" class="text-blue-600 hover:underline dark:text-blue-400" data-tolk-audit-link>{{ __('Check the setup') }}</Link>
            </div>
        </Card>

        <Card v-else-if="rows.length === 0" data-tolk-overview-nothing>
            <Text>{{ __('No collection, taxonomy, global set or navigation has more than one site yet, so there is nothing to translate.') }}</Text>
        </Card>

        <Card v-else class="space-y-3" data-tolk-matrix>
            <div class="overflow-x-auto">
                <Table>
                    <TableColumns>
                        <TableColumn>{{ __('Content') }}</TableColumn>
                        <TableColumn v-for="site in columns" :key="site.handle" :data-tolk-column="site.handle">
                            <div class="flex flex-col items-start gap-1">
                                <span>{{ site.name }}</span>
                                <Button
                                    v-if="siteRuns[site.handle]"
                                    size="xs"
                                    :text="__('Translate everything missing')"
                                    :aria-label="__('Translate everything missing into :site', { site: site.name })"
                                    :loading="busy === `site|${site.handle}`"
                                    :disabled="busy !== null"
                                    :data-tolk-site-action="site.handle"
                                    @click="createRun(`site|${site.handle}`, siteRuns[site.handle])"
                                />
                            </div>
                        </TableColumn>
                    </TableColumns>
                    <TableRows>
                        <template v-for="group in groups" :key="group.type">
                            <TableRow :data-tolk-group="group.type">
                                <TableCell :colspan="columns.length + 1" class="bg-gray-50 dark:bg-gray-900">
                                    <Heading :text="group.label" />
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="row in group.rows" :key="`${row.type}|${row.handle}`" :data-tolk-row="`${row.type}|${row.handle}`">
                                <TableCell>
                                    <Text>{{ row.title }}</Text>
                                    <Description>{{ row.handle }}</Description>
                                </TableCell>
                                <TableCell
                                    v-for="{ site, cell, status, view } in row.cells"
                                    :key="site.handle"
                                    class="align-top"
                                    :data-tolk-cell="cell.key"
                                    :data-tolk-cell-status="status"
                                >
                                    <Description v-if="!view">—</Description>
                                    <div v-else class="space-y-1.5">
                                        <div v-if="view.badges.length" class="flex flex-wrap gap-1">
                                            <Badge v-for="badge in view.badges" :key="badge.status" :text="badge.text" :color="badge.color" :data-tolk-count="badge.status" />
                                        </div>
                                        <Description v-if="view.loading">{{ __('Counting…') }}</Description>
                                        <Description v-else-if="view.finished && view.scanned === 0">{{ __('Nothing to translate') }}</Description>
                                        <Description v-if="view.untracked > 0" data-tolk-untracked>
                                            {{ __n(':count untracked field|:count untracked fields', view.untracked) }}
                                        </Description>
                                        <Description v-if="view.unreadable > 0" data-tolk-unreadable>
                                            {{ __n(':count item with unreadable state|:count items with unreadable state', view.unreadable) }}
                                        </Description>
                                        <div v-if="view.failed" class="space-y-1">
                                            <Text size="sm" variant="danger" data-tolk-cell-error>{{ view.error }}</Text>
                                            <Button size="xs" :text="__('Try again')" data-tolk-cell-retry @click="loader.retry(cell)" />
                                        </div>
                                        <div v-if="view.missing > 0 || view.outdated > 0" class="flex flex-wrap gap-1">
                                            <Button
                                                v-if="view.missing > 0"
                                                size="xs"
                                                :text="__('Translate missing')"
                                                :aria-label="__('Translate missing :content into :site', { content: row.title, site: site.name })"
                                                :loading="busy === `${cell.key}|missing`"
                                                :disabled="busy !== null"
                                                data-tolk-cell-action="missing"
                                                @click="createRun(`${cell.key}|missing`, cellRun(row.type, row.handle, site.handle, 'missing'))"
                                            />
                                            <Button
                                                v-if="view.outdated > 0"
                                                size="xs"
                                                :text="__('Update outdated')"
                                                :aria-label="__('Update outdated :content in :site', { content: row.title, site: site.name })"
                                                :loading="busy === `${cell.key}|update`"
                                                :disabled="busy !== null"
                                                data-tolk-cell-action="update"
                                                @click="createRun(`${cell.key}|update`, cellRun(row.type, row.handle, site.handle, 'update'))"
                                            />
                                        </div>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableRows>
                </Table>
            </div>
            <Description v-if="untracked" data-tolk-baseline-hint>
                {{ __('Untracked fields were translated by hand without a record of their origin, so a change to the origin goes unnoticed. Run php please tolk:baseline to record the origin of each.') }}
            </Description>
            <Description v-if="unreadable" data-tolk-unreadable-hint>
                {{ __('The translation state of some items cannot be read, so their status is unknown and translating them fails. Run php please tolk:doctor to list the files.') }}
            </Description>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card class="space-y-3" data-tolk-usage>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Heading size="lg" :text="__('Usage in :month', { month: formatMonth(month.month) })" />
                    <Button
                        v-if="canResetUsage && month.rows.length > 0"
                        size="sm"
                        :text="__('Reset')"
                        :aria-label="__('Reset the usage of :month', { month: formatMonth(month.month) })"
                        :loading="resetting"
                        data-tolk-usage-reset
                        @click="confirmingReset = true"
                    />
                </div>
                <Text v-if="month.rows.length === 0">{{ __('No model was called this month.') }}</Text>
                <template v-else>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableColumns>
                                <TableColumn>{{ __('Model') }}</TableColumn>
                                <TableColumn>{{ __('Calls') }}</TableColumn>
                                <TableColumn>{{ __('Tokens') }}</TableColumn>
                                <TableColumn>{{ __('Cost') }}</TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="row in month.rows" :key="row.reference" :data-tolk-usage-model="row.reference">
                                    <TableCell>
                                        {{ row.model }}
                                        <Description>{{ row.sources.map(sourceLabel).join(', ') }}</Description>
                                    </TableCell>
                                    <TableCell>{{ formatNumber(row.calls) }}</TableCell>
                                    <TableCell>
                                        {{ __(':input in, :output out', { input: formatNumber(row.input), output: formatNumber(row.output) }) }}
                                    </TableCell>
                                    <TableCell>{{ row.cost === null ? __('Unknown') : formatCost(row.cost) }}</TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </div>
                    <Description data-tolk-usage-total>
                        {{
                            month.cost === null
                                ? __n(
                                      ':calls call this month. The cost is unknown: a model without a price was called.|:calls calls this month. The cost is unknown: a model without a price was called.',
                                      month.calls,
                                      { calls: formatNumber(month.calls) },
                                  )
                                : __n(':calls call this month, an estimated :cost.|:calls calls this month, an estimated :cost.', month.calls, {
                                      calls: formatNumber(month.calls),
                                      cost: formatCost(month.cost),
                                  })
                        }}
                    </Description>
                </template>
            </Card>

            <Card class="space-y-3" data-tolk-providers>
                <Heading size="lg" :text="__('Providers')" />
                <ul class="space-y-2">
                    <li v-for="provider in providers" :key="provider.provider" class="flex items-center justify-between gap-2" :data-tolk-provider="provider.provider">
                        <Text>{{ provider.label }}</Text>
                        <Badge v-bind="providerBadge(provider.configured)" />
                    </li>
                </ul>
                <Description v-if="fakeEngine" data-tolk-fake-engine>{{ __('The fake engine is on: translations are made up without calling a provider.') }}</Description>
                <a v-if="settingsUrl" :href="settingsUrl" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ __('Settings') }}</a>
            </Card>
        </div>

        <Card class="space-y-3" data-tolk-latest-runs>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <Heading size="lg" :text="__('Latest runs')" />
                <Link v-if="runs.length > 0" :href="urls.runs" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ __('All runs') }}</Link>
            </div>
            <Text v-if="runs.length === 0">{{ __('No translation runs yet.') }}</Text>
            <RunsTable v-else :runs="runs" />
        </Card>

        <!-- Mounted afresh each time: a modal reopened while its last close is pending (300 ms) closes again. -->
        <ConfirmationModal
            v-if="confirmingReset"
            open
            danger
            :title="__('Reset the usage of :month?', { month: formatMonth(month.month) })"
            :body-text="__('The calls, tokens and costs counted this month are deleted, and counting starts again from zero. The runs keep their own usage.')"
            :button-text="__('Reset')"
            data-tolk-usage-reset-confirm
            @update:open="confirmingReset = $event"
            @confirm="resetUsage"
        />
    </div>
</template>
