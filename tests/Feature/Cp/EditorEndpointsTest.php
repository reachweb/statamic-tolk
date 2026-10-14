<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Throttle;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Git\CommitJob;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'subtitle' => ['type' => 'text', 'localizable' => true],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.', 'subtitle' => 'All about it'], 'hello');
    $this->greek = $this->localize($this->origin, 'el');
});

function editorPlan(EntryContract $entry, array $body = []): TestResponse
{
    return test()->postJson(cp_route('tolk.editor.plan'), ['reference' => $entry->reference(), 'mode' => 'missing', ...$body]);
}

function editorUnit(string $plan, string $unit): TestResponse
{
    return test()->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan, 'unit' => $unit]);
}

/**
 * Plans and runs every unit, spawned ones included, and returns every delivered field by handle.
 */
function editorTranslate(EntryContract $entry, array $body = []): array
{
    $plan = editorPlan($entry, $body)->assertOk()->json();
    $queue = array_column($plan['units'], 'id');
    $fields = $plan['ready'];

    while ($queue !== []) {
        $response = editorUnit($plan['plan'], array_shift($queue))->assertOk()->json();
        $fields = [...$fields, ...$response['fields']];
        array_push($queue, ...array_column($response['spawned'], 'id'));
    }

    return $fields;
}

function greekEditor(): void
{
    test()->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
}

/**
 * Saves the Greek article through core's update endpoint, as its publish form does: the values it shows, without those
 * of the fields a condition hides, and the handles of the fields the localization holds as `_localized`.
 */
function saveGreekInCp(array $values, array $localized): void
{
    test()->patchJson(cp_route('collections.entries.update', ['articles', test()->greek->id()]), [
        'slug' => 'hello',
        ...$values,
        '_localized' => $localized,
    ])->assertOk();
}

function greekState(string $handle)
{
    return StateFixtures::stored(test()->greek)->field($handle);
}

