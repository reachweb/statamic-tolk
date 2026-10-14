<?php

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Prompts\AgentPrompt;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Ai\Throttle;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderTimeout;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\RequestRejected;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownUnit;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\Unit;
use Reach\StatamicTolk\Translation\UnitOutcome;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'seo_title' => ['type' => 'text', 'localizable' => true, 'display' => 'SEO title', 'character_limit' => 25],
        'faq' => ['type' => 'grid', 'localizable' => true, 'display' => 'FAQ', 'fields' => [
            ['handle' => 'question', 'field' => ['type' => 'text', 'display' => 'Question']],
            ['handle' => 'answer', 'field' => ['type' => 'textarea', 'display' => 'Answer']],
        ]],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => 'The body text.',
        'seo_title' => 'Hello | Example',
        'faq' => [['question' => 'What is it?', 'answer' => 'Call {{ phone }} today.']],
    ], 'hello');
});

function pipelineOptions(array $overrides = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $overrides);
}

function pipelineTarget(EntryContract $source, string $site = 'el'): Target
{
    return app(TargetFactory::class)->forEntry($source, $site);
}

function pipelinePlan(EntryContract $source, array $options = []): Plan
{
    $plan = app(Planner::class)->plan(pipelineTarget($source), pipelineOptions($options));

    app(PlanStore::class)->put($plan);

    return $plan;
}

function tolkPipeline(): Pipeline
{
    return app(Pipeline::class);
}

/**
 * Runs the first runnable unit until the plan is finished, and returns the unit ids in the order they ran.
 */
function pipelineRun(Plan $plan): array
{
    $ran = [];

    while (($runnable = tolkPipeline()->runnable($plan)) !== [] && count($ran) < 30) {
        $ran[] = $id = array_key_first($runnable);
        tolkPipeline()->runUnit($plan, $id);
    }

    return $ran;
}

