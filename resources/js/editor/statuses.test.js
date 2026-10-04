import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { countParts, describe as describeStatus, describeSummary, itemStatusLabel, modelName, needsSetup } from './statuses.js';

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

afterEach(() => {
    vi.unstubAllGlobals();
});

const zero = { untranslated: 0, current: 0, outdated: 0, manual: 0, attention: 0, untracked: 0 };

function field(label, status, extra = {}) {
    return { status, label, source_changed: null, incomplete: false, ...extra };
}

function localization(fields, counts, extra = {}) {
    return {
        source: { site: 'en', name: 'English' },
        target: { site: 'el', name: 'Greek' },
        status: 'current',
        fields,
        counts: { ...zero, ...counts },
        out_of_scope: [],
        origin_slug: 'what-to-eat-in-chania',
        ...extra,
    };
}

function payload(blocks = {}, defaults = {}) {
    return {
        translatable: true,
        reason: null,
        localization: null,
        origin: null,
        defaults: { translator: 'anthropic:claude-sonnet-5-5', providers_configured: true, fake_engine: false, ...defaults },
        offer: true,
        ...blocks,
    };
}

const texts = (parts) => parts.map((part) => part.text);

describe('a localization', () => {
    it('with nothing to do shows no buttons', () => {
        const banner = describeStatus(
            payload({
                localization: localization(
                    { title: field('Title', 'current', { source_changed: false }), intro: field('Intro', 'current', { source_changed: false }) },
                    { current: 2 },
                ),
            }),
        );

        expect(banner).toMatchObject({ visible: true, setup: false, translator: 'anthropic:claude-sonnet-5-5', origin: null });
        expect(banner.localization).toMatchObject({
            source: { site: 'en', name: 'English' },
            target: { site: 'el', name: 'Greek' },
            status: 'current',
            label: 'Up to date',
            summary: 'Up to date with English.',
            lead: 'Up to date with English.',
            incomplete: [],
            incompleteNote: null,
            notLocalizable: [],
            hint: null,
            translate: false,
            update: false,
        });
        expect(texts(banner.localization.parts)).toEqual(['2 fields up to date']);
    });

    it('with untranslated fields only offers Translate', () => {
        const banner = describeStatus(
            payload({
                localization: localization(
                    { title: field('Title', 'untranslated'), intro: field('Intro', 'untranslated'), seo_title: field('SEO title', 'untranslated') },
                    { untranslated: 3 },
                    { status: 'untranslated' },
                ),
            }),
        );

        expect(banner.localization).toMatchObject({
            label: 'Untranslated',
            summary: 'From English: 3 fields untranslated.',
            lead: 'From English:',
            translate: true,
            update: false,
        });
        expect(banner.localization.parts).toEqual([
            { key: 'untranslated', count: 3, text: '3 fields untranslated', description: expect.stringContaining('Translate fills them in') },
        ]);
    });

    it('with outdated and manual fields offers Update and counts the manual ones that need attention', () => {
        const banner = describeStatus(
            payload({
                localization: localization(
                    {
                        title: field('Title', 'current', { source_changed: false }),
                        intro: field('Intro', 'outdated', { source_changed: true }),
                        body: field('Body', 'outdated', { source_changed: true }),
                        teaser: field('Teaser', 'manual', { source_changed: true }),
                        seo_title: field('SEO title', 'manual', { source_changed: false }),
                    },
                    { current: 1, outdated: 2, manual: 2, attention: 1 },
                    { status: 'outdated' },
                ),
            }),
        );

        expect(banner.localization).toMatchObject({ label: 'Outdated', translate: false, update: true, incomplete: [] });
        expect(banner.localization.parts.map((part) => part.key)).toEqual(['outdated', 'manual', 'attention', 'current']);
        expect(banner.localization.summary).toBe(
            'From English: 2 fields outdated, 2 fields edited by hand, 1 needs attention, 1 field up to date.',
        );
        expect(banner.localization.lead).toBe('From English:');
    });

    it('with untranslated and outdated fields offers both buttons', () => {
        const banner = describeStatus(
            payload({
                localization: localization(
                    { title: field('Title', 'untranslated'), intro: field('Intro', 'outdated', { source_changed: true }) },
                    { untranslated: 1, outdated: 1 },
                    { status: 'untranslated' },
                ),
            }),
        );

        expect(banner.localization).toMatchObject({
            summary: 'From English: 1 field untranslated, 1 field outdated.',
            translate: true,
            update: true,
        });
    });

    it('names the fields left partly untranslated', () => {
        const banner = describeStatus(
            payload({
                localization: localization(
                    {
                        title: field('Title', 'current', { source_changed: false }),
                        intro: field('Intro', 'outdated', { source_changed: false, incomplete: true }),
                    },
                    { current: 1, outdated: 1 },
                    { status: 'outdated' },
                ),
            }),
        );

        expect(banner.localization).toMatchObject({
            incomplete: [{ handle: 'intro', label: 'Intro' }],
            incompleteNote: 'Intro is partly untranslated. Update translates the rest.',
            update: true,
        });

        const two = describeStatus(
            payload({
                localization: localization(
                    {
                        intro: field('Intro', 'outdated', { incomplete: true }),
                        body: field('Body', 'outdated', { incomplete: true }),
                    },
                    { outdated: 2 },
                    { status: 'outdated' },
                ),
            }),
        );

        expect(two.localization.incompleteNote).toBe('Intro, Body are partly untranslated. Update translates the rest.');
    });

    it('counts fields edited by hand before they were tracked', () => {
        const banner = describeStatus(
            payload({
                localization: localization(
                    { title: field('Title', 'manual'), intro: field('Intro', 'manual'), body: field('Body', 'manual', { source_changed: false }) },
                    { manual: 3, untracked: 2 },
                    { status: 'current' },
                ),
            }),
        );

        expect(banner.localization).toMatchObject({
            label: 'Up to date',
            summary: 'From English: 3 fields edited by hand, 2 untracked.',
            translate: false,
            update: false,
        });
        expect(banner.localization.parts[1]).toMatchObject({
            key: 'untracked',
            count: 2,
            description: expect.stringContaining('Mark them as up to date'),
        });
    });

    it('hints at text fields that are not localizable, and only at those', () => {
        const banner = describeStatus(
            payload({
                localization: localization({ title: field('Title', 'untranslated') }, { untranslated: 1 }, {
                    status: 'untranslated',
                    out_of_scope: [
                        { handle: 'teaser', label: 'Teaser', type: 'textarea', reason: 'not_localizable' },
                        { handle: 'notes', label: 'Notes', type: 'text', reason: 'disabled' },
                        { handle: 'kicker', label: 'Kicker', type: 'text', reason: 'not_localizable' },
                    ],
                }),
            }),
        );

        expect(banner.localization.notLocalizable).toEqual([
            { handle: 'teaser', label: 'Teaser' },
            { handle: 'kicker', label: 'Kicker' },
        ]);
        expect(banner.localization.hint).toBe(
            'Teaser, Kicker are not translated because they are not localizable. Run php please tolk:doctor for details.',
        );
    });

    it('has nothing to translate when no field is in scope', () => {
        const banner = describeStatus(payload({ localization: localization([], {}) }));

        expect(banner.localization).toMatchObject({
            parts: [],
            summary: 'Nothing to translate from English.',
            lead: 'Nothing to translate from English.',
            translate: false,
            update: false,
        });
    });
});

