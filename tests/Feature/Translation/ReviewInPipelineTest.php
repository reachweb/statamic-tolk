<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;
use Reach\StatamicTolk\Ai\Agents\ReviewAgent;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\RequestRejected;
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
use Reach\StatamicTolk\Translation\Unit;
use Statamic\Contracts\Entries\Entry as EntryContract;

const REVIEWING_TRANSLATOR = 'anthropic:claude-sonnet-5-5';

const REVIEWING_REVIEWER = 'openai:gpt-6.1-sol';

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'body' => ['type' => 'bard', 'localizable' => true, 'display' => 'Body', 'buttons' => ['bold']],
        'seo_title' => ['type' => 'text', 'localizable' => true, 'display' => 'SEO title', 'character_limit' => 25],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => [
            reviewingParagraph(['Book', 'bold'], ' a room.'),
            reviewingParagraph('Breakfast is served until ten.'),
        ],
        'seo_title' => 'Hello | Example',
    ], 'hello');
});

/**
 * A Bard paragraph; a string is a plain text node, [text, mark] a text node with one mark.
 */
function reviewingParagraph(string|array ...$runs): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (string|array $run) => is_string($run)
        ? ['type' => 'text', 'text' => $run]
        : ['type' => 'text', 'marks' => [['type' => $run[1]]], 'text' => $run[0]], $runs)];
}

function reviewingOptions(array $overrides = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['review' => true, ...$overrides]);
}

function reviewingTarget(EntryContract $source): Target
{
    return app(TargetFactory::class)->forEntry($source, 'el');
}

function reviewingPlan(EntryContract $source, array $options = []): Plan
{
    $plan = app(Planner::class)->plan(reviewingTarget($source), reviewingOptions($options));

    app(PlanStore::class)->put($plan);

    return $plan;
}

function reviewingPipeline(): Pipeline
{
    return app(Pipeline::class);
}

/**
 * Runs the first runnable unit until the plan is finished, and returns the unit ids in the order they ran.
 */
function reviewingRun(Plan $plan): array
{
    $ran = [];

    while (($runnable = reviewingPipeline()->runnable($plan)) !== [] && count($ran) < 30) {
        $ran[] = $id = array_key_first($runnable);
        reviewingPipeline()->runUnit($plan, $id);
    }

    return $ran;
}

function reviewingTranslate(EntryContract $source, array $options = []): TargetResult
{
    return app(TargetTranslator::class)->translate(reviewingTarget($source), reviewingOptions(['profile' => 'background', ...$options]), new ApplyOptions);
}

/**
 * The segments of every review prompt, as the prompt carries them.
 */
function reviewingSent(): array
{
    $sent = [];

    ReviewAgent::assertPrompted(function (AgentPrompt $prompt) use (&$sent) {
        array_push($sent, ...json_decode($prompt->prompt, true)['segments']);

        return true;
    });

    return $sent;
}

