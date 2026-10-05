<?php

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Prompts\PromptBuilder;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Runs\Estimate;
use Reach\StatamicTolk\Runs\Estimator;
use Reach\StatamicTolk\Support\Languages;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\PlanOptions;

const ESTIMATE_DOCUMENT = ['type' => 'entry', 'title' => 'Hello', 'container' => 'Articles', 'url' => null, 'summary' => 'A page about greetings.'];

beforeEach(function () {
    $this->context = new PromptContext(siteProfile: 'A travel guide for families.');
});

/**
 * One segment per text, `s1`, `s2`, …; a segment holds the matching key of `$segments` merged in. Without `$batches`
 * every segment without a translation goes into one batch.
 *
 * @param  list<string>  $texts
 * @param  array<string, array>  $segments
 * @param  ?list<list<string>>  $batches
 */
function estimatePlan(array $texts, array $options = [], array $segments = [], ?array $batches = null, UnitKind $kind = UnitKind::Translate): Plan
{
    $planned = [];

    foreach (array_values($texts) as $index => $text) {
        $id = 's'.($index + 1);
        $planned[$id] = [
            'field' => 'body', 'index' => $index, 'kind' => 'plain', 'text' => $text, 'label' => 'Body',
            'hint' => null, 'max_length' => null, 'before' => null, 'after' => null, 'translation' => null,
            ...$segments[$id] ?? [],
        ];
    }

    $batches ??= array_values(array_filter([array_keys(array_filter($planned, fn (array $segment) => $kind === UnitKind::Review || $segment['translation'] === null))]));

    return new Plan(
        id: 'plan-1',
        kind: $kind,
        reference: 'entry::greek',
        target: ['type' => 'entry', 'source_id' => 'origin', 'site' => 'el'],
        sourceSite: 'en',
        targetSite: 'el',
        options: new PlanOptions(...['translator' => ModelRef::fromString('anthropic:claude-sonnet-5-5'), ...$options]),
        document: ESTIMATE_DOCUMENT,
        fields: [],
        segments: $planned,
        batches: array_map(fn (array $ids, int $index) => [
            'id' => 'b'.($index + 1),
            'segment_ids' => $ids,
            'chars' => array_sum(array_map(fn (string $id) => mb_strlen($planned[$id]['text']), $ids)),
        ], $batches, array_keys($batches)),
        skipped: [],
    );
}

function estimate(Plan $plan, ?PromptContext $context = null): Estimate
{
    return app(Estimator::class)->forPlan($plan, $context ?? test()->context);
}

/**
 * The prompt of one call as the pipeline sends it: its system prompt and its user JSON, in characters.
 */
function promptChars(string $system, array $segments): int
{
    return mb_strlen($system) + mb_strlen(app(PromptBuilder::class)->user(ESTIMATE_DOCUMENT, $segments));
}

function systemPrompt(bool $review = false): string
{
    $languages = app(Languages::class);
    $method = $review ? 'reviewSystem' : 'system';

    return app(PromptBuilder::class)->{$method}($languages->forSite('en'), $languages->forSite('el'), test()->context);
}

function promptSegment(string $id, string $text, array $keys = []): array
{
    return ['id' => $id, 'field' => 'Body', 'kind' => 'plain', ...$keys, 'text' => $text];
}

it('counts the system prompt and the user JSON of every batch as input, and 115% of the text as output', function () {
    $plan = estimatePlan(['Hello world', 'Where to stay in Athens'], batches: [['s1'], ['s2']]);
    $system = systemPrompt();
    $input = (int) ceil(promptChars($system, [promptSegment('s1', 'Hello world')]) / 3)
        + (int) ceil(promptChars($system, [promptSegment('s2', 'Where to stay in Athens')]) / 3);
    // ceil(11 × 1.15 / 3) + ceil(23 × 1.15 / 3)
    $output = 5 + 9;

    $estimate = estimate($plan);

    expect($estimate->segments)->toBe(2)
        ->and($estimate->chars)->toBe(34)
        ->and($estimate->inputTokens)->toBe($input)
        ->and($estimate->outputTokens)->toBe($output)
        ->and($estimate->cost)->toEqualWithDelta(($input * 2 + $output * 10) / 1_000_000, 1e-12);
});

