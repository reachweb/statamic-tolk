<?php

use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Exceptions\ProviderTimeout;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

const EDITOR_REVIEWER = 'openai:gpt-6.1-sol';

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'subtitle' => ['type' => 'text', 'localizable' => true],
        'body' => ['type' => 'bard', 'localizable' => true, 'display' => 'Body', 'buttons' => ['bold']],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'subtitle' => 'All about it',
        'body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rooms with a view.']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Breakfast'], ['type' => 'text', 'text' => ' until ten.']]],
        ],
    ], 'hello');
    $this->greek = $this->localize($this->origin, 'el');

    $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
});

/**
 * Runs a plan from `editor/plan` or `editor/review` unit by unit, spawned units included, and returns the delivered
 * fields and the unit responses.
 *
 * @return array{fields: array<string, array>, responses: list<array>}
 */
function editorReviewRun(array $plan): array
{
    $queue = array_column($plan['units'], 'id');
    $fields = $plan['ready'];
    $responses = [];

    while ($queue !== []) {
        $responses[] = $response = test()->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan['plan'], 'unit' => array_shift($queue)])->assertOk()->json();
        $fields = [...$fields, ...$response['fields']];
        array_push($queue, ...array_column($response['spawned'], 'id'));
    }

    return ['fields' => $fields, 'responses' => $responses];
}

function editorReviewTranslate(EntryContract $entry, array $body = []): array
{
    return editorReviewRun(test()->postJson(cp_route('tolk.editor.plan'), ['reference' => $entry->reference(), 'mode' => 'missing', ...$body])->assertOk()->json());
}

function editorReview(EntryContract $entry, array $values, array $body = []): TestResponse
{
    return test()->postJson(cp_route('tolk.editor.review'), ['reference' => $entry->reference(), 'values' => $values, ...$body]);
}

/**
 * The delivered fields as the open form holds them.
 */
function editorReviewValues(array $fields): array
{
    return array_map(fn (array $field) => $field['value'], $fields);
}

function editorReviewState(string $handle)
{
    return StateFixtures::stored(test()->greek)->field($handle);
}

/**
 * Corrects the translations of the given source texts with `[R] ` + source.
 */
function editorReviewCorrecting(string ...$sources): Closure
{
    return fn (string $source) => in_array($source, $sources, true) ? ['text' => "[R] {$source}", 'reason' => "Reads better: {$source}"] : null;
}