describe('an origin', () => {
    const origin = {
        localizations: [
            {
                site: 'el',
                name: 'Greek',
                exists: true,
                status: 'outdated',
                counts: { ...zero, outdated: 1, current: 3 },
                edit_url: '/cp/collections/articles/entries/el-id',
            },
            {
                site: 'de',
                name: 'German',
                exists: false,
                status: 'missing',
                counts: { ...zero, untranslated: 4 },
                edit_url: null,
            },
        ],
    };

    it('lists one missing and one outdated localization', () => {
        const banner = describeStatus(payload({ origin }));

        expect(banner.localization).toBeNull();
        expect(banner.origin.localizations).toEqual([
            {
                site: 'el',
                name: 'Greek',
                exists: true,
                status: 'outdated',
                label: 'Outdated',
                parts: expect.any(Array),
                summary: '1 field outdated, 3 fields up to date',
                editUrl: '/cp/collections/articles/entries/el-id',
                translate: false,
                update: true,
            },
            {
                site: 'de',
                name: 'German',
                exists: false,
                status: 'missing',
                label: 'Missing',
                parts: [],
                summary: 'Not created yet.',
                editUrl: null,
                translate: true,
                update: false,
            },
        ]);
        expect(banner.origin).toMatchObject({ translateInto: true, update: true });
    });

    it('offers no button for localizations that are up to date', () => {
        const banner = describeStatus(
            payload({
                origin: {
                    localizations: [
                        { site: 'el', name: 'Greek', exists: true, status: 'current', counts: { ...zero, current: 4 }, edit_url: '/el' },
                        { site: 'de', name: 'German', exists: true, status: 'attention', counts: { ...zero, manual: 1, attention: 1 }, edit_url: '/de' },
                    ],
                },
            }),
        );

        expect(banner.origin).toMatchObject({ translateInto: false, update: false });
        expect(banner.origin.localizations.map((l) => [l.label, l.summary])).toEqual([
            ['Up to date', '4 fields up to date'],
            ['Needs attention', '1 field edited by hand, 1 needs attention'],
        ]);
    });
});

