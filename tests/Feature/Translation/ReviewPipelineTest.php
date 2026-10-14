<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;
use Reach\StatamicTolk\Ai\Agents\ReviewAgent;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaved;

const REVIEW_EXISTING_LINK = ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms']];

const REVIEW_EXISTING_TRANSLATOR = 'anthropic:claude-sonnet-5-5';

const REVIEW_EXISTING_REVIEWER = 'openai:gpt-6.1-sol';

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'body' => ['type' => 'bard', 'localizable' => true, 'display' => 'Body', 'buttons' => ['bold', 'italic', 'anchor']],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => [
            reviewExistingParagraph(['Book', ['type' => 'bold']], ' a ', ['sea view suite', REVIEW_EXISTING_LINK], '.'),
            reviewExistingParagraph('Breakfast is served until ten.'),
        ],
    ], 'hello');
});

/**
 * A Bard paragraph; a string is a plain text node, [text, mark] a text node with one mark.
 */
function reviewExistingParagraph(string|array ...$runs): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (string|array $run) => is_string($run)
        ? ['type' => 'text', 'text' => $run]
        : ['type' => 'text', 'marks' => [$run[1]], 'text' => $run[0]], $runs)];
}

function reviewExistingOptions(array $overrides = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['mode' => Mode::Review, 'profile' => 'background', ...$overrides]);
}

function reviewExistingTarget(EntryContract $source): Target
{
    return app(TargetFactory::class)->forEntry($source, 'el');
}

function reviewExistingPlan(EntryContract $source, array $options = [], ?array $values = null): Plan
{
    $plan = app(Planner::class)->planReview(reviewExistingTarget($source), reviewExistingOptions($options), $values);

    app(PlanStore::class)->put($plan);

    return $plan;
}

/**
 * Translates the origin without review; the transform gets each segment's text, as fakeTranslations() does.
 */
function reviewExistingTranslate(EntryContract $source, ?Closure $transform = null, array $options = []): TargetResult
{
    test()->fakeTranslations($transform);

    return app(TargetTranslator::class)->translate(
        reviewExistingTarget($source),
        PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background', 'review' => false, ...$options]),
        new ApplyOptions,
    );
}

function reviewExistingReview(EntryContract $source, array $options = [], ApplyOptions $apply = new ApplyOptions): TargetResult
{
    return app(TargetTranslator::class)->review(reviewExistingTarget($source), reviewExistingOptions($options), $apply);
}

function reviewExistingLocalization(EntryContract $origin): EntryContract
{
    return $origin->fresh()->in('el');
}

/**
 * The segments of every review prompt, as the prompts carry them.
 */
function reviewExistingSent(): array
{
    $sent = [];

    ReviewAgent::assertPrompted(function (AgentPrompt $prompt) use (&$sent) {
        array_push($sent, ...json_decode($prompt->prompt, true)['segments']);

        return true;
    });

    return $sent;
}

