<?php

use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Facades\Term;

beforeEach(function () {
    $this->useFileCache();
    config(['tolk.runs.execution' => 'browser']);

    $this->makeTaxonomy('topics', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'description' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Description'],
        'internal' => ['type' => 'textarea', 'display' => 'Internal'],
    ]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food', 'description' => 'Eat well.']]);
});

const FOOD_EL = 'term::topics::food::el';

const FOOD_EN = 'term::topics::food::en';

function termStatus(string $reference): TestResponse
{
    return test()->getJson(cp_route('tolk.editor.status', ['reference' => $reference]));
}

/**
 * Plans the Greek term and runs every unit, and returns the delivered fields by handle.
 */
function translateTermInEditor(array $body = []): array
{
    $plan = test()->postJson(cp_route('tolk.editor.plan'), ['reference' => FOOD_EL, 'mode' => 'missing', ...$body])->assertOk()->json();
    $fields = $plan['ready'];

    foreach (array_column($plan['units'], 'id') as $unit) {
        $fields = [...$fields, ...test()->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan['plan'], 'unit' => $unit])->assertOk()->json('fields')];
    }

    return $fields;
}

/**
 * Saves the Greek term through core's update endpoint, as its publish form does: every value of the form, and the
 * fields the localization holds as `_localized`.
 */
function saveGreekTermInCp(array $values, array $localized): void
{
    test()->patchJson(cp_route('taxonomies.terms.update', ['topics', 'food', 'el']), [
        'title' => 'Food',
        'description' => 'Eat well.',
        'slug' => 'food',
        ...$values,
        '_localized' => $localized,
        'published' => true,
    ])->assertOk();
}

function termEditor(array $sites = ['en', 'el', 'de']): void
{
    test()->signInWithPermissions(['access cp', 'translate content', ...array_map(fn (string $site) => "access {$site} site", $sites), 'view topics terms', 'edit topics terms']);
}

function greekTermState(string $handle)
{
    return app(StateRepository::class)->get(new TargetRef(ContentType::Term, 'topics::food', 'el'))->field($handle);
}

