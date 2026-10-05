<?php

use Illuminate\Support\Arr;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Http\EditorTranslation;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;

const REUSE_LINK = ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms']];

const REUSE_QUOTE_SET = ['quote' => ['display' => 'Quote', 'fields' => [
    ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
]]];

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el'], reuseFields());

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'body' => [
            reuseParagraph(['Book', ['type' => 'bold']], ' a ', ['sea view suite', REUSE_LINK], '.'),
            reuseParagraph('Our rooms face the sea.'),
            reuseParagraph('Breakfast is served until ten.'),
        ],
        'blocks' => [
            ['type' => 'text', 'heading' => 'Rooms', 'body' => 'Every room has a balcony.'],
            ['type' => 'text', 'heading' => 'Dining', 'body' => 'The restaurant opens at seven.'],
        ],
    ], 'hello');
});

function reuseFields(array $textSetFields = []): array
{
    return [
        'title' => ['type' => 'text', 'localizable' => true],
        'body' => ['type' => 'bard', 'localizable' => true, 'display' => 'Body', 'buttons' => ['bold', 'italic', 'anchor'], 'sets' => ['main' => ['sets' => REUSE_QUOTE_SET]]],
        'blocks' => ['type' => 'replicator', 'localizable' => true, 'display' => 'Blocks', 'sets' => ['main' => ['sets' => [
            'text' => ['display' => 'Text', 'fields' => [
                ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
                ['handle' => 'body', 'field' => ['type' => 'textarea', 'display' => 'Body']],
                ...$textSetFields,
            ]],
        ]]]],
    ];
}

/**
 * A Bard paragraph; a string is a plain text node, [text, mark] a text node with one mark.
 */
function reuseParagraph(string|array ...$runs): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (string|array $run) => is_string($run)
        ? ['type' => 'text', 'text' => $run]
        : ['type' => 'text', 'marks' => [$run[1]], 'text' => $run[0]], $runs)];
}

function reuseQuote(string $text): array
{
    return ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => $text]]];
}

function reuseOptions(array $overrides = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background', ...$overrides]);
}

function reuseTranslate(EntryContract $source, array $options = []): TargetResult
{
    return app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($source, 'el'), reuseOptions($options), new ApplyOptions);
}

function reusePlan(EntryContract $source, array $options = []): Plan
{
    return app(Planner::class)->plan(app(TargetFactory::class)->forEntry($source, 'el'), reuseOptions($options));
}

/**
 * Fakes the model with the transform and records every segment it is sent, as the prompt carries it.
 */
function reuseRecordSent(array &$sent, ?Closure $transform = null): void
{
    $transform ??= fn (string $text) => '[T] '.$text;

    test()->fakeTranslations(function (string $text, array $segment) use (&$sent, $transform) {
        $sent[] = $segment;

        return $transform($text, $segment);
    });
}

function reuseLocalization(EntryContract $origin): EntryContract
{
    return $origin->fresh()->in('el');
}

// Replaces one leaf's text in the origin and saves it as code does, without the CP's normalisation.
function reuseEditOrigin(EntryContract $origin, string $handle, Closure $edit): void
{
    $origin->set($handle, $edit($origin->get($handle)))->save();
}

