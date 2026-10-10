// Turns the Overview's props and its scanned cells into what the page shows, and builds the runs its buttons create.
// Pure apart from `__()` and `__n()`.

import { asMap } from '../api.js';
import { itemStatusLabel, statusColor } from '../editor/statuses.js';

export const CONTENT_TYPES = ['entry', 'term', 'globals', 'nav'];

// Worst first, as the item statuses of §A6.
export const STATUSES = ['missing', 'untranslated', 'outdated', 'attention', 'current'];

// The key of each content type's containers in a `runs.store` request.
const SCOPE_KEYS = { entry: 'collections', term: 'taxonomies', globals: 'globals', nav: 'navs' };

// The banner's labels, but a whole item is current rather than up to date.
export function statusLabel(status) {
    return status === 'current' ? __('Current') : itemStatusLabel(status);
}

// The banner's colours, so a status looks the same on every page.
export { statusColor };

export function groupLabel(type) {
    return (
        {
            entry: __('Collections'),
            term: __('Taxonomies'),
            globals: __('Global sets'),
            nav: __('Navigation'),
        }[type] ?? type
    );
}

export function cellKey(type, handle, site) {
    return `${type}|${handle}|${site}`;
}

// The sites any row has a cell in, in the order of `sites`.
export function matrixColumns(rows, sites) {
    const used = new Set((Array.isArray(rows) ? rows : []).flatMap((row) => row.sites ?? []));

    return (Array.isArray(sites) ? sites : []).filter((site) => used.has(site.handle));
}

// The rows by content type, in the order of CONTENT_TYPES; a type without rows is left out.
export function groupRows(rows) {
    const list = Array.isArray(rows) ? rows : [];

    return CONTENT_TYPES.map((type) => ({ type, label: groupLabel(type), rows: list.filter((row) => row.type === type) })).filter(
        (group) => group.rows.length > 0,
    );
}

// A row's cell in a site, as `scan` and the loader take it.
export function scanCell(row, site) {
    return { key: cellKey(row.type, row.handle, site), type: row.type, container: row.handle, site };
}

// Every cell of the matrix, in the order rows and sites are shown.
export function matrixCells(rows) {
    return (Array.isArray(rows) ? rows : []).flatMap((row) => (row.sites ?? []).map((site) => scanCell(row, site)));
}

// What a cell's scan found so far: the counts per status, the untracked fields and what can be done.
export function cellWork(cell) {
    const counts = asMap(cell?.counts);
    const count = (status) => Number(counts[status]) || 0;

    return {
        counts: Object.fromEntries(STATUSES.map((status) => [status, count(status)])),
        untracked: Number(cell?.untracked) || 0,
        scanned: Number(cell?.scanned) || 0,
        // Mode `missing` creates what is missing and translates every untranslated field (§A7).
        missing: count('missing') + count('untranslated'),
        // Mode `update` also translates what is untranslated, so Update is offered only for outdated items.
        outdated: count('outdated'),
    };
}

// The cell as badges, one per status with a count, worst first.
export function describeCell(cell) {
    const work = cellWork(cell);

    return {
        ...work,
        badges: STATUSES.filter((status) => work.counts[status] > 0).map((status) => ({
            status,
            text: `${statusLabel(status)} ${work.counts[status]}`,
            color: statusColor(status),
        })),
        finished: cell?.status === 'done',
        loading: cell?.status === 'waiting' || cell?.status === 'loading',
        failed: cell?.status === 'failed',
        error: cell?.error ?? null,
    };
}

// The body of `runs.store` for one container into one site.
export function cellRun(type, handle, site, mode) {
    return { sites: [site], [SCOPE_KEYS[type]]: [handle], options: { mode } };
}

// The body of `runs.store` that translates everything missing into a site: every container with a cell there, but not
// those whose finished cell has nothing missing. Null when nothing is left.
export function siteRun(rows, cells, site) {
    const scope = {};

    for (const row of Array.isArray(rows) ? rows : []) {
        if (!(row.sites ?? []).includes(site)) {
            continue;
        }

        const cell = asMap(cells)[cellKey(row.type, row.handle, site)];

        if (cell?.status === 'done' && cellWork(cell).missing === 0) {
            continue;
        }

        (scope[SCOPE_KEYS[row.type]] ??= []).push(row.handle);
    }

    return Object.keys(scope).length > 0 ? { sites: [site], ...scope, options: { mode: 'missing' } } : null;
}

// Whether the page can offer anything: more than one site, and a provider with a key or the fake engine.
export function emptyState({ multisite, providers, fakeEngine }) {
    if (!multisite) {
        return 'single_site';
    }

    const configured = (Array.isArray(providers) ? providers : []).some((provider) => provider.configured);

    return configured || fakeEngine ? null : 'no_provider';
}

// This month's usage (`UsageLog::month()`) as rows per model, with the calls and the cost in total. The total cost is
// unknown once one model's is.
export function describeMonth(usage) {
    const labels = asMap(usage?.labels);
    const rows = Object.entries(asMap(usage?.models)).map(([reference, model]) => ({
        reference,
        model: labels[reference] ?? reference,
        calls: Number(model?.calls) || 0,
        input: Number(model?.input) || 0,
        output: Number(model?.output) || 0,
        cost: model?.cost === null || model?.cost === undefined ? null : Number(model.cost),
        sources: Object.keys(asMap(model?.sources)),
    }));

    return {
        month: usage?.month ?? '',
        rows,
        calls: rows.reduce((sum, row) => sum + row.calls, 0),
        cost: rows.some((row) => row.cost === null) ? null : rows.reduce((sum, row) => sum + row.cost, 0),
    };
}

export function sourceLabel(source) {
    return { editor: __('Editor'), run: __('Runs'), cli: __('Command line'), auto: __('Automatic updates') }[source] ?? source;
}

// `YYYY-MM` as the month's name.
export function formatMonth(month, locale = undefined) {
    const match = /^(\d{4})-(\d{2})$/.exec(month ?? '');

    return match ? new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(Date.UTC(match[1], match[2] - 1, 15)) : '';
}
