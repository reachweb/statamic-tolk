<?php

use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Statamic\Contracts\Entries\Entry as EntryContract;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'seo_title' => ['type' => 'text', 'localizable' => true, 'display' => 'SEO title', 'character_limit' => 60, 'tolk_hint' => 'Search result title'],
        'faq' => ['type' => 'grid', 'localizable' => true, 'display' => 'FAQ', 'fields' => [
            ['handle' => 'question', 'field' => ['type' => 'text']],
            ['handle' => 'answer', 'field' => ['type' => 'textarea']],
        ]],
        'notes' => ['type' => 'textarea'],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => 'The body text.',
        'seo_title' => 'Hello | Example',
    ], 'hello');
});

function planOptions(array $overrides = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $overrides);
}

function planTarget(EntryContract $source, array $options = [], string $site = 'el'): Plan
{
    return app(Planner::class)->plan(app(TargetFactory::class)->forEntry($source, $site), planOptions($options));
}

function changeOrigin(EntryContract $origin, array $values): void
{
    foreach ($values as $handle => $value) {
        $value === null ? $origin->remove($handle) : $origin->set($handle, $value);
    }

    $origin->save();
}

/**
 * A Greek localization with one field of each status: title untranslated, intro current, body outdated, seo_title manual.
 */
function localizationOfEveryStatus(EntryContract $origin): EntryContract
{
    $localization = test()->localize($origin, 'el', [
        'intro' => 'Μια σύντομη εισαγωγή.',
        'body' => 'Το κείμενο.',
        'seo_title' => 'Γεια σας | Example',
    ]);

    StateFixtures::store($localization, [
        'intro' => StateFixtures::machine('A short intro.', 'Μια σύντομη εισαγωγή.'),
        'body' => StateFixtures::machine('The old body text.', 'Το κείμενο.'),
    ]);

    return $localization;
}