describe('the review unit', function () {
    it('follows each translate unit with one review of its accepted translations, which the fields wait for', function () {
        $this->fakeTranslations();
        $this->fakeReview();
        $plan = reviewingPlan($this->origin, ['fields' => ['title', 'intro']]);

        $outcome = reviewingPipeline()->runUnit($plan, 'b1');

        expect($outcome->spawned)->toEqual([new Unit('b1.v', UnitKind::Review, ['s1', 's2'])])
            ->and(array_keys(reviewingPipeline()->runnable($plan)))->toBe(['b1.v'])
            ->and(reviewingPipeline()->readyFields($plan))->toBe([])
            ->and(reviewingPipeline()->translation($plan, 's1'))->toBe('[T] Hello');

        reviewingPipeline()->runUnit($plan, 'b1.v');

        expect(reviewingPipeline()->isFinished($plan))->toBeTrue()
            ->and(reviewingPipeline()->readyFields($plan))->toBe(['title', 'intro'])
            ->and($this->modelCalls())->toBe(2);
    });

    it('reviews the translations of a repair in a review of their own', function () {
        $this->fakeTranslations(fn (string $text, array $segment) => $text === '<1>Book</1> a room.' && ! isset($segment['note'])
            ? '[T] Book a room.'
            : '[T] '.$text);
        $this->fakeReview();
        $plan = reviewingPlan($this->origin, ['fields' => ['body']]);

        expect(reviewingRun($plan))->toBe(['b1', 'b1~s1', 'b1~s1.v', 'b1.v'])
            ->and(reviewingPipeline()->units($plan)['b1.v']->segmentIds)->toBe(['s2'])
            ->and(reviewingPipeline()->units($plan)['b1~s1.v']->segmentIds)->toBe(['s1']);
    });

    it('sends the reviewer each segment with its source, its translation and its context', function () {
        $this->fakeTranslations();
        $this->fakeReview();
        $plan = reviewingPlan($this->origin, ['fields' => ['body', 'seo_title']]);

        reviewingRun($plan);

        ReviewAgent::assertPrompted(function (AgentPrompt $prompt) use ($plan) {
            $user = json_decode($prompt->prompt, true);

            return $prompt->model === 'gpt-6.1-sol'
                && $user['document'] === $plan->document
                && $user['segments'] === [
                    ['id' => 's1', 'field' => 'Body', 'kind' => 'inline', 'source' => '<1>Book</1> a room.', 'translation' => '[T] <1>Book</1> a room.'],
                    ['id' => 's2', 'field' => 'Body', 'kind' => 'inline', 'source' => 'Breakfast is served until ten.', 'translation' => '[T] Breakfast is served until ten.'],
                    ['id' => 's3', 'field' => 'SEO title', 'kind' => 'plain', 'max_length' => 25, 'source' => 'Hello | Example', 'translation' => '[T] Hello | Example'],
                ]
                && str_contains($prompt->agent->instructions, 'senior translation reviewer');
        });
    });

    it('does not review when review is off', function () {
        $this->fakeTranslations();
        $plan = reviewingPlan($this->origin, ['fields' => ['title'], 'review' => false]);

        expect(reviewingRun($plan))->toBe(['b1'])
            ->and(reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'title')->reviewer)->toBeNull();
        ReviewAgent::assertNeverPrompted();
    });
});