it('describes both blocks for an entry in the middle of a chain', () => {
    const banner = describeStatus(
        payload({
            localization: localization(
                { title: field('Title', 'current', { source_changed: false }), intro: field('Intro', 'untranslated') },
                { current: 1, untranslated: 1 },
                { status: 'untranslated', target: { site: 'de', name: 'German' } },
            ),
            origin: {
                localizations: [
                    { site: 'el', name: 'Greek', exists: true, status: 'untranslated', counts: { ...zero, untranslated: 2 }, edit_url: '/el' },
                ],
            },
        }),
    );

    expect(banner.localization).toMatchObject({
        target: { site: 'de', name: 'German' },
        summary: 'From English: 1 field untranslated, 1 field up to date.',
        translate: true,
        update: false,
    });
    expect(banner.origin).toMatchObject({ translateInto: true, update: false });
    expect(banner.origin.localizations[0]).toMatchObject({ site: 'el', summary: '2 fields untranslated', translate: true });
});

describe('setup', () => {
    const block = () => localization({ title: field('Title', 'untranslated') }, { untranslated: 1, outdated: 1 }, { status: 'untranslated' });
    const missing = { localizations: [{ site: 'de', name: 'German', exists: false, status: 'missing', counts: zero, edit_url: null }] };

    it('hides every button without a provider and without the fake engine', () => {
        const banner = describeStatus(payload({ localization: block(), origin: missing }, { translator: null, providers_configured: false }));

        expect(banner.setup).toBe(true);
        expect(banner.localization).toMatchObject({ translate: false, update: false, summary: 'From English: 1 field untranslated, 1 field outdated.' });
        expect(banner.origin).toMatchObject({ translateInto: false, update: false });
        expect(banner.origin.localizations[0].translate).toBe(true);
    });

    it('is not needed with the fake engine', () => {
        const status = payload({ localization: block() }, { translator: 'anthropic:claude-sonnet-5-5', providers_configured: false, fake_engine: true });

        expect(needsSetup(status)).toBe(false);
        expect(describeStatus(status).localization.translate).toBe(true);
    });

    it('is needed when no default model resolves', () => {
        expect(needsSetup(payload({}, { translator: null, providers_configured: true }))).toBe(true);
    });
});

describe('not translatable', () => {
    it.each(['single_site', 'unknown_reference', 'no_access'])('hides the banner for %s', (reason) => {
        const status = { ...payload(), translatable: false, reason };

        expect(describeStatus(status)).toEqual({
            visible: false,
            reason,
            setup: false,
            translator: null,
            localization: null,
            origin: null,
        });
    });

    it('hides the banner before the status has loaded', () => {
        expect(describeStatus(null)).toMatchObject({ visible: false, reason: null });
    });
});