describe('planning', function () {
    it('plans review units over current machine output, each target leaf in its source leaf\'s numbering', function () {
        reviewExistingTranslate($this->origin, fn (string $text) => $text === '<1>Book</1> a <2>sea view suite</2>.'
            ? 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.'
            : '[T] '.$text);
        $localization = reviewExistingLocalization($this->origin);
        $state = StateFixtures::stored($localization)->field('body');

        $plan = reviewExistingPlan($this->origin);

        expect($plan->kind)->toBe(UnitKind::Review)
            ->and($plan->fieldHandles())->toBe(['title', 'intro', 'body'])
            ->and($plan->skipped)->toBe([])
            ->and($plan->batches)->toBe([['id' => 'b1', 'segment_ids' => ['s1', 's2', 's3', 's4'], 'chars' => 85]])
            ->and(array_map(fn (array $segment) => [$segment['field'], $segment['index'], $segment['text'], $segment['translation']], $plan->segments))->toBe([
                's1' => ['title', 0, 'Hello', '[T] Hello'],
                's2' => ['intro', 0, 'A short intro.', '[T] A short intro.'],
                's3' => ['body', 0, '<1>Book</1> a <2>sea view suite</2>.', 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.'],
                's4' => ['body', 1, 'Breakfast is served until ten.', '[T] Breakfast is served until ten.'],
            ])
            ->and($plan->fields['body'])->toBe([
                'source_hash' => Fingerprint::of($this->origin->get('body')),
                'target_before' => $state->targetHash,
                'leaf_count' => 2,
                'status_before' => 'current',
                'segment_ids' => ['s3', 's4'],
                'reused' => 0,
                'reused_translator' => null,
                'clear' => false,
                'value' => $localization->get('body'),
                'record' => ['segments' => $state->segments, 'translator' => REVIEW_EXISTING_TRANSLATOR],
            ])
            ->and(Plan::fromArray($plan->toArray()))->toEqual($plan);
    });

    it('skips fields that are not current machine output, and reviews manual ones only with overwrite_manual', function () {
        $localization = $this->localize($this->origin, 'el', [
            'intro' => 'Μια σύντομη εισαγωγή.',
            'body' => [reviewExistingParagraph('Κλείστε ένα δωμάτιο.')],
        ]);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('An old intro.', 'Μια σύντομη εισαγωγή.')]);

        expect(reviewExistingPlan($this->origin)->skipped)->toBe(['title' => 'untranslated', 'intro' => 'outdated', 'body' => 'manual'])
            ->and(reviewExistingPlan($this->origin, ['overwriteManual' => true])->skipped)->toBe(['title' => 'untranslated', 'intro' => 'outdated', 'body' => 'structure_differs'])
            ->and(reviewExistingPlan($this->origin, ['fields' => ['intro']])->skipped)->toBe(['title' => 'not_requested', 'intro' => 'outdated', 'body' => 'not_requested'])
            ->and(reviewExistingPlan($this->origin, ['skip' => ['title' => 'edited_in_form']])->skipped['title'])->toBe('edited_in_form');
    });

    it('does not review a translation identical to its source that the target only inherits', function () {
        $localization = $this->localize($this->origin, 'el');
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Hello', ['identical' => true])]);

        expect(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Current)
            ->and(reviewExistingPlan($this->origin)->skipped['title'])->toBe('untranslated');
    });

    it('skips machine output whose leaves do not pair up with the source\'s, with its reason', function () {
        $body = [reviewExistingParagraph('Κλείστε ένα δωμάτιο.')];
        $localization = $this->localize($this->origin, 'el', ['body' => $body]);
        StateFixtures::store($localization, ['body' => new FieldState(
            sourceHash: Fingerprint::of($this->origin->get('body')),
            targetHash: Fingerprint::ofLeaves(['Κλείστε ένα δωμάτιο.']),
            translator: REVIEW_EXISTING_TRANSLATOR,
        )]);

        $plan = reviewExistingPlan($this->origin);

        expect(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current)
            ->and($plan->skipped)->toBe(['title' => 'untranslated', 'intro' => 'untranslated', 'body' => 'structure_differs'])
            ->and($plan->isEmpty())->toBeTrue();
    });

    it('leaves out a leaf whose formatting has no counterpart in its source, and a field none of whose leaves pairs up', function () {
        $this->origin->set('title', '<b>Hello</b>')->set('intro', 'A <b>short</b> intro.')->save();
        $this->localize($this->origin, 'el', [
            'title' => '<i>Γεια</i>',
            'intro' => 'Μια σύντομη εισαγωγή.',
            'body' => [
                reviewExistingParagraph(['Κλείστε', ['type' => 'italic']], ' μια ', ['σουίτα με θέα', REVIEW_EXISTING_LINK], '.'),
                reviewExistingParagraph('Το πρωινό σερβίρεται έως τις δέκα.'),
            ],
        ]);

        $plan = reviewExistingPlan($this->origin, ['overwriteManual' => true]);

        expect($plan->fieldHandles())->toBe(['body'])
            ->and($plan->skipped)->toBe(['title' => 'structure_differs', 'intro' => 'structure_differs'])
            ->and($plan->fields['body']['segment_ids'])->toBe(['s1'])
            ->and($plan->segments['s1']['index'])->toBe(1)
            ->and($plan->fields['body']['leaf_count'])->toBe(2);
    });

    it('splits the editor\'s review batches by source characters and gives each segment its neighbours from other batches', function () {
        config(['tolk.batch.min_chars' => 1, 'tolk.concurrency.editor' => 2]);
        reviewExistingTranslate($this->origin, options: ['fields' => ['body']]);

        $plan = reviewExistingPlan($this->origin, ['profile' => 'web', 'parallel' => true]);

        expect($plan->batches)->toBe([
            ['id' => 'b1', 'segment_ids' => ['s1'], 'chars' => 36],
            ['id' => 'b2', 'segment_ids' => ['s2'], 'chars' => 30],
        ])
            ->and([$plan->segments['s1']['before'], $plan->segments['s1']['after']])->toBe([null, 'Breakfast is served until ten.'])
            ->and([$plan->segments['s2']['before'], $plan->segments['s2']['after']])->toBe(['Book a sea view suite.', null]);
    });

    it('reviews the values of the open form, delivered output that is not saved yet included, and only the fields they hold', function () {
        $localization = $this->localize($this->origin, 'el');
        StateFixtures::store($localization, [
            'title' => new FieldState(pending: [StateFixtures::pending('Hello', 'Γεια')]),
            'intro' => new FieldState(pending: [StateFixtures::pending('A short intro.', 'Μια σύντομη εισαγωγή.')]),
        ]);

        $plan = reviewExistingPlan($this->origin, values: ['title' => 'Γεια', 'intro' => 'Μια εισαγωγή που γράφτηκε ξανά.']);

        expect($plan->fieldHandles())->toBe(['title'])
            ->and($plan->skipped)->toBe(['intro' => 'manual', 'body' => 'not_requested'])
            ->and($plan->segments['s1']['translation'])->toBe('Γεια')
            ->and($plan->fields['title']['value'])->toBe('Γεια')
            ->and($plan->fields['title']['target_before'])->toBeNull()
            ->and($plan->fields['title']['record'])->toBe(['segments' => [Fingerprint::ofText('Hello')], 'translator' => 'openai:gpt-6.1-sol']);
    });
});