describe('corrections', function () {
    it('puts corrections into the assembled value and lists them with their reasons', function () {
        $this->fakeTranslations();
        $this->fakeReview([
            's1' => ['text' => 'Κάντε <1>κράτηση</1> για ένα δωμάτιο.', 'reason' => 'The translation was left in English.'],
            's3' => 'Γεια | Example',
        ]);
        $plan = reviewingPlan($this->origin, ['fields' => ['body', 'seo_title']]);
        reviewingRun($plan);

        $body = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'body');
        $seoTitle = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'seo_title');

        expect($body->value)->toBe([
            reviewingParagraph('Κάντε ', ['κράτηση', 'bold'], ' για ένα δωμάτιο.'),
            reviewingParagraph('[T] Breakfast is served until ten.'),
        ])
            ->and($body->corrections)->toBe([
                ['id' => 's1', 'before' => '[T] <1>Book</1> a room.', 'after' => 'Κάντε <1>κράτηση</1> για ένα δωμάτιο.', 'reason' => 'The translation was left in English.'],
            ])
            ->and($body->warnings)->toBe([])
            ->and($body->incomplete)->toBeFalse()
            ->and($body->segments)->toHaveCount(2)
            ->and($body->translator)->toBe(REVIEWING_TRANSLATOR)
            ->and($body->reviewer)->toBe(REVIEWING_REVIEWER)
            ->and($seoTitle->value)->toBe('Γεια | Example')
            ->and($seoTitle->corrections)->toBe([
                ['id' => 's3', 'before' => '[T] Hello | Example', 'after' => 'Γεια | Example', 'reason' => $this::FAKE_REVIEW_REASON],
            ])
            ->and(reviewingPipeline()->translation($plan, 's3'))->toBe('Γεια | Example');
    });

    it('leaves the translations as they were when the reviewer has no corrections', function () {
        $this->fakeTranslations();
        $this->fakeReview();
        $plan = reviewingPlan($this->origin, ['fields' => ['title', 'intro']]);
        reviewingRun($plan);

        $title = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'title');

        expect($title->value)->toBe('[T] Hello')
            ->and($title->corrections)->toBe([])
            ->and($title->warnings)->toBe([])
            ->and($title->reviewer)->toBe(REVIEWING_REVIEWER)
            ->and(reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'intro')->value)->toBe('[T] A short intro.');
    });

    it('drops a correction equal to the translation silently', function () {
        $this->fakeTranslations();
        $this->fakeReview(fn (string $source, string $translation) => $translation);
        $plan = reviewingPlan($this->origin, ['fields' => ['title']]);
        reviewingRun($plan);

        $title = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'title');

        expect($title->value)->toBe('[T] Hello')
            ->and($title->corrections)->toBe([])
            ->and($title->warnings)->toBe([]);
    });

    it('discards a correction that breaks a placeholder or the length limit and keeps the translation', function () {
        $this->fakeTranslations();
        $this->fakeReview([
            's1' => 'Κάντε κράτηση για ένα δωμάτιο.',
            's3' => 'Γεια σας | Example | Ένα πολύ μεγάλο όνομα',
        ]);
        $plan = reviewingPlan($this->origin, ['fields' => ['body', 'seo_title']]);
        reviewingRun($plan);

        $body = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'body');
        $seoTitle = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'seo_title');

        expect($body->value[0])->toBe(reviewingParagraph('[T] ', ['Book', 'bold'], ' a room.'))
            ->and($body->corrections)->toBe([])
            ->and($body->warnings)->toBe(['Body: the reviewer\'s correction of "Book a room." was not valid and was discarded.'])
            ->and($body->incomplete)->toBeFalse()
            ->and($seoTitle->value)->toBe('[T] Hello | Example')
            ->and($seoTitle->corrections)->toBe([])
            ->and($seoTitle->warnings)->toBe(['SEO title: the reviewer\'s correction of "Hello | Example" was not valid and was discarded.']);
    });

    it('checks corrections against the glossary and keeps only the notes about the text that stands', function () {
        $this->withSettings(['glossary' => [
            ['id' => 'g1', 'term' => 'Example', 'do_not_translate' => true],
            ['id' => 'g2', 'term' => 'intro', 'site' => ['el'], 'translation' => 'εισαγωγή'],
        ]]);
        $this->fakeTranslations(fn (string $text) => match ($text) {
            'Hello | Example' => 'Γεια | Παράδειγμα',
            'A short intro.' => 'Μια σύντομη εισαγωγή.',
            default => '[T] '.$text,
        });
        $this->fakeReview(['s1' => 'Μια σύντομη παρουσίαση.', 's2' => 'Γεια | Example']);
        $plan = reviewingPlan($this->origin, ['fields' => ['intro', 'seo_title']]);

        expect(reviewingPipeline()->runUnit($plan, 'b1')->warnings)->toBe([
            's2' => ['SEO title: the glossary keeps "Example" untranslated, but the translation does not contain it.'],
        ]);

        reviewingRun($plan);

        $intro = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'intro');
        $seoTitle = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'seo_title');

        expect($seoTitle->value)->toBe('Γεια | Example')
            ->and($seoTitle->warnings)->toBe([])
            ->and($intro->value)->toBe('Μια σύντομη παρουσίαση.')
            ->and($intro->warnings)->toBe(['Introduction: the glossary translates "intro" as "εισαγωγή", but the translation does not seem to use it.']);
    });

    it('drops the length warning of a translation the reviewer corrected', function () {
        $this->fakeTranslations(fn (string $text) => $text === 'Hello | Example' ? 'Γεια σας και καλώς ήρθατε | Example' : '[T] '.$text);
        $this->fakeReview(['s1' => 'Γεια | Example']);
        $plan = reviewingPlan($this->origin, ['fields' => ['seo_title']]);

        expect(reviewingRun($plan))->toBe(['b1', 'b1~s1', 'b1~s1.v']);

        $seoTitle = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'seo_title');

        expect($seoTitle->value)->toBe('Γεια | Example')
            ->and($seoTitle->warnings)->toBe([]);
    });
});

