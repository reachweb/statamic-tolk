import { beforeEach, describe, expect, it, vi } from 'vitest';
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
    formatNumber,
    notLocalizableHint,
    runActions,
    runStatusColor,
    runStatusLabel,
    siteNames,
} from './describe.js';

// Core's translator, without translation files: replacements in order, then the plural form, `one|other`.
function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

beforeEach(() => {
    vi.stubGlobal('__', translate);
    vi.stubGlobal('__n', (key, count, replacements = {}) => {
        const [one, other] = translate(key, { ...replacements, count }).split('|');

        return count === 1 ? one : (other ?? one);
    });
});

const counts = (values = {}) => ({ pending: 0, translating: 0, translated: 0, applying: 0, done: 0, skipped: 0, failed: 0, ...values });

const state = (run = {}, values = {}, extra = {}) => ({
    run: { status: 'running', driver: 'browser', ...run },
    counts: counts(values),
    stalled: false,
    ...extra,
});

const siteName = siteNames([
    { handle: 'el', name: 'Greek' },
    { handle: 'de', name: 'German' },
]);

describe('run status', () => {
    it('names and colours every run status', () => {
        expect(['pending', 'running', 'completed', 'completed_with_errors', 'cancelled', 'aborted'].map(runStatusLabel)).toEqual([
            'Not started',
            'Running',
            'Completed',
            'Completed with errors',
            'Cancelled',
            'Aborted',
        ]);
        expect(runStatusColor('completed')).toBe('green');
        expect(runStatusColor('unknown')).toBe('default');
    });

    it('counts the items, and those finished, from counts that may come as an empty list', () => {
        expect(describeProgress(state({}, { pending: 2, translated: 1, done: 3, skipped: 1, failed: 1 }))).toMatchObject({
            total: 8,
            finished: 5,
            unfinished: 3,
        });
        expect(describeProgress({ counts: [] })).toMatchObject({ total: 0, finished: 0, unfinished: 0 });
    });
});

describe('runActions', () => {
    it('offers Start on a pending run and Cancel on a running one', () => {
        expect(runActions(state({ status: 'pending' }, { pending: 3 }))).toEqual({
            start: true,
            cancel: false,
            resume: false,
            retry: false,
            takeOver: false,
        });
        expect(runActions(state({}, { pending: 3 }))).toEqual({ start: false, cancel: true, resume: false, retry: false, takeOver: false });
    });

    it('offers Resume and the take-over on a stalled queue run, and only Resume on another stalled run', () => {
        expect(runActions(state({ driver: 'queue' }, { pending: 3 }, { stalled: true }))).toMatchObject({ cancel: true, resume: true, takeOver: true });
        expect(runActions(state({ driver: 'cli' }, { pending: 3 }, { stalled: true }))).toMatchObject({ resume: true, takeOver: false });
    });

    it('offers Resume on a cancelled or aborted run with unfinished items, and Retry when items failed', () => {
        expect(runActions(state({ status: 'cancelled' }, { pending: 2, done: 1 }))).toMatchObject({ resume: true, retry: false, cancel: false });
        expect(runActions(state({ status: 'aborted' }, { done: 1, failed: 2 }))).toMatchObject({ resume: false, retry: true });
        expect(runActions(state({ status: 'completed_with_errors' }, { done: 1, failed: 1 }))).toMatchObject({ resume: false, retry: true });
        expect(runActions(state({ status: 'completed' }, { done: 3 }))).toEqual({ start: false, cancel: false, resume: false, retry: false, takeOver: false });
    });
});

describe('describeResult', () => {
    it('describes a finished run in one sentence, and nothing for one that is not', () => {
        expect(describeResult(state({ status: 'completed' }, { done: 11, skipped: 2 }))).toEqual({
            status: 'completed',
            variant: 'success',
            text: 'The run is complete: 11 done, 2 skipped.',
            error: null,
        });
        expect(describeResult(state({ status: 'completed_with_errors' }, { done: 1, failed: 1 })).text).toBe(
            'The run is complete, with errors: 1 done, 1 failed.',
        );
        expect(describeResult(state({ status: 'cancelled' }, { done: 0, pending: 4 })).text).toBe('The run was cancelled: 0 done.');
        expect(describeResult(state({ status: 'aborted', error: 'The provider is unavailable.' }, { failed: 3 }))).toMatchObject({
            variant: 'error',
            text: 'The run was stopped: 0 done, 3 failed.',
            error: 'The provider is unavailable.',
        });
        expect(describeResult(state())).toBeNull();
    });
});