describe('reviewing and saving', function () {
    it('changes only the corrected paragraph, and the field stays current with the reviewer recorded', function () {
        Event::fake([TargetTranslated::class]);
        reviewExistingTranslate($this->origin);
        $localization = reviewExistingLocalization($this->origin);
        $before = $localization->get('body');
        $state = StateFixtures::stored($localization)->field('body');
        $this->fakeReview(['s4' => ['text' => 'Το πρωινό σερβίρεται έως τις δέκα.', 'reason' => 'The translation was left in English.']]);

        $result = reviewExistingReview($this->origin);
        $localization = reviewExistingLocalization($this->origin);
        $after = $localization->get('body');
        $reviewed = StateFixtures::stored($localization)->field('body');

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->fields)->toBe(['title' => TargetResult::NO_CORRECTIONS, 'intro' => TargetResult::NO_CORRECTIONS, 'body' => TargetResult::APPLIED])
            ->and($result->corrections)->toBe(['body' => [[
                'id' => 's4',
                'before' => '[T] Breakfast is served until ten.',
                'after' => 'Το πρωινό σερβίρεται έως τις δέκα.',
                'reason' => 'The translation was left in English.',
            ]]])
            ->and(array_keys($result->usage->usages))->toBe([REVIEW_EXISTING_REVIEWER])
            ->and($after[0])->toBe($before[0])
            ->and($after[1])->toBe(reviewExistingParagraph('Το πρωινό σερβίρεται έως τις δέκα.'))
            ->and($localization->get('title'))->toBe('[T] Hello')
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current, 'body' => FieldStatus::Current])
            ->and($reviewed->sourceHash)->toBe($state->sourceHash)
            ->and($reviewed->segments)->toBe($state->segments)
            ->and($reviewed->translator)->toBe(REVIEW_EXISTING_TRANSLATOR)
            ->and($reviewed->reviewer)->toBe(REVIEW_EXISTING_REVIEWER)
            ->and($reviewed->targetHash)->not->toBe($state->targetHash)
            ->and($reviewed->identical)->toBeFalse()
            ->and($reviewed->history)->toBe([['source_hash' => $state->sourceHash, 'target_hash' => $state->targetHash]])
            ->and($reviewed->pending)->toBe([])
            ->and(StateFixtures::stored($localization)->field('title')->reviewer)->toBeNull();
        Event::assertDispatched(TargetTranslated::class, fn (TargetTranslated $event) => $event->handles === ['body'] && $event->models === [REVIEW_EXISTING_REVIEWER]);
    });

    it('saves nothing when the reviewer has no corrections', function () {
        reviewExistingTranslate($this->origin);
        $localization = reviewExistingLocalization($this->origin);
        $state = StateFixtures::stored($localization)->toArray();
        $this->fakeReview();
        Event::fake([EntrySaved::class, TargetTranslated::class]);

        $result = reviewExistingReview($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->reason)->toBe(TargetResult::NO_CORRECTIONS)
            ->and($result->fields)->toBe(array_fill_keys(['title', 'intro', 'body'], TargetResult::NO_CORRECTIONS))
            ->and(array_keys($result->usage->usages))->toBe([REVIEW_EXISTING_REVIEWER])
            ->and(StateFixtures::stored($localization)->toArray())->toBe($state)
            ->and(reviewExistingLocalization($this->origin)->data()->all())->toBe($localization->data()->all());
        Event::assertNotDispatched(EntrySaved::class);
        Event::assertNotDispatched(TargetTranslated::class);
    });

    it('does not wait for the write lock when the reviewer has no corrections', function () {
        reviewExistingTranslate($this->origin);
        $localization = reviewExistingLocalization($this->origin);
        $state = StateFixtures::stored($localization)->toArray();
        $this->fakeReview();
        config(['tolk.write.wait_seconds' => 0]);
        Cache::lock(ContentWriter::LOCK, 60)->get();

        $result = reviewExistingReview($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->reason)->toBe(TargetResult::NO_CORRECTIONS)
            ->and($result->fields)->toBe(array_fill_keys(['title', 'intro', 'body'], TargetResult::NO_CORRECTIONS))
            ->and(StateFixtures::stored($localization)->toArray())->toBe($state);
    });

    it('sends nothing for a target without machine output', function () {
        $this->fakeReview();

        $result = reviewExistingReview($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->reason)->toBe(TargetResult::NOTHING_TO_DO)
            ->and($this->origin->fresh()->in('el'))->toBeNull();
        $this->assertNoModelCalls();
    });

    it('corrects a translation whose formatted runs were reordered and keeps the marks on the right words', function () {
        reviewExistingTranslate($this->origin, fn (string $text) => $text === '<1>Book</1> a <2>sea view suite</2>.'
            ? 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.'
            : '[T] '.$text, ['fields' => ['body']]);

        expect(reviewExistingLocalization($this->origin)->get('body')[0])
            ->toBe(reviewExistingParagraph('Μια ', ['σουίτα με θέα', REVIEW_EXISTING_LINK], ' ', ['κλείστε', ['type' => 'bold']], '.'));

        $this->fakeReview(fn (string $source, string $translation) => $translation === 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.'
            ? '<1>Κλείστε</1> μια <2>σουίτα με θέα</2>.'
            : null);

        $result = reviewExistingReview($this->origin);

        expect(reviewExistingSent()[0])->toBe([
            'id' => 's1',
            'field' => 'Body',
            'kind' => 'inline',
            'source' => '<1>Book</1> a <2>sea view suite</2>.',
            'translation' => 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.',
        ])
            ->and($result->fields['body'])->toBe(TargetResult::APPLIED)
            ->and(reviewExistingLocalization($this->origin)->get('body')[0])
            ->toBe(reviewExistingParagraph(['Κλείστε', ['type' => 'bold']], ' μια ', ['σουίτα με θέα', REVIEW_EXISTING_LINK], '.'))
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current);
    });

    it('reviews a manual field only with overwrite_manual; it stays manual, and a later origin edit plus Update leaves it alone', function () {
        $body = [
            reviewExistingParagraph(['Κλείστε', ['type' => 'bold']], ' μια ', ['σουίτα με θέα', REVIEW_EXISTING_LINK], '.'),
            reviewExistingParagraph('Breakfast is served until ten.'),
        ];
        $this->localize($this->origin, 'el', ['body' => $body]);
        $this->fakeReview(fn (string $source, string $translation) => $translation === 'Breakfast is served until ten.' ? 'Το πρωινό σερβίρεται έως τις δέκα.' : null);

        $refused = reviewExistingReview($this->origin, ['fields' => ['body']]);

        expect($refused->status)->toBe(TargetResultStatus::Skipped)
            ->and($refused->fields['body'])->toBe('manual')
            ->and(reviewExistingLocalization($this->origin)->get('body'))->toBe($body);

        $result = reviewExistingReview($this->origin, ['fields' => ['body'], 'overwriteManual' => true], new ApplyOptions(overwriteManual: true));
        $localization = reviewExistingLocalization($this->origin);
        $state = StateFixtures::stored($localization)->field('body');
        $reviewedBody = $localization->get('body');

        expect($result->fields['body'])->toBe(TargetResult::APPLIED)
            ->and($reviewedBody)->toBe([$body[0], reviewExistingParagraph('Το πρωινό σερβίρεται έως τις δέκα.')])
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Manual)
            ->and($state->isBaseline())->toBeTrue()
            ->and($state->sourceHash)->toBe(Fingerprint::of($this->origin->get('body')))
            ->and($state->reviewer)->toBe(REVIEW_EXISTING_REVIEWER)
            ->and($state->pending)->toBe([])
            ->and(app(StatusResolver::class)->resolve(reviewExistingTarget($this->origin))->fields['body']->sourceChanged)->toBeFalse();

        $this->origin->set('body', [...$this->origin->get('body'), reviewExistingParagraph('Dinner is served at eight.')])->save();
        $update = reviewExistingTranslate($this->origin, options: ['mode' => Mode::Update]);

        expect($update->fields['body'])->toBe('manual')
            ->and(reviewExistingLocalization($this->origin)->get('body'))->toBe($reviewedBody)
            ->and(app(StatusResolver::class)->resolve(reviewExistingTarget($this->origin))->fields['body']->sourceChanged)->toBeTrue();
    });

    it('keeps a field a human edited while the reviewer worked, with overwrite_manual too, because the review corrects the text it planned from', function () {
        reviewExistingTranslate($this->origin, options: ['fields' => ['title']]);
        reviewExistingLocalization($this->origin)->set('intro', 'Μια σύντομη εισαγωγή')->save();
        $edited = false;
        $this->fakeReview(function (string $source) use (&$edited) {
            if (! $edited) {
                $edited = true;
                reviewExistingLocalization($this->origin)->set('title', 'Γεια σας')->set('intro', 'Μια εισαγωγή από το χέρι.')->save();
            }

            return $source === 'Hello' ? 'Γεια' : 'Μια σύντομη εισαγωγή.';
        });

        $result = reviewExistingReview($this->origin, ['fields' => ['title', 'intro'], 'overwriteManual' => true], new ApplyOptions(overwriteManual: true));
        $localization = reviewExistingLocalization($this->origin);

        expect($edited)->toBeTrue()
            ->and($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->reason)->toBe(TargetResult::TARGET_CHANGED)
            ->and($result->fields)->toBe(['title' => TargetResult::TARGET_CHANGED, 'intro' => TargetResult::TARGET_CHANGED, 'body' => 'not_requested'])
            ->and($result->corrections)->toBe([])
            ->and($localization->get('title'))->toBe('Γεια σας')
            ->and($localization->get('intro'))->toBe('Μια εισαγωγή από το χέρι.')
            ->and(StateFixtures::statuses($this->origin))->toMatchArray(['title' => FieldStatus::Manual, 'intro' => FieldStatus::Manual])
            ->and(StateFixtures::stored($localization)->field('title')->pending)->toBe([]);
    });

    it('discards a correction that does not keep the placeholders of the translation it corrects', function () {
        $this->localize($this->origin, 'el', ['body' => [
            reviewExistingParagraph('Κλείστε μια ', ['σουίτα με θέα', REVIEW_EXISTING_LINK], '.'),
            reviewExistingParagraph('Το πρωινό σερβίρεται έως τις δέκα.'),
        ]]);
        $this->fakeReview(['s1' => '<1>Κλείστε</1> μια <2>σουίτα με θέα</2>.']);

        $plan = reviewExistingPlan($this->origin, ['overwriteManual' => true, 'fields' => ['body']]);
        app(Pipeline::class)->runUnit($plan, 'b1');
        $body = app(Pipeline::class)->assemble($plan, reviewExistingTarget($this->origin), 'body');

        expect($plan->segments['s1']['translation'])->toBe('Κλείστε μια <2>σουίτα με θέα</2>.')
            ->and($body->corrections)->toBe([])
            ->and($body->warnings)->toBe(['Body: the reviewer\'s correction of "Book a sea view suite." was not valid and was discarded.'])
            ->and($body->value)->toBe($plan->fields['body']['value']);

        $this->fakeReview(['s1' => 'Κλείστε τη <2>σουίτα με θέα</2> μας.']);
        $plan = reviewExistingPlan($this->origin, ['overwriteManual' => true, 'fields' => ['body']]);
        app(Pipeline::class)->runUnit($plan, 'b1');
        $body = app(Pipeline::class)->assemble($plan, reviewExistingTarget($this->origin), 'body');

        expect($body->warnings)->toBe([])
            ->and($body->value[0])->toBe(reviewExistingParagraph('Κλείστε τη ', ['σουίτα με θέα', REVIEW_EXISTING_LINK], ' μας.'));
    });

    // A correction is validated on its own block, and a list marker it adds makes the indented code of the next block the
    // item's content: the page would render that code as Markdown or raw HTML.
    it('discards a correction that would turn the code of the next block into markup', function () {
        $this->makeCollection('notes', ['en', 'el'], ['body' => ['type' => 'markdown', 'localizable' => true, 'display' => 'Body']]);
        $origin = $this->makeEntry('notes', 'en', ['body' => "Paste this snippet:\n\n    <script>alert(1)</script>\n\nDone."], 'snippet');
        reviewExistingTranslate($origin);
        $this->fakeReview(fn (string $source, string $translation) => match ($source) {
            'Paste this snippet:' => '- '.$translation,
            'Done.' => 'Fertig.',
        });

        $plan = reviewExistingPlan($origin, ['fields' => ['body']]);
        app(Pipeline::class)->runUnit($plan, 'b1');
        $body = app(Pipeline::class)->assemble($plan, reviewExistingTarget($origin), 'body');

        expect($body->value)->toBe("[T] Paste this snippet:\n\n    <script>alert(1)</script>\n\nFertig.")
            ->and(array_column($body->corrections, 'after'))->toBe(['Fertig.'])
            ->and($body->warnings)->toBe(['Body: the reviewer\'s correction of "Paste this snippet:" was not valid and was discarded.']);
    });

    it('reviews the corrected text again with another model', function () {
        reviewExistingTranslate($this->origin, options: ['fields' => ['intro']]);
        $this->fakeReview(['s1' => 'Μια σύντομη εισαγωγή.']);
        reviewExistingReview($this->origin, ['reviewer' => 'gemini:gemini-3.8-flash']);

        expect(StateFixtures::stored(reviewExistingLocalization($this->origin))->field('intro')->reviewer)->toBe('gemini:gemini-3.8-flash');

        $sent = [];
        $this->fakeReview(function (string $source, string $translation, array $segment) use (&$sent) {
            $sent[] = $segment;

            return 'Μια σύντομη παρουσίαση.';
        });

        $result = reviewExistingReview($this->origin, ['reviewer' => REVIEW_EXISTING_REVIEWER]);
        $state = StateFixtures::stored(reviewExistingLocalization($this->origin))->field('intro');

        expect(array_column($sent, 'translation'))->toBe(['Μια σύντομη εισαγωγή.'])
            ->and($result->corrections['intro'][0]['before'])->toBe('Μια σύντομη εισαγωγή.')
            ->and(reviewExistingLocalization($this->origin)->get('intro'))->toBe('Μια σύντομη παρουσίαση.')
            ->and($state->reviewer)->toBe(REVIEW_EXISTING_REVIEWER)
            ->and($state->translator)->toBe(REVIEW_EXISTING_TRANSLATOR)
            ->and(StateFixtures::statuses($this->origin)['intro'])->toBe(FieldStatus::Current);
    });

    it('fails the target, saving nothing, when the reviewer stays unavailable, so it does not read as reviewed', function () {
        Sleep::fake();
        reviewExistingTranslate($this->origin, options: ['fields' => ['title']]);
        $state = StateFixtures::stored(reviewExistingLocalization($this->origin))->toArray();
        $engine = ScriptedEngine::bind(
            fn (EngineCall $call) => ScriptedEngine::translated($call),
            fn (EngineCall $call) => throw ProviderUnavailable::connection('openai', 3),
        );
        Event::fake([EntrySaved::class, TargetTranslated::class]);

        $result = reviewExistingReview($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::REVIEW_SKIPPED)
            ->and($result->error)->toBe('Part of the text could not be reviewed and was kept as it was.')
            ->and($result->fields)->toBe(['title' => TargetResult::REVIEW_SKIPPED, 'intro' => 'untranslated', 'body' => 'untranslated'])
            ->and($result->warnings)->toBe(['Title: review skipped (the provider was unavailable).'])
            ->and($engine->reviews)->toHaveCount(3)
            ->and(reviewExistingLocalization($this->origin)->get('title'))->toBe('[T] Hello')
            ->and(StateFixtures::stored(reviewExistingLocalization($this->origin))->toArray())->toBe($state);
        Event::assertNotDispatched(EntrySaved::class);
        Event::assertNotDispatched(TargetTranslated::class);
    });

    it('names the fields it could not review: the target fails when nothing was corrected, and is done when a correction was saved', function () {
        reviewExistingTranslate($this->origin);
        config(['tolk.batch.max_segments' => 1]);
        $refuse = fn (EngineCall $call, array $corrections) => in_array('[T] Hello', array_column($call->segments, 'translation'), true)
            ? ScriptedEngine::result($call, Finish::Refused)
            : ScriptedEngine::result($call, Finish::Stop, array_intersect_key($corrections, array_flip(array_column($call->segments, 'id'))));
        ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call), fn (EngineCall $call) => $refuse($call, []));

        $failed = reviewExistingReview($this->origin);

        expect($failed->status)->toBe(TargetResultStatus::Failed)
            ->and($failed->reason)->toBe(TargetResult::REVIEW_SKIPPED)
            ->and($failed->fields)->toBe(['title' => TargetResult::REVIEW_SKIPPED, 'intro' => TargetResult::NO_CORRECTIONS, 'body' => TargetResult::NO_CORRECTIONS])
            ->and($failed->warnings)->toBe(['Title: review skipped (the model refused it).']);

        ScriptedEngine::bind(
            fn (EngineCall $call) => ScriptedEngine::translated($call),
            fn (EngineCall $call) => $refuse($call, ['s4' => ['text' => 'Το πρωινό σερβίρεται έως τις δέκα.', 'reason' => 'Left in English.']]),
        );

        $done = reviewExistingReview($this->origin);

        expect($done->status)->toBe(TargetResultStatus::Done)
            ->and($done->reason)->toBeNull()
            ->and($done->fields)->toBe(['title' => TargetResult::REVIEW_SKIPPED, 'intro' => TargetResult::NO_CORRECTIONS, 'body' => TargetResult::APPLIED])
            ->and($done->warnings)->toBe(['Title: review skipped (the model refused it).'])
            ->and(reviewExistingLocalization($this->origin)->get('body')[1])->toBe(reviewExistingParagraph('Το πρωινό σερβίρεται έως τις δέκα.'));
    });

    it('names a corrected field with a part it could not review, and still counts it as saved', function () {
        reviewExistingTranslate($this->origin);
        config(['tolk.batch.max_segments' => 1]);
        ScriptedEngine::bind(
            fn (EngineCall $call) => ScriptedEngine::translated($call),
            fn (EngineCall $call) => match (array_column($call->segments, 'id')) {
                ['s3'] => ScriptedEngine::result($call, Finish::Refused),
                ['s4'] => ScriptedEngine::result($call, Finish::Stop, ['s4' => ['text' => 'Το πρωινό σερβίρεται έως τις δέκα.', 'reason' => 'Left in English.']]),
                default => ScriptedEngine::result($call, Finish::Stop),
            },
        );
        Event::fake([TargetTranslated::class]);

        $result = reviewExistingReview($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->reason)->toBeNull()
            ->and($result->fields)->toBe(['title' => TargetResult::NO_CORRECTIONS, 'intro' => TargetResult::NO_CORRECTIONS, 'body' => TargetResult::PARTLY_REVIEWED])
            ->and($result->appliedFields())->toBe(['body'])
            ->and(array_column($result->corrections['body'], 'id'))->toBe(['s4'])
            ->and($result->warnings)->toBe(['Body: review skipped (the model refused it).'])
            ->and(reviewExistingLocalization($this->origin)->get('body')[1])->toBe(reviewExistingParagraph('Το πρωινό σερβίρεται έως τις δέκα.'));
        Event::assertDispatched(TargetTranslated::class, fn (TargetTranslated $event) => $event->handles === ['body']);
    });

    it('refuses to assemble a reviewed field whose source changed meanwhile, or whose walk no longer gives the planned leaves', function () {
        reviewExistingTranslate($this->origin, options: ['fields' => ['title']]);
        $this->fakeReview(['s1' => 'Γεια']);
        $plan = reviewExistingPlan($this->origin);
        app(Pipeline::class)->runUnit($plan, 'b1');
        $stale = Plan::fromArray(array_replace_recursive($plan->toArray(), ['segments' => ['s1' => ['translation' => '[T] Hi']]]));

        expect(fn () => app(Pipeline::class)->assemble($stale, reviewExistingTarget($this->origin), 'title'))->toThrow(SourceChanged::class)
            ->and(app(Pipeline::class)->assemble($plan, reviewExistingTarget($this->origin), 'title')->value)->toBe('Γεια');

        $this->origin->set('title', 'Hello there')->save();

        expect(fn () => app(Pipeline::class)->assemble($plan, reviewExistingTarget($this->origin->fresh()), 'title'))->toThrow(SourceChanged::class);
    });
});
