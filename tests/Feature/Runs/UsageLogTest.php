<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;

const USAGE_TRANSLATOR = 'anthropic:claude-sonnet-5-5';

const USAGE_REVIEWER = 'openai:gpt-6.1-sol';

beforeEach(function () {
    $this->useFileCache();
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));

    $this->log = app(UsageLog::class);
    $this->sonnet = ModelRef::fromString(USAGE_TRANSLATOR);
});

function usageCost(string $model, Usage $usage): float
{
    return app(Pricing::class)->cost(ModelRef::fromString($model), $usage);
}

function translateWithUsage(array $overrides = []): TargetResult
{
    test()->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);
    $origin = test()->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background', ...$overrides]);

    return app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($origin, 'el'), $options, new ApplyOptions);
}

it('adds up the calls of a model', function () {
    $first = new Usage(input: 1000, output: 200, cacheRead: 600, reasoning: 50, calls: 1);
    $second = new Usage(input: 2000, output: 300, cacheWrite: 400, calls: 1);

    $this->log->add($this->sonnet, $first, UsageSource::Editor);
    $this->log->add($this->sonnet, $second, UsageSource::Editor);

    $month = $this->log->month();
    $total = ['input' => 3000, 'output' => 500, 'cache_read' => 600, 'cache_write' => 400, 'reasoning' => 50, 'calls' => 2];

    expect(array_keys($month))->toBe([USAGE_TRANSLATOR])
        ->and(array_diff_key($month[USAGE_TRANSLATOR], ['cost' => 0, 'sources' => 0]))->toBe($total)
        ->and($month[USAGE_TRANSLATOR]['cost'])->toEqualWithDelta(usageCost(USAGE_TRANSLATOR, $first) + usageCost(USAGE_TRANSLATOR, $second), 1e-12)
        ->and(array_keys($month[USAGE_TRANSLATOR]['sources']))->toBe(['editor'])
        ->and($month[USAGE_TRANSLATOR]['sources']['editor'])->toBe([...$total, 'cost' => $month[USAGE_TRANSLATOR]['cost']]);
});

it('keeps each month in its own file', function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 59, 59));
    $this->log->add($this->sonnet, new Usage(input: 100, output: 10, calls: 1), UsageSource::Cli);

    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(0, 0, 1));
    $this->log->add($this->sonnet, new Usage(input: 300, output: 30, calls: 1), UsageSource::Cli);
    $this->log->add($this->sonnet, new Usage(input: 500, output: 50, calls: 1), UsageSource::Cli);

    expect((new Filesystem)->files($this->temporaryPath('usage')))->toHaveCount(2)
        ->and($this->temporaryPath('usage/2026-09.json'))->toBeFile()
        ->and($this->temporaryPath('usage/2026-10.json'))->toBeFile()
        ->and($this->log->month('2026-09')[USAGE_TRANSLATOR])->toMatchArray(['input' => 100, 'output' => 10, 'calls' => 1])
        ->and($this->log->month()[USAGE_TRANSLATOR])->toMatchArray(['input' => 800, 'output' => 80, 'calls' => 2])
        ->and($this->log->month('2026-10'))->toBe($this->log->month())
        ->and($this->log->month('2026-08'))->toBe([]);
});

it('keeps the sources apart and adds them up per model', function () {
    $this->log->add($this->sonnet, new Usage(input: 100, output: 10, calls: 1), UsageSource::Cli);
    $this->log->add($this->sonnet, new Usage(input: 200, output: 20, calls: 1), UsageSource::Run);
    $this->log->add($this->sonnet, new Usage(input: 400, output: 40, calls: 1), UsageSource::Editor);

    $model = $this->log->month()[USAGE_TRANSLATOR];

    expect(array_keys($model['sources']))->toBe(['editor', 'run', 'cli'])
        ->and($model['sources']['run'])->toMatchArray(['input' => 200, 'output' => 20, 'calls' => 1])
        ->and($model)->toMatchArray(['input' => 700, 'output' => 70, 'calls' => 3])
        ->and($model['cost'])->toEqualWithDelta(usageCost(USAGE_TRANSLATOR, new Usage(input: 700, output: 70)), 1e-12);
});

