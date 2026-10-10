// Turns a run's state (`runs/{id}/state`, T8.7) into what the run pages show. Pure apart from `__()` and `__n()`.

import { asMap } from '../api.js';
import { notLocalizableText } from '../editor/statuses.js';
import { isFinal } from './ticker.js';

export const ITEM_STATUSES = ['pending', 'translating', 'translated', 'applying', 'done', 'skipped', 'failed'];

// Items nothing has finished yet.
const UNFINISHED = ['pending', 'translating', 'translated', 'applying'];

const RUN_COLORS = {
    pending: 'default',
    running: 'blue',
    completed: 'green',
    completed_with_errors: 'orange',
    cancelled: 'amber',
    aborted: 'red',
};

const ITEM_COLORS = {
    pending: 'default',
    translating: 'blue',
    translated: 'sky',
    applying: 'blue',
    done: 'green',
    skipped: 'default',
    failed: 'red',
};

export function runStatusLabel(status) {
    return (
        {
            pending: __('Not started'),
            running: __('Running'),
            completed: __('Completed'),
            completed_with_errors: __('Completed with errors'),
            cancelled: __('Cancelled'),
            aborted: __('Aborted'),
        }[status] ?? status
    );
}

export function runStatusColor(status) {
    return RUN_COLORS[status] ?? 'default';
}

export function itemStatusLabel(status) {
    return (
        {
            pending: __('Waiting'),
            translating: __('Translating'),
            translated: __('Translated, not saved yet'),
            applying: __('Saving'),
            done: __('Done'),
            skipped: __('Skipped'),
            failed: __('Failed'),
        }[status] ?? status
    );
}

export function itemStatusColor(status) {
    return ITEM_COLORS[status] ?? 'default';
}

export function typeLabel(type) {
    return (
        {
            entry: __('Entries'),
            term: __('Terms'),
            globals: __('Global sets'),
            nav: __('Navigation'),
        }[type] ?? type
    );
}

// A site's name from the CP's sites (`Statamic.$config.get('sites')`), its handle when the user cannot see it.
export function siteNames(sites = []) {
    const names = Object.fromEntries((Array.isArray(sites) ? sites : []).map((site) => [site.handle, site.name]));

    return (handle) => names[handle] ?? handle;
}

export function formatNumber(value, locale = undefined) {
    return new Intl.NumberFormat(locale).format(Number(value) || 0);
}

// US dollars, as the catalog's prices are. Below a cent, two significant digits.
export function formatCost(value, locale = undefined) {
    const amount = Number(value) || 0;
    const digits = amount > 0 && amount < 0.01 ? { maximumSignificantDigits: 2 } : { minimumFractionDigits: 2, maximumFractionDigits: 2 };

    return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', ...digits }).format(amount);
}

export function formatDate(value, locale = undefined) {
    const time = Date.parse(value ?? '');

    return Number.isNaN(time) ? '' : new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(time);
}

// The run's progress: the counts per item status, how many items there are and how many are finished.
export function describeProgress(state) {
    const counts = { ...Object.fromEntries(ITEM_STATUSES.map((status) => [status, 0])), ...asMap(state?.counts) };
    const total = ITEM_STATUSES.reduce((sum, status) => sum + (Number(counts[status]) || 0), 0);
    const unfinished = UNFINISHED.reduce((sum, status) => sum + (Number(counts[status]) || 0), 0);

    return { counts, total, finished: total - unfinished, unfinished };
}

// What the user can do with the run now. The Start, Resume, Retry and take-over routes need `run bulk translations`,
// which the run page requires anyway.
export function runActions(state) {
    const run = state?.run ?? {};
    const { counts, unfinished } = describeProgress(state);
    const final = isFinal(state);
    const stalled = run.status === 'running' && Boolean(state?.stalled);

    return {
        start: run.status === 'pending',
        cancel: run.status === 'running',
        resume: stalled || (['cancelled', 'aborted'].includes(run.status) && unfinished > 0),
        retry: final && counts.failed > 0,
        takeOver: stalled && run.driver === 'queue',
    };
}

// The counts of a final run as one list: `done`, the text for the done items, then the skipped and failed ones.
export function describeCounts(counts, done) {
    const parts = [done];

    if (counts.skipped > 0) {
        parts.push(__n(':count skipped|:count skipped', counts.skipped));
    }

    if (counts.failed > 0) {
        parts.push(__n(':count failed|:count failed', counts.failed));
    }

    return parts.join(', ');
}

