import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cellKey,
    cellRun,
    cellWork,
    describeCell,
    describeMonth,
    emptyState,
    formatMonth,
    groupRows,
    matrixCells,
    matrixColumns,
    siteRun,
    sourceLabel,
    statusColor,
    statusLabel,
} from './matrix.js';

function translate(key, replacements = {}) {
    return Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(value), key);
}

beforeEach(() => {
    vi.stubGlobal('__', translate);
});

const SITES = [
    { handle: 'en', name: 'English' },
    { handle: 'el', name: 'Greek' },
    { handle: 'de', name: 'German' },
];

const ROWS = [
    { type: 'nav', handle: 'main', title: 'Main', sites: ['el', 'de'] },
    { type: 'entry', handle: 'pages', title: 'Pages', sites: ['el', 'de'] },
    { type: 'entry', handle: 'articles', title: 'Articles', sites: ['el'] },
    { type: 'globals', handle: 'site_settings', title: 'Site Settings', sites: ['el', 'de'] },
    { type: 'term', handle: 'topics', title: 'Topics', sites: ['de'] },
];

const counts = (values = {}) => ({ missing: 0, untranslated: 0, outdated: 0, attention: 0, current: 0, ...values });

const done = (values = {}, extra = {}) => ({ status: 'done', counts: counts(values), untracked: 0, unreadable: 0, scanned: 1, cursor: null, ...extra });

describe('the matrix', () => {
    it('has a column for each site a row has a cell in, in the order of the sites', () => {
        expect(matrixColumns(ROWS, SITES).map((site) => site.handle)).toEqual(['el', 'de']);
        expect(matrixColumns([{ type: 'entry', handle: 'pages', sites: ['de'] }], SITES)).toEqual([SITES[2]]);
        expect(matrixColumns([], SITES)).toEqual([]);
        expect(matrixColumns(null, null)).toEqual([]);
    });

    it('groups the rows by content type, in a fixed order, without empty groups', () => {
        const groups = groupRows(ROWS);

        expect(groups.map((group) => [group.type, group.label, group.rows.map((row) => row.handle)])).toEqual([
            ['entry', 'Collections', ['pages', 'articles']],
            ['term', 'Taxonomies', ['topics']],
            ['globals', 'Global sets', ['site_settings']],
            ['nav', 'Navigation', ['main']],
        ]);
        expect(groupRows([ROWS[1]]).map((group) => group.type)).toEqual(['entry']);
    });

    it('lists every cell to scan, row by row', () => {
        expect(matrixCells(ROWS).map((cell) => cell.key)).toEqual([
            'nav|main|el',
            'nav|main|de',
            'entry|pages|el',
            'entry|pages|de',
            'entry|articles|el',
            'globals|site_settings|el',
            'globals|site_settings|de',
            'term|topics|de',
        ]);
        expect(matrixCells([ROWS[2]])).toEqual([{ key: 'entry|articles|el', type: 'entry', container: 'articles', site: 'el' }]);
    });
});

describe('a cell', () => {
    it('counts missing and untranslated targets as missing work, and outdated ones as updates', () => {
        const work = cellWork(done({ missing: 2, untranslated: 3, outdated: 4, attention: 5, current: 6 }, { untracked: 7, unreadable: 1, scanned: 21 }));

        expect(work).toEqual({
            counts: counts({ missing: 2, untranslated: 3, outdated: 4, attention: 5, current: 6 }),
            untracked: 7,
            unreadable: 1,
            scanned: 21,
            missing: 5,
            outdated: 4,
        });
        expect(cellWork(undefined)).toEqual({ counts: counts(), untracked: 0, unreadable: 0, scanned: 0, missing: 0, outdated: 0 });
        expect(cellWork({ counts: [] }).missing).toBe(0);
    });

    it('shows a badge for each status with a count, worst first', () => {
        const cell = describeCell(done({ current: 2, missing: 1, attention: 3 }));

        expect(cell.badges).toEqual([
            { status: 'missing', text: 'Missing 1', color: 'red' },
            { status: 'attention', text: 'Needs attention 3', color: 'purple' },
            { status: 'current', text: 'Current 2', color: 'green' },
        ]);
        expect([cell.finished, cell.loading, cell.failed]).toEqual([true, false, false]);
    });

    it('is loading while it waits or scans, and failed with the error', () => {
        expect(describeCell(undefined)).toMatchObject({ badges: [], finished: false, loading: false });
        expect(describeCell({ status: 'waiting' }).loading).toBe(true);
        expect(describeCell({ status: 'loading', counts: counts({ outdated: 1 }), cursor: '4' })).toMatchObject({ loading: true, finished: false, outdated: 1 });
        expect(describeCell({ status: 'failed', error: 'Boom' })).toMatchObject({ failed: true, error: 'Boom', loading: false });
    });

    it('labels and colours every status', () => {
        expect(['missing', 'untranslated', 'outdated', 'attention', 'current'].map(statusLabel)).toEqual(['Missing', 'Untranslated', 'Outdated', 'Needs attention', 'Current']);
        expect(['missing', 'untranslated', 'outdated', 'attention', 'current', 'other'].map(statusColor)).toEqual(['red', 'orange', 'amber', 'purple', 'green', 'default']);
        expect(cellKey('globals', 'site_settings', 'el')).toBe('globals|site_settings|el');
    });
});