describe('review of the open form', function () {
    it('reviews the posted values and delivers only the fields it corrected', function () {
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($this->greek)['fields']);
        $before = Entry::find($this->greek->id())->data()->all();
        $this->fakeReview(editorReviewCorrecting('Hello'));

        $plan = editorReview($this->greek, $values)->assertOk()->json();
        $run = editorReviewRun($plan);
        [$response] = $run['responses'];

        expect($plan['fields'])->toBe(['title', 'intro', 'subtitle', 'body'])
            ->and($plan['skipped'])->toBe([])
            ->and($plan['ready'])->toBe([])
            ->and($plan['reviewer'])->toBe(EDITOR_REVIEWER)
            ->and(app(PlanStore::class)->get($plan['plan'])->kind)->toBe(UnitKind::Review)
            ->and($run['fields'])->toBe(['title' => [
                'value' => '[R] Hello',
                'meta' => null,
                'incomplete' => false,
                'warnings' => [],
                'corrections' => [['id' => 's1', 'label' => 'Title', 'before' => '[T] Hello', 'after' => '[R] Hello', 'reason' => 'Reads better: Hello']],
                'reviewer' => EDITOR_REVIEWER,
            ]])
            ->and($response['unchanged'])->toBe(['intro', 'subtitle', 'body'])
            ->and($response['review_skipped'])->toBeFalse()
            ->and($response['reviewed'])->toBeTrue()
            ->and(array_keys($response['usage']))->toBe([EDITOR_REVIEWER])
            ->and(Entry::find($this->greek->id())->data()->all())->toBe($before)
            ->and(editorReviewState('title')->pending[0]->plan)->toBe($plan['plan'])
            ->and(editorReviewState('title')->pending[0]->reviewer)->toBe(EDITOR_REVIEWER)
            ->and(editorReviewState('title')->pending[0]->targetHash)->toBe(Fingerprint::ofLeaves(['[R] Hello']))
            ->and(collect(editorReviewState('intro')->pending)->pluck('plan')->all())->not->toContain($plan['plan']);
    });

    it('records the reviewer once the form is saved with the corrections', function () {
        $this->fakeTranslations();
        $translated = editorReviewValues(editorReviewTranslate($this->greek)['fields']);
        $this->fakeReview(editorReviewCorrecting('Hello'));
        $corrected = editorReviewValues(editorReviewRun(editorReview($this->greek, $translated)->json())['fields']);
        Entry::find($this->greek->id())->merge([...$translated, ...$corrected])->save();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($translated)])
            ->assertOk()
            ->assertJsonPath('localization.status', 'current');

        expect(editorReviewState('title')->reviewer)->toBe(EDITOR_REVIEWER)
            ->and(editorReviewState('title')->translator)->toBe('anthropic:claude-sonnet-5-5')
            ->and(editorReviewState('title')->targetHash)->toBe(Fingerprint::ofLeaves(['[R] Hello']))
            ->and(editorReviewState('title')->pending)->toBe([])
            ->and(editorReviewState('intro')->reviewer)->toBeNull();
    });

    it('reviews Bard values in publish-form shape and keeps the marks on their words', function () {
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($this->greek)['fields']);
        $this->fakeReview(fn (string $source, string $translation) => $source === '<1>Breakfast</1> until ten.' ? ['text' => '<1>[R] Breakfast</1> until ten.', 'reason' => 'Bold word.'] : null);

        $fields = editorReviewRun(editorReview($this->greek, ['body' => $values['body']])->assertOk()->json())['fields'];

        expect(array_keys($fields))->toBe(['body'])
            ->and($fields['body']['value'][1]['content'])->toBe([
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => '[R] Breakfast'],
                ['type' => 'text', 'text' => ' until ten.'],
            ])
            ->and($fields['body']['corrections'])->toBe([['id' => 's2', 'label' => 'Body', 'before' => '[T] <1>Breakfast</1> until ten.', 'after' => '<1>[R] Breakfast</1> until ten.', 'reason' => 'Bold word.']]);
    });

    it('processes the posted values as a save does, so set rows keep their ids', function () {
        $this->makeCollection('pages', ['en', 'el'], [
            'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'text' => ['display' => 'Text', 'fields' => [['handle' => 'heading', 'field' => ['type' => 'text']]]],
            ]]]],
        ]);
        $origin = $this->makeEntry('pages', 'en', ['blocks' => [['id' => 'row-rooms', 'type' => 'text', 'heading' => 'Rooms']]], 'home');
        $greek = $this->localize($origin, 'el');
        $this->signInAdmin();
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($greek)['fields']);
        $this->fakeReview(editorReviewCorrecting('Rooms'));

        $fields = editorReviewRun(editorReview($greek, $values)->assertOk()->json())['fields'];

        expect($values['blocks'][0]['_id'])->toBe('row-rooms')
            ->and($fields['blocks']['value'][0])->toMatchArray(['_id' => 'row-rooms', 'heading' => '[R] Rooms']);
    });

    it('skips the fields that are not machine output', function () {
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($this->greek)['fields']);
        Entry::find($this->greek->id())->merge(['title' => 'Γεια σας', 'intro' => $values['intro']])->save();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => ['title', 'intro']])->assertOk();
        $this->fakeReview(editorReviewCorrecting('Hello', 'A short intro.', 'All about it'));

        $plan = editorReview($this->greek, [
            'title' => 'Γεια σας',
            'intro' => $values['intro'],
            'subtitle' => 'Typed in the form',
        ])->assertOk()->json();

        expect($plan['fields'])->toBe(['intro'])
            ->and($plan['skipped'])->toBe(['title' => 'manual', 'subtitle' => 'manual', 'body' => 'not_requested'])
            ->and(array_keys(editorReviewRun($plan)['fields']))->toBe(['intro']);
    });

    it('skips a delivered field the editor changed in the form', function () {
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($this->greek)['fields']);

        $plan = editorReview($this->greek, [...$values, 'intro' => 'Μια δική μου εισαγωγή.'])->assertOk()->json();

        expect($plan['fields'])->toBe(['title', 'subtitle', 'body'])
            ->and($plan['skipped'])->toBe(['intro' => 'manual']);
    });

    it('reviews manual fields too with overwrite_manual, and they stay manual', function () {
        $this->greek->set('title', 'Γεια σας')->save();
        $this->fakeReview(fn (string $source) => $source === 'Hello' ? ['text' => 'Γεια σου', 'reason' => 'Informal.'] : null);

        $plan = editorReview($this->greek, ['title' => 'Γεια σας'], ['overwrite_manual' => true])->assertOk()->json();
        $fields = editorReviewRun($plan)['fields'];
        Entry::find($this->greek->id())->set('title', 'Γεια σου')->save();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => ['title']])
            ->assertOk()
            ->assertJsonPath('localization.fields.title.status', 'manual');

        expect($plan['fields'])->toBe(['title'])
            ->and($fields['title']['value'])->toBe('Γεια σου')
            ->and(editorReviewState('title')->isBaseline())->toBeTrue()
            ->and(editorReviewState('title')->reviewer)->toBe(EDITOR_REVIEWER);
    });

    it('uses the reviewer that was asked for while review is off in the settings', function () {
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($this->greek)['fields']);

        $plan = editorReview($this->greek, $values, ['reviewer' => 'gemini:gemini-3.8-flash'])->assertOk()->json();

        expect($plan['reviewer'])->toBe('gemini:gemini-3.8-flash')
            ->and((string) app(PlanStore::class)->get($plan['plan'])->options->reviewer)->toBe('gemini:gemini-3.8-flash');
    });

    it('plans nothing when the form holds no translation', function () {
        $plan = editorReview($this->greek, [])->assertOk()->json();

        expect($plan['units'])->toBe([])
            ->and($plan['fields'])->toBe([])
            ->and($plan['skipped'])->toBe(['title' => 'not_requested', 'intro' => 'not_requested', 'subtitle' => 'not_requested', 'body' => 'not_requested']);

        $this->assertNoModelCalls();
    });

    it('reports a review that could not be made and keeps the translation', function () {
        $this->fakeTranslations();
        $values = editorReviewValues(editorReviewTranslate($this->greek)['fields']);
        ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call), fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Refused));

        $run = editorReviewRun(editorReview($this->greek, ['title' => $values['title']])->assertOk()->json());

        expect($run['fields'])->toBe([])
            ->and($run['responses'][0]['unchanged'])->toBe(['title'])
            ->and($run['responses'][0]['review_skipped'])->toBeTrue()
            ->and($run['responses'][0]['reviewed'])->toBeFalse()
            ->and($run['responses'][0]['warnings'])->toBe(['Title: review skipped (refused).']);
    });

    it('reports no skipped review for a translation unit that was split without a model', function () {
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderTimeout::after('anthropic', 45, 2)
            : ScriptedEngine::translated($call));

        $run = editorReviewTranslate($this->greek, ['review' => false]);

        expect($run['responses'][0]['spawned'])->not->toBe([])
            ->and(array_column($run['responses'], 'review_skipped'))->each->toBeFalse();
    });

    it('refuses an origin and a user who cannot edit the localization', function () {
        editorReview($this->origin, [])->assertUnprocessable()->assertJsonPath('code', 'not_translatable');

        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']);

        editorReview($this->greek, [])->assertForbidden();
    });
});