describe('running units', function () {
    it('runs a two-batch plan to finished and assembles every field', function () {
        config(['tolk.batch.max_segments' => 3]);
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin);

        expect(array_column($plan->batches, 'id'))->toBe(['b1', 'b2'])
            ->and(array_keys(tolkPipeline()->runnable($plan)))->toBe(['b1', 'b2'])
            ->and(tolkPipeline()->readyFields($plan))->toBe([]);

        tolkPipeline()->runUnit($plan, 'b1');

        expect(tolkPipeline()->readyFields($plan))->toBe(['title', 'intro', 'body'])
            ->and(tolkPipeline()->isFinished($plan))->toBeFalse();

        tolkPipeline()->runUnit($plan, 'b2');

        expect(tolkPipeline()->isFinished($plan))->toBeTrue()
            ->and(tolkPipeline()->readyFields($plan))->toBe(['title', 'intro', 'body', 'seo_title', 'faq'])
            ->and($this->modelCalls())->toBe(2);

        $target = pipelineTarget($this->origin);
        $values = array_map(fn (string $handle) => tolkPipeline()->assemble($plan, $target, $handle)->value, array_combine($plan->fieldHandles(), $plan->fieldHandles()));

        expect($values)->toBe([
            'title' => '[T] Hello',
            'intro' => '[T] A short intro.',
            'body' => '[T] The body text.',
            'seo_title' => '[T] Hello | Example',
            'faq' => [['question' => '[T] What is it?', 'answer' => '[T] Call {{ phone }} today.']],
        ]);
    });

    it('describes the assembled field for the state', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'faq']]);
        pipelineRun($plan);

        $title = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title');
        $faq = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'faq');

        expect($title->handle)->toBe('title')
            ->and($title->plan)->toBe($plan->id)
            ->and($title->remove)->toBeFalse()
            ->and($title->sourceHash)->toBe(Fingerprint::of('Hello'))
            ->and($title->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Hello']))
            ->and($title->targetBefore)->toBeNull()
            ->and($title->segments)->toBe([Fingerprint::ofText('Hello')])
            ->and($title->identical)->toBeFalse()
            ->and($title->incomplete)->toBeFalse()
            ->and($title->translator)->toBe('anthropic:claude-sonnet-5-5')
            ->and($title->corrections)->toBe([])
            ->and($title->warnings)->toBe([])
            ->and($faq->segments)->toBe([Fingerprint::ofText('What is it?'), Fingerprint::ofText('Call <x1/> today.')])
            ->and($faq->targetHash)->toBe(Fingerprint::ofLeaves(['[T] What is it?', '[T] Call <x1/> today.']));
    });

    it('makes one model call when a unit runs twice', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin);

        $first = tolkPipeline()->runUnit($plan, 'b1');
        $second = tolkPipeline()->runUnit($plan, 'b1');

        expect($this->modelCalls())->toBe(1)
            ->and($second->toArray())->toBe($first->toArray());
    });

    it('keeps the plan and its outcomes in the file store as arrays', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin);
        pipelineRun($plan);

        $stored = app(PlanStore::class)->get($plan->id);

        expect(Cache::get("tolk:plan:{$plan->id}"))->toBeArray()
            ->and(Cache::get("tolk:plan:{$plan->id}:unit:b1"))->toBeArray()
            ->and($stored->toArray())->toBe($plan->toArray())
            ->and(tolkPipeline()->isFinished($stored))->toBeTrue()
            ->and(tolkPipeline()->assemble($stored, pipelineTarget($this->origin), 'intro')->value)->toBe('[T] A short intro.');
    });

    it('sends each segment with its label as the field and the plan document', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin, ['fields' => ['seo_title', 'faq']]);

        tolkPipeline()->runUnit($plan, 'b1');

        TranslationAgent::assertPrompted(function (AgentPrompt $prompt) use ($plan) {
            $user = json_decode($prompt->prompt, true);

            return $user['document'] === $plan->document
                && $user['segments'] === [
                    ['id' => 's1', 'field' => 'SEO title', 'kind' => 'plain', 'max_length' => 25, 'text' => 'Hello | Example'],
                    ['id' => 's2', 'field' => 'FAQ › Question', 'kind' => 'plain', 'text' => 'What is it?'],
                    ['id' => 's3', 'field' => 'FAQ › Answer', 'kind' => 'plain', 'text' => 'Call <x1/> today.'],
                ]
                && $prompt->timeout === 45
                && str_contains($prompt->agent->instructions, 'into Greek');
        });
    });

    it('records the usage of every call in the outcome and through the callback', function () {
        ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin);
        $recorded = [];

        $outcome = tolkPipeline()->runUnit($plan, 'b1', function (ModelRef $model, Usage $usage) use (&$recorded) {
            $recorded[] = [(string) $model, $usage->toArray()];
        });

        $usage = ['input' => 10, 'output' => 5, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0, 'calls' => 1];

        expect($recorded)->toBe([['anthropic:claude-sonnet-5-5', $usage]])
            ->and($outcome->usage->toArray())->toBe(['anthropic:claude-sonnet-5-5' => $usage])
            ->and($outcome->model)->toBe('anthropic:claude-sonnet-5-5');
    });

    it('refuses a unit the plan does not have', function () {
        $plan = pipelinePlan($this->origin);

        expect(fn () => tolkPipeline()->runUnit($plan, 'b9'))->toThrow(UnknownUnit::class);
    });

    it('calls with the timeout of the profile it is given, the plan\'s by default, and holds the unit as long', function (?string $profile, int $timeout) {
        $this->freezeSecond();
        $plan = pipelinePlan($this->origin, ['fields' => ['title'], 'profile' => 'background']);
        $held = [];
        $engine = ScriptedEngine::bind(function (EngineCall $call) use ($plan, $timeout, &$held) {
            $this->travel($timeout + 9)->seconds();
            $held[] = ! app(PlanStore::class)->lock($plan->id, 'b1', 1)->get();
            $this->travel(2)->seconds();
            $held[] = ! app(PlanStore::class)->lock($plan->id, 'b1', 1)->get();

            return ScriptedEngine::translated($call);
        });

        tolkPipeline()->runUnit($plan, 'b1', profile: $profile);

        expect($engine->calls[0]->timeout)->toBe($timeout)
            ->and($held)->toBe([true, false]);
    })->with([
        'the plan\'s' => [null, 180],
        'web' => ['web', 45],
        'background' => ['background', 180],
    ]);

    it('splits a unit larger than the batch size of the profile it is given into halves without a call, at its depth', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro'], 'profile' => 'background']);
        $unit = tolkPipeline()->units($plan)['b1'];

        expect($plan->batches)->toHaveCount(1)
            ->and(tolkPipeline()->fits($plan, $unit, 'background'))->toBeTrue()
            ->and(tolkPipeline()->fits($plan, $unit, 'web'))->toBeFalse();

        $outcome = tolkPipeline()->runUnit($plan, 'b1', profile: 'web');

        expect($outcome->spawned)->toEqual([new Unit('b1.a', $plan->kind, ['s1']), new Unit('b1.b', $plan->kind, ['s2'])])
            ->and($outcome->model)->toBeNull()
            ->and($outcome->usage->isEmpty())->toBeTrue()
            ->and($engine->calls)->toBe([])
            // One segment over the size is sent as it is.
            ->and(tolkPipeline()->fits($plan, tolkPipeline()->units($plan)['b1.b'], 'web'))->toBeTrue();

        foreach (array_keys(tolkPipeline()->runnable($plan)) as $id) {
            tolkPipeline()->runUnit($plan, $id, profile: 'web');
        }

        expect($engine->segmentIds())->toBe([['s1'], ['s2']])
            ->and(array_column($engine->calls, 'timeout'))->toBe([45, 45])
            ->and(tolkPipeline()->isFinished($plan))->toBeTrue()
            ->and(tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro')->value)->toBe('[T] A short intro.');
    });

    it('sends the halves of a unit split for its size to the fallback when the unit would have gone there', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro'], 'profile' => 'background', 'fallback' => 'openai:gpt-6-luna']);

        foreach (range(1, (int) config('tolk.retry.max_attempts')) as $failure) {
            app(PlanStore::class)->countFailure($plan->id, 'b1');
        }

        $outcome = tolkPipeline()->runUnit($plan, 'b1', profile: 'web');
        pipelineRun($plan);

        expect(array_map(fn (Unit $unit) => $unit->useFallback, $outcome->spawned))->toBe([true, true])
            ->and(array_map(fn (EngineCall $call) => (string) $call->model, $engine->calls))->toBe(['openai:gpt-6-luna', 'openai:gpt-6-luna']);
    });

    it('gives a second caller of a running unit UnitBusy and makes one model call', function () {
        $plan = null;
        $busy = null;

        $this->fakeTranslations(function (string $text) use (&$plan, &$busy) {
            if ($busy === null) {
                $busy = false;

                try {
                    tolkPipeline()->runUnit($plan, 'b1');
                } catch (UnitBusy $exception) {
                    $busy = $exception;
                }
            }

            return '[T] '.$text;
        });

        $plan = pipelinePlan($this->origin, ['fields' => ['title']]);
        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($busy)->toBeInstanceOf(UnitBusy::class)
            ->and($outcome->translations)->toBe(['s1' => '[T] Hello'])
            ->and($this->modelCalls())->toBe(1);
    });

    it('gives UnitBusy while another process holds the unit\'s lock', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin);
        $lock = app(PlanStore::class)->lock($plan->id, 'b1', 60);
        $lock->get();

        expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(UnitBusy::class);

        $lock->release();

        expect(app(PlanStore::class)->outcome($plan->id, 'b1'))->toBeNull();
        $this->assertNoModelCalls();
    });
});