describe('reused translations', function () {
    it('reviews only what was sent to the translator, and nothing when every leaf was reused', function () {
        $this->fakeTranslations();
        $this->fakeReview();
        reviewingTranslate($this->origin, ['fields' => ['body'], 'review' => false]);
        ReviewAgent::assertNeverPrompted();

        $this->origin->set('body', [
            reviewingParagraph(['Book', 'bold'], ' a room.'),
            reviewingParagraph('Breakfast is served until eleven.'),
        ])->save();

        $result = reviewingTranslate($this->origin, ['fields' => ['body'], 'mode' => Mode::Update]);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and(array_column(reviewingSent(), 'source'))->toBe(['Breakfast is served until eleven.']);

        $calls = $this->modelCalls();
        $this->origin->set('body', [
            reviewingParagraph('Breakfast is served until eleven.'),
            reviewingParagraph(['Book', 'bold'], ' a room.'),
        ])->save();

        $plan = reviewingPlan($this->origin, ['fields' => ['body'], 'mode' => Mode::Update]);

        expect($plan->batches)->toBe([])
            ->and($plan->fields['body']['reused'])->toBe(2)
            ->and(reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'body')->reviewer)->toBeNull()
            ->and($this->modelCalls())->toBe($calls);
    });
});

describe('when the review cannot be made', function () {
    it('skips the review after retry.max_attempts transient failures and still delivers the field', function () {
        $engine = ScriptedEngine::bind(
            fn (EngineCall $call) => ScriptedEngine::translated($call),
            fn (EngineCall $call) => throw ProviderUnavailable::connection('openai', 3),
        );
        $plan = reviewingPlan($this->origin, ['fields' => ['title', 'intro']]);
        reviewingPipeline()->runUnit($plan, 'b1');

        foreach (range(1, 2) as $attempt) {
            expect(fn () => reviewingPipeline()->runUnit($plan, 'b1.v'))->toThrow(ProviderUnavailable::class);
        }

        $outcome = reviewingPipeline()->runUnit($plan, 'b1.v');
        $title = reviewingPipeline()->assemble($plan, reviewingTarget($this->origin), 'title');

        expect($outcome->model)->toBeNull()
            ->and($outcome->corrections)->toBe([])
            ->and($outcome->warnings)->toBe(['s1' => ['Title: review skipped (the provider was unavailable).'], 's2' => ['Introduction: review skipped (the provider was unavailable).']])
            ->and(array_map(fn (EngineCall $call) => $call->attempt, $engine->reviews))->toBe([1, 2, 3])
            ->and(array_map(fn (EngineCall $call) => (string) $call->model, $engine->reviews))->toBe(array_fill(0, 3, REVIEWING_REVIEWER))
            ->and(reviewingPipeline()->isFinished($plan))->toBeTrue()
            ->and($title->value)->toBe('[T] Hello')
            ->and($title->incomplete)->toBeFalse()
            ->and($title->warnings)->toBe(['Title: review skipped (the provider was unavailable).'])
            ->and($title->reviewer)->toBeNull();
    });

    it('saves the fields of a target whose reviewer stays unavailable, with the warning', function () {
        Sleep::fake();
        ScriptedEngine::bind(
            fn (EngineCall $call) => ScriptedEngine::translated($call),
            fn (EngineCall $call) => throw ProviderUnavailable::connection('openai', 3),
        );

        $result = reviewingTranslate($this->origin, ['fields' => ['title']]);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->appliedFields())->toBe(['title'])
            ->and($result->warnings)->toBe(['Title: review skipped (the provider was unavailable).'])
            ->and($this->origin->fresh()->in('el')->get('title'))->toBe('[T] Hello')
            ->and(StateFixtures::stored($this->origin->fresh()->in('el'))->fields['title']->reviewer)->toBeNull();
    });

    it('skips the review when its answer is cut off, refused or rejected', function (Closure $answer, string $reason, bool $usage) {
        ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call), $answer);
        $plan = reviewingPlan($this->origin, ['fields' => ['title']]);
        reviewingPipeline()->runUnit($plan, 'b1');

        $outcome = reviewingPipeline()->runUnit($plan, 'b1.v');

        expect($outcome->warnings)->toBe(['s1' => ["Title: review skipped ({$reason})."]])
            ->and($outcome->spawned)->toBe([])
            ->and(array_keys($outcome->usage->usages))->toBe($usage ? [REVIEWING_REVIEWER] : [])
            ->and(reviewingPipeline()->translation($plan, 's1'))->toBe('[T] Hello')
            ->and(reviewingPipeline()->failure($plan, 's1'))->toBeNull();
    })->with([
        'length' => [fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Length), "the model's answer was cut off", true],
        'empty' => [fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Empty), "the model's answer was cut off", true],
        'refused' => [fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Refused), 'the model refused it', true],
        'rejected request' => [fn (EngineCall $call) => throw RequestRejected::from('openai', new ProviderError(ProviderErrorKind::RequestRejected, 'Bad request.', 400, 'invalid_request')), 'the provider rejected the request', false],
    ]);

    it('lets ProviderRejected from the reviewer propagate and stores nothing', function () {
        $this->fakeTranslations();
        $this->fakeReview();
        $plan = reviewingPlan($this->origin, ['fields' => ['title']]);
        reviewingPipeline()->runUnit($plan, 'b1');
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);

        expect(fn () => reviewingPipeline()->runUnit($plan, 'b1.v'))->toThrow(ProviderRejected::class);
        expect(app(PlanStore::class)->outcome($plan->id, 'b1.v'))->toBeNull()
            ->and(app(PlanStore::class)->failures($plan->id, 'b1.v'))->toBe(0);
    });
});