describe('what the run translates', () => {
    it('describes the counts by content type and site', () => {
        expect(
            describeBreakdown(
                [
                    { type: 'entry', site: 'el', counts: counts({ pending: 10, skipped: 3 }) },
                    { type: 'term', site: 'fr', counts: counts({ done: 2, failed: 1 }) },
                ],
                siteName,
            ),
        ).toEqual([
            { key: 'entry|el', type: 'Entries', site: 'Greek', total: 13, skipped: 3, done: 0, failed: 0 },
            { key: 'term|fr', type: 'Terms', site: 'fr', total: 3, skipped: 0, done: 2, failed: 1 },
        ]);
        expect(describeBreakdown(undefined)).toEqual([]);
    });

    it('explains the targets left out when the run was created', () => {
        expect(describeLeftOut({ skipped: { excluded_collection: 1, unauthorized: 4, site_not_in_collection: 0, from_site: 1, new_reason: 2 } })).toEqual([
            { reason: 'excluded_collection', text: '1 target is in a collection excluded in the settings.' },
            { reason: 'unauthorized', text: '4 targets are ones you may not edit.' },
            { reason: 'from_site', text: '1 target is a navigation in the site it is translated from.' },
            { reason: 'new_reason', text: '2 targets left out: new reason.' },
        ]);
        expect(describeLeftOut({ skipped: [] })).toEqual([]);
    });

    it('names the fields that are not localizable, by container when there are several', () => {
        const field = (container, handle, label, reason = 'not_localizable') => ({ container, handle, label, type: 'text', reason });

        expect(notLocalizableHint({ out_of_scope: [field('notes', 'internal_note', 'Internal note'), field('notes', 'summary', 'Summary', 'disabled')] })).toBe(
            'Internal note is not translated because it is not localizable.',
        );
        expect(notLocalizableHint({ out_of_scope: [field('notes', 'internal_note', 'Internal note'), field('articles', 'teaser', '')] })).toBe(
            'Internal note (notes), teaser (articles) are not translated because they are not localizable.',
        );
        expect(notLocalizableHint({ out_of_scope: [field('notes', 'summary', 'Summary', 'hidden')] })).toBeNull();
        expect(notLocalizableHint({})).toBeNull();
    });

    it('describes the scope: collections, selected entries and sites', () => {
        expect(describeScope({ collections: ['articles', 'pages'], sites: ['el', 'de'] }, siteName)).toEqual({
            what: 'articles, pages',
            sites: 'Greek, German',
            automatic: false,
        });
        expect(describeScope({ collections: ['*'], entry_ids: ['a'], sites: ['el'] }, siteName)).toEqual({
            what: 'All collections, 1 selected entry',
            sites: 'Greek',
            automatic: false,
        });
        expect(describeScope({ collections: [], entry_ids: ['a', 'b'], sites: [] })).toEqual({ what: '2 selected entries', sites: '', automatic: false });
        expect(describeScope({ entry_ids: ['a'], sites: ['el'], automatic: true }, siteName)).toEqual({ what: '1 selected entry', sites: 'Greek', automatic: true });
        expect(describeScope({ collections: ['articles'], taxonomies: ['topics', 'tags'], sites: ['el'] }, siteName).what).toBe('articles, topics, tags');
        expect(describeScope({ collections: ['*'], taxonomies: ['*'], term_ids: ['topics::food'] }).what).toBe('All collections, All taxonomies, 1 selected term');
        expect(describeScope({ term_ids: ['topics::food', 'topics::wellness'], sites: ['el', 'de'] }, siteName)).toEqual({
            what: '2 selected terms',
            sites: 'Greek, German',
            automatic: false,
        });
        expect(describeScope({ taxonomies: ['topics'], globals: ['site_settings', 'footer'], sites: ['el'] }, siteName).what).toBe('topics, site_settings, footer');
        expect(describeScope({ collections: ['*'], taxonomies: ['*'], globals: ['*'] }).what).toBe('All collections, All taxonomies, All global sets');
        expect(describeScope({ globals: ['site_settings'], navs: ['main', 'footer'], sites: ['el'] }, siteName).what).toBe('site_settings, main, footer');
        expect(describeScope({ collections: ['*'], taxonomies: ['*'], globals: ['*'], navs: ['*'] }).what).toBe(
            'All collections, All taxonomies, All global sets, All navigation',
        );
        expect(describeScope({}).what).toBe('Nothing selected');
    });

    it('lists the options with the models by their labels', () => {
        const models = { 'openai:gpt-6.1-sol': 'GPT-6.1 Sol' };

        expect(
            describeOptions(
                { mode: 'update', translator: 'openai:gpt-6.1-sol', review: true, reviewer: 'gemini:gemini-3.8-flash', overwrite_manual: false, new_status: 'draft' },
                models,
            ),
        ).toEqual([
            { key: 'mode', label: 'Translate', value: 'Also update outdated translations' },
            { key: 'translator', label: 'Model', value: 'GPT-6.1 Sol' },
            { key: 'review', label: 'Review', value: 'gemini:gemini-3.8-flash' },
            { key: 'overwrite_manual', label: 'Manual edits', value: 'Kept' },
            { key: 'new_status', label: 'New localizations', value: 'Drafts' },
        ]);

        const rows = describeOptions({ mode: 'all', review: false, overwrite_manual: true, new_status: 'mirror', publish_changes: true });

        expect(rows.map(({ value }) => value)).toEqual(['Retranslate everything', 'Default', 'Off', 'Overwritten', 'Same as origin', 'Changes are published']);
    });
});