describe('missing and invalid translations', function () {
    it('asks again for a missing id and translates it', function () {
        $answers = 0;
        $this->fakeTranslations(function (string $text) use (&$answers) {
            return $text === 'A short intro.' && $answers++ === 0 ? null : '[T] '.$text;
        });
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->translations)->toBe(['s1' => '[T] Hello'])
            ->and($outcome->spawned)->toEqual([new Unit('b1?', $plan->kind, ['s2'], missingRetry: true)])
            ->and(array_keys(tolkPipeline()->runnable($plan)))->toBe(['b1?']);

        tolkPipeline()->runUnit($plan, 'b1?');

        expect(tolkPipeline()->isFinished($plan))->toBeTrue()
            ->and(tolkPipeline()->translation($plan, 's2'))->toBe('[T] A short intro.')
            ->and(tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro')->value)->toBe('[T] A short intro.');
    });

    it('fails an id that is missing twice', function () {
        $this->fakeTranslations(fn (string $text) => $text === 'A short intro.' ? null : '[T] '.$text);
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);

        expect(pipelineRun($plan))->toBe(['b1', 'b1?'])
            ->and(tolkPipeline()->failure($plan, 's2'))->toBe(UnitOutcome::MISSING)
            ->and(tolkPipeline()->translation($plan, 's2'))->toBeNull()
            ->and(tolkPipeline()->failure($plan, 's1'))->toBeNull();
    });

    it('repairs a dropped placeholder with a note, and the repair succeeds', function () {
        $this->fakeTranslations(fn (string $text, array $segment) => isset($segment['note']) ? '[T] '.$text : '[T] '.str_replace('<x1/>', '', $text));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'faq']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->translations)->toBe(['s1' => '[T] Hello', 's2' => '[T] What is it?'])
            ->and($outcome->spawned)->toEqual([new Unit('b1~s3', $plan->kind, ['s3'], repair: 1, notes: ['s3' => '<x1/> is missing.'])]);

        tolkPipeline()->runUnit($plan, 'b1~s3');

        TranslationAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"id":"s3","field":"FAQ › Answer","kind":"plain","text":"Call <x1/> today.","note":"<x1/> is missing."'));

        $faq = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'faq');

        expect($faq->value)->toBe([['question' => '[T] What is it?', 'answer' => '[T] Call {{ phone }} today.']])
            ->and($faq->incomplete)->toBeFalse()
            ->and($this->modelCalls())->toBe(2);
    });

    it('fails a segment after two bad repairs and leaves that leaf in the source language', function () {
        $this->fakeTranslations(fn (string $text) => '[T] '.str_replace('<x1/>', '', $text));
        $plan = pipelinePlan($this->origin, ['fields' => ['faq']]);

        expect(pipelineRun($plan))->toBe(['b1', 'b1~s2', 'b1~s2~2'])
            ->and(tolkPipeline()->failure($plan, 's2'))->toBe(UnitOutcome::INVALID);

        $faq = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'faq');

        expect($faq->value)->toBe([['question' => '[T] What is it?', 'answer' => 'Call {{ phone }} today.']])
            ->and($faq->incomplete)->toBeTrue()
            ->and($faq->identical)->toBeFalse()
            ->and($faq->segments)->toBe([Fingerprint::ofText('What is it?'), null])
            ->and($faq->targetHash)->toBe(Fingerprint::ofLeaves(['[T] What is it?', 'Call <x1/> today.']))
            ->and($faq->warnings)->toBe(['FAQ › Answer: "Call today." was left untranslated (the model kept breaking its formatting).']);
    });

    it('throws FieldFailed for that field when the plan is strict', function () {
        $this->fakeTranslations(fn (string $text) => '[T] '.str_replace('<x1/>', '', $text));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'faq'], 'strict' => true]);
        pipelineRun($plan);

        expect(tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title')->value)->toBe('[T] Hello');

        try {
            tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'faq');
            $this->fail('FieldFailed was not thrown.');
        } catch (FieldFailed $exception) {
            expect($exception->handle)->toBe('faq')
                ->and($exception->reasons)->toBe(['s3' => UnitOutcome::INVALID]);
        }
    });

    it('leaves a Markdown block in the source language when its translation would turn the code after it into markup, and fails the field when the plan is strict', function () {
        $this->makeCollection('notes', ['en', 'el'], ['body' => ['type' => 'markdown', 'localizable' => true, 'display' => 'Body']]);
        $origin = $this->makeEntry('notes', 'en', ['body' => "Paste this snippet:\n\n    <script>alert(1)</script>\n\nDone."], 'snippet');
        $this->fakeTranslations(fn (string $text) => $text === 'Paste this snippet:' ? '- Fügen Sie dieses Snippet ein:' : '[T] '.$text);
        $plan = pipelinePlan($origin);
        pipelineRun($plan);

        $body = tolkPipeline()->assemble($plan, pipelineTarget($origin), 'body');

        expect(tolkPipeline()->translation($plan, 's1'))->toBe('- Fügen Sie dieses Snippet ein:')
            ->and($body->value)->toBe("Paste this snippet:\n\n    <script>alert(1)</script>\n\n[T] Done.")
            ->and($body->incomplete)->toBeTrue()
            ->and($body->identical)->toBeFalse()
            ->and($body->segments)->toBe([null, Fingerprint::ofText('Done.')])
            ->and($body->targetHash)->toBe(Fingerprint::ofLeaves(['Paste this snippet:', '[T] Done.']))
            ->and($body->warnings)->toBe(['Body: "Paste this snippet:" was left untranslated (the model kept breaking its formatting).']);

        $strict = pipelinePlan($origin, ['strict' => true]);
        pipelineRun($strict);

        try {
            tolkPipeline()->assemble($strict, pipelineTarget($origin), 'body');
            $this->fail('FieldFailed was not thrown.');
        } catch (FieldFailed $exception) {
            expect($exception->reasons)->toBe(['s1' => UnitOutcome::INVALID]);
        }
    });

    it('repairs a translation over its length limit once, then accepts it with a warning', function () {
        $this->fakeTranslations(fn (string $text) => '[T] '.$text.' and a much longer tail');
        $plan = pipelinePlan($this->origin, ['fields' => ['seo_title']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->spawned)->toEqual([new Unit('b1~s1', $plan->kind, ['s1'], repair: 1, notes: ['s1' => 'The translation has 42 characters; it must not exceed 25.'])]);

        $repair = tolkPipeline()->runUnit($plan, 'b1~s1');
        $field = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'seo_title');

        expect($repair->translations)->toBe(['s1' => '[T] Hello | Example and a much longer tail'])
            ->and($field->value)->toBe('[T] Hello | Example and a much longer tail')
            ->and($field->incomplete)->toBeFalse()
            ->and($field->warnings)->toBe(['SEO title: the translation of "Hello | Example" is longer than 25 characters.']);
    });
});