it('has no cost for a model without a price, keeps the cost of the others and sorts the models', function () {
    $custom = new ModelRef('anthropic', 'claude-custom');

    $this->log->add($this->sonnet, new Usage(input: 100, output: 10, calls: 1), UsageSource::Run);
    $this->log->add($custom, new Usage(input: 100, output: 10, calls: 1), UsageSource::Run);

    $month = $this->log->month();

    expect(array_keys($month))->toBe(['anthropic:claude-custom', USAGE_TRANSLATOR])
        ->and($month['anthropic:claude-custom'])->toMatchArray(['input' => 100, 'calls' => 1, 'cost' => null])
        ->and($month['anthropic:claude-custom']['sources']['run']['cost'])->toBeNull()
        ->and($month[USAGE_TRANSLATOR]['cost'])->toBeGreaterThan(0.0);
});

it('keeps the price of the day a call was made', function () {
    $this->log->add($this->sonnet, new Usage(input: 1_000_000, calls: 1), UsageSource::Cli);

    config(['tolk.models.anthropic.0.price.input' => 4]);
    $this->log->add($this->sonnet, new Usage(input: 1_000_000, calls: 1), UsageSource::Cli);

    expect($this->log->month()[USAGE_TRANSLATOR]['cost'])->toEqualWithDelta(6.0, 1e-9);
});

it('refuses a month that is not one', function (string $month) {
    $this->log->month($month);
})->throws(InvalidArgumentException::class)->with(['../2026-10', '2026-13', '2026-1', 'october']);

it('resets a month and leaves the others', function () {
    $this->travelTo(now()->setDate(2026, 9, 30));
    $this->log->add($this->sonnet, new Usage(input: 100, output: 10, calls: 1), UsageSource::Cli);
    $this->travelTo(now()->setDate(2026, 10, 5));
    $this->log->add($this->sonnet, new Usage(input: 300, output: 30, calls: 1), UsageSource::Editor);

    $this->log->reset();

    expect($this->log->month())->toBe([])
        ->and($this->temporaryPath('usage/2026-10.json'))->not->toBeFile()
        ->and($this->log->month('2026-09')[USAGE_TRANSLATOR])->toMatchArray(['input' => 100, 'calls' => 1]);

    $this->log->add($this->sonnet, new Usage(input: 500, output: 50, calls: 1), UsageSource::Run);
    $this->log->reset('2026-09');
    $this->log->reset('2026-08');

    expect($this->log->month('2026-09'))->toBe([])
        ->and($this->log->month()[USAGE_TRANSLATOR])->toMatchArray(['input' => 500, 'calls' => 1]);
});

it('refuses to reset a month that is not one', function () {
    $this->log->reset('../2026-10');
})->throws(InvalidArgumentException::class);

it('logs the translator and the reviewer under their own models', function () {
    $this->fakeTranslations();
    $this->fakeReview(['s1' => 'Γεια']);

    $result = translateWithUsage(['review' => true, 'reviewer' => USAGE_REVIEWER]);
    $month = $this->log->month();

    expect($result->status)->toBe(TargetResultStatus::Done)
        ->and(array_keys($month))->toBe([USAGE_TRANSLATOR, USAGE_REVIEWER])
        ->and($month[USAGE_TRANSLATOR])->toMatchArray(['calls' => 1])
        ->and($month[USAGE_REVIEWER])->toMatchArray(['calls' => 1])
        ->and(array_keys($month[USAGE_TRANSLATOR]['sources']))->toBe(['cli'])
        ->and(array_keys($month[USAGE_REVIEWER]['sources']))->toBe(['cli'])
        ->and(array_keys($result->usage->usages))->toBe([USAGE_TRANSLATOR, USAGE_REVIEWER]);
});

it('keeps translating, without a second call, when the log cannot be written', function () {
    Exceptions::fake();
    (new Filesystem)->ensureDirectoryExists($this->temporaryPath());
    (new Filesystem)->put($this->temporaryPath('blocked'), 'a file, not a folder');
    config(['tolk.usage.path' => $this->temporaryPath('blocked/usage')]);
    $this->fakeTranslations();

    $result = translateWithUsage();

    expect($result->status)->toBe(TargetResultStatus::Done)
        ->and($this->modelCalls())->toBe(1)
        ->and($result->usage->get($this->sonnet)->calls)->toBe(1);

    Exceptions::assertReported(ErrorException::class);
});
