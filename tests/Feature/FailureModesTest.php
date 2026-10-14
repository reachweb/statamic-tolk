<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Throttle;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;

const FAILING_TIMEOUT = 'cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received';

const FAILING_BOOK = 'Book <1>a room</1> today.';

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    config(['tolk.runs.execution' => 'browser']);

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true, 'display' => 'Title'],
        'body' => ['type' => 'bard', 'localizable' => true, 'display' => 'Body', 'buttons' => ['bold']],
    ]);
});

/**
 * A Bard paragraph; a string is a plain text node, [text, mark] a text node with one mark.
 */
function failingParagraph(string|array ...$runs): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (string|array $run) => is_string($run)
        ? ['type' => 'text', 'text' => $run]
        : ['type' => 'text', 'marks' => [['type' => $run[1]]], 'text' => $run[0]], $runs)];
}

/**
 * The text of each node of a Bard value, without its marks.
 *
 * @return list<string>
 */
function failingTexts(array $body): array
{
    return array_map(fn (array $node) => implode('', array_column($node['content'] ?? [], 'text')), $body);
}

function failingArticle(string $slug, array $body, string $title = 'Hello'): EntryContract
{
    return test()->makeEntry('articles', 'en', ['title' => $title, 'body' => $body], $slug);
}

/**
 * Fakes the translation agent and keeps the segments of each prompt in $prompts. The transform gets a segment's text,
 * the segment and the prompt's number, and returns the translation, or null to leave the segment out; it may throw for
 * the whole prompt. Each answer lists its translations in reverse order.
 *
 * @param  ?Closure(string, array, int): ?string  $transform
 */
function failingModel(?array &$prompts = null, ?Closure $transform = null): void
{
    $prompts = [];
    $transform ??= fn (string $text) => '[T] '.$text;

    TranslationAgent::fake(function (string $prompt) use (&$prompts, $transform) {
        $segments = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR)['segments'];
        $prompts[] = $segments;
        $translations = [];

        foreach ($segments as $segment) {
            if (($text = $transform($segment['text'], $segment, count($prompts))) !== null) {
                $translations[] = ['id' => $segment['id'], 'text' => $text];
            }
        }

        return ['translations' => array_reverse($translations)];
    });
}

/**
 * @return list<string> the text of every segment sent, prompt after prompt
 */
function failingSent(array $prompts): array
{
    return array_column(array_merge(...$prompts), 'text');
}

function failingWithoutPlaceholders(string $text): string
{
    return '[T] '.preg_replace('/<\/?\d+>/', '', $text);
}

function failingTranslate(EntryContract $origin, array $options = []): TargetResult
{
    return app(TargetTranslator::class)->translate(
        app(TargetFactory::class)->forEntry($origin, 'el'),
        PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background', ...$options]),
        new ApplyOptions,
    );
}

function failingGreek(EntryContract $origin): EntryContract
{
    return Entry::find($origin->id())->in('el');
}

/**
 * Plans in the editor and runs every unit, spawned ones too, as the unit runner does; returns the delivered fields.
 */
function failingEditorTranslate(EntryContract $localization, array $body = []): array
{
    $plan = test()->postJson(cp_route('tolk.editor.plan'), ['reference' => $localization->reference(), 'mode' => 'missing', ...$body])->assertOk()->json();
    $queue = array_column($plan['units'], 'id');
    $fields = $plan['ready'];

    while ($queue !== []) {
        $response = test()->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan['plan'], 'unit' => array_shift($queue)])->assertOk()->json();
        $fields = [...$fields, ...$response['fields']];
        array_push($queue, ...array_column($response['spawned'], 'id'));
    }

    return $fields;
}

/**
 * Saves delivered fields as the publish form does, processed by their fieldtypes, and confirms them as the banner does.
 */
function failingSaveForm(EntryContract $localization, array $fields): void
{
    $localization = Entry::find($localization->id());
    $values = array_map(fn (array $field) => $field['value'], $fields);
    $processed = $localization->blueprint()->setParent($localization)->fields()->addValues($values)->process()->values()->only(array_keys($values));

    $localization->merge($processed->all())->save();

    test()->postJson(cp_route('tolk.editor.confirm'), ['reference' => $localization->reference(), 'delivered' => array_keys($fields)])->assertOk();
}

/**
 * Creates a run into Greek as the bulk action does, and starts it as its page does.
 */