describe('plan', function () {
    it('plans the untranslated fields into units', function () {
        $this->fakeTranslations();
        greekEditor();

        $response = editorPlan($this->greek)->assertOk();

        expect($response->json('units'))->toBe([['id' => 'b1', 'fields' => ['title', 'intro', 'subtitle'], 'chars' => 31]])
            ->and($response->json('fields'))->toBe(['title', 'intro', 'subtitle'])
            ->and($response->json('skipped'))->toBe([])
            ->and($response->json('ready'))->toBe([])
            ->and(app(PlanStore::class)->get($response->json('plan'))->options->userId)->toBe((string) auth()->id())
            ->and(app(PlanStore::class)->get($response->json('plan'))->options->profile)->toBe('web');

        $this->assertNoModelCalls();
    });

    it('reports the fields with unsaved edits as edited in the form', function () {
        greekEditor();

        $response = editorPlan($this->greek, ['skip' => ['intro'], 'fields' => ['title', 'intro']])->assertOk();

        expect($response->json('fields'))->toBe(['title'])
            ->and($response->json('skipped'))->toBe(['intro' => 'edited_in_form', 'subtitle' => 'not_requested'])
            ->and($response->json('units.0.fields'))->toBe(['title']);
    });

    it('skips manual fields unless they are to be overwritten', function () {
        greekEditor();
        $this->greek->set('title', 'Γεια')->save();

        expect(editorPlan($this->greek)->json('skipped'))->toBe(['title' => 'manual'])
            ->and(editorPlan($this->greek, ['overwrite_manual' => true])->json('fields'))->toBe(['title', 'intro', 'subtitle']);
    });

    it('delivers a field that needs no model call at once', function () {
        $this->fakeTranslations();
        greekEditor();
        editorTranslate($this->greek);
        $this->greek->merge(['title' => '[T] Hello', 'intro' => '[T] A short intro.', 'subtitle' => '[T] All about it'])->save();
        $this->origin->remove('subtitle')->save();
        $calls = $this->modelCalls();

        $response = editorPlan($this->greek, ['mode' => 'update'])->assertOk();

        expect($response->json('units'))->toBe([])
            ->and($response->json('fields'))->toBe(['subtitle'])
            ->and($response->json('ready'))->toBe(['subtitle' => ['value' => null, 'meta' => null, 'sync' => true]])
            ->and($this->modelCalls())->toBe($calls);
    });

    it('passes on what the handlers noticed about a field it delivers at once', function () {
        $this->makeCollection('stories', ['en', 'el'], [
            'content' => ['type' => 'bard', 'localizable' => true, 'display' => 'Content', 'sets' => ['quote' => ['display' => 'Quote', 'fields' => [
                ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
            ]]]],
        ]);
        $story = $this->makeEntry('stories', 'en', ['content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Welcome to our hotel.']]]]], 'story');
        $this->fakeTranslations();
        $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);
        app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($story, 'el'), $options, new ApplyOptions);
        // The origin's text is now in the Bard format of Statamic 2, which the handler leaves as it is.
        $story->set('content', [['type' => 'text', 'text' => '<p>Welcome to our hotel.</p>'], ['type' => 'quote', 'text' => 'Lovely stay']])->save();
        $calls = $this->modelCalls();
        $this->signInAdmin();

        $response = editorPlan($story->in('el'), ['mode' => 'update'])->assertOk();

        expect($response->json('units'))->toBe([])
            ->and(array_keys($response->json('ready')))->toBe(['content'])
            ->and($response->json('warnings'))->toBe(['Content (content) is stored in the Bard format of Statamic 2 and was left untouched. Saving it once in the control panel converts it.'])
            ->and($this->modelCalls())->toBe($calls);
    });

    it('uses the translator that was asked for', function () {
        greekEditor();

        $plan = editorPlan($this->greek, ['translator' => 'openai:gpt-6-luna'])->assertOk()->json('plan');

        expect((string) app(PlanStore::class)->get($plan)->options->translator)->toBe('openai:gpt-6-luna');
    });

    it('refuses a model outside the catalog for an ordinary editor', function () {
        greekEditor();

        editorPlan($this->greek, ['translator' => 'anthropic:claude-opus-5-5'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['translator']);
    });

    it('accepts a model outside the catalog from a user who may edit the settings', function (?array $permissions) {
        $permissions === null
            ? $this->signInAdmin()
            : $this->signInWithPermissions(['access cp', 'translate content', 'access el site', 'edit articles entries', ...$permissions]);

        $plan = editorPlan($this->greek, ['translator' => 'anthropic:claude-opus-5-5'])->assertOk()->json('plan');

        expect((string) app(PlanStore::class)->get($plan)->options->translator)->toBe('anthropic:claude-opus-5-5');
    })->with([
        'super user' => [null],
        'settings permission' => [['edit reachweb/statamic-tolk settings']],
        'configure addons' => [['configure addons']],
    ]);

    it('refuses an entry that is not a localization, and one that does not exist', function () {
        $this->signInAdmin();

        editorPlan($this->origin)->assertUnprocessable()->assertJsonPath('code', 'not_translatable');
        $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'entry::missing', 'mode' => 'missing'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unknown_reference');
    });

    it('refuses a navigation, which has no publish form', function () {
        $this->signInAdmin();
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay']], 'el' => []]);

        $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'nav::main::el', 'mode' => 'missing'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_translatable');
        $this->postJson(cp_route('tolk.editor.review'), ['reference' => 'nav::main::el', 'values' => ['tree' => []]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_translatable');
    });

    it('refuses a localization of a collection the settings exclude', function () {
        $this->signInAdmin();
        $this->withSettings(['excluded_collections' => ['articles']]);

        editorPlan($this->greek)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_translatable')
            ->assertJsonPath('message', 'Entries of this collection are excluded from translation in the settings.');
        $this->postJson(cp_route('tolk.editor.review'), ['reference' => $this->greek->reference(), 'values' => ['title' => 'Γεια']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_translatable');
        $this->assertNoModelCalls();
    });

    it('forbids a user who cannot edit the localization', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']);

        editorPlan($this->greek)->assertForbidden();
        expect(StateFixtures::stored($this->greek)->isEmpty())->toBeTrue();
    });

    it('answers 422 when there is no translator', function () {
        greekEditor();
        $this->withProviderKeys();

        editorPlan($this->greek)->assertUnprocessable()->assertJsonPath('code', 'no_translator');
    });
});

describe('unit', function () {
    it('delivers every text field of the plan in publish-form shape', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $before = Entry::find($this->greek->id())->data()->all();

        $response = editorUnit($plan, 'b1')->assertOk();

        expect($response->json())->toBe([
            'fields' => [
                'title' => ['value' => '[T] Hello', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null],
                'intro' => ['value' => '[T] A short intro.', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null],
                'subtitle' => ['value' => '[T] All about it', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null],
            ],
            'unchanged' => [],
            'spawned' => [],
            'failed' => [],
            'warnings' => [],
            'usage' => ['anthropic:claude-sonnet-5-5' => $response->json('usage.anthropic:claude-sonnet-5-5')],
            'remaining' => 0,
            'review_skipped' => false,
            'reviewed' => false,
        ])
            ->and($response->json('usage.anthropic:claude-sonnet-5-5.calls'))->toBe(1)
            ->and(Entry::find($this->greek->id())->data()->all())->toBe($before)
            ->and(greekState('intro')->pending[0]->plan)->toBe($plan)
            ->and(greekState('intro')->pending[0]->targetHash)->toBe(Fingerprint::ofLeaves(['[T] A short intro.']));
    });

    it('returns a spawned repair unit, which can be run', function () {
        $this->fakeTranslations(fn (string $text, array $segment) => $segment['field'] === 'Introduction' && ! isset($segment['note']) ? "<b>{$text}" : "[T] {$text}");
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        $first = editorUnit($plan, 'b1')->assertOk();

        expect(array_keys($first->json('fields')))->toBe(['title', 'subtitle'])
            ->and($first->json('spawned'))->toBe([['id' => 'b1~s2', 'fields' => ['intro'], 'chars' => 14]])
            ->and($first->json('remaining'))->toBe(1);

        $repair = editorUnit($plan, 'b1~s2')->assertOk();

        expect($repair->json('fields'))->toBe(['intro' => ['value' => '[T] A short intro.', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null]])
            ->and($repair->json('spawned'))->toBe([])
            ->and($repair->json('remaining'))->toBe(0);
    });

    it('delivers a field that spans two units once both ran', function () {
        $this->makeCollection('guides', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [
                ['handle' => 'question', 'field' => ['type' => 'text']],
            ]],
        ]);
        $origin = $this->makeEntry('guides', 'en', ['title' => 'Guide', 'faq' => [['question' => 'What is it?'], ['question' => 'Who is it for?']]]);
        $greek = $this->localize($origin, 'el');
        config(['tolk.batch.max_chars.web' => 16]);
        $this->fakeTranslations();
        $this->signInAdmin();

        $plan = editorPlan($greek)->assertOk()->json();

        expect($plan['units'])->toBe([
            ['id' => 'b1', 'fields' => ['title', 'faq'], 'chars' => 16],
            ['id' => 'b2', 'fields' => ['faq'], 'chars' => 14],
        ])
            ->and(array_keys(editorUnit($plan['plan'], 'b1')->json('fields')))->toBe(['title'])
            ->and(editorUnit($plan['plan'], 'b2')->json('fields.faq.value.*.question'))->toBe(['[T] What is it?', '[T] Who is it for?']);
    });

    it('delivers the fields of a unit that already ran again, without a model call', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        editorUnit($plan, 'b1')->assertOk();

        $again = editorUnit($plan, 'b1')->assertOk();

        expect($this->modelCalls())->toBe(1)
            ->and(array_keys($again->json('fields')))->toBe(['title', 'intro', 'subtitle'])
            ->and($again->json('usage'))->toBe([])
            ->and(greekState('intro')->pending)->toHaveCount(1);
    });

    it('adds each call to the month\'s usage log, under the editor', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        $usage = editorUnit($plan, 'b1')->assertOk()->json('usage');
        $logged = app(UsageLog::class)->month();
        editorUnit($plan, 'b1')->assertOk();

        expect(array_keys($logged))->toBe(['anthropic:claude-sonnet-5-5'])
            ->and($logged['anthropic:claude-sonnet-5-5']['calls'])->toBe(1)
            ->and(array_keys($logged['anthropic:claude-sonnet-5-5']['sources']))->toBe(['editor'])
            ->and(array_diff_key($logged['anthropic:claude-sonnet-5-5']['sources']['editor'], ['cost' => 0]))->toBe($usage['anthropic:claude-sonnet-5-5'])
            ->and(app(UsageLog::class)->month())->toBe($logged);
    });

    it('reports a field that failed in strict mode and delivers the field completed with it', function () {
        $this->withSettings(['strict_fields' => true]);
        $answered = [];
        // The first answer leaves intro and subtitle out; the second, for both, leaves intro out again.
        $this->fakeTranslations(function (string $text, array $segment) use (&$answered) {
            $answered[$segment['id']] = ($answered[$segment['id']] ?? 0) + 1;

            return $segment['field'] === 'Introduction' || ($segment['field'] === 'Subtitle' && $answered[$segment['id']] === 1) ? null : "[T] {$text}";
        });
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        $first = editorUnit($plan, 'b1')->assertOk();
        $retry = editorUnit($plan, 'b1?')->assertOk();

        expect(array_keys($first->json('fields')))->toBe(['title'])
            ->and($first->json('spawned'))->toBe([['id' => 'b1?', 'fields' => ['intro', 'subtitle'], 'chars' => 26]])
            ->and($retry->json('fields'))->toBe(['subtitle' => ['value' => '[T] All about it', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null]])
            ->and($retry->json('failed'))->toBe(['intro' => 'missing'])
            ->and($retry->json('warnings'))->toBe(['The field [intro] could not be translated completely: s2 (the model left it out).'])
            ->and(greekState('intro')->pending)->toBe([])
            ->and(greekState('subtitle')->pending)->toHaveCount(1);
    });

    it('lets only the user who planned run the units', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();
        $plan = editorPlan($this->greek)->json('plan');

        greekEditor();
        editorUnit($plan, 'b1')->assertForbidden();

        $this->actingAs($admin);
        editorUnit($plan, 'b1')->assertOk();
    });

    it('forbids a user who may no longer edit the localization', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        $this->setTestRoles(['tolk_test' => ['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']]);

        editorUnit($plan, 'b1')->assertForbidden();
        $this->assertNoModelCalls();
    });

    it('answers 410 for a plan that expired', function () {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        app(PlanStore::class)->forget(app(PlanStore::class)->get($plan));

        editorUnit($plan, 'b1')->assertStatus(410)->assertJsonPath('code', 'plan_expired');
        editorUnit('01JUNKNOWNPLAN', 'b1')->assertStatus(410)->assertJsonPath('code', 'plan_expired');
    });

    it('answers 410 when the localization was deleted', function () {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $this->greek->delete();
        // A new request starts without the origin's descendants in Blink.
        Blink::flush();

        editorUnit($plan, 'b1')->assertStatus(410)->assertJsonPath('code', 'target_missing');
    });

    it('answers 404 for a unit the plan does not have', function () {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        editorUnit($plan, 'b7')->assertNotFound()->assertJsonPath('code', 'unknown_unit');
    });

    it('answers 409 while the unit runs in another request', function () {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $lock = app(PlanStore::class)->lock($plan, 'b1', 60);
        $lock->get();

        try {
            editorUnit($plan, 'b1')->assertConflict()->assertJsonPath('code', 'busy');
        } finally {
            $lock->release();
        }
    });

    it('answers 409 when the origin changed after planning', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $this->origin->set('intro', 'A longer intro.')->save();

        editorUnit($plan, 'b1')->assertConflict()->assertJsonPath('code', 'source_changed');
        expect(greekState('title')->pending)->toBe([]);
    });

    it('answers 409 when another process changed the origin while the unit ran', function () {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $saved = false;
        $this->fakeTranslations(function (string $text) use (&$saved) {
            if (! $saved) {
                $saved = true;
                $this->saveEntryElsewhere($this->origin->id(), ['intro' => 'A longer intro.']);
            }

            return '[T] '.$text;
        });

        editorUnit($plan, 'b1')->assertConflict()->assertJsonPath('code', 'source_changed');
        expect(greekState('title')->pending)->toBe([]);
    });

    it('answers 410 when the localization was deleted and created again after planning', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $this->greek->delete();
        Blink::flush();
        $again = $this->localize($this->origin, 'el');

        editorUnit($plan, 'b1')->assertStatus(410)->assertJsonPath('code', 'target_missing');
        expect(StateFixtures::stored($again)->field('title')->pending)->toBe([]);
        $this->assertNoModelCalls();
    });

    it('answers 410 when another process deleted, detached or replaced the localization, or deleted the origin, while the unit ran', function (Closure $elsewhere) {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        $done = false;
        $this->fakeTranslations(function (string $text) use (&$done, $elsewhere) {
            if (! $done) {
                $done = true;
                $elsewhere->call($this);
            }

            return '[T] '.$text;
        });

        editorUnit($plan, 'b1')
            ->assertStatus(410)
            ->assertJsonPath('code', 'target_missing')
            ->assertJsonPath('message', 'The entry this translation was made for no longer exists.');

        ContentWriter::refreshStache();
        $now = Entry::find($this->origin->id())?->in('el');

        expect(greekState('title')->pending)->toBe([])
            ->and($now === null ? [] : StateFixtures::stored($now)->field('title')->pending)->toBe([]);
    })->with([
        'the localization deleted' => fn () => $this->deleteEntryElsewhere($this->greek->id()),
        'the localization detached' => fn () => $this->detachLocalizationsElsewhere($this->origin->id()),
        'the localization deleted and created again' => function () {
            $this->deleteEntryElsewhere($this->greek->id());
            $this->localizeEntryElsewhere($this->origin->id(), 'el');
        },
        'the origin deleted with its localizations' => fn () => $this->deleteEntryElsewhere($this->origin->id(), 'delete'),
        'the origin deleted after its localizations were detached' => fn () => $this->deleteEntryElsewhere($this->origin->id(), 'detach'),
    ]);

    it('does not reload the Stache for a unit that completes no field', function () {
        $this->makeCollection('guides', ['en', 'el'], [
            'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [
                ['handle' => 'question', 'field' => ['type' => 'text']],
            ]],
        ]);
        $origin = $this->makeEntry('guides', 'en', ['faq' => [['question' => 'What is it?'], ['question' => 'Who is it for?']]]);
        $greek = $this->localize($origin, 'el');
        config(['tolk.batch.max_chars.web' => 12]);
        $this->fakeTranslations();
        $this->signInAdmin();
        $plan = editorPlan($greek)->assertOk()->json();
        Blink::put('tolk-test-sentinel', true);

        $first = editorUnit($plan['plan'], 'b1')->assertOk();

        expect($plan['units'])->toBe([
            ['id' => 'b1', 'fields' => ['faq'], 'chars' => 11],
            ['id' => 'b2', 'fields' => ['faq'], 'chars' => 14],
        ])
            ->and($first->json('fields'))->toBe([])
            ->and(Blink::has('tolk-test-sentinel'))->toBeTrue()
            ->and(editorUnit($plan['plan'], 'b2')->json('fields.faq.value.*.question'))->toBe(['[T] What is it?', '[T] Who is it for?']);
    });

    it('answers 429 with the wait while the provider is paused', function () {
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        app(Throttle::class)->pause('anthropic', 60);

        $response = editorUnit($plan, 'b1')->assertTooManyRequests()->assertJsonPath('code', 'provider_paused');

        expect($response->json('retry_after'))->toBeGreaterThanOrEqual(59)->toBeLessThanOrEqual(60)
            ->and($response->headers->get('Retry-After'))->toBe((string) $response->json('retry_after'))
            ->and(app(PlanStore::class)->outcome($plan, 'b1'))->toBeNull();
    });

    it('answers 503 with the wait while the provider cannot be reached', function () {
        ScriptedEngine::bind(fn (EngineCall $call) => throw ProviderUnavailable::connection('anthropic', 7));
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        editorUnit($plan, 'b1')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('retry_after', 7);
    });

    it('answers 502 for a rejected key, without the key', function () {
        $key = 'sk-ant-api03-'.str_repeat('Secret', 6);
        config(['ai.providers.anthropic.key' => $key]);
        ProviderFixtures::fake('anthropic', ProviderFixtures::error('anthropic', 401, 'authentication_error', "invalid x-api-key {$key}"), 401);
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');

        $response = editorUnit($plan, 'b1')->assertStatus(502)->assertJsonPath('code', 'provider_rejected');

        expect($response->json('message'))->toContain('[anthropic]')
            ->and($response->getContent())->not->toContain($key)
            ->and($response->getContent())->not->toContain('Secret');
    });

    it('offers a translator whose provider has no key while another has one, and answers 502 naming its variables', function () {
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
        $this->withSettings(['translator_model' => 'gemini:gemini-3.8-flash']);
        greekEditor();

        $this->getJson(cp_route('tolk.editor.status', ['reference' => $this->greek->reference()]))
            ->assertJsonPath('defaults.translator', 'gemini:gemini-3.8-flash')
            ->assertJsonPath('defaults.providers_configured', true);
        $plan = editorPlan($this->greek)->assertOk()->json('plan');

        editorUnit($plan, 'b1')
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_rejected')
            ->assertJsonPath('message', 'The AI provider [gemini] has no API key. Set GEMINI_API_KEY or TOLK_GEMINI_API_KEY in .env.');
    });

    it('raises a time limit that is too low for a provider call', function () {
        $this->fakeTranslations();
        greekEditor();
        $plan = editorPlan($this->greek)->json('plan');
        config(['tolk.http.timeout.web' => 50]);

        try {
            set_time_limit(30);
            editorUnit($plan, 'b1')->assertOk();

            expect(ini_get('max_execution_time'))->toBe('65');
        } finally {
            set_time_limit(0);
        }
    });
});