describe('updating', function () {
    it('sends only the changed paragraph of a Bard field, with its neighbours, and keeps the others with their formatting', function () {
        $sent = [];
        reuseRecordSent($sent, fn (string $text) => $text === '<1>Book</1> a <2>sea view suite</2>.' ? 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.' : '[T] '.$text);
        reuseTranslate($this->origin, ['fields' => ['body']]);
        $before = reuseLocalization($this->origin)->get('body');

        reuseEditOrigin($this->origin, 'body', function (array $body) {
            $body[1] = reuseParagraph('Our rooms face the bay.');

            return $body;
        });
        $sent = [];
        $result = reuseTranslate($this->origin, ['mode' => Mode::Update, 'fields' => ['body']]);
        $after = reuseLocalization($this->origin)->get('body');

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($sent)->toBe([[
                'id' => 's2',
                'field' => 'Body',
                'kind' => 'inline',
                'before' => 'Book a sea view suite.',
                'after' => 'Breakfast is served until ten.',
                'text' => 'Our rooms face the bay.',
            ]])
            ->and($this->modelCalls())->toBe(2)
            ->and($after[0])->toBe($before[0])
            ->and($after[0]['content'][1])->toBe(['type' => 'text', 'marks' => [REUSE_LINK], 'text' => 'σουίτα με θέα'])
            ->and($after[1])->toBe(reuseParagraph('[T] Our rooms face the bay.'))
            ->and($after[2])->toBe($before[2])
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current);
    });

    it('sends nothing for a field whose only change is two swapped sets, and the target follows the new order', function () {
        reuseEditOrigin($this->origin, 'body', fn (array $body) => [reuseQuote('First quote.'), ...$body, reuseQuote('Second quote.')]);
        $this->fakeTranslations();
        reuseTranslate($this->origin, ['fields' => ['body']]);
        $localization = reuseLocalization($this->origin);
        $translator = StateFixtures::stored($localization)->field('body')->translator;

        reuseEditOrigin($this->origin, 'body', function (array $body) {
            [$body[0], $body[4]] = [$body[4], $body[0]];

            return $body;
        });
        $calls = $this->modelCalls();
        $result = reuseTranslate($this->origin, ['mode' => Mode::Update, 'fields' => ['body']]);
        $body = reuseLocalization($this->origin)->get('body');

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->fields['body'])->toBe(TargetResult::APPLIED)
            ->and($this->modelCalls())->toBe($calls)
            ->and($body[0]['attrs']['values']['text'])->toBe('[T] Second quote.')
            ->and($body[4]['attrs']['values']['text'])->toBe('[T] First quote.')
            ->and(array_column(array_slice($body, 1, 3), 'type'))->toBe(['paragraph', 'paragraph', 'paragraph'])
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current)
            ->and(StateFixtures::stored($localization)->field('body')->translator)->toBe($translator)->not->toBeNull();
    });

    it('sends only the leaf that was left untranslated on the next update', function () {
        $sent = [];
        reuseRecordSent($sent, fn (string $text) => $text === 'Our rooms face the sea.' ? null : '[T] '.$text);
        reuseTranslate($this->origin, ['fields' => ['body']]);
        $localization = reuseLocalization($this->origin);

        expect(StateFixtures::stored($localization)->field('body')->segments[1])->toBeNull()
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Outdated);

        $sent = [];
        reuseRecordSent($sent);
        reuseTranslate($this->origin, ['mode' => Mode::Update, 'fields' => ['body']]);

        expect(array_column($sent, 'text'))->toBe(['Our rooms face the sea.'])
            ->and(array_column(reuseLocalization($this->origin)->get('body')[1]['content'], 'text'))->toBe(['[T] Our rooms face the sea.'])
            ->and(StateFixtures::stored($localization)->field('body')->incomplete)->toBeFalse()
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current);
    });

    it('stays current when the CP fills a later set field\'s default into the target, and an update sends only the edited leaf', function () {
        $this->fakeTranslations();
        reuseTranslate($this->origin, ['fields' => ['blocks']]);

        $this->makeBlueprint('collections.articles', 'article', reuseFields([
            ['handle' => 'kicker', 'field' => ['type' => 'text', 'display' => 'Kicker', 'default' => 'Read this']],
        ]));
        Blink::flush();
        $localization = reuseLocalization($this->origin);
        $saved = $localization->blueprint()->setParent($localization)->fields()
            ->addValues(['blocks' => $localization->get('blocks')])
            ->preProcess()
            ->process()
            ->values()['blocks'];
        $localization->set('blocks', $saved)->save();

        expect(array_column(reuseLocalization($this->origin)->get('blocks'), 'kicker'))->toBe(['Read this', 'Read this'])
            ->and(StateFixtures::statuses($this->origin)['blocks'])->toBe(FieldStatus::Current);

        reuseEditOrigin($this->origin, 'blocks', function (array $blocks) {
            $blocks[1]['body'] = 'The restaurant opens at eight.';

            return $blocks;
        });
        $sent = [];
        reuseRecordSent($sent);
        reuseTranslate($this->origin, ['mode' => Mode::Update, 'fields' => ['blocks']]);

        // The server-side save normalises the rows as the CP does, so the default is back in the source language.
        expect(array_column($sent, 'text'))->toBe(['The restaurant opens at eight.'])
            ->and(array_map(fn (array $row) => Arr::except($row, 'id'), reuseLocalization($this->origin)->get('blocks')))->toBe([
                ['type' => 'text', 'heading' => '[T] Rooms', 'body' => '[T] Every room has a balcony.', 'kicker' => 'Read this', 'enabled' => true],
                ['type' => 'text', 'heading' => '[T] Dining', 'body' => '[T] The restaurant opens at eight.', 'kicker' => 'Read this', 'enabled' => true],
            ])
            ->and(StateFixtures::statuses($this->origin)['blocks'])->toBe(FieldStatus::Current);
    });

    it('delivers a field whose leaves are all reused to the editor at once, without a unit', function () {
        $this->signInAdmin();
        $this->fakeTranslations();
        reuseTranslate($this->origin, ['fields' => ['blocks']]);
        reuseEditOrigin($this->origin, 'blocks', fn (array $blocks) => array_reverse($blocks));
        $calls = $this->modelCalls();

        $payload = app(EditorTranslation::class)->plan(
            app(TargetFactory::class)->forEntry($this->origin, 'el'),
            reuseOptions(['mode' => Mode::Update, 'fields' => ['blocks'], 'profile' => 'web']),
        );

        expect($payload['units'])->toBe([])
            ->and($payload['fields'])->toBe(['blocks'])
            ->and(array_keys($payload['ready']))->toBe(['blocks'])
            ->and($this->modelCalls())->toBe($calls);
    });
});