function failingRun(array $body): Run
{
    $id = test()->postJson(cp_route('tolk.runs.store'), ['sites' => ['el'], ...$body])->assertCreated()->json('run');
    test()->postJson(cp_route('tolk.runs.start', $id))->assertOk();

    return app(RunRepository::class)->find($id);
}

function failingTick(Run $run): TestResponse
{
    return test()->postJson(cp_route('tolk.runs.tick', $run->id), ['nonce' => 'failureModes1', 'lane' => 1]);
}

function failingApply(Run $run): TestResponse
{
    return test()->postJson(cp_route('tolk.runs.apply', $run->id));
}

/**
 * Ticks one lane and applies, as the run page does, until neither has work left; at most 20 rounds.
 */
function failingDrive(Run $run): void
{
    for ($round = 0; $round < 20; $round++) {
        $ticked = failingTick($run)->assertOk()->json('report');
        $applied = failingApply($run)->assertOk()->json('report');

        if (! $ticked['remains'] && ! $applied['remains']) {
            return;
        }
    }

    throw new RuntimeException('The run did not finish in 20 rounds.');
}

describe('long content', function () {
    it('splits a field of 300 paragraphs into several units and reassembles it in order', function () {
        failingModel($prompts);
        $paragraphs = array_map(fn (int $n) => sprintf('Paragraph %03d tells guests about the rooms, the beach and the village.', $n), range(1, 300));
        $origin = failingArticle('long-read', array_map(fn (string $text) => failingParagraph($text), $paragraphs), 'A long read');

        $result = failingTranslate($origin);
        $body = failingGreek($origin)->get('body');
        $chars = array_map(fn (array $segments) => array_sum(array_map(mb_strlen(...), array_column($segments, 'text'))), $prompts);

        // 301 segments, at most 80 per unit (batch.max_segments), sent once each and in document order.
        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->fields)->toBe(['title' => 'applied', 'body' => 'applied'])
            ->and(array_map(count(...), $prompts))->toBe([80, 80, 80, 61])
            ->and(max($chars))->toBeLessThanOrEqual(config('tolk.batch.max_chars.background'))
            ->and(failingSent($prompts))->toBe(['A long read', ...$paragraphs])
            ->and(failingTexts($body))->toBe(array_map(fn (string $text) => '[T] '.$text, $paragraphs))
            ->and(array_unique(array_column($body, 'type')))->toBe(['paragraph'])
            ->and(StateFixtures::statuses($origin))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Current]);
    });

    it('delivers the field to the editor with its last unit, in order whatever the order its units ran in', function () {
        $this->signInAdmin();
        failingModel($prompts);
        $paragraphs = array_map(fn (int $n) => sprintf('Paragraph %03d tells guests about the rooms, the beach and the village.', $n), range(1, 300));
        $greek = $this->localize(failingArticle('long-read', array_map(fn (string $text) => failingParagraph($text), $paragraphs), 'A long read'), 'el');
        $plan = $this->postJson(cp_route('tolk.editor.plan'), ['reference' => $greek->reference(), 'mode' => 'missing'])->assertOk()->json();
        $delivered = [];

        foreach (array_reverse(array_column($plan['units'], 'id')) as $unit) {
            $delivered[] = $this->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan['plan'], 'unit' => $unit])->assertOk()->json('fields');
        }

        $last = array_pop($delivered);

        expect(count($plan['units']))->toBeGreaterThan(2)
            ->and($delivered)->each->toBe([])
            ->and(array_keys($last))->toBe(['title', 'body'])
            ->and(failingTexts($last['body']['value']))->toBe(array_map(fn (string $text) => '[T] '.$text, $paragraphs))
            ->and($last['body']['incomplete'])->toBeFalse();
    });

    it('translates a paragraph longer than the batch budget in a unit of its own', function (string $profile) {
        failingModel($prompts);
        $long = trim(str_repeat('The bay is quiet in the morning and the water is clear. ', 200));
        $origin = failingArticle('long-paragraph', [
            failingParagraph('Before the long one.'),
            failingParagraph($long),
            failingParagraph('After the long one.'),
        ]);

        $result = failingTranslate($origin, ['profile' => $profile]);

        expect(mb_strlen($long))->toBeGreaterThan(config("tolk.batch.max_chars.{$profile}"))
            ->and($result->status)->toBe(TargetResultStatus::Done)
            ->and(array_map(fn (array $segments) => array_column($segments, 'text'), $prompts))->toBe([['Hello', 'Before the long one.'], [$long], ['After the long one.']])
            ->and(failingTexts(failingGreek($origin)->get('body')))->toBe(['[T] Before the long one.', '[T] '.$long, '[T] After the long one.']);
    })->with(['web', 'background']);
});