describe('usage and state', function () {
    it('records the translator and the reviewer in the usage map and the reviewer in the state', function () {
        Event::fake([TargetTranslated::class]);
        $this->fakeTranslations();
        $this->fakeReview(['s1' => 'Γεια']);
        $recorded = [];
        $plan = reviewingPlan($this->origin, ['fields' => ['title']]);

        foreach (['b1', 'b1.v'] as $unit) {
            reviewingPipeline()->runUnit($plan, $unit, function (ModelRef $model, Usage $usage) use (&$recorded) {
                $recorded[] = (string) $model;
            });
        }

        expect($recorded)->toBe([REVIEWING_TRANSLATOR, REVIEWING_REVIEWER]);

        $result = reviewingTranslate($this->origin, ['fields' => ['title', 'intro']]);
        $localization = $this->origin->fresh()->in('el');
        $state = StateFixtures::stored($localization)->fields;

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and(array_keys($result->usage->usages))->toBe([REVIEWING_TRANSLATOR, REVIEWING_REVIEWER])
            ->and($result->corrections)->toBe(['title' => [['id' => 's1', 'before' => '[T] Hello', 'after' => 'Γεια', 'reason' => $this::FAKE_REVIEW_REASON]]])
            ->and($localization->get('title'))->toBe('Γεια')
            ->and($localization->get('intro'))->toBe('[T] A short intro.')
            ->and([$state['title']->translator, $state['title']->reviewer])->toBe([REVIEWING_TRANSLATOR, REVIEWING_REVIEWER])
            ->and([$state['intro']->translator, $state['intro']->reviewer])->toBe([REVIEWING_TRANSLATOR, REVIEWING_REVIEWER])
            ->and(StateFixtures::statuses($this->origin))->toMatchArray(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
        Event::assertDispatched(TargetTranslated::class, fn (TargetTranslated $event) => $event->models === [REVIEWING_TRANSLATOR, REVIEWING_REVIEWER]);
    });
});