// What a finished or interrupted run did, in one sentence.
export function describeResult(state) {
    const run = state?.run ?? {};

    if (!isFinal(state)) {
        return null;
    }

    const { counts } = describeProgress(state);
    const items = describeCounts(counts, __n(':count done|:count done', counts.done));

    return {
        status: run.status,
        variant: { completed: 'success', completed_with_errors: 'warning', cancelled: 'warning', aborted: 'error' }[run.status],
        text: {
            completed: __('The run is complete: :items.', { items }),
            completed_with_errors: __('The run is complete, with errors: :items.', { items }),
            cancelled: __('The run was cancelled: :items.', { items }),
            aborted: __('The run was stopped: :items.', { items }),
        }[run.status],
        error: run.error ?? null,
    };
}

// The rows of the item counts by content type and site.
export function describeBreakdown(breakdown, siteName = (handle) => handle) {
    return (Array.isArray(breakdown) ? breakdown : []).map(({ type, site, counts }) => {
        const progress = describeProgress({ counts });

        return {
            key: `${type}|${site}`,
            type: typeLabel(type),
            site: siteName(site),
            total: progress.total,
            skipped: progress.counts.skipped,
            done: progress.counts.done,
            failed: progress.counts.failed,
        };
    });
}

// Targets left out of the run when it was created (`run.scope.skipped`, T8.3), each as a sentence.
export function describeLeftOut(scope) {
    const texts = {
        excluded_collection: (count) =>
            __n(':count target is in a collection excluded in the settings.|:count targets are in collections excluded in the settings.', count),
        site_not_in_collection: (count) =>
            __n(":count target is in a site its collection does not have.|:count targets are in sites their collection does not have.", count),
        unauthorized: (count) => __n(':count target is one you may not edit.|:count targets are ones you may not edit.', count),
        from_site: (count) =>
            __n(':count target is a navigation in the site it is translated from.|:count targets are navigations in the site they are translated from.', count),
    };

    return Object.entries(asMap(scope?.skipped))
        .filter(([, count]) => Number(count) > 0)
        .map(([reason, count]) => ({
            reason,
            text: texts[reason]?.(Number(count)) ?? __(':count targets left out: :reason.', { count, reason: reason.replaceAll('_', ' ') }),
        }));
}

// The text fields that are not translated because they are not localizable (`run.scope.out_of_scope`), so that "nothing
// was translated" has an explanation.
export function notLocalizableHint(scope) {
    const fields = (Array.isArray(scope?.out_of_scope) ? scope.out_of_scope : []).filter((field) => field.reason === 'not_localizable');

    if (fields.length === 0) {
        return null;
    }

    const containers = new Set(fields.map((field) => field.container));
    const names = fields
        .map((field) => (containers.size > 1 ? `${field.label || field.handle} (${field.container})` : field.label || field.handle))
        .join(', ');

    return notLocalizableText(names, fields.length);
}

// What the run translates, for the runs list and the run page's title.
export function describeScope(scope, siteName = (handle) => handle) {
    const list = (key) => (Array.isArray(scope?.[key]) ? scope[key] : []);
    const collections = list('collections');
    const taxonomies = list('taxonomies');
    const globals = list('globals');
    const navs = list('navs');
    const entries = list('entry_ids').length;
    const terms = list('term_ids').length;
    const parts = [];
    const containers = (handles, all) => {
        if (handles.includes('*')) {
            parts.push(all);
        } else if (handles.length > 0) {
            parts.push(handles.join(', '));
        }
    };

    containers(collections, __('All collections'));

    if (entries > 0) {
        parts.push(__n(':count selected entry|:count selected entries', entries));
    }

    containers(taxonomies, __('All taxonomies'));

    if (terms > 0) {
        parts.push(__n(':count selected term|:count selected terms', terms));
    }

    containers(globals, __('All global sets'));
    containers(navs, __('All navigation'));

    return {
        what: parts.join(', ') || __('Nothing selected'),
        sites: list('sites').map(siteName).join(', '),
        // Saving the entry started the run (T11.1).
        automatic: scope?.automatic === true,
    };
}

// The run's options as label and value rows. `models` names the models by reference.
export function describeOptions(options, models = {}) {
    const model = (reference) => (reference ? (asMap(models)[reference] ?? reference) : null);
    const rows = [
        {
            key: 'mode',
            label: __('Translate'),
            value:
                {
                    missing: __('Only what is not translated yet'),
                    update: __('Also update outdated translations'),
                    all: __('Retranslate everything'),
                    review: __('Review existing translations'),
                }[options?.mode] ?? options?.mode ?? '',
        },
        { key: 'translator', label: __('Model'), value: model(options?.translator) ?? __('Default') },
        { key: 'review', label: __('Review'), value: options?.review ? (model(options?.reviewer) ?? __('On')) : __('Off') },
        { key: 'overwrite_manual', label: __('Manual edits'), value: options?.overwrite_manual ? __('Overwritten') : __('Kept') },
        { key: 'new_status', label: __('New localizations'), value: options?.new_status === 'mirror' ? __('Same as origin') : __('Drafts') },
    ];

    if (options?.publish_changes) {
        rows.push({ key: 'publish_changes', label: __('Revisions'), value: __('Changes are published') });
    }

    return rows;
}