describe('a part the model keeps getting wrong', function () {
    it('delivers the field with that paragraph in the source language, and the next update sends only that paragraph', function () {
        $this->signInAdmin();
        $origin = failingArticle('hello', [
            failingParagraph('Book ', ['a room', 'bold'], ' today.'),
            failingParagraph('Breakfast is served until ten.'),
            failingParagraph('The pool opens at eight.'),
        ]);
        $greek = $this->localize($origin, 'el');
        failingModel($prompts, fn (string $text) => failingWithoutPlaceholders($text));

        $fields = failingEditorTranslate($greek);

        // The answer and its two repairs all lose the bold text's placeholders.
        expect(array_count_values(failingSent($prompts))[FAILING_BOOK])->toBe(3)
            ->and($fields['body']['incomplete'])->toBeTrue()
            ->and($fields['body']['warnings'])->toBe(['Body: "Book a room today." was left untranslated (the model kept breaking its formatting).'])
            ->and($fields['body']['value'][0])->toBe($origin->get('body')[0])
            ->and(failingTexts($fields['body']['value']))->toBe(['Book a room today.', '[T] Breakfast is served until ten.', '[T] The pool opens at eight.'])
            ->and($fields['title']['incomplete'])->toBeFalse();

        failingSaveForm($greek, $fields);

        expect(StateFixtures::stored($greek)->field('body')->incomplete)->toBeTrue()
            ->and(StateFixtures::statuses($origin))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Outdated]);

        failingModel($prompts);
        $fields = failingEditorTranslate($greek, ['mode' => 'update']);

        expect(failingSent($prompts))->toBe([FAILING_BOOK])
            ->and(array_keys($fields))->toBe(['body'])
            ->and($fields['body']['incomplete'])->toBeFalse()
            ->and(failingTexts($fields['body']['value']))->toBe(['[T] Book a room today.', '[T] Breakfast is served until ten.', '[T] The pool opens at eight.'])
            ->and($fields['body']['value'][0]['content'][1])->toBe(['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'a room']);

        failingSaveForm($greek, $fields);

        expect(StateFixtures::stored($greek)->field('body')->incomplete)->toBeFalse()
            ->and(StateFixtures::statuses($origin))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Current]);
    });
});

describe('an unavailable provider', function () {
    beforeEach(function () {
        $this->origin = failingArticle('hello', [failingParagraph('Breakfast is served until ten.')]);
        // Overloaded, again and again.
        ProviderFixtures::fake('anthropic', ProviderFixtures::error('anthropic', 529, 'overloaded_error', 'Overloaded'), 529, ['retry-after' => '3']);
    });

    it('retries an entry of a browser run whose provider stays down and fails it in the end, saving nothing', function () {
        Sleep::fake();
        $this->signInAdmin();
        $commits = RunFixtures::commits();
        $run = failingRun(['entry_ids' => [$this->origin->id()]]);
        $waits = [];

        for ($tick = 0; $tick < 20 && RunFixtures::items($run)[0]->status === RunItemStatus::Pending; $tick++) {
            $waits[] = failingTick($run)->assertOk()->json('report.wait');
            $this->travel(3)->seconds();
        }

        failingApply($run)->assertOk();
        $stored = RunFixtures::stored($run);

        expect(RunFixtures::items($run)[0])->toMatchObject([
            'status' => RunItemStatus::Failed,
            'reason' => TargetResult::PROVIDER_UNAVAILABLE,
            'error' => 'The AI provider [anthropic] was still unavailable after 10 waits.',
        ])
            ->and($waits)->toBe([...array_fill(0, 10, 3), null])
            ->and(Http::recorded())->toHaveCount(11)
            ->and($stored->status)->toBe(RunStatus::CompletedWithErrors)
            ->and($stored->saves)->toBe(0)
            ->and($stored->usage->isEmpty())->toBeTrue()
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse()
            ->and(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([])
            ->and($commits)->toHaveCount(0);
        Sleep::assertNeverSlept();
    });

    it('waits through the CLI\'s retries and fails the entry in the end, saving nothing', function () {
        Sleep::fake(syncWithCarbon: true);

        $result = failingTranslate($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::PROVIDER_UNAVAILABLE)
            ->and($result->usage->isEmpty())->toBeTrue()
            ->and(Http::recorded())->toHaveCount(11)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse()
            ->and(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([]);
        Sleep::assertSleptTimes(10);
    });

    it('answers a browser tick with the wait while the provider is paused, without calling it or blocking', function () {
        Sleep::fake();
        $this->signInAdmin();
        $run = failingRun(['entry_ids' => [$this->origin->id()]]);
        app(Throttle::class)->pause('anthropic', 30);

        $ticked = failingTick($run)->assertOk();
        $item = RunFixtures::items($run)[0];

        expect($ticked->json('report'))->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 30])
            ->and($ticked->json('run.status'))->toBe('running')
            ->and($item)->toMatchObject(['status' => RunItemStatus::Pending, 'waits' => 1, 'claim' => null])
            ->and($item->availableAt)->toEqual(CarbonImmutable::now()->addSeconds(30))
            ->and(Http::recorded())->toHaveCount(0);
        Sleep::assertNeverSlept();

        $this->travel(30)->seconds();
        failingModel();
        failingDrive($run);

        expect(RunFixtures::statuses($run))->toBe(['done'])
            ->and(failingGreek($this->origin)->get('title'))->toBe('[T] Hello');
    });
});

