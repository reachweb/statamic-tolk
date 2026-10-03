<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Ai\TranslationEngine;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Support\Language;

beforeEach(function () {
    config(['tolk.fake_engine' => true, 'tolk.fake_delay_ms' => 0]);
});

function fakeEngineCall(array $segments, string $lang = 'el'): EngineCall
{
    return new EngineCall(
        model: new ModelRef('openai', 'gpt-6-luna'),
        source: new Language('en', 'English', 'en_US', 'en', null, 'ltr'),
        target: new Language($lang, 'Greek', "{$lang}_GR", $lang, null, 'ltr'),
        context: new PromptContext,
        document: ['type' => 'entry', 'title' => 'Markers'],
        segments: $segments,
        timeout: 45,
    );
}

function fakeEngineSegment(string $id, string $text): array
{
    return ['id' => $id, 'field' => 'Body', 'kind' => 'plain', 'text' => $text];
}

function fakeEngineReviewSegment(string $id, string $source, string $translation): array
{
    return ['id' => $id, 'field' => 'Body', 'kind' => 'plain', 'source' => $source, 'translation' => $translation];
}

it('is enabled in the testing and local environments with the flag on', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(FakeEngine::enabled())->toBeTrue();

    config(['tolk.fake_engine' => false]);

    expect(FakeEngine::enabled())->toBeFalse();
})->with(['testing', 'local']);

it('is never enabled in any other environment', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(FakeEngine::enabled())->toBeFalse();
})->with(['production', 'staging']);

it('reads the flag as a boolean', function (mixed $flag, bool $enabled) {
    config(['tolk.fake_engine' => $flag]);

    expect(FakeEngine::enabled())->toBe($enabled);
})->with([
    [true, true],
    ['true', true],
    ['1', true],
    [false, false],
    ['false', false],
    ['0', false],
    [null, false],
]);

it('binds the engine on every resolution', function () {
    expect(app(Engine::class))->toBeInstanceOf(FakeEngine::class);

    config(['tolk.fake_engine' => false]);

    expect(app(Engine::class))->toBeInstanceOf(TranslationEngine::class);

    config(['tolk.fake_engine' => true]);
    app()->detectEnvironment(fn () => 'production');

    expect(app(Engine::class))->toBeInstanceOf(TranslationEngine::class);
});