// The estimate as rows, `null` before the first answer. `total` is the number of items, for the progress line.
export function describeEstimate(estimate, total = 0) {
    if (!estimate) {
        return null;
    }

    const next = Number.isInteger(estimate.next) ? estimate.next : null;

    return {
        finished: next === null,
        progress: next === null ? null : __(':done of :total items', { done: Math.min(next, total), total }),
        segments: Number(estimate.segments) || 0,
        chars: Number(estimate.chars) || 0,
        inputTokens: Number(estimate.input_tokens) || 0,
        outputTokens: Number(estimate.output_tokens) || 0,
        cost: estimate.cost === null || estimate.cost === undefined ? null : Number(estimate.cost),
    };
}

// The run's usage per model and in total. `cost` is the run's (`run.cost`), null once a model without a price was called.
export function describeUsage(run, models = {}) {
    const rows = Object.entries(asMap(run?.usage)).map(([reference, usage]) => ({
        model: asMap(models)[reference] ?? reference,
        input: Number(usage?.input) || 0,
        output: Number(usage?.output) || 0,
        calls: Number(usage?.calls) || 0,
    }));

    return {
        rows,
        input: rows.reduce((sum, row) => sum + row.input, 0),
        output: rows.reduce((sum, row) => sum + row.output, 0),
        calls: rows.reduce((sum, row) => sum + row.calls, 0),
        cost: run?.cost === null || run?.cost === undefined ? null : Number(run.cost),
    };
}

const FIELD_REASONS = {
    current: () => __('up to date'),
    outdated: () => __('outdated, not updated in this mode'),
    untranslated: () => __('not translated yet'),
    manual: () => __('edited by hand, kept'),
    target_changed: () => __('edited meanwhile, kept'),
    not_applicable: () => __('empty in the source'),
    not_requested: () => __('not requested'),
    origin_untranslated: () => __('not translated in its source yet'),
    structure_differs: () => __('its structure differs from the source'),
    no_corrections: () => __('reviewed, nothing to correct'),
};

const ITEM_REASONS = {
    nothing_to_do: () => __('Nothing to do.'),
    dependency_failed: () => __('An item it depends on failed.'),
    unauthorized: () => __('You may not edit it.'),
    target_changed: () => __('Edited by someone while it was being translated.'),
    no_corrections: () => __('Reviewed, nothing to correct.'),
    source_changed: () => __('Its source changed, so it is translated again.'),
};

// One row of the items table: its fields grouped by what happened to them, and why it was skipped or failed.
export function describeItem(item, siteName = (handle) => handle) {
    const fields = Object.entries(asMap(item?.fields));
    const handles = (result) => fields.filter(([, value]) => value === result).map(([handle]) => handle);
    const applied = fields.filter(([, value]) => value === 'applied' || value === 'incomplete').length;
    const kept = fields
        .filter(([, value]) => !['applied', 'incomplete'].includes(value))
        .map(([handle, value]) => ({ handle, reason: FIELD_REASONS[value]?.() ?? String(value).replaceAll('_', ' ') }));
    const reason = item?.reason ? (ITEM_REASONS[item.reason]?.() ?? null) : null;
    // The reviewer's corrections, labelled by their field's handle: the run page has no blueprints.
    const corrections = Object.entries(asMap(item?.corrections)).flatMap(([handle, list]) =>
        (Array.isArray(list) ? list : []).map((correction) => ({ ...correction, handle, label: handle })),
    );

    return {
        index: item?.index,
        title: item?.title || item?.source_id || '',
        site: siteName(item?.target_site),
        status: item?.status,
        statusLabel: itemStatusLabel(item?.status),
        statusColor: itemStatusColor(item?.status),
        editUrl: item?.edit_url ?? null,
        applied: applied > 0 ? __n(':count field saved|:count fields saved', applied) : null,
        incomplete: handles('incomplete'),
        kept,
        reason,
        error: item?.error ?? null,
        // For `root_missing`: the root page to translate first.
        rootPage: item?.root_page?.edit_url ? { title: item.root_page.title || '', url: item.root_page.edit_url } : null,
        warnings: Array.isArray(item?.warnings) ? item.warnings : [],
        corrected: corrections.length > 0 ? __n(':count correction|:count corrections', corrections.length) : null,
        corrections,
    };
}