describe('a lost plan', function () {
    beforeEach(function () {
        $this->signInAdmin();
        failingModel($this->prompts);
        $this->origin = failingArticle('hello', [failingParagraph('Breakfast is served until ten.'), failingParagraph('The pool opens at eight.')]);
    });

    it('plans an item again when its plan is lost from the cache while its units run', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $run = failingRun(['entry_ids' => [$this->origin->id()]]);
        failingTick($run)->assertOk();
        $lost = RunFixtures::items($run)[0]->planId;

        Cache::flush();
        failingDrive($run);
        $item = RunFixtures::items($run)[0];

        expect($item->status)->toBe(RunItemStatus::Done)
            ->and($item->fields)->toBe(['title' => 'applied', 'body' => 'applied'])
            ->and($lost)->not->toBeNull()
            ->and(failingSent($this->prompts))->toBe(['Hello', 'Hello', 'Breakfast is served until ten.', 'The pool opens at eight.'])
            ->and(failingTexts(failingGreek($this->origin)->get('body')))->toBe(['[T] Breakfast is served until ten.', '[T] The pool opens at eight.'])
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Current]);
    });

    it('sends an item whose plan is lost before it is applied back to be planned and translated again', function () {
        $run = failingRun(['entry_ids' => [$this->origin->id()]]);
        failingTick($run)->assertOk();

        Cache::flush();
        $applied = failingApply($run)->assertOk()->json('report');
        $returned = RunFixtures::items($run)[0];
        failingDrive($run);

        expect($applied)->toMatchArray(['items' => 0, 'returned' => 1])
            ->and($returned)->toMatchObject(['status' => RunItemStatus::Pending, 'planId' => null])
            ->and($this->origin->fresh()->existsIn('el'))->toBeTrue()
            ->and(RunFixtures::items($run)[0]->status)->toBe(RunItemStatus::Done)
            ->and($this->prompts)->toHaveCount(2)
            ->and(Entry::query()->where('site', 'el')->count())->toBe(1)
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Current]);
    });
});

