<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import { Badge, Card, Description, Header, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Text } from '@statamic/cms/ui';
import { describeProgress, describeScope, describeUsage, formatCost, formatDate, formatNumber, runStatusColor, runStatusLabel, siteNames } from '../../runs/describe.js';

const props = defineProps({
    runs: { type: Array, default: () => [] },
});

const siteName = siteNames(Statamic.$config.get('sites'));

const rows = computed(() =>
    props.runs.map((run) => {
        const progress = describeProgress({ counts: run.counts });
        const usage = describeUsage(run);
        const scope = describeScope(run.scope, siteName);

        return {
            id: run.id,
            url: run.url,
            status: run.status,
            what: scope.what,
            sites: scope.sites,
            progress: __(':finished of :total', { finished: formatNumber(progress.finished), total: formatNumber(progress.total) }),
            failed: progress.counts.failed,
            tokens: usage.input + usage.output,
            cost: usage.cost,
            date: formatDate(run.created_at),
            by: run.created_by?.name ?? __('Command line'),
        };
    }),
);
</script>

<template>
    <div class="mx-auto max-w-page space-y-6" data-tolk-runs>
        <Head :title="__('Translations')" />
        <Header :title="__('Translation runs')" icon="dictionary-language-book" />

        <Card v-if="rows.length === 0" data-tolk-runs-empty>
            <Text>{{ __('No translation runs yet. Runs started from a listing, the overview or the command line appear here.') }}</Text>
        </Card>

        <Card v-else>
            <Table>
                <TableColumns>
                    <TableColumn>{{ __('Status') }}</TableColumn>
                    <TableColumn>{{ __('Content') }}</TableColumn>
                    <TableColumn>{{ __('Sites') }}</TableColumn>
                    <TableColumn>{{ __('Items finished') }}</TableColumn>
                    <TableColumn>{{ __('Usage') }}</TableColumn>
                    <TableColumn>{{ __('Created') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="row in rows" :key="row.id" data-tolk-run-row :data-tolk-run-id="row.id">
                        <TableCell>
                            <Link :href="row.url" class="inline-flex"><Badge :text="runStatusLabel(row.status)" :color="runStatusColor(row.status)" /></Link>
                        </TableCell>
                        <TableCell>
                            <Link :href="row.url" class="text-blue-600 hover:underline dark:text-blue-400">{{ row.what }}</Link>
                        </TableCell>
                        <TableCell>{{ row.sites }}</TableCell>
                        <TableCell>
                            {{ row.progress }}
                            <Description v-if="row.failed > 0">{{ __n(':count failed|:count failed', row.failed) }}</Description>
                        </TableCell>
                        <TableCell>
                            {{ __(':tokens tokens', { tokens: formatNumber(row.tokens) }) }}
                            <Description>{{ row.cost === null ? __('Cost unknown') : formatCost(row.cost) }}</Description>
                        </TableCell>
                        <TableCell>
                            {{ row.date }}
                            <Description>{{ row.by }}</Description>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Card>
    </div>
</template>