it('honours the configured characters per token', function () {
    $plan = estimatePlan(['Hello world, and welcome to our guide.']);
    $three = estimate($plan);

    config(['tolk.tokens.chars_per_token' => 4]);
    $four = estimate($plan);

    config(['tolk.tokens.chars_per_token' => 0]);

    expect($four->inputTokens)->toBeLessThan($three->inputTokens)
        ->and($four->outputTokens)->toBe((int) ceil(38 * 1.15 / 4))
        ->and($four->chars)->toBe($three->chars)
        ->and(estimate($plan))->toEqual($three);
});

it('grows with the characters', function () {
    $previous = null;

    foreach ([20, 200, 2000, 8000] as $length) {
        $estimate = estimate(estimatePlan([str_repeat('word ', $length / 5)]));

        if ($previous) {
            expect($estimate->chars)->toBeGreaterThan($previous->chars)
                ->and($estimate->inputTokens)->toBeGreaterThan($previous->inputTokens)
                ->and($estimate->outputTokens)->toBeGreaterThan($previous->outputTokens)
                ->and($estimate->cost)->toBeGreaterThan($previous->cost);
        }

        $previous = $estimate;
    }
});

it('sends the neighbouring text along as input', function () {
    $plain = estimate(estimatePlan(['Second paragraph.']));
    $neighbours = estimate(estimatePlan(['Second paragraph.'], segments: ['s1' => ['before' => 'The first paragraph.', 'after' => 'The third one.']]));
    $system = systemPrompt();

    expect($neighbours->inputTokens)->toBe((int) ceil(promptChars($system, [promptSegment('s1', 'Second paragraph.', ['before' => 'The first paragraph.', 'after' => 'The third one.'])]) / 3))
        ->and($neighbours->inputTokens)->toBeGreaterThan($plain->inputTokens)
        ->and($neighbours->outputTokens)->toBe($plain->outputTokens)
        ->and($neighbours->chars)->toBe($plain->chars);
});

it('counts only the segments of the batches, never the reused ones', function () {
    $reused = estimatePlan(['A new sentence.', 'A sentence translated before.'], segments: ['s2' => ['translation' => 'Μια πρόταση.']]);

    expect(estimate($reused))->toEqual(estimate(estimatePlan(['A new sentence.'])))
        ->and(estimate($reused)->segments)->toBe(1);
});

it('estimates nothing for a plan without batches', function () {
    $plan = estimatePlan(['Reused.'], ['translator' => new ModelRef('anthropic', 'claude-custom')], ['s1' => ['translation' => 'Ξανά.']]);

    expect(estimate($plan))->toEqual(new Estimate)
        ->and(estimate($plan)->cost)->toBe(0.0);
});

it('adds a review call per batch: twice the text plus the review system prompt in, a tenth of the text out', function () {
    $texts = ['Hello world', 'Where to stay in Athens'];
    $reviewer = new ModelRef('openai', 'gpt-6.1-sol');
    $without = estimate(estimatePlan($texts));
    $with = estimate(estimatePlan($texts, ['review' => true, 'reviewer' => $reviewer]));
    $input = (int) ceil(promptChars(systemPrompt(review: true), [
        ['id' => 's1', 'field' => 'Body', 'kind' => 'plain', 'source' => 'Hello world', 'translation' => 'Hello world'],
        ['id' => 's2', 'field' => 'Body', 'kind' => 'plain', 'source' => 'Where to stay in Athens', 'translation' => 'Where to stay in Athens'],
    ]) / 3);
    // ceil(34 × 0.1 / 3)
    $output = 2;

    expect($with->segments)->toBe($without->segments)
        ->and($with->chars)->toBe($without->chars)
        ->and($with->inputTokens)->toBe($without->inputTokens + $input)
        ->and($with->outputTokens)->toBe($without->outputTokens + $output)
        ->and($with->cost)->toBeGreaterThan($without->cost)
        ->and($with->cost - $without->cost)->toEqualWithDelta(app(Pricing::class)->cost($reviewer, new Usage($input, $output)), 1e-12);
});