describe('two runs over the same target', function () {
    beforeEach(function () {
        $this->label = 'first';
        $this->engine = ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Stop, array_combine(
            array_column($call->segments, 'id'),
            array_map(fn (array $segment) => "[{$this->label}] {$segment['text']}", $call->segments),
        )));
        $this->origin = failingArticle('hello', [failingParagraph('Breakfast is served until ten.')]);
        $this->first = RunFixtures::started(['entryIds' => [$this->origin->id()]]);
    });

    it('skips the target in the run that translated it before the other run saved it', function () {
        $second = RunFixtures::started(['entryIds' => [$this->origin->id()]]);
        RunFixtures::translate($this->first);
        $this->label = 'second';
        RunFixtures::translate($second);

        app(RunProcessor::class)->applyReady($this->first, 'apply-1', CarbonImmutable::now()->addSeconds(30));
        $state = StateFixtures::stored(failingGreek($this->origin))->toArray();
        app(RunProcessor::class)->applyReady($second, 'apply-2', CarbonImmutable::now()->addSeconds(30));

        expect(RunFixtures::items($this->first)[0]->status)->toBe(RunItemStatus::Done)
            ->and(RunFixtures::items($second)[0])->toMatchObject([
                'status' => RunItemStatus::Skipped,
                'reason' => TargetResult::TARGET_CHANGED,
                'fields' => ['title' => 'target_changed', 'body' => 'target_changed'],
            ])
            ->and(RunFixtures::stored($second)->saves)->toBe(0)
            ->and(Entry::query()->where('site', 'el')->count())->toBe(1)
            ->and(failingGreek($this->origin)->get('title'))->toBe('[first] Hello')
            ->and(StateFixtures::stored(failingGreek($this->origin))->toArray())->toBe($state)
            ->and(StateFixtures::stored(failingGreek($this->origin))->hasPending())->toBeFalse()
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Current]);
    });

    it('skips the target in the run that planned it after the other run saved it, without a model call', function () {
        RunFixtures::finish($this->first);
        $state = StateFixtures::stored(failingGreek($this->origin))->toArray();
        $calls = count($this->engine->calls);
        $this->label = 'second';

        $second = RunFixtures::started(['entryIds' => [$this->origin->id()]]);
        RunFixtures::finish($second);

        expect(RunFixtures::items($second)[0])->toMatchObject(['status' => RunItemStatus::Skipped, 'reason' => TargetResult::NOTHING_TO_DO])
            ->and($this->engine->calls)->toHaveCount($calls)
            ->and(failingGreek($this->origin)->get('title'))->toBe('[first] Hello')
            ->and(StateFixtures::stored(failingGreek($this->origin))->toArray())->toBe($state);
    });
});