describe('status', function () {
    it('returns the localization block for a term in another site than the default site', function () {
        termEditor();

        $response = termStatus(FOOD_EL)->assertOk();

        expect($response->json('translatable'))->toBeTrue()
            ->and($response->json('origin'))->toBeNull()
            ->and($response->json('active_run'))->toBeNull()
            ->and($response->json('localization'))->toMatchArray([
                'source' => ['site' => 'en', 'name' => 'English'],
                'target' => ['site' => 'el', 'name' => 'Greek'],
                'status' => 'untranslated',
                'origin_slug' => 'food',
                'translate_slug' => false,
            ])
            ->and(array_keys($response->json('localization.fields')))->toBe(['title', 'description'])
            ->and($response->json('localization.out_of_scope'))->toBe([
                ['handle' => 'internal', 'label' => 'Internal', 'type' => 'textarea', 'reason' => 'not_localizable'],
            ]);
    });

    it('returns the origin block for the term in the default site, with every other site of the taxonomy', function () {
        termEditor();

        $response = termStatus(FOOD_EN)->assertOk();
        $greek = Term::find('topics::food')->in('el');

        expect($response->json('localization'))->toBeNull()
            ->and($response->json('origin.localizations'))->toBe([
                ['site' => 'el', 'name' => 'Greek', 'exists' => true, 'status' => 'untranslated', 'counts' => $response->json('origin.localizations.0.counts'), 'edit_url' => $greek->editUrl(), 'error' => null],
                ['site' => 'de', 'name' => 'German', 'exists' => true, 'status' => 'untranslated', 'counts' => $response->json('origin.localizations.1.counts'), 'edit_url' => Term::find('topics::food')->in('de')->editUrl(), 'error' => null],
            ])
            ->and($response->json('origin.localizations.0.counts.untranslated'))->toBe(2);
    });

    it('lists only the sites the user may access in the origin block', function () {
        termEditor(['en', 'el']);

        expect(array_column(termStatus(FOOD_EN)->assertOk()->json('origin.localizations'), 'site'))->toBe(['el']);
    });

    it('answers single_site for a taxonomy with one site, and unknown_reference for a term or site it does not have', function () {
        $this->makeTaxonomy('labels', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('labels', 'new', ['en' => ['title' => 'New']]);
        $this->signInAdmin();

        expect(termStatus('term::labels::new::en')->assertOk()->json('reason'))->toBe('single_site')
            ->and(termStatus('term::topics::drink::el')->assertOk()->json('reason'))->toBe('unknown_reference')
            ->and(termStatus('term::labels::new::el')->assertOk()->json('reason'))->toBe('unknown_reference');
    });

    it('answers excluded for a taxonomy the settings exclude', function () {
        $this->signInAdmin();
        $this->withSettings(['excluded_taxonomies' => ['topics']]);

        expect(termStatus(FOOD_EL)->assertOk()->json('reason'))->toBe('excluded')
            ->and(termStatus(FOOD_EN)->assertOk()->json('reason'))->toBe('excluded');
        test()->postJson(cp_route('tolk.editor.plan'), ['reference' => FOOD_EL, 'mode' => 'missing'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_translatable')
            ->assertJsonPath('message', 'Terms of this taxonomy are excluded from translation in the settings.');
    });

    it('forbids a user without access to the site or without the permission to edit the terms', function () {
        termEditor(['en', 'de']);

        termStatus(FOOD_EL)->assertForbidden();

        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'view topics terms']);

        termStatus(FOOD_EL)->assertForbidden();
        termStatus(FOOD_EN)->assertForbidden();
    });
});

describe('translating in the term form', function () {
    it('delivers the fields unsaved, and confirm promotes them once the form saved them', function () {
        $this->fakeTranslations();
        termEditor();

        $fields = translateTermInEditor();

        expect(array_map(fn (array $field) => $field['value'], $fields))->toBe(['title' => '[T] Food', 'description' => '[T] Eat well.'])
            ->and(Term::find('topics::food')->in('el')->data()->all())->toBe([])
            ->and(greekTermState('description')->pending)->toHaveCount(1);

        saveGreekTermInCp(['title' => '[T] Food', 'description' => '[T] Eat well.'], ['title', 'description']);

        $response = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => FOOD_EL, 'delivered' => ['title', 'description']])->assertOk();

        expect($response->json('not_saved'))->toBe([])
            ->and($response->json('localization.status'))->toBe('current')
            ->and(greekTermState('description')->pending)->toBe([])
            ->and(greekTermState('description')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Eat well.']))
            ->and(Term::find('topics::food')->in('el')->get('description'))->toBe('[T] Eat well.')
            ->and(Term::find('topics::food')->in('en')->get('description'))->toBe('Eat well.');
    });

    it('lists a delivered field that the save did not store', function () {
        $this->fakeTranslations();
        termEditor();
        translateTermInEditor();

        saveGreekTermInCp(['title' => '[T] Food'], ['title']);

        $response = $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => FOOD_EL, 'delivered' => ['title', 'description']])->assertOk();

        expect($response->json('not_saved'))->toBe(['description'])
            ->and($response->json('localization.fields.title.status'))->toBe('current')
            ->and($response->json('localization.fields.description.status'))->toBe('untranslated');
    });

    it('protects a field with a baseline', function () {
        termEditor();
        Term::find('topics::food')->in('el')->merge(['description' => 'Τρώτε καλά.'])->save();

        $response = $this->postJson(cp_route('tolk.editor.baseline'), ['reference' => FOOD_EL, 'fields' => ['description']])->assertOk();

        expect($response->json('baselined'))->toBe(1)
            ->and(greekTermState('description')->isBaseline())->toBeTrue()
            ->and($response->json('localization.fields.description.source_changed'))->toBeFalse();
    });

    it('refuses to plan the default site\'s term, and an unknown term', function () {
        $this->signInAdmin();

        $this->postJson(cp_route('tolk.editor.plan'), ['reference' => FOOD_EN, 'mode' => 'missing'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_translatable');
        $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'term::topics::drink::el', 'mode' => 'missing'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unknown_reference')
            ->assertJsonPath('message', 'The term to translate was not found.');
    });

    it('forbids planning for a user without access to the target site', function () {
        termEditor(['en', 'de']);

        $this->postJson(cp_route('tolk.editor.plan'), ['reference' => FOOD_EL, 'mode' => 'missing'])->assertForbidden();
    });

    it('answers 410 with the term\'s wording when the term was deleted after planning', function () {
        $this->fakeTranslations();
        termEditor();
        $plan = $this->postJson(cp_route('tolk.editor.plan'), ['reference' => FOOD_EL, 'mode' => 'missing'])->assertOk()->json('plan');

        Term::find('topics::food')->term()->delete();

        $this->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan, 'unit' => 'b1'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'target_missing')
            ->assertJsonPath('message', 'The term this translation was made for no longer exists.');
        expect(app(PlanStore::class)->get($plan))->not->toBeNull();
    });

    it('answers 410 with the term\'s wording when another process deleted the term while the unit ran', function () {
        termEditor();
        $plan = $this->postJson(cp_route('tolk.editor.plan'), ['reference' => FOOD_EL, 'mode' => 'missing'])->assertOk()->json('plan');
        $deleted = false;
        $this->fakeTranslations(function (string $text) use (&$deleted) {
            if (! $deleted) {
                $deleted = true;
                $this->deleteTermElsewhere('topics::food');
            }

            return '[T] '.$text;
        });

        $this->postJson(cp_route('tolk.editor.unit'), ['plan' => $plan, 'unit' => 'b1'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'target_missing')
            ->assertJsonPath('message', 'The term this translation was made for no longer exists.');
        expect(greekTermState('title')->pending)->toBe([])
            ->and(greekTermState('description')->pending)->toBe([]);
    });
});