it('estimates only the review calls of a review plan, with the translations under review', function () {
    // A review plan's units are review calls, whatever its review option says.
    $plan = estimatePlan(['Hello world'], ['reviewer' => new ModelRef('openai', 'gpt-6.1-sol')], ['s1' => ['translation' => 'Γεια σου κόσμε']], kind: UnitKind::Review);
    $input = (int) ceil(promptChars(systemPrompt(review: true), [
        ['id' => 's1', 'field' => 'Body', 'kind' => 'plain', 'source' => 'Hello world', 'translation' => 'Γεια σου κόσμε'],
    ]) / 3);

    $estimate = estimate($plan);

    expect($estimate->segments)->toBe(1)
        ->and($estimate->chars)->toBe(11)
        ->and($estimate->inputTokens)->toBe($input)
        ->and($estimate->outputTokens)->toBe(1)
        ->and($estimate->cost)->toEqualWithDelta(app(Pricing::class)->cost(new ModelRef('openai', 'gpt-6.1-sol'), new Usage($input, 1)), 1e-12);
});

it('has no cost when a model that is called has no price', function () {
    $unknown = new ModelRef('anthropic', 'claude-custom');
    $translator = estimate(estimatePlan(['Hello world'], ['translator' => $unknown]));
    $reviewer = estimate(estimatePlan(['Hello world'], ['review' => true, 'reviewer' => $unknown]));
    $reviewOnly = estimate(estimatePlan(['Hello world'], ['translator' => $unknown, 'reviewer' => new ModelRef('openai', 'gpt-6.1-sol')], ['s1' => ['translation' => 'Γεια σου κόσμε']], kind: UnitKind::Review));

    expect($translator->cost)->toBeNull()
        ->and($translator->inputTokens)->toBeGreaterThan(0)
        ->and($translator->outputTokens)->toBeGreaterThan(0)
        ->and($reviewer->cost)->toBeNull()
        ->and($reviewOnly->cost)->toBeGreaterThan(0.0)
        ->and(estimate(estimatePlan(['Hello world']))->cost)->toBeGreaterThan(0.0);
});

it('adds estimates up, and an unknown cost makes the total unknown', function () {
    $a = new Estimate(segments: 2, chars: 30, inputTokens: 400, outputTokens: 12, cost: 0.25);
    $b = new Estimate(segments: 1, chars: 10, inputTokens: 300, outputTokens: 4, cost: 0.5);

    expect($a->add($b))->toEqual(new Estimate(3, 40, 700, 16, 0.75))
        ->and((new Estimate)->add($a))->toEqual($a)
        ->and($a->add(new Estimate(cost: null))->cost)->toBeNull()
        ->and((new Estimate(cost: null))->add($a)->cost)->toBeNull();
});

it('round-trips an estimate through its array', function () {
    $estimate = new Estimate(segments: 3, chars: 40, inputTokens: 700, outputTokens: 16, cost: 1.0);

    expect($estimate->toArray())->toBe(['segments' => 3, 'chars' => 40, 'input_tokens' => 700, 'output_tokens' => 16, 'cost' => 1.0])
        ->and(Estimate::fromArray($estimate->toArray()))->toEqual($estimate)
        ->and(Estimate::fromArray([...$estimate->toArray(), 'cost' => null])->cost)->toBeNull()
        ->and(Estimate::fromArray([]))->toEqual(new Estimate)
        ->and(Estimate::fromArray(['segments' => 'many', 'chars' => -5, 'cost' => 'unknown']))->toEqual(new Estimate(cost: null));
});