describe('numbers', () => {
    it('describes the estimate while it fills in, and a cost that is unknown', () => {
        expect(describeEstimate(null)).toBeNull();
        expect(describeEstimate({ segments: 12, chars: 3400, input_tokens: 2000, output_tokens: 1500, cost: 0.0123, next: 4 }, 13)).toEqual({
            finished: false,
            progress: '4 of 13 items',
            partial: null,
            segments: 12,
            chars: 3400,
            inputTokens: 2000,
            outputTokens: 1500,
            cost: 0.0123,
        });
        expect(describeEstimate({ segments: 1, cost: null, next: null }, 13)).toMatchObject({ finished: true, progress: null, cost: null });
    });

    it('says how many items a partial estimate covers once the run started, instead of estimating on', () => {
        const partial = { segments: 6, chars: 900, input_tokens: 500, output_tokens: 400, cost: 0.002, next: 3 };

        expect(describeEstimate(partial, 10, false)).toMatchObject({
            finished: false,
            progress: null,
            partial: 'Only 3 of 10 items were estimated before the run started.',
            segments: 6,
        });
        expect(describeEstimate(partial, 10, true)).toMatchObject({ progress: '3 of 10 items', partial: null });
        expect(describeEstimate({ ...partial, next: null }, 10, false)).toMatchObject({ finished: true, progress: null, partial: null });
    });

    it('adds up the usage of every model and keeps the run\'s cost', () => {
        const run = {
            usage: {
                'openai:gpt-6.1-sol': { input: 1000, output: 400, calls: 3 },
                'gemini:gemini-3.8-flash': { input: 200, output: 20, calls: 1 },
            },
            cost: 0.5,
        };

        expect(describeUsage(run, { 'openai:gpt-6.1-sol': 'GPT-6.1 Sol' })).toEqual({
            rows: [
                { model: 'GPT-6.1 Sol', input: 1000, output: 400, calls: 3 },
                { model: 'gemini:gemini-3.8-flash', input: 200, output: 20, calls: 1 },
            ],
            input: 1200,
            output: 420,
            calls: 4,
            cost: 0.5,
        });
        expect(describeUsage({ usage: [], cost: null })).toEqual({ rows: [], input: 0, output: 0, calls: 0, cost: null });
    });

    it('formats numbers and dollars, with two significant digits below a cent', () => {
        expect(formatNumber(12345, 'en-US')).toBe('12,345');
        expect(formatCost(1.5, 'en-US')).toBe('$1.50');
        expect(formatCost(0.004321, 'en-US')).toBe('$0.0043');
        expect(formatCost(0, 'en-US')).toBe('$0.00');
    });
});