describe('field selection', function () {
    it('selects the fields of each mode', function (array $options, array $planned, array $skipped) {
        localizationOfEveryStatus($this->origin);

        $plan = planTarget($this->origin, $options);

        expect($plan->fieldHandles())->toBe($planned)
            ->and($plan->skipped)->toBe($skipped);
    })->with([
        'missing' => [['mode' => Mode::Missing], ['title'], ['intro' => 'current', 'body' => 'outdated', 'seo_title' => 'manual', 'faq' => 'not_applicable']],
        'update' => [['mode' => Mode::Update], ['title', 'body'], ['intro' => 'current', 'seo_title' => 'manual', 'faq' => 'not_applicable']],
        'all' => [['mode' => Mode::All], ['title', 'intro', 'body'], ['seo_title' => 'manual', 'faq' => 'not_applicable']],
        'missing, overwriting manual edits' => [['mode' => Mode::Missing, 'overwriteManual' => true], ['title', 'seo_title'], ['intro' => 'current', 'body' => 'outdated', 'faq' => 'not_applicable']],
        'update, overwriting manual edits' => [['mode' => Mode::Update, 'overwriteManual' => true], ['title', 'body', 'seo_title'], ['intro' => 'current', 'faq' => 'not_applicable']],
        'all, overwriting manual edits' => [['mode' => Mode::All, 'overwriteManual' => true], ['title', 'intro', 'body', 'seo_title'], ['faq' => 'not_applicable']],
    ]);

    it('records the status of each planned field before the translation', function () {
        localizationOfEveryStatus($this->origin);

        $fields = planTarget($this->origin, ['mode' => Mode::All, 'overwriteManual' => true])->fields;

        expect(array_map(fn (array $field) => $field['status_before'], $fields))->toBe([
            'title' => 'untranslated',
            'intro' => 'current',
            'body' => 'outdated',
            'seo_title' => 'manual',
        ]);
    });

    it('skips a manual field unless manual edits are overwritten', function () {
        $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);

        $kept = planTarget($this->origin, ['mode' => Mode::All]);
        $overwritten = planTarget($this->origin, ['mode' => Mode::All, 'overwriteManual' => true]);

        expect($kept->skipped['title'])->toBe('manual')
            ->and($kept->fieldHandles())->not->toContain('title')
            ->and($overwritten->fieldHandles())->toContain('title')
            ->and($overwritten->fields['title']['target_before'])->toBe(Fingerprint::ofLeaves(['Χειροποίητο']))
            ->and($overwritten->segmentsOfField('title'))->toHaveCount(1);
    });

    it('plans only the requested fields and records the others as not requested', function () {
        $plan = planTarget($this->origin, ['fields' => ['body', 'title', 'unknown']]);

        expect($plan->fieldHandles())->toBe(['title', 'body'])
            ->and($plan->skipped)->toBe(['intro' => 'not_requested', 'seo_title' => 'not_requested', 'faq' => 'not_requested'])
            ->and(array_column($plan->segments, 'field'))->toBe(['title', 'body']);
    });

    it('skips the fields the caller rules out, with the caller\'s reason', function () {
        $plan = planTarget($this->origin, ['skip' => ['intro' => 'edited_in_form', 'notes' => 'edited_in_form']]);

        expect($plan->fieldHandles())->toBe(['title', 'body', 'seo_title'])
            ->and($plan->skipped)->toBe(['intro' => 'edited_in_form', 'faq' => 'not_applicable']);
    });

    it('applies the fields filter before the caller\'s skips and both before the status', function () {
        localizationOfEveryStatus($this->origin);

        $plan = planTarget($this->origin, [
            'fields' => ['title', 'intro'],
            'skip' => ['title' => 'edited_in_form', 'body' => 'edited_in_form', 'intro' => 'edited_in_form'],
        ]);

        expect($plan->fieldHandles())->toBe([])
            ->and($plan->skipped)->toBe([
                'title' => 'edited_in_form',
                'intro' => 'edited_in_form',
                'body' => 'not_requested',
                'seo_title' => 'not_requested',
                'faq' => 'not_requested',
            ])
            ->and($plan->isEmpty())->toBeTrue();
    });

    it('plans every applicable field of a missing localization', function () {
        $plan = planTarget($this->origin);

        expect($plan->reference)->toBeNull()
            ->and($plan->fieldHandles())->toBe(['title', 'intro', 'body', 'seo_title'])
            ->and($plan->skipped)->toBe(['faq' => 'not_applicable'])
            ->and(array_column($plan->fields, 'status_before'))->toBe(array_fill(0, 4, 'untranslated'))
            ->and(array_column($plan->fields, 'target_before'))->toBe(array_fill(0, 4, null))
            ->and($plan->isEmpty())->toBeFalse();
    });

    it('skips a field its chained source inherits as untranslated in the origin', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'faq' => [['question' => 'Τι;', 'answer' => 'Αυτό.']]]);

        $plan = planTarget($el, ['mode' => Mode::All, 'overwriteManual' => true], 'de');

        expect($plan->sourceSite)->toBe('el')
            ->and($plan->targetSite)->toBe('de')
            ->and($plan->fieldHandles())->toBe(['title', 'faq'])
            ->and($plan->skipped)->toBe(['intro' => 'origin_untranslated', 'body' => 'origin_untranslated', 'seo_title' => 'origin_untranslated'])
            ->and(array_column($plan->segments, 'text'))->toBe(['Γεια', 'Τι;', 'Αυτό.']);
    });

    it('never clears a chained target\'s translation while its source inherits the field', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $de = $this->localize($el->fresh(), 'de', ['title' => 'Hallo', 'intro' => 'Eine kurze Einleitung.']);
        StateFixtures::store($de, ['intro' => StateFixtures::machine('Μια εισαγωγή.', 'Eine kurze Einleitung.')]);

        $plan = planTarget($this->origin, ['mode' => Mode::Update], 'de');

        expect($plan->skipped['intro'])->toBe('origin_untranslated')
            ->and($plan->fieldHandles())->not->toContain('intro');
    });

    it('refuses mode review, which has a planner of its own', function () {
        expect(fn () => planTarget($this->origin, ['mode' => Mode::Review]))
            ->toThrow(InvalidArgumentException::class, 'planReview');
    });
});