describe('push', function () {
    it('translates the default site\'s term into other sites as a run of term items', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();

        $response = $this->postJson(cp_route('tolk.editor.push'), ['reference' => FOOD_EN, 'sites' => ['el', 'de'], 'mode' => 'missing'])->assertCreated();
        $run = app(RunRepository::class)->find($response->json('run'));

        expect($run)->toBeInstanceOf(Run::class)
            ->and($run->status)->toBe(RunStatus::Running)
            ->and($run->createdBy['id'])->toBe((string) $admin->id())
            ->and($run->scope)->toMatchArray(['entry_ids' => [], 'term_ids' => ['topics::food'], 'reference' => FOOD_EN])
            ->and(array_map(fn ($item) => [$item->type->value, $item->sourceId, $item->targetSite], RunFixtures::items($run)))->toBe([
                ['term', 'topics::food', 'el'],
                ['term', 'topics::food', 'de'],
            ])
            ->and(termStatus(FOOD_EN)->json('active_run.run'))->toBe($run->id);

        for ($round = 0; $round < 5 && app(RunRepository::class)->find($run->id)->status === RunStatus::Running; $round++) {
            $this->postJson($response->json('urls.tick'), ['nonce' => 'bannerNonce1', 'lane' => 1])->assertOk();
            $this->postJson($response->json('urls.apply'))->assertOk();
        }

        expect(app(RunRepository::class)->find($run->id)->status)->toBe(RunStatus::Completed)
            ->and(Term::find('topics::food')->in('el')->get('title'))->toBe('[T] Food')
            ->and(Term::find('topics::food')->in('de')->get('description'))->toBe('[T] Eat well.')
            ->and(termStatus(FOOD_EN)->json('origin.localizations.0.status'))->toBe('current');
    });

    it('words its refusals for terms', function (Closure $request, int $status, string $code, string $message) {
        $this->signInAdmin();

        $request()->assertStatus($status)->assertJsonPath('code', $code)->assertJsonPath('message', $message);
        expect(app(RunRepository::class)->recent(10))->toBe([]);
    })->with([
        'a term in another site' => [fn () => test()->postJson(cp_route('tolk.editor.push'), ['reference' => FOOD_EL, 'sites' => ['de'], 'mode' => 'missing']), 422, 'not_an_origin', 'This term has no translations to update from here.'],
        'a site the taxonomy lacks' => [fn () => test()->postJson(cp_route('tolk.editor.push'), ['reference' => FOOD_EN, 'sites' => ['en'], 'mode' => 'missing']), 422, 'invalid_sites', 'This term cannot be translated into en from here.'],
        'an unknown term' => [fn () => test()->postJson(cp_route('tolk.editor.push'), ['reference' => 'term::topics::drink::en', 'sites' => ['el'], 'mode' => 'missing']), 404, 'unknown_reference', 'The term to translate was not found.'],
    ]);

    it('says that the taxonomy is excluded in the settings', function () {
        $this->withSettings(['excluded_taxonomies' => ['topics']]);
        $this->signInAdmin();

        $this->postJson(cp_route('tolk.editor.push'), ['reference' => FOOD_EN, 'sites' => ['el'], 'mode' => 'missing'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'nothing_to_translate')
            ->assertJsonPath('message', 'Terms of this taxonomy are excluded from translation in the settings.');
    });

    it('refuses a second push while the first runs', function () {
        $this->signInAdmin();
        $this->postJson(cp_route('tolk.editor.push'), ['reference' => FOOD_EN, 'sites' => ['el'], 'mode' => 'missing'])->assertCreated();

        $this->postJson(cp_route('tolk.editor.push'), ['reference' => FOOD_EN, 'sites' => ['de'], 'mode' => 'missing'])
            ->assertConflict()
            ->assertJsonPath('code', 'run_active')
            ->assertJsonPath('message', 'A translation of this term is already in progress.');
    });
});