describe('web requests', function () {
    beforeEach(function () {
        Sleep::fake();
        $this->signInAdmin();
        $this->calls = [];
        // Records the provider calls each request made.
        $this->request = function (string $method, string $url, array $body = []) {
            $before = $this->modelCalls();
            $response = $this->json($method, $url, $body);
            $this->calls[] = $this->modelCalls() - $before;

            return $response;
        };
    });

    afterEach(function () {
        expect(max($this->calls))->toBeLessThanOrEqual(1)
            ->and(array_sum($this->calls))->toBe($this->modelCalls());
        Sleep::assertNeverSlept();
    });

    it('makes at most one provider call per editor request, also when units split, are repaired, retried for missing ids and reviewed', function () {
        $origin = failingArticle('hello', [
            failingParagraph('Book ', ['a room', 'bold'], ' today.'),
            failingParagraph('Breakfast is served until ten.'),
            failingParagraph('The pool opens at eight.'),
        ]);
        $greek = $this->localize($origin, 'el');
        $answered = [];
        failingModel($prompts, function (string $text, array $segment, int $prompt) use (&$answered) {
            if ($prompt === 1) {
                throw new ProviderConnectionException('Could not connect to AI provider [anthropic].', previous: new ConnectionException(FAILING_TIMEOUT));
            }

            $first = ! isset($answered[$segment['id']]);
            $answered[$segment['id']] = true;

            return match (true) {
                $first && $text === FAILING_BOOK => failingWithoutPlaceholders($text),
                $first && $text === 'Breakfast is served until ten.' => null,
                default => '[T] '.$text,
            };
        });
        $this->fakeReview(fn (string $source, string $translation) => $source === 'The pool opens at eight.' ? '[T] The pool opens at eight in the morning.' : null);

        $plan = ($this->request)('POST', cp_route('tolk.editor.plan'), ['reference' => $greek->reference(), 'mode' => 'missing', 'review' => true])->assertOk()->json();
        $queue = array_column($plan['units'], 'id');
        $ran = $fields = [];

        while ($queue !== []) {
            $ran[] = $unit = array_shift($queue);
            $response = ($this->request)('POST', cp_route('tolk.editor.unit'), ['plan' => $plan['plan'], 'unit' => $unit])->assertOk()->json();
            $fields = [...$fields, ...$response['fields']];
            array_push($queue, ...array_column($response['spawned'], 'id'));
        }

        // b1 timed out and was split; one half needed a repair of s2, the other a retry for the missing s3, and every
        // accepted translation a review.
        expect($ran)->toBe(['b1', 'b1.a', 'b1.b', 'b1.a~s2', 'b1.a.v', 'b1.b?', 'b1.b.v', 'b1.a~s2.v', 'b1.b?.v'])
            ->and($this->calls)->toBe([0, 1, 1, 1, 1, 1, 1, 1, 1, 1])
            ->and(failingTexts($fields['body']['value']))->toBe(['[T] Book a room today.', '[T] Breakfast is served until ten.', '[T] The pool opens at eight in the morning.'])
            ->and($fields['body']['incomplete'])->toBeFalse();
    });

    it('makes at most one provider call per request of a browser run, and none while the provider is paused', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        failingModel($prompts);
        $entries = array_map(fn (string $slug) => failingArticle($slug, [failingParagraph('Breakfast is served until ten.'), failingParagraph('The pool opens at eight.')], ucfirst($slug)), ['hello', 'world', 'again']);
        $id = ($this->request)('POST', cp_route('tolk.runs.store'), ['sites' => ['el', 'de'], 'collections' => ['articles']])->assertCreated()->json('run');
        $run = app(RunRepository::class)->find($id);
        ($this->request)('POST', cp_route('tolk.runs.estimate', $id), ['from' => 0])->assertOk();
        ($this->request)('POST', cp_route('tolk.runs.start', $id))->assertOk();
        app(Throttle::class)->pause('anthropic', 5);
        ($this->request)('POST', cp_route('tolk.runs.tick', $id), ['nonce' => 'failureModes1', 'lane' => 1])->assertOk()->assertJsonPath('report.wait', 5);
        $this->travel(5)->seconds();

        for ($round = 0; $round < 40 && RunFixtures::stored($run)->status === RunStatus::Running; $round++) {
            foreach ([1, 2] as $lane) {
                ($this->request)('POST', cp_route('tolk.runs.tick', $id), ['nonce' => 'failureModes1', 'lane' => $lane])->assertOk();
            }

            ($this->request)('POST', cp_route('tolk.runs.apply', $id))->assertOk();
            ($this->request)('GET', cp_route('tolk.runs.state', $id))->assertOk();
        }

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::statuses($run))->toBe(array_fill(0, 6, 'done'))
            ->and($prompts)->toHaveCount(18)
            ->and(array_sum($this->calls))->toBe(18)
            ->and(failingGreek($entries[2])->get('title'))->toBe('[T] Again');
    });
});

describe('git', function () {
    it('dispatches exactly one commit for a run over an entry on a revision-enabled collection, a term, a global set and a nav', function () {
        config(['statamic.revisions.enabled' => true]);
        Collection::find('articles')->revisionsEnabled(true)->save();
        $this->signInAdmin();
        failingModel();
        $hello = failingArticle('hello', [failingParagraph('Breakfast is served until ten.')]);
        // Published and without text of its own, so its translation goes into a working copy.
        $this->localize($hello, 'el');
        $world = failingArticle('world', [failingParagraph('The pool opens at eight.')], 'World');
        $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']]]);
        $commits = RunFixtures::commits();

        $run = failingRun([
            'entry_ids' => [$hello->id(), $world->id()],
            'term_ids' => ['topics::food'],
            'globals' => ['site_settings'],
            'navs' => ['main'],
        ]);
        failingDrive($run);
        $greek = failingGreek($hello);

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(array_map(fn ($item) => [$item->type->value, $item->status->value], RunFixtures::items($run)))->toBe([
                ['entry', 'done'], ['entry', 'done'], ['term', 'done'], ['globals', 'done'], ['nav', 'done'],
            ])
            ->and($greek->get('title'))->toBeNull()
            ->and($greek->hasWorkingCopy())->toBeTrue()
            ->and($greek->fromWorkingCopy()->get('title'))->toBe('[T] Hello')
            ->and(failingGreek($world)->get('title'))->toBe('[T] World')
            ->and(failingGreek($world)->published())->toBeFalse()
            ->and(Term::find('topics::food')->in('el')->get('title'))->toBe('[T] Food')
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->get('tagline'))->toBe('[T] By the sea')
            ->and(Nav::find('main')->in('el')->tree()[0]['title'])->toBe('[T] Book your stay')
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });
});