describe('glossary', function () {
    it('passes on what the handlers noticed about a source they leave untouched', function () {
        $this->makeCollection('stories', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'content' => ['type' => 'bard', 'localizable' => true, 'display' => 'Content', 'sets' => ['quote' => ['display' => 'Quote', 'fields' => [
                ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
            ]]]],
        ]);
        $legacy = [['type' => 'text', 'text' => '<p>Welcome to our hotel.</p>'], ['type' => 'quote', 'text' => 'Lovely stay']];
        $note = 'Content (content) is stored in the Bard format of Statamic 2 and was left untouched. Saving it once in the control panel converts it.';
        $story = $this->makeEntry('stories', 'en', ['title' => 'Legacy', 'content' => $legacy], 'legacy');
        $this->fakeTranslations();

        // Without text to plan, the field is left out of the plan with a note.
        $plan = pipelinePlan($story);

        expect($plan->skipped)->toBe(['content' => 'not_applicable'])
            ->and($plan->notes)->toBe(['content' => [$note]])
            ->and($plan->noteList())->toBe([$note])
            ->and(Plan::fromArray($plan->toArray())->notes)->toBe($plan->notes)
            ->and(pipelinePlan($this->origin)->notes)->toBe([]);

        pipelineRun($plan);

        expect(tolkPipeline()->assemble($plan, pipelineTarget($story), 'title')->warnings)->toBe([]);
    });

    it('adds glossary notes to the warnings of an accepted translation without failing it', function () {
        $this->withSettings(['glossary' => [
            ['id' => 'g1', 'term' => 'Example', 'do_not_translate' => true],
            ['id' => 'g2', 'term' => 'intro', 'site' => ['el'], 'translation' => 'εισαγωγή'],
            ['id' => 'g3', 'term' => 'body', 'site' => ['el'], 'translation' => 'κείμενο'],
        ]]);
        $this->fakeTranslations(fn (string $text) => match ($text) {
            'Hello | Example' => 'Γεια | Παράδειγμα',
            'A short intro.' => 'Μια σύντομη εισαγωγή.',
            'The body text.' => 'Το κυρίως μέρος.',
            default => '[T] '.$text,
        });
        $plan = pipelinePlan($this->origin, ['fields' => ['intro', 'body', 'seo_title']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->translations)->toHaveCount(3)
            ->and($outcome->failed)->toBe([])
            ->and($outcome->warnings)->toBe([
                's2' => ['Body: the glossary translates "body" as "κείμενο", but the translation does not seem to use it.'],
                's3' => ['SEO title: the glossary keeps "Example" untranslated, but the translation does not contain it.'],
            ]);

        $field = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'seo_title');

        expect($field->value)->toBe('Γεια | Παράδειγμα')
            ->and($field->incomplete)->toBeFalse()
            ->and($field->warnings)->toBe(['SEO title: the glossary keeps "Example" untranslated, but the translation does not contain it.'])
            ->and(tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro')->warnings)->toBe([]);
    });
});