describe('fields and segments', function () {
    it('numbers the source leaves in document order across fields', function () {
        changeOrigin($this->origin, ['faq' => [
            ['id' => 'r1', 'question' => 'Why?', 'answer' => 'Because.'],
            ['id' => 'r2', 'question' => 'How?', 'answer' => 'https://example.com'],
        ]]);

        $plan = planTarget($this->origin);

        expect(array_keys($plan->segments))->toBe(['s1', 's2', 's3', 's4', 's5', 's6', 's7'])
            ->and(array_column($plan->segments, 'text'))->toBe(['Hello', 'A short intro.', 'The body text.', 'Hello | Example', 'Why?', 'Because.', 'How?'])
            ->and($plan->fields['faq'])->toBe([
                'source_hash' => Fingerprint::of($this->origin->get('faq')),
                'target_before' => null,
                'leaf_count' => 3,
                'status_before' => 'untranslated',
                'segment_ids' => ['s5', 's6', 's7'],
                'reused' => 0,
                'reused_translator' => null,
                'clear' => false,
            ])
            ->and(array_column($plan->segmentsOfField('faq'), 'index'))->toBe([0, 1, 2])
            ->and($plan->segments['s7']['label'])->toBe('FAQ › Question');
    });

    it('describes each segment with the keys of the plan shape', function () {
        $plan = planTarget($this->origin, ['fields' => ['seo_title']]);

        expect($plan->segments)->toBe(['s1' => [
            'field' => 'seo_title',
            'index' => 0,
            'kind' => 'plain',
            'text' => 'Hello | Example',
            'label' => 'SEO title',
            'hint' => 'Search result title',
            'max_length' => 60,
            'plural_form' => false,
            'before' => null,
            'after' => null,
            'translation' => null,
        ]]);
    });

    it('gives a markdown segment what its placeholders stand for, which the validator reads the translation back with', function () {
        $this->makeCollection('pages', ['en', 'el'], [
            'body' => ['type' => 'markdown', 'localizable' => true],
        ]);
        $origin = $this->makeEntry('pages', 'en', ['body' => 'Run `npm install` first.'], 'start');

        expect(planTarget($origin)->segments['s1'])->toMatchArray([
            'kind' => 'markdown',
            'text' => 'Run <x1/> first.',
            'originals' => ['x1' => '`npm install`'],
        ])->and(planTarget($this->origin, ['fields' => ['seo_title']])->segments['s1'])->not->toHaveKey('originals');
    });

    it('records the source fingerprint and the target text fingerprint of each field', function () {
        localizationOfEveryStatus($this->origin);

        $fields = planTarget($this->origin, ['mode' => Mode::All])->fields;

        expect($fields['title']['source_hash'])->toBe(Fingerprint::of('Hello'))
            ->and($fields['title']['target_before'])->toBeNull()
            ->and($fields['body']['source_hash'])->toBe(Fingerprint::of('The body text.'))
            ->and($fields['body']['target_before'])->toBe(Fingerprint::ofLeaves(['Το κείμενο.']))
            ->and($fields['body']['leaf_count'])->toBe(1);
    });

    it('plans a field whose source was emptied as a clear without segments', function () {
        $localization = $this->localize($this->origin, 'el', ['body' => 'Το κείμενο.']);
        StateFixtures::store($localization, ['body' => StateFixtures::machine('The body text.', 'Το κείμενο.')]);
        changeOrigin($this->origin, ['body' => null]);

        $plan = planTarget($this->origin, ['mode' => Mode::Update, 'fields' => ['body']]);

        expect($plan->fields['body'])->toBe([
            'source_hash' => Fingerprint::of(null),
            'target_before' => Fingerprint::ofLeaves(['Το κείμενο.']),
            'leaf_count' => 0,
            'status_before' => 'outdated',
            'segment_ids' => [],
            'reused' => 0,
            'reused_translator' => null,
            'clear' => true,
        ])
            ->and($plan->segments)->toBe([])
            ->and($plan->batches)->toBe([])
            ->and($plan->isEmpty())->toBeFalse();
    });

    it('plans a source that keeps its structure but loses its text as a clear', function () {
        $faq = [['id' => 'r1', 'question' => 'Why?', 'answer' => 'Because.']];
        changeOrigin($this->origin, ['faq' => $faq]);
        $localization = $this->localize($this->origin, 'el', ['faq' => [['id' => 'r1', 'question' => 'Γιατί;', 'answer' => 'Επειδή.']]]);
        StateFixtures::store($localization, ['faq' => new FieldState(
            sourceHash: Fingerprint::of($faq),
            targetHash: Fingerprint::ofLeaves(['Γιατί;', 'Επειδή.']),
            segments: [Fingerprint::ofText('Why?'), Fingerprint::ofText('Because.')],
        )]);
        changeOrigin($this->origin, ['faq' => [['id' => 'r1', 'question' => '42', 'answer' => 'https://example.com']]]);

        $field = planTarget($this->origin, ['mode' => Mode::Update])->fields['faq'];

        expect($field['clear'])->toBeTrue()
            ->and($field['segment_ids'])->toBe([])
            ->and($field['status_before'])->toBe('outdated');
    });
});