describe('confirm', function () {
    it('promotes the delivered fields after the form was saved with them', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        Entry::find($this->greek->id())->merge(array_map(fn (array $field) => $field['value'], $fields))->save();

        $response = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();

        expect($response->json('not_saved'))->toBe([])
            ->and($response->json('translatable'))->toBeTrue()
            ->and($response->json('localization.status'))->toBe('current')
            ->and(greekState('intro')->pending)->toBe([])
            ->and(greekState('intro')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] A short intro.']));
    });

    it('writes a baseline for a delivered field that was edited before the save', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        Entry::find($this->greek->id())->merge(['title' => '[T] Hello', 'intro' => 'Μια σύντομη εισαγωγή.', 'subtitle' => '[T] All about it'])->save();

        $response = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();

        expect($response->json('localization.fields.intro'))->toBe(['status' => 'manual', 'label' => 'Introduction', 'source_changed' => false, 'incomplete' => false])
            ->and(greekState('intro')->isBaseline())->toBeTrue()
            ->and(greekState('intro')->sourceHash)->toBe(Fingerprint::of('A short intro.'))
            ->and(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Current);
    });

    it('keeps a translation machine output when it is saved after another tab saved a hand-typed text of the field', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);

        Entry::find($this->greek->id())->set('title', 'Γεια σου')->save();
        $other = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => []])->assertOk();

        Entry::find($this->greek->id())->merge(array_map(fn (array $field) => $field['value'], $fields))->save();
        $response = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();

        expect($other->json('localization.fields.title.status'))->toBe('manual')
            ->and($response->json('localization.fields.title.status'))->toBe('current')
            ->and(greekState('title')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Hello']))
            ->and(greekState('title')->pending)->toBe([]);
    });

    it('reads a delivered field as machine output again once its edit is undone and saved', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        $values = array_map(fn (array $field) => $field['value'], $fields);

        Entry::find($this->greek->id())->merge([...$values, 'title' => '[T] Hello!'])->save();
        $edited = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();

        Entry::find($this->greek->id())->set('title', '[T] Hello')->save();
        $undone = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => []])->assertOk();

        expect($edited->json('localization.fields.title.status'))->toBe('manual')
            ->and($undone->json('localization.fields.title.status'))->toBe('current');
    });

    it('lists a delivered field that was not stored', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        Entry::find($this->greek->id())->merge(['intro' => $fields['intro']['value'], 'subtitle' => $fields['subtitle']['value']])->save();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])
            ->assertOk()
            ->assertJsonPath('not_saved', ['title'])
            ->assertJsonPath('localization.fields.title.status', 'untranslated')
            ->assertJsonPath('localization.fields.intro.status', 'current');

        expect(greekState('title')->pending)->toHaveCount(1);
    });

    it('does not list a delivered field whose earlier override was saved instead', function () {
        $this->fakeTranslations();
        greekEditor();
        $this->greek->set('title', 'Γεια')->save();
        $fields = editorTranslate($this->greek, ['overwrite_manual' => true]);
        Entry::find($this->greek->id())->merge(['intro' => $fields['intro']['value'], 'subtitle' => $fields['subtitle']['value']])->save();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])
            ->assertOk()
            ->assertJsonPath('not_saved', [])
            ->assertJsonPath('localization.fields.title.status', 'manual');

        expect(greekState('title')->pending)->toHaveCount(1);
    });

    it('lists a delivered field that a condition hid, which the form left out of the post and of `_localized`', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);

        saveGreekInCp(['title' => $fields['title']['value'], 'intro' => $fields['intro']['value']], ['title', 'intro']);

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])
            ->assertOk()
            ->assertJsonPath('not_saved', ['subtitle'])
            ->assertJsonPath('localization.fields.subtitle.status', 'untranslated')
            ->assertJsonPath('localization.fields.intro.status', 'current');

        expect(Entry::find($this->greek->id())->data()->has('subtitle'))->toBeFalse()
            ->and(Entry::find($this->greek->id())->value('subtitle'))->toBe('All about it')
            ->and(greekState('subtitle')->pending)->toHaveCount(1);
    });

    it('stores a field that core posts in `_localized` without its value as an empty value of its own, so the editor takes a hidden field out', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);

        saveGreekInCp(['title' => $fields['title']['value'], 'intro' => $fields['intro']['value']], ['title', 'intro', 'subtitle']);

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])
            ->assertOk()
            ->assertJsonPath('not_saved', [])
            ->assertJsonPath('localization.fields.subtitle.status', 'manual');

        expect(Entry::find($this->greek->id())->data()->has('subtitle'))->toBeTrue()
            ->and(Entry::find($this->greek->id())->get('subtitle'))->toBeNull();
    });

    it('forgets the plan and the model\'s answers once the form was saved with its translation', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        $plan = greekState('intro')->pending[0]->plan;
        Entry::find($this->greek->id())->merge([...array_map(fn (array $field) => $field['value'], $fields), 'title' => 'Γεια'])->save();

        expect(app(PlanStore::class)->get($plan))->not->toBeNull();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();

        expect(app(PlanStore::class)->get($plan))->toBeNull()
            ->and(app(PlanStore::class)->outcome($plan, 'b1'))->toBeNull()
            ->and(editorUnit($plan, 'b1')->assertStatus(410)->json('code'))->toBe('plan_expired');
    });

    it('forgets the plan once every field it delivered was saved edited', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek, ['fields' => ['intro']]);
        $plan = greekState('intro')->pending[0]->plan;
        Entry::find($this->greek->id())->set('intro', 'Μια σύντομη εισαγωγή.')->save();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])
            ->assertOk()
            ->assertJsonPath('localization.fields.intro.status', 'manual');

        expect(app(PlanStore::class)->get($plan))->toBeNull();
    });

    it('keeps a plan with units still to run until they ran and the form was saved again', function () {
        $this->makeCollection('guides', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [
                ['handle' => 'question', 'field' => ['type' => 'text']],
            ]],
        ]);
        $origin = $this->makeEntry('guides', 'en', ['title' => 'Guide', 'faq' => [['question' => 'What is it?'], ['question' => 'Who is it for?']]]);
        $greek = $this->localize($origin, 'el');
        config(['tolk.batch.max_chars.web' => 16]);
        $this->fakeTranslations();
        $this->signInAdmin();
        $plan = editorPlan($greek)->assertOk()->json('plan');

        $title = editorUnit($plan, 'b1')->assertOk()->json('fields.title.value');
        Entry::find($greek->id())->set('title', $title)->save();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $greek->reference(), 'delivered' => ['title']])
            ->assertOk()
            ->assertJsonPath('localization.fields.title.status', 'current');

        expect(app(PlanStore::class)->get($plan))->not->toBeNull();

        $faq = editorUnit($plan, 'b2')->assertOk()->json('fields.faq.value');
        Entry::find($greek->id())->set('faq', $faq)->save();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $greek->reference(), 'delivered' => ['faq']])
            ->assertOk()
            ->assertJsonPath('localization.fields.faq.status', 'current');

        expect(app(PlanStore::class)->get($plan))->toBeNull();
    });

    it('keeps the plan of another user who translated the fields that were saved', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        $plan = greekState('intro')->pending[0]->plan;
        Entry::find($this->greek->id())->merge(array_map(fn (array $field) => $field['value'], $fields))->save();
        $this->signInAdmin();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => []])
            ->assertOk()
            ->assertJsonPath('localization.fields.intro.status', 'current');

        expect(app(PlanStore::class)->get($plan))->not->toBeNull();
    });

    it('commits the state it wrote, and only then', function () {
        $this->fakeTranslations();
        greekEditor();
        Queue::fake();
        config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
        $fields = editorTranslate($this->greek);

        Queue::assertNotPushed(CommitJob::class);

        Entry::find($this->greek->id())->merge(array_map(fn (array $field) => $field['value'], $fields))->saveQuietly();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();

        Queue::assertPushed(CommitJob::class, 1);
        Queue::assertPushed(CommitJob::class, fn (CommitJob $job) => $job->message === StateWriter::COMMIT_MESSAGE);
    });

    it('returns the status of an entry that is not a localization', function () {
        $this->signInAdmin();

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->origin->reference(), 'delivered' => ['title']])
            ->assertOk()
            ->assertJsonPath('not_saved', [])
            ->assertJsonPath('origin.localizations.*.site', ['el', 'de']);
    });

    it('forbids a user who cannot edit the localization, before it writes', function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek);
        Entry::find($this->greek->id())->merge(array_map(fn (array $field) => $field['value'], $fields))->save();
        $this->setTestRoles(['tolk_test' => ['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']]);

        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertForbidden();
        expect(greekState('intro')->pending)->toHaveCount(1);
    });
});