describe('describeItem', () => {
    it('groups the fields by what happened to them', () => {
        const item = {
            index: 3,
            title: 'Hello',
            target_site: 'el',
            status: 'done',
            fields: { title: 'applied', content: 'incomplete', intro: 'manual', seo: 'current', teaser: 'something_new' },
            warnings: ['Its parent page has no localization.'],
            error: null,
            edit_url: 'https://cp.test/cp/collections/articles/entries/abc',
        };

        expect(describeItem(item, siteName)).toEqual({
            index: 3,
            title: 'Hello',
            site: 'Greek',
            status: 'done',
            statusLabel: 'Done',
            statusColor: 'green',
            editUrl: 'https://cp.test/cp/collections/articles/entries/abc',
            applied: '2 fields saved',
            incomplete: ['content'],
            kept: [
                { handle: 'intro', reason: 'edited by hand, kept' },
                { handle: 'seo', reason: 'up to date' },
                { handle: 'teaser', reason: 'something new' },
            ],
            reason: null,
            error: null,
            rootPage: null,
            warnings: ['Its parent page has no localization.'],
            corrected: null,
            corrections: [],
        });
    });

    it('lists the reviewer\'s corrections with their field, for the changes modal', () => {
        const item = {
            status: 'done',
            fields: { title: 'applied', content: 'applied', intro: 'no_corrections' },
            corrections: {
                title: [{ id: 's1', before: 'Στη θάλασσα', after: 'Δίπλα στη θάλασσα', reason: 'By the sea, not in it.' }],
                content: [
                    { id: 's4', before: 'Ένα', after: 'Μία', reason: 'Gender.' },
                    { id: 's7', before: 'καλό', after: 'ωραίο', reason: 'Word choice.' },
                ],
            },
        };

        expect(describeItem(item)).toMatchObject({
            applied: '2 fields saved',
            corrected: '3 corrections',
            corrections: [
                { handle: 'title', label: 'title', id: 's1', before: 'Στη θάλασσα', after: 'Δίπλα στη θάλασσα', reason: 'By the sea, not in it.' },
                { handle: 'content', label: 'content', id: 's4', before: 'Ένα', after: 'Μία', reason: 'Gender.' },
                { handle: 'content', label: 'content', id: 's7', before: 'καλό', after: 'ωραίο', reason: 'Word choice.' },
            ],
        });
        expect(describeItem({ ...item, corrections: { title: item.corrections.title } }).corrected).toBe('1 correction');
        // PHP sends an item without corrections as an empty list.
        expect(describeItem({ ...item, corrections: [] })).toMatchObject({ corrected: null, corrections: [] });
    });

    it('says why an item was skipped or failed', () => {
        expect(describeItem({ status: 'skipped', reason: 'nothing_to_do', fields: [], source_id: 'abc' })).toMatchObject({
            title: 'abc',
            statusLabel: 'Skipped',
            applied: null,
            reason: 'Nothing to do.',
        });
        const rootMissing = {
            status: 'skipped',
            reason: 'root_missing',
            error: 'Translate Home into Greek first.',
            root_page: { title: 'Home', edit_url: 'https://cp.test/cp/collections/pages/entries/home' },
        };
        expect(describeItem(rootMissing)).toMatchObject({
            reason: null,
            error: 'Translate Home into Greek first.',
            rootPage: { title: 'Home', url: 'https://cp.test/cp/collections/pages/entries/home' },
        });
        expect(describeItem({ ...rootMissing, root_page: { title: 'Home', edit_url: null } }).rootPage).toBeNull();
        expect(describeItem({ status: 'failed', reason: 'dependency_failed' })).toMatchObject({ statusColor: 'red', reason: 'An item it depends on failed.' });
    });
});