describe('helpers', () => {
    it('labels every item status', () => {
        expect(['missing', 'untranslated', 'outdated', 'attention', 'current'].map(itemStatusLabel)).toEqual([
            'Missing',
            'Untranslated',
            'Outdated',
            'Needs attention',
            'Up to date',
        ]);
    });

    it('lists the non-zero counts in banner order', () => {
        expect(countParts({ current: 2, untracked: 1, manual: 1, untranslated: 0 }).map((part) => part.key)).toEqual([
            'manual',
            'untracked',
            'current',
        ]);
        expect(countParts()).toEqual([]);
    });
});

describe('describeSummary', () => {
    const status = {
        localization: {
            fields: {
                title: field('Title', 'manual'),
                intro: field('Introduction', 'untranslated'),
                seo_title: field('SEO title', 'untranslated'),
                seo_description: field('SEO description', 'untranslated'),
            },
        },
    };

    const summary = (extra = {}) => ({
        delivered: [],
        incomplete: [],
        skipped: {},
        failed: {},
        models: [],
        cancelled: false,
        ...extra,
    });

    it('is null without a summary', () => {
        expect(describeSummary(null)).toBeNull();
    });

    it('names the count and the model', () => {
        expect(describeSummary(summary({ delivered: ['intro', 'seo_title'], models: ['anthropic:claude-sonnet-5-5'] }), status)).toEqual({
            translated: '2 fields translated with claude-sonnet-5-5.',
            reviewed: null,
            corrections: 0,
            reviewSkipped: null,
            incomplete: null,
            edited: null,
            manual: null,
            failed: null,
            cancelled: null,
            unsaved: 'Not saved yet. Check the fields, then save.',
        });
    });

    it('names the reviewer and the corrections of a translation that was reviewed', () => {
        const corrections = [{ handle: 'intro', id: 's2', label: 'Introduction', before: 'a', after: 'b', reason: 'Better.' }];
        const text = describeSummary(
            summary({ delivered: ['intro', 'seo_title'], models: ['anthropic:claude-sonnet-5-5', 'openai:gpt-6.1-sol'], reviewer: 'openai:gpt-6.1-sol', corrections }),
            status,
        );

        expect(text.translated).toBe('2 fields translated with claude-sonnet-5-5.');
        expect(text.reviewed).toBe('Reviewed with gpt-6.1-sol: 1 correction.');
        expect(text.corrections).toBe(1);
        expect(describeSummary(summary({ delivered: ['intro'], reviewer: 'openai:gpt-6.1-sol', corrections: [...corrections, ...corrections] }), status).reviewed).toBe(
            'Reviewed with gpt-6.1-sol: 2 corrections.',
        );
    });

    it('names the reviewer as the translator when it translated too', () => {
        const text = describeSummary(summary({ delivered: ['intro'], models: ['anthropic:claude-sonnet-5-5'], reviewer: 'anthropic:claude-sonnet-5-5' }), status);

        expect(text.translated).toBe('1 field translated with claude-sonnet-5-5.');
        expect(text.reviewed).toBe('Reviewed with claude-sonnet-5-5: nothing to correct.');
        expect(text.corrections).toBe(0);
    });

    it('describes a review without a translated line', () => {
        const review = (extra = {}) =>
            summary({ kind: 'review', planned: ['intro', 'seo_title'], models: ['openai:gpt-6.1-sol'], reviewer: 'openai:gpt-6.1-sol', ...extra });
        const corrected = describeSummary(review({ delivered: ['intro'], corrections: [{ handle: 'intro', id: 's1' }] }), status);

        expect(corrected.translated).toBeNull();
        expect(corrected.reviewed).toBe('Reviewed with gpt-6.1-sol: 1 correction.');
        expect(corrected.unsaved).toBe('Not saved yet. Check the fields, then save.');

        const unchanged = describeSummary(review(), status);

        expect(unchanged.reviewed).toBe('Reviewed with gpt-6.1-sol: nothing to correct.');
        expect(unchanged.unsaved).toBeNull();
        expect(describeSummary(review({ planned: [] }), status).reviewed).toBe('There was nothing to review: no field holds a translation that is up to date.');
        expect(describeSummary(review({ cancelled: true }), status).cancelled).toBe('The review was cancelled before it finished.');
        expect(describeSummary(review({ failed: { intro: 'failed' } }), status).failed).toBe('Introduction could not be reviewed.');
    });

    it('says that a review left out manual fields, and how to include them', () => {
        expect(describeSummary(summary({ kind: 'review', planned: ['intro'], planSkipped: { title: 'manual', seo_title: 'outdated' } }), status).manual).toBe(
            'Title was not reviewed because it was edited by hand. Overwrite manual edits in the options reviews it too.',
        );
    });

    it('says when part of the review could not be made', () => {
        expect(describeSummary(summary({ delivered: ['intro'], reviewer: 'openai:gpt-6.1-sol', reviewSkipped: ['b1.v'] }), status).reviewSkipped).toBe(
            'Part of the text could not be reviewed and was kept as it was.',
        );
        expect(describeSummary(summary({ reviewSkipped: [] }), status).reviewSkipped).toBeNull();
    });

    it('names every model used and the singular', () => {
        const text = describeSummary(summary({ delivered: ['intro'], models: ['anthropic:claude-sonnet-5-5', 'openai:gpt-6-luna'] }), status);

        expect(text.translated).toBe('1 field translated with claude-sonnet-5-5, gpt-6-luna.');
    });

    it('leaves the model out when no call was made', () => {
        expect(describeSummary(summary({ delivered: ['intro'] }), status).translated).toBe('1 field translated.');
    });

    it('says when nothing was filled, without the save hint', () => {
        const text = describeSummary(summary({ skipped: { intro: 'edited' } }), status);

        expect(text.translated).toBe('No fields were translated.');
        expect(text.unsaved).toBeNull();
        expect(text.edited).toBe('Introduction was skipped because you edited it.');
    });

    it('lists incomplete, edited and failed fields by label', () => {
        const text = describeSummary(
            summary({
                delivered: ['intro', 'seo_title'],
                incomplete: ['intro', 'seo_title'],
                skipped: { title: 'edited', seo_description: 'edited', other: 'stale' },
                failed: { body: 'refused' },
            }),
            status,
        );

        expect(text.incomplete).toBe('Introduction, SEO title are partly untranslated. Update translates the rest after you save.');
        expect(text.edited).toBe('Title, SEO description were skipped because you edited them.');
        expect(text.failed).toBe('body could not be translated.');
    });

    it('lists fields the plan skipped because they hold hand-written text, and no other plan reason', () => {
        expect(describeSummary(summary({ planSkipped: { title: 'manual', intro: 'current', seo_title: 'not_requested' } }), status).manual).toBe(
            'Title was skipped because it was edited by hand. Overwrite manual edits in the options replaces it.',
        );
        expect(describeSummary(summary({ planSkipped: { title: 'manual', seo_description: 'manual' } }), status).manual).toBe(
            'Title, SEO description were skipped because they were edited by hand. Overwrite manual edits in the options replaces them.',
        );
        expect(describeSummary(summary({ planSkipped: { intro: 'current' } }), status).manual).toBeNull();
        expect(describeSummary(summary(), status).manual).toBeNull();
    });

    it('says when it was cancelled', () => {
        expect(describeSummary(summary({ cancelled: true }), status).cancelled).toBe('The translation was cancelled before it finished.');
    });

    it('falls back to handles when the status has no fields', () => {
        expect(describeSummary(summary({ failed: { intro: 'refused' } }), { localization: { fields: [] } }).failed).toBe('intro could not be translated.');
    });
});

describe('modelName', () => {
    it('drops the provider', () => {
        expect(modelName('gemini:gemini-3.8-flash')).toBe('gemini-3.8-flash');
        expect(modelName('plain')).toBe('plain');
    });
});