describe('finish reasons and request failures', function () {
    it('splits a three-segment unit that hit its length limit into halves', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? ScriptedEngine::result($call, Finish::Length)
            : ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro', 'body']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->spawned)->toEqual([
            new Unit('b1.a', $plan->kind, ['s1', 's2'], depth: 1),
            new Unit('b1.b', $plan->kind, ['s3'], depth: 1),
        ])
            ->and($outcome->usage->isEmpty())->toBeFalse();

        expect(pipelineRun($plan))->toBe(['b1.a', 'b1.b'])
            ->and($engine->segmentIds())->toBe([['s1', 's2', 's3'], ['s1', 's2'], ['s3']])
            ->and(tolkPipeline()->translation($plan, 's3'))->toBe('[T] The body text.');
    });

    it('retries one segment with a doubled cap, then fails it as too long', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Length));
        $plan = pipelinePlan($this->origin, ['fields' => ['title']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->spawned)->toEqual([new Unit('b1^', $plan->kind, ['s1'], capMultiplier: 2)]);

        tolkPipeline()->runUnit($plan, 'b1^');

        expect(array_map(fn (EngineCall $call) => $call->capMultiplier, $engine->calls))->toBe([1, 2])
            ->and(tolkPipeline()->failure($plan, 's1'))->toBe(UnitOutcome::TOO_LONG)
            ->and(tolkPipeline()->isFinished($plan))->toBeTrue();
    });

    it('treats an empty answer like a length limit', function () {
        ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Empty));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);

        expect(pipelineRun($plan))->toBe(['b1', 'b1.a', 'b1.a^', 'b1.b', 'b1.b^'])
            ->and(tolkPipeline()->failure($plan, 's1'))->toBe(UnitOutcome::TOO_LONG);
    });

    it('splits a refused unit with several segments and fails a refused single segment', function () {
        ScriptedEngine::bind(fn (EngineCall $call) => in_array('s2', array_column($call->segments, 'id'), true)
            ? ScriptedEngine::result($call, Finish::Refused)
            : ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);

        expect(pipelineRun($plan))->toBe(['b1', 'b1.a', 'b1.b'])
            ->and(tolkPipeline()->translation($plan, 's1'))->toBe('[T] Hello')
            ->and(tolkPipeline()->failure($plan, 's2'))->toBe(UnitOutcome::REFUSED);

        $intro = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro');

        expect($intro->value)->toBe('A short intro.')
            ->and($intro->incomplete)->toBeTrue()
            ->and($intro->identical)->toBeTrue()
            ->and($intro->translator)->toBeNull()
            ->and($intro->warnings)->toBe(['Introduction: "A short intro." was left untranslated (the model refused it).']);
    });

    it('halves a refused unit past the split depth until only the refused segment fails', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => in_array('Answer 13.', array_column($call->segments, 'text'), true)
            ? ScriptedEngine::result($call, Finish::Refused)
            : ScriptedEngine::translated($call));
        $faq = array_map(fn (int $row) => ['question' => "Question {$row}?", 'answer' => "Answer {$row}."], range(1, 20));
        $origin = $this->makeEntry('articles', 'en', ['title' => 'FAQ', 'faq' => $faq], 'faq');
        $plan = pipelinePlan($origin, ['fields' => ['faq']]);
        $refused = array_search('Answer 13.', array_map(fn (array $segment) => $segment['text'], $plan->segments), true);

        expect(tolkPipeline()->units($plan))->toHaveCount(1);

        $ran = pipelineRun($plan);
        $failures = collect($plan->segments)->keys()->mapWithKeys(fn (string $id) => [$id => tolkPipeline()->failure($plan, $id)])->filter();

        expect($failures->all())->toBe([$refused => UnitOutcome::REFUSED])
            ->and($ran)->toHaveCount(13)
            ->and($engine->calls)->toHaveCount(13);

        $field = tolkPipeline()->assemble($plan, pipelineTarget($origin), 'faq');

        expect($field->value[12])->toBe(['question' => '[T] Question 13?', 'answer' => 'Answer 13.'])
            ->and($field->value[11])->toBe(['question' => '[T] Question 12?', 'answer' => '[T] Answer 12.'])
            ->and($field->warnings)->toBe(['FAQ › Answer: "Answer 13." was left untranslated (the model refused it).']);
    });

    it('reviews each translated half of a refused unit on its own, so with review on a halving costs three calls', function () {
        $engine = ScriptedEngine::bind(
            fn (EngineCall $call) => in_array('Answer 13.', array_column($call->segments, 'text'), true)
                ? ScriptedEngine::result($call, Finish::Refused)
                : ScriptedEngine::translated($call),
            fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Stop),
        );
        $faq = array_map(fn (int $row) => ['question' => "Question {$row}?", 'answer' => "Answer {$row}."], range(1, 20));
        $origin = $this->makeEntry('articles', 'en', ['title' => 'FAQ', 'faq' => $faq], 'faq');
        $plan = pipelinePlan($origin, ['fields' => ['faq'], 'review' => true]);

        $ran = pipelineRun($plan);

        expect($ran)->toHaveCount(19)
            ->and($engine->calls)->toHaveCount(13)
            ->and($engine->reviews)->toHaveCount(6)
            ->and(array_sum(array_map(fn (EngineCall $call) => count($call->segments), $engine->reviews)))->toBe(39);
    });

    it('splits a unit with several segments after a timeout without counting a failure', function () {
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderTimeout::after('anthropic', 45, 2)
            : ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->spawned)->toEqual([new Unit('b1.a', $plan->kind, ['s1'], depth: 1), new Unit('b1.b', $plan->kind, ['s2'], depth: 1)])
            ->and($outcome->model)->toBeNull()
            ->and($outcome->usage->isEmpty())->toBeTrue()
            ->and(app(PlanStore::class)->failures($plan->id, 'b1'))->toBe(0);
    });

    it('lets a timeout of one segment propagate, counts it and stores nothing', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderTimeout::after('anthropic', 45, 2)
            : ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title']]);

        expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderTimeout::class);
        expect(app(PlanStore::class)->outcome($plan->id, 'b1'))->toBeNull()
            ->and(app(PlanStore::class)->failures($plan->id, 'b1'))->toBe(1);

        tolkPipeline()->runUnit($plan, 'b1');

        expect(array_map(fn (EngineCall $call) => $call->attempt, $engine->calls))->toBe([1, 2]);
    });

    it('splits a request that was too large and fails the segments of another rejected request', function () {
        $rejected = fn (int $status) => RequestRejected::from('anthropic', new ProviderError(ProviderErrorKind::RequestRejected, 'Rejected.', $status));
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => match ($number) {
            1 => throw $rejected(413),
            2 => throw $rejected(400),
            3 => throw $rejected(413),
        });
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);

        expect(pipelineRun($plan))->toBe(['b1', 'b1.a', 'b1.b'])
            ->and(tolkPipeline()->failure($plan, 's1'))->toBe(UnitOutcome::REQUEST_REJECTED)
            ->and(tolkPipeline()->failure($plan, 's2'))->toBe(UnitOutcome::REQUEST_REJECTED)
            ->and(tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro')->warnings)
            ->toBe(['Introduction: "A short intro." was left untranslated (the provider rejected the request).']);
    });
});