describe('planning reuse', function () {
    beforeEach(function () {
        $this->fakeTranslations();
        reuseTranslate($this->origin, ['fields' => ['blocks']]);
        $this->localization = reuseLocalization($this->origin);

        reuseEditOrigin($this->origin, 'blocks', function (array $blocks) {
            $blocks[0]['body'] = 'Every room has a terrace.';

            return $blocks;
        });
    });

    it('pre-fills the leaves an outdated machine field still has and batches only the others', function () {
        $plan = reusePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['blocks']]);
        $state = StateFixtures::stored($this->localization)->field('blocks');

        expect($plan->fieldHandles())->toBe(['blocks'])
            ->and(array_column($plan->segments, 'translation', 'text'))->toBe([
                'Rooms' => '[T] Rooms',
                'Every room has a terrace.' => null,
                'Dining' => '[T] Dining',
                'The restaurant opens at seven.' => '[T] The restaurant opens at seven.',
            ])
            ->and($plan->fields['blocks']['segment_ids'])->toBe(['s1', 's2', 's3', 's4'])
            ->and($plan->fields['blocks']['reused'])->toBe(3)
            ->and($plan->fields['blocks']['reused_translator'])->toBe($state->translator)
            ->and($plan->fields['blocks']['clear'])->toBeFalse()
            ->and($plan->batches)->toBe([['id' => 'b1', 'segment_ids' => ['s2'], 'chars' => 25]]);
    });

    it('splits the editor\'s batches by the characters still to translate, without the reused ones', function () {
        config(['tolk.batch.min_chars' => 1, 'tolk.concurrency.editor' => 2]);

        $plan = reusePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['title', 'blocks'], 'profile' => 'web', 'parallel' => true]);

        expect($plan->fields['blocks']['reused'])->toBe(3)
            ->and($plan->batches)->toBe([
                ['id' => 'b1', 'segment_ids' => ['s1'], 'chars' => 5],
                ['id' => 'b2', 'segment_ids' => ['s3'], 'chars' => 25],
            ]);
    });

    it('translates every leaf again in mode all', function () {
        $plan = reusePlan($this->origin, ['mode' => Mode::All, 'fields' => ['blocks']]);

        expect(array_column($plan->segments, 'translation'))->toBe([null, null, null, null])
            ->and($plan->fields['blocks']['reused'])->toBe(0)
            ->and($plan->fields['blocks']['reused_translator'])->toBeNull()
            ->and($plan->batches[0]['segment_ids'])->toBe(['s1', 's2', 's3', 's4']);
    });

    it('does not reuse a record of another format version', function () {
        $ref = StateFixtures::ref($this->localization);
        app(StateRepository::class)->update($ref, fn (ItemState $state) => new ItemState($state->reference, $state->sourceSite, 0, $state->fields));

        $plan = reusePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['blocks']]);

        expect($plan->fields['blocks']['status_before'])->toBe('outdated')
            ->and($plan->fields['blocks']['reused'])->toBe(0)
            ->and(array_column($plan->segments, 'translation'))->toBe([null, null, null, null]);
    });

    it('does not reuse a target that holds an older machine output', function () {
        $state = StateFixtures::stored($this->localization)->field('blocks');
        StateFixtures::store($this->localization, ['blocks' => new FieldState(
            sourceHash: 'newer',
            targetHash: 'newer-output',
            segments: $state->segments,
            translator: $state->translator,
            history: [['source_hash' => $state->sourceHash, 'target_hash' => $state->targetHash]],
        )]);

        $plan = reusePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['blocks']]);

        expect($plan->fields['blocks']['status_before'])->toBe('outdated')
            ->and($plan->fields['blocks']['reused'])->toBe(0);
    });

    it('does not reuse a manual field that is overwritten', function () {
        $this->localization->set('blocks', [
            ['type' => 'text', 'heading' => 'Δωμάτια', 'body' => 'Κάθε δωμάτιο έχει μπαλκόνι.'],
            ['type' => 'text', 'heading' => '[T] Dining', 'body' => '[T] The restaurant opens at seven.'],
        ])->save();

        $plan = reusePlan($this->origin, ['mode' => Mode::Update, 'overwriteManual' => true, 'fields' => ['blocks']]);

        expect($plan->fields['blocks']['status_before'])->toBe('manual')
            ->and($plan->fields['blocks']['reused'])->toBe(0);
    });

    it('plans a field whose leaves are all reused without a batch, and the plan is not empty', function () {
        reuseEditOrigin($this->origin, 'blocks', fn () => [
            ['type' => 'text', 'heading' => 'Dining', 'body' => 'The restaurant opens at seven.'],
            ['type' => 'text', 'heading' => 'Rooms', 'body' => 'Every room has a balcony.'],
        ]);

        $plan = reusePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['blocks']]);

        expect($plan->fields['blocks']['reused'])->toBe(4)
            ->and($plan->batches)->toBe([])
            ->and($plan->isEmpty())->toBeFalse();
    });
});