describe('baseline', function () {
    beforeEach(function () {
        $this->fakeTranslations();
        greekEditor();
        $fields = editorTranslate($this->greek, ['fields' => ['intro', 'subtitle']]);
        Entry::find($this->greek->id())->merge(['title' => 'Γεια', ...array_map(fn (array $field) => $field['value'], $fields)])->save();
        $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $this->greek->reference(), 'delivered' => array_keys($fields)])->assertOk();
    });

    it('marks the manual fields as checked against the current origin', function () {
        $response = $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => $this->greek->reference()])->assertOk();

        expect($response->json('baselined'))->toBe(1)
            ->and($response->json('localization.fields.title.source_changed'))->toBeFalse()
            ->and($response->json('localization.fields.intro.status'))->toBe('current')
            ->and($response->json('localization.counts.untracked'))->toBe(0);
    });

    it('protects machine fields from updates', function () {
        $response = $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => $this->greek->reference(), 'fields' => ['intro'], 'protect' => true])->assertOk();

        expect($response->json('baselined'))->toBe(1)
            ->and($response->json('localization.fields.intro.status'))->toBe('manual')
            ->and($response->json('localization.fields.subtitle.status'))->toBe('current')
            ->and($response->json('localization.fields.title.source_changed'))->toBeNull();
    });

    it('commits the baseline it wrote', function () {
        Queue::fake();
        config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);

        $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => $this->greek->reference()])->assertJsonPath('baselined', 1);
        $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => $this->greek->reference()])->assertJsonPath('baselined', 0);

        Queue::assertPushed(CommitJob::class, 1);
    });

    it('forbids a user who cannot edit the localization', function () {
        $this->setTestRoles(['tolk_test' => ['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']]);

        $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => $this->greek->reference()])->assertForbidden();
        expect(greekState('title')->isEmpty())->toBeTrue();
    });
});