describe('provider failures and the fallback', function () {
    it('throws ProviderPaused for a paused provider and stores nothing', function () {
        $this->fakeTranslations();
        app(Throttle::class)->pause('anthropic', 60);
        $plan = pipelinePlan($this->origin);

        expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderPaused::class);
        expect(app(PlanStore::class)->outcome($plan->id, 'b1'))->toBeNull()
            ->and(app(PlanStore::class)->failures($plan->id, 'b1'))->toBe(1)
            ->and(array_keys(tolkPipeline()->runnable($plan)))->toBe(['b1']);
        $this->assertNoModelCalls();
    });

    it('uses the fallback model after retry.max_attempts transient failures', function () {
        $this->fakeTranslations();
        app(Throttle::class)->pause('anthropic', 60);
        $plan = pipelinePlan($this->origin, ['fields' => ['title'], 'fallback' => 'openai:gpt-6-luna']);

        foreach (range(1, 3) as $attempt) {
            expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderPaused::class);
        }

        $this->assertNoModelCalls();

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect($outcome->model)->toBe('openai:gpt-6-luna')
            ->and(array_keys($outcome->usage->usages))->toBe(['openai:gpt-6-luna'])
            ->and($this->modelCalls())->toBe(1)
            ->and(tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title')->translator)->toBe('openai:gpt-6-luna');
        TranslationAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'gpt-6-luna');
    });

    it('counts attempts from retry.max_attempts', function () {
        config(['tolk.retry.max_attempts' => 1]);
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderUnavailable::connection('anthropic', 3)
            : ScriptedEngine::translated($call));
        $plan = pipelinePlan($this->origin, ['fields' => ['title'], 'fallback' => 'openai:gpt-6-luna']);

        expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderUnavailable::class);

        tolkPipeline()->runUnit($plan, 'b1');

        expect(array_map(fn (EngineCall $call) => (string) $call->model, $engine->calls))->toBe(['anthropic:claude-sonnet-5-5', 'openai:gpt-6-luna']);
    });

    it('does not use a fallback whose provider is paused', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number <= 3
            ? throw ProviderUnavailable::connection($call->model->provider, 3)
            : ScriptedEngine::translated($call));
        app(Throttle::class)->pause('openai', 60);
        $plan = pipelinePlan($this->origin, ['fields' => ['title'], 'fallback' => 'openai:gpt-6-luna']);

        foreach (range(1, 3) as $attempt) {
            expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderUnavailable::class);
        }

        $outcome = tolkPipeline()->runUnit($plan, 'b1');

        expect(array_map(fn (EngineCall $call) => (string) $call->model, $engine->calls))->toBe(array_fill(0, 4, 'anthropic:claude-sonnet-5-5'))
            ->and(array_map(fn (EngineCall $call) => $call->attempt, $engine->calls))->toBe([1, 2, 3, 4])
            ->and($outcome->model)->toBe('anthropic:claude-sonnet-5-5');
    });

    it('runs the units spawned by a fallback answer on the fallback', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => match (true) {
            $number <= 3 => throw ProviderUnavailable::connection('anthropic', 3),
            $number === 4 => ScriptedEngine::result($call, Finish::Length),
            default => ScriptedEngine::translated($call),
        });
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro'], 'fallback' => 'openai:gpt-6-luna']);

        foreach (range(1, 3) as $attempt) {
            expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderUnavailable::class);
        }

        expect(tolkPipeline()->runUnit($plan, 'b1')->spawned)->each->toHaveProperty('useFallback', true);

        pipelineRun($plan);

        expect(array_map(fn (EngineCall $call) => (string) $call->model, array_slice($engine->calls, 3)))->toBe(array_fill(0, 3, 'openai:gpt-6-luna'));
    });

    it('lets ProviderRejected propagate and stores nothing', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin);
        $this->withProviderKeys();

        expect(fn () => tolkPipeline()->runUnit($plan, 'b1'))->toThrow(ProviderRejected::class);
        expect(app(PlanStore::class)->outcome($plan->id, 'b1'))->toBeNull()
            ->and(app(PlanStore::class)->failures($plan->id, 'b1'))->toBe(0);
    });
});