describe('the plan', function () {
    it('describes the target, its document and the options', function () {
        $localization = localizationOfEveryStatus($this->origin);
        $target = app(TargetFactory::class)->forEntry($this->origin, 'el');

        $plan = app(Planner::class)->plan($target, planOptions(['mode' => Mode::Update, 'userId' => 'user-1', 'profile' => 'background']));

        expect($plan->id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($plan->kind)->toBe(UnitKind::Translate)
            ->and($plan->reference)->toBe('entry::'.$localization->id())
            ->and($plan->target)->toBe(['type' => 'entry', 'source_id' => $this->origin->id(), 'site' => 'el'])
            ->and($plan->sourceSite)->toBe('en')
            ->and($plan->targetSite)->toBe('el')
            ->and($plan->document)->toBe($target->document())
            ->and($plan->options->userId)->toBe('user-1')
            ->and($plan->toArray()['user_id'])->toBe('user-1')
            ->and($plan->toArray()['options'])->toBe([
                'mode' => 'update',
                'overwrite_manual' => false,
                'fields' => null,
                'skip' => [],
                'translator' => 'anthropic:claude-sonnet-5-5',
                'review' => false,
                'reviewer' => null,
                'fallback' => null,
                'profile' => 'background',
                'strict' => false,
                'parallel' => false,
            ]);
    });

    it('gives every plan a new id', function () {
        expect(planTarget($this->origin)->id)->not->toBe(planTarget($this->origin)->id);
    });

    it('batches the segments with the budget of the profile', function () {
        config(['tolk.batch.max_chars.web' => 20, 'tolk.batch.max_chars.background' => 40]);

        $web = planTarget($this->origin);
        $background = planTarget($this->origin, ['profile' => 'background']);

        expect($web->batches)->toBe([
            ['id' => 'b1', 'segment_ids' => ['s1', 's2'], 'chars' => 19],
            ['id' => 'b2', 'segment_ids' => ['s3'], 'chars' => 14],
            ['id' => 'b3', 'segment_ids' => ['s4'], 'chars' => 15],
        ])
            ->and($background->batches)->toBe([
                ['id' => 'b1', 'segment_ids' => ['s1', 's2', 's3'], 'chars' => 33],
                ['id' => 'b2', 'segment_ids' => ['s4'], 'chars' => 15],
            ])
            ->and($web->batch('b2'))->toBe(['id' => 'b2', 'segment_ids' => ['s3'], 'chars' => 14])
            ->and($web->batch('b9'))->toBeNull();
    });

    it('splits a small entry over the editor\'s parallel requests, and only for them', function (array $config, array $batches, array $serial) {
        config($config);

        expect(array_column(planTarget($this->origin, ['parallel' => true])->batches, 'segment_ids'))->toBe($batches)
            ->and(array_column(planTarget($this->origin, ['profile' => 'background', 'parallel' => true])->batches, 'segment_ids'))->toBe([['s1', 's2', 's3', 's4']])
            ->and(array_column(planTarget($this->origin)->batches, 'segment_ids'))->toBe($serial);
    })->with([
        'the defaults' => [[], [['s1', 's2', 's3', 's4']], [['s1', 's2', 's3', 's4']]],
        'the floor' => [['tolk.batch.min_chars' => 30], [['s1', 's2'], ['s3', 's4']], [['s1', 's2', 's3', 's4']]],
        'a share of each request' => [['tolk.batch.min_chars' => 10, 'tolk.concurrency.editor' => 2], [['s1', 's2'], ['s3'], ['s4']], [['s1', 's2', 's3', 's4']]],
        'the most per batch' => [['tolk.batch.min_chars' => 30, 'tolk.batch.max_chars.web' => 16], [['s1'], ['s2'], ['s3'], ['s4']], [['s1'], ['s2'], ['s3'], ['s4']]],
    ]);

    it('caps the segments of a batch', function () {
        config(['tolk.batch.max_segments' => 3]);

        expect(array_column(planTarget($this->origin)->batches, 'segment_ids'))->toBe([['s1', 's2', 's3'], ['s4']]);
    });

    it('is empty when every field is current', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Εισαγωγή.', 'body' => 'Κείμενο.', 'seo_title' => 'Γεια | Example']);
        StateFixtures::store($localization, [
            'title' => StateFixtures::machine('Hello', 'Γεια'),
            'intro' => StateFixtures::machine('A short intro.', 'Εισαγωγή.'),
            'body' => StateFixtures::machine('The body text.', 'Κείμενο.'),
            'seo_title' => StateFixtures::machine('Hello | Example', 'Γεια | Example'),
        ]);

        $plan = planTarget($this->origin, ['mode' => Mode::Update]);

        expect($plan->isEmpty())->toBeTrue()
            ->and($plan->fields)->toBe([])
            ->and($plan->skipped)->toBe(['title' => 'current', 'intro' => 'current', 'body' => 'current', 'seo_title' => 'current', 'faq' => 'not_applicable'])
            ->and(planTarget($this->origin, ['mode' => Mode::All])->isEmpty())->toBeFalse();
    });

    it('reconciles pending state in memory and writes nothing', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια'))]);
        $repository = app(StateRepository::class);
        $before = $repository->get(StateFixtures::ref($localization));

        $plan = planTarget($this->origin, ['mode' => Mode::Update]);

        expect($plan->skipped['title'])->toBe('current')
            ->and($repository->get(StateFixtures::ref($localization)))->toEqual($before)
            ->and(iterator_to_array($repository->references()))->toHaveCount(1);
    });

    it('survives toArray() and fromArray() through the cache', function () {
        localizationOfEveryStatus($this->origin);
        changeOrigin($this->origin, ['faq' => [['id' => 'r1', 'question' => 'Why?', 'answer' => 'Because.']]]);
        $plan = planTarget($this->origin, [
            'mode' => Mode::Update,
            'fields' => ['title', 'body', 'faq'],
            'skip' => ['faq' => 'edited_in_form'],
            'review' => true,
            'strict' => true,
            'userId' => 'user-1',
        ]);

        Cache::put('plan-test', $plan->toArray());
        $restored = Plan::fromArray(Cache::get('plan-test'));

        expect($restored)->toEqual($plan)
            ->and($restored->toArray())->toBe($plan->toArray())
            ->and(array_keys($plan->toArray()))->toBe(['id', 'kind', 'reference', 'target', 'user_id', 'source_site', 'target_site', 'options', 'document', 'fields', 'segments', 'batches', 'skipped', 'notes']);
    });
});