describe('runs', () => {
    it('translates one container into one site in the given mode', () => {
        expect(cellRun('entry', 'pages', 'el', 'missing')).toEqual({ sites: ['el'], collections: ['pages'], options: { mode: 'missing' } });
        expect(cellRun('term', 'topics', 'de', 'update')).toEqual({ sites: ['de'], taxonomies: ['topics'], options: { mode: 'update' } });
        expect(cellRun('globals', 'site_settings', 'el', 'missing')).toEqual({ sites: ['el'], globals: ['site_settings'], options: { mode: 'missing' } });
        expect(cellRun('nav', 'main', 'el', 'missing')).toEqual({ sites: ['el'], navs: ['main'], options: { mode: 'missing' } });
    });

    it('translates everything missing into a site: every container with a cell there, unless its counted cell has nothing missing', () => {
        const cells = {
            'entry|pages|el': done({ missing: 2 }),
            'entry|articles|el': done({ current: 4 }),
            'globals|site_settings|el': { status: 'loading', counts: counts({ current: 1 }), cursor: '1' },
            'nav|main|el': done({ untranslated: 1, outdated: 1 }),
            'nav|main|de': done({ current: 1 }),
        };

        expect(siteRun(ROWS, cells, 'el')).toEqual({
            sites: ['el'],
            navs: ['main'],
            collections: ['pages'],
            globals: ['site_settings'],
            options: { mode: 'missing' },
        });
        expect(siteRun(ROWS, {}, 'de')).toEqual({
            sites: ['de'],
            navs: ['main'],
            collections: ['pages'],
            globals: ['site_settings'],
            taxonomies: ['topics'],
            options: { mode: 'missing' },
        });
        expect(siteRun(ROWS, { 'entry|articles|el': done({ outdated: 3 }) }, 'el').collections).toEqual(['pages']);
        expect(siteRun([ROWS[2]], { 'entry|articles|el': done({ outdated: 3 }) }, 'el')).toBeNull();
        expect(siteRun(ROWS, {}, 'en')).toBeNull();
    });
});

describe('the page', () => {
    it('is empty with a single site, or without a provider unless the fake engine is on', () => {
        const providers = [{ provider: 'anthropic', configured: false }, { provider: 'openai', configured: true }];

        expect(emptyState({ multisite: false, providers, fakeEngine: true })).toBe('single_site');
        expect(emptyState({ multisite: true, providers, fakeEngine: false })).toBeNull();
        expect(emptyState({ multisite: true, providers: [providers[0]], fakeEngine: false })).toBe('no_provider');
        expect(emptyState({ multisite: true, providers: [providers[0]], fakeEngine: true })).toBeNull();
        expect(emptyState({ multisite: true, providers: undefined, fakeEngine: false })).toBe('no_provider');
    });

    it('sums the month\'s usage by model, with an unknown cost once a model has no price', () => {
        const usage = {
            month: '2026-10',
            models: {
                'openai:gpt-6.1-sol': { input: 1000, output: 500, calls: 2, cost: 0.007, sources: { run: {}, cli: {} } },
                'anthropic:claude-haiku-5-5': { input: 10, output: 5, calls: 1, cost: 0.001, sources: { editor: {} } },
            },
            labels: { 'openai:gpt-6.1-sol': 'GPT-6.1 Sol' },
        };

        expect(describeMonth(usage)).toEqual({
            month: '2026-10',
            rows: [
                { reference: 'openai:gpt-6.1-sol', model: 'GPT-6.1 Sol', calls: 2, input: 1000, output: 500, cost: 0.007, sources: ['run', 'cli'] },
                { reference: 'anthropic:claude-haiku-5-5', model: 'anthropic:claude-haiku-5-5', calls: 1, input: 10, output: 5, cost: 0.001, sources: ['editor'] },
            ],
            calls: 3,
            cost: 0.008,
        });

        usage.models['openai:custom'] = { input: 1, output: 1, calls: 1, cost: null, sources: { editor: {} } };
        expect(describeMonth(usage).cost).toBeNull();
        expect(describeMonth({ month: '2026-10', models: [], labels: [] })).toMatchObject({ rows: [], calls: 0, cost: 0 });
    });

    it('names where the usage came from', () => {
        expect(['editor', 'run', 'cli', 'auto', 'other'].map(sourceLabel)).toEqual(['Editor', 'Runs', 'Command line', 'Automatic updates', 'other']);
    });

    it('names the month', () => {
        expect(formatMonth('2026-10', 'en-US')).toBe('October 2026');
        expect(formatMonth('2027-01', 'en-US')).toBe('January 2027');
        expect(formatMonth('nonsense', 'en-US')).toBe('');
    });
});