it('translates every segment into "[lang] text"', function () {
    $call = fakeEngineCall([
        fakeEngineSegment('s1', 'Welcome to Kyma Bay'),
        fakeEngineSegment('s2', 'The <1>sea</1> is warm.<x1/>'),
    ], 'de');

    $result = app(Engine::class)->translate($call);

    expect($result->items)->toBe([
        's1' => '[de] Welcome to Kyma Bay',
        's2' => '[de] The <1>sea</1> is warm.<x1/>',
    ])
        ->and($result->finish)->toBe(Finish::Stop)
        ->and($result->model)->toBe($call->model)
        ->and($result->usage->toArray())->toBe(['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0, 'calls' => 1]);
});

it('answers only the requested ids, the first of a repeated id, and segments with text', function () {
    $result = app(Engine::class)->translate(fakeEngineCall([
        fakeEngineSegment('s1', 'One'),
        fakeEngineSegment('s1', 'Again'),
        ['id' => 2, 'field' => 'Body', 'kind' => 'plain', 'text' => 'Numeric id'],
        ['id' => 's3', 'field' => 'Body', 'kind' => 'plain'],
        fakeEngineSegment('s4', ''),
    ]));

    expect($result->items)->toBe(['s1' => '[el] One', 's4' => '[el] ']);
});

it('calls no model and sends no request, with or without provider keys', function () {
    Http::fake();
    $this->withProviderKeys();
    $engine = app(Engine::class);

    $engine->translate(fakeEngineCall([fakeEngineSegment('s1', 'Hello')]));
    $engine->review(fakeEngineCall([fakeEngineReviewSegment('s1', '[[review]] Hello', '[el] [[review]] Hello')]));

    $this->assertNoModelCalls();
    Http::assertNothingSent();
});

it('waits fake_delay_ms before each answer', function () {
    config(['tolk.fake_delay_ms' => 250]);
    Sleep::fake();
    $engine = app(Engine::class);

    $engine->translate(fakeEngineCall([fakeEngineSegment('s1', 'Hello')]));
    $engine->review(fakeEngineCall([fakeEngineReviewSegment('s1', 'Hello', '[el] Hello')]));

    Sleep::assertSlept(fn (CarbonInterval $duration) => $duration->totalMilliseconds == 250, times: 2);
});

it('does not wait when fake_delay_ms is 0', function () {
    Sleep::fake();

    app(Engine::class)->translate(fakeEngineCall([fakeEngineSegment('s1', 'Hello')]));

    Sleep::assertNeverSlept();
});

it('strips the placeholders of a segment with the [[invalid]] marker, and only of that one', function () {
    $invalid = '[[invalid]] This has <1>bold text</1>, <2>a link</2>,<br/>a break and an image <x1/>.';
    $valid = 'The <1>sea</1> is warm.<x1/>';

    $result = app(Engine::class)->translate(fakeEngineCall([fakeEngineSegment('s1', $invalid), fakeEngineSegment('s2', $valid)]));

    expect($result->finish)->toBe(Finish::Stop)
        ->and($result->items['s1'])->toBe('[el] [[invalid]] This has bold text, a link,a break and an image .')
        ->and(Placeholders::validate($invalid, $result->items['s1'], SegmentKind::Plain))->not->toBeNull()
        ->and($result->items['s2'])->toBe('[el] The <1>sea</1> is warm.<x1/>')
        ->and(Placeholders::validate($valid, $result->items['s2'], SegmentKind::Plain))->toBeNull();
});

it('ends a call with a segment that has the [[refuse]] marker as refused, without items', function () {
    $engine = app(Engine::class);

    $refused = $engine->translate(fakeEngineCall([
        fakeEngineSegment('s1', 'Hello'),
        fakeEngineSegment('s2', '[[refuse]] Do not translate this.'),
    ]));
    $half = $engine->translate(fakeEngineCall([fakeEngineSegment('s1', 'Hello')]));

    expect($refused->finish)->toBe(Finish::Refused)
        ->and($refused->items)->toBe([])
        ->and($refused->usage->calls)->toBe(1)
        ->and($half->finish)->toBe(Finish::Stop)
        ->and($half->items)->toBe(['s1' => '[el] Hello']);
});

it('returns no review corrections without the [[review]] marker', function () {
    $result = app(Engine::class)->review(fakeEngineCall([
        fakeEngineReviewSegment('s1', 'Hello', '[el] Hello'),
        fakeEngineReviewSegment('s2', '[[invalid]] <1>Bold</1>', '[el] [[invalid]] Bold'),
    ]));

    expect($result->items)->toBe([])
        ->and($result->finish)->toBe(Finish::Stop)
        ->and($result->usage->calls)->toBe(1);
});

it('corrects each reviewed segment with the [[review]] marker, with a reason', function () {
    $source = '[[review]] The <1>sea</1> is warm.<x1/>';

    $result = app(Engine::class)->review(fakeEngineCall([
        fakeEngineReviewSegment('s1', 'Hello', '[el] Hello'),
        fakeEngineReviewSegment('s2', $source, '[el] '.$source),
        fakeEngineReviewSegment('s2', '[[review]] Again', '[el] [[review]] Again'),
    ]));

    expect($result->items)->toBe([
        's2' => ['text' => '[el, reviewed] '.$source, 'reason' => 'The source contains the [[review]] marker.'],
    ])
        ->and($result->finish)->toBe(Finish::Stop)
        ->and(Placeholders::validate($source, $result->items['s2']['text'], SegmentKind::Plain))->toBeNull();
});
