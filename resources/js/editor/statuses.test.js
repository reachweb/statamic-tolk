import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { countParts, describe as describeStatus, itemStatusLabel, needsSetup } from './statuses.js';

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

        expect(banner.localization).toMatchObject({ parts: [], summary: 'Nothing to translate from English.', translate: false, update: false });
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