describe('review while translating', function () {
    it('names the reviewer and delivers the corrections with each field', function () {
        $this->fakeTranslations();
        $this->fakeReview(editorReviewCorrecting('A short intro.'));

        $plan = $this->postJson(cp_route('tolk.editor.plan'), ['reference' => $this->greek->reference(), 'mode' => 'missing', 'review' => true])->assertOk()->json();
        $fields = editorReviewRun($plan)['fields'];

        expect($plan['reviewer'])->toBe(EDITOR_REVIEWER)
            ->and($fields['intro']['value'])->toBe('[R] A short intro.')
            ->and($fields['intro']['corrections'])->toBe([['id' => 's2', 'label' => 'Introduction', 'before' => '[T] A short intro.', 'after' => '[R] A short intro.', 'reason' => 'Reads better: A short intro.']])
            ->and($fields['intro']['reviewer'])->toBe(EDITOR_REVIEWER)
            ->and($fields['title']['corrections'])->toBe([])
            ->and($fields['title']['reviewer'])->toBe(EDITOR_REVIEWER);
    });

    it('reports as reviewed the review units that called the reviewer, also when one is run again', function () {
        $this->fakeTranslations();
        $this->fakeReview();

        $plan = $this->postJson(cp_route('tolk.editor.plan'), ['reference' => $this->greek->reference(), 'mode' => 'missing', 'review' => true])->assertOk()->json();
        $responses = editorReviewRun($plan)['responses'];
        $again = $this->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan['plan'], 'unit' => 'b1.v'])->assertOk()->json();

        expect(array_column($responses, 'reviewed'))->toBe([false, true])
            ->and($again['reviewed'])->toBeTrue();
    });

    it('follows the review setting unless the request turns it on or off', function (bool $setting, ?bool $review, ?string $reviewer) {
        $this->withSettings(['review_enabled' => $setting]);

        $plan = $this->postJson(cp_route('tolk.editor.plan'), ['reference' => $this->greek->reference(), 'mode' => 'missing', 'review' => $review, 'reviewer' => 'gemini:gemini-3.8-flash'])->assertOk()->json();
        $options = app(PlanStore::class)->get($plan['plan'])->options;

        expect($plan['reviewer'])->toBe($reviewer)
            ->and($options->review)->toBe($reviewer !== null);
    })->with([
        'setting on' => [true, null, 'gemini:gemini-3.8-flash'],
        'setting off' => [false, null, null],
        'turned on' => [false, true, 'gemini:gemini-3.8-flash'],
        'turned off' => [true, false, null],
    ]);
});