it('answers for a localization in a site its collection no longer lists as for an unknown entry, as once the Stache is rebuilt, and writes no state', function () {
    $this->fakeTranslations();
    $this->signInAdmin();
    $plan = editorPlan($this->greek)->assertOk()->json();
    StateFixtures::store($this->greek, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
    $stored = StateFixtures::stored($this->greek)->toArray();
    $reference = $this->greek->reference();

    Collection::find('articles')->sites(['en', 'de'])->save();

    // Core still finds the entry, and opens its form, until the Stache is rebuilt.
    expect(Entry::find($this->greek->id()))->not->toBeNull();
    $this->getJson(cp_route('tolk.editor.status', ['reference' => $reference]))->assertOk()->assertJsonPath('reason', 'unknown_reference');
    editorPlan($this->greek)->assertNotFound()->assertJsonPath('code', 'unknown_reference')->assertJsonPath('message', 'The entry to translate was not found.');
    $this->postJson(cp_route('tolk.editor.review'), ['reference' => $reference, 'values' => []])->assertNotFound()->assertJsonPath('code', 'unknown_reference');
    editorUnit($plan['plan'], $plan['units'][0]['id'])->assertStatus(410)->assertJsonPath('code', 'target_missing');
    $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $reference, 'delivered' => ['title']])->assertOk()->assertJsonPath('reason', 'unknown_reference')->assertJsonPath('not_saved', []);
    $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => $reference])->assertOk()->assertJsonPath('reason', 'unknown_reference')->assertJsonPath('baselined', 0);
    $this->postJson(cp_route('tolk.editor.push'), ['reference' => $reference, 'sites' => ['de'], 'mode' => 'missing'])->assertNotFound()->assertJsonPath('code', 'unknown_reference');

    expect(StateFixtures::stored($this->greek)->toArray())->toBe($stored);
    $this->assertNoModelCalls();
});
