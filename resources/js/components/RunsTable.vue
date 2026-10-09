<script setup>
import { computed } from 'vue';
import { Link } from '@statamic/cms/inertia';
import { Badge, Description, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import { describeProgress, describeScope, describeUsage, formatCost, formatDate, formatNumber, runStatusColor, runStatusLabel, siteNames } from '../runs/describe.js';

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
            automatic: scope.automatic,
            sites: scope.sites,
            progress: __(':finished of :total', { finished: formatNumber(progress.finished), total: formatNumber(progress.total) }),
            failed: progress.counts.failed,
            tokens: usage.input + usage.output,
            cost: usage.cost,
            date: formatDate(run.created_at),
            by: run.created_by?.name ?? (scope.automatic ? __('Automatic update') : __('Command line')),
        };
    }),
);
</script>

<template>
    <div class="overflow-x-auto">
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
                        <Description v-if="row.automatic" data-tolk-run-automatic>{{ __('Automatic update') }}</Description>
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
    </div>
</template>