describe('assembling', function () {
    it('raises SourceChanged when the origin was edited after planning', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro', 'faq']]);
        pipelineRun($plan);

        $this->origin->set('intro', 'A longer intro.')->set('faq', [['id' => 'row1', 'question' => 'What is it?', 'answer' => 'Call {{ phone }} today.']])->save();
        $target = pipelineTarget($this->origin);

        expect(tolkPipeline()->assemble($plan, $target, 'title')->value)->toBe('[T] Hello')
            ->and(fn () => tolkPipeline()->assemble($plan, $target, 'intro'))->toThrow(SourceChanged::class)
            ->and(fn () => tolkPipeline()->assemble($plan, $target, 'faq'))->toThrow(SourceChanged::class);
    });

    it('raises SourceChanged when the field left the blueprint', function () {
        $this->fakeTranslations();
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'intro']]);
        pipelineRun($plan);

        $this->makeBlueprint('collections.articles', 'article', ['title' => ['type' => 'text', 'localizable' => true]]);
        Blink::flush();

        expect(fn () => tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro'))->toThrow(SourceChanged::class);
    });

    it('refuses to assemble a field that still has segments to translate', function () {
        $plan = pipelinePlan($this->origin);

        expect(fn () => tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title'))->toThrow(LogicException::class);
    });

    it('leaves the segments out when a translation with no letters is no longer a leaf', function () {
        $this->fakeTranslations(fn (string $text) => $text === 'Hello' ? '2026' : '[T] '.$text);
        $plan = pipelinePlan($this->origin, ['fields' => ['title', 'faq']]);
        pipelineRun($plan);

        $title = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title');
        $faq = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'faq');

        expect($title->value)->toBe('2026')
            ->and($title->segments)->toBe([])
            ->and($title->targetHash)->toBe(Fingerprint::ofLeaves([]))
            ->and($title->incomplete)->toBeFalse()
            ->and($faq->segments)->toHaveCount(2);
    });

    it('leaves the segments out when a translation equals its field\'s default', function () {
        $this->makeBlueprint('collections.articles', 'article', [
            'faq' => ['type' => 'grid', 'localizable' => true, 'display' => 'FAQ', 'fields' => [
                ['handle' => 'question', 'field' => ['type' => 'text']],
                ['handle' => 'answer', 'field' => ['type' => 'textarea', 'default' => 'Weiterlesen']],
            ]],
        ]);
        Blink::flush();
        $this->origin->set('faq', [['question' => 'What is it?', 'answer' => 'Read more']])->save();
        $this->fakeTranslations(fn (string $text) => $text === 'Read more' ? 'Weiterlesen' : '[T] '.$text);
        $plan = pipelinePlan($this->origin, ['fields' => ['faq']]);
        pipelineRun($plan);

        $faq = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'faq');

        expect($faq->value)->toBe([['question' => '[T] What is it?', 'answer' => 'Weiterlesen']])
            ->and($faq->segments)->toBe([])
            ->and($faq->targetHash)->toBe(Fingerprint::ofLeaves(['[T] What is it?']));
    });

    it('keeps the segments when the translation moves its placeholders', function () {
        $this->origin->set('intro', '{{ a }} and {{ b }} & more')->save();
        $this->fakeTranslations(fn (string $text) => $text === '<x1/> and <x2/> &amp; more' ? '<x2/> und <x1/> & mehr' : '[T] '.$text);
        $plan = pipelinePlan($this->origin, ['fields' => ['intro']]);
        pipelineRun($plan);

        $intro = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro');

        expect($intro->value)->toBe('{{ b }} und {{ a }} & mehr')
            ->and($intro->segments)->toBe([Fingerprint::ofText('<x1/> and <x2/> &amp; more')]);
    });

    it('uses a reused translation without a model call', function () {
        $planned = pipelinePlan($this->origin, ['fields' => ['title']])->toArray();
        $planned['segments']['s1']['translation'] = 'Γεια';
        $planned['batches'] = [];
        $plan = Plan::fromArray($planned);

        $title = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title');

        expect(tolkPipeline()->isFinished($plan))->toBeTrue()
            ->and(tolkPipeline()->translation($plan, 's1'))->toBe('Γεια')
            ->and($title->value)->toBe('Γεια')
            ->and($title->segments)->toBe([Fingerprint::ofText('Hello')])
            ->and($title->translator)->toBeNull();
        $this->assertNoModelCalls();
    });

    it('removes the override of a field whose source was emptied', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Μια σύντομη εισαγωγή.']);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Μια σύντομη εισαγωγή.')]);
        $this->origin->remove('intro')->save();

        $plan = pipelinePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['intro']]);
        $intro = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro');

        expect($plan->fields['intro']['clear'])->toBeTrue()
            ->and(tolkPipeline()->isFinished($plan))->toBeTrue()
            ->and($intro->remove)->toBeTrue()
            ->and($intro->value)->toBeNull()
            ->and($intro->targetHash)->toBeNull()
            ->and($intro->targetBefore)->toBe(Fingerprint::ofLeaves(['Μια σύντομη εισαγωγή.']))
            ->and($intro->sourceHash)->toBe(Fingerprint::of(null))
            ->and($intro->segments)->toBe([]);
    });

    it('copies a source value that has no text', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Μια σύντομη εισαγωγή.']);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Μια σύντομη εισαγωγή.')]);
        $this->origin->set('intro', '2026')->save();

        $plan = pipelinePlan($this->origin, ['mode' => Mode::Update, 'fields' => ['intro']]);
        $intro = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'intro');

        expect($intro->remove)->toBeFalse()
            ->and($intro->value)->toBe('2026')
            ->and($intro->targetHash)->toBe(Fingerprint::ofLeaves([]))
            ->and($intro->identical)->toBeTrue()
            ->and($intro->segments)->toBe([]);
    });

    it('marks a translation equal to its source as identical', function () {
        $this->fakeTranslations(fn (string $text) => $text);
        $plan = pipelinePlan($this->origin, ['fields' => ['title']]);
        pipelineRun($plan);

        $title = tolkPipeline()->assemble($plan, pipelineTarget($this->origin), 'title');

        expect($title->value)->toBe('Hello')
            ->and($title->identical)->toBeTrue()
            ->and($title->incomplete)->toBeFalse()
            ->and($title->segments)->toBe([Fingerprint::ofText('Hello')]);
    });
});