describe('neighbour context', function () {
    it('gives a segment the texts of its field\'s neighbours that are in another batch', function () {
        config(['tolk.batch.max_chars.background' => 60]);

        $plan = reusePlan($this->origin, ['fields' => ['title', 'body']]);

        expect(array_map(fn (array $batch) => $batch['segment_ids'], $plan->batches))->toBe([['s1', 's2'], ['s3', 's4']])
            ->and(array_map(fn (array $segment) => [$segment['before'], $segment['after']], $plan->segments))->toBe([
                's1' => [null, null],
                's2' => [null, 'Our rooms face the sea.'],
                's3' => ['Book a sea view suite.', null],
                's4' => [null, null],
            ]);
    });

    it('takes neighbours from the segment\'s own field only, as plain text cut next to the segment', function () {
        config(['tolk.batch.max_chars.background' => 1]);
        $long = trim(str_repeat('Sea & sun. ', 40));
        reuseEditOrigin($this->origin, 'body', fn () => [reuseParagraph($long), reuseParagraph(['Short', ['type' => 'bold']], '.'), reuseParagraph($long)]);

        $segments = reusePlan($this->origin, ['fields' => ['title', 'body']])->segments;

        expect($segments['s1']['after'])->toBeNull()
            ->and($segments['s2']['before'])->toBeNull()
            ->and($segments['s2']['after'])->toBe('Short.')
            ->and($segments['s3']['before'])->toBe('…'.mb_substr($long, -299))
            ->and($segments['s3']['after'])->toBe(mb_substr($long, 0, 299).'…')
            ->and(mb_strlen($segments['s3']['before']))->toBe(300);
    });
});
