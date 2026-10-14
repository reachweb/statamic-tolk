<?php

use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Exceptions\UnreadableState;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Pruner;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Targets\TermTarget;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Events\TermSaved;
use Statamic\Events\TermSaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Term;

beforeEach(function () {
    $this->useFileCache();
    $this->fakeTranslations();

    $this->makeTaxonomy('topics', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'description' => ['type' => 'textarea', 'localizable' => true],
        'icon' => ['type' => 'text'],
    ]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food', 'description' => 'Eat well.', 'icon' => 'Fork']]);
});

function termTarget(string $site = 'el', string $id = 'topics::food'): TermTarget
{
    return app(TargetFactory::class)->forTerm($id, $site);
}

/**
 * @return array<string, FieldStatus>
 */
function termStatuses(string $site = 'el'): array
{
    return array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve(termTarget($site))->fields);
}

/**
 * Plans the term, runs every unit and assembles every planned field from a fresh target.
 *
 * @return list<AssembledField>
 */
function termAssembled(string $site = 'el', array $options = []): array
{
    $target = termTarget($site);
    $plan = app(Planner::class)->plan($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $options));
    app(PlanStore::class)->put($plan);
    $pipeline = app(Pipeline::class);

    while (($runnable = $pipeline->runnable($plan)) !== []) {
        $pipeline->runUnit($plan, array_key_first($runnable));
    }

    $fresh = $target->fresh();

    return array_map(fn (string $handle) => $pipeline->assemble($plan, $fresh, $handle), $plan->fieldHandles());
}

function applyTerm(array $fields, ApplyOptions $options = new ApplyOptions, string $site = 'el'): ApplyResult
{
    return ContentWriter::write(fn () => termTarget($site)->apply($fields, $options));
}

function translateTerm(string $site = 'el', ApplyOptions $options = new ApplyOptions, array $plan = []): ApplyResult
{
    return applyTerm(termAssembled($site, $plan), $options, $site);
}

describe('reading', function () {
    it('translates from the taxonomy\'s default site into a site that always exists', function () {
        $target = termTarget();

        expect($target->ref())->toEqual(new TargetRef(ContentType::Term, 'topics::food', 'el'))
            ->and((string) $target->ref())->toBe('term::topics::food::el')
            ->and($target->type())->toBe(ContentType::Term)
            ->and($target->exists())->toBeTrue()
            ->and($target->sourceSite())->toBe('en')
            ->and($target->targetSite())->toBe('el')
            ->and($target->container())->toBe('topics')
            ->and($target->title())->toBe('Food')
            ->and($target->sourceIsLocalization())->toBeFalse()
            ->and($target->editUrl())->toBe(Term::find('topics::food')->in('el')->editUrl())
            ->and($target->describe())->toBe(['type' => 'term', 'source_id' => 'topics::food', 'site' => 'el'])
            ->and($target->fields()->keys()->all())->toBe(['title', 'description'])
            ->and($target->document())->toMatchArray(['type' => 'term', 'title' => 'Food', 'container' => 'Topics'])
            ->and($target->document()['summary'])->toContain('Eat well.');
    });

    it('takes the first site of the taxonomy as the source, which need not be the default site', function () {
        $this->makeTaxonomy('tags', ['el', 'en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('tags', 'sea', ['el' => ['title' => 'Θάλασσα']]);

        $target = termTarget('en', 'tags::sea');

        expect($target->sourceSite())->toBe('el')
            ->and($target->sourceValue('title'))->toBe('Θάλασσα')
            ->and((string) $target->ref())->toBe('term::tags::sea::en');
    });

    it('reads the target\'s own data, explicit nulls included, and the source\'s', function () {
        Term::find('topics::food')->in('el')->merge(['title' => 'Φαγητό', 'description' => null])->save();

        $target = termTarget();

        expect($target->hasOverride('title'))->toBeTrue()
            ->and($target->targetValue('title'))->toBe('Φαγητό')
            ->and($target->hasOverride('description'))->toBeTrue()
            ->and($target->targetValue('description'))->toBeNull()
            ->and($target->hasOverride('icon'))->toBeFalse()
            ->and($target->sourceHasValue('description'))->toBeTrue()
            ->and($target->sourceValue('description'))->toBe('Eat well.')
            ->and($target->inheritedValue('description'))->toBe('Eat well.')
            ->and(termTarget('de')->hasOverride('title'))->toBeFalse();
    });

    it('shows a field the source holds empty with its default, as the term form does', function () {
        Term::find('topics::food')->in('en')->merge(['description' => ''])->save();

        expect(termTarget()->sourceHasValue('description'))->toBeTrue()
            ->and(termTarget()->inheritedValue('description'))->toBeNull();
    });

    it('builds the form fields with the target or the source as parent', function () {
        $target = termTarget();

        expect($target->formFields(['title' => 'Φαγητό'])->get('title')->value())->toBe('Φαγητό')
            ->and($target->formFields(['title' => 'Food'])->get('title')->parent()->locale())->toBe('el')
            ->and($target->formFields(['title' => 'Food'], source: true)->get('title')->parent()->locale())->toBe('en');
    });
});

describe('the factory', function () {
    it('accepts the term in any site, or its id', function () {
        $fromOtherSite = app(TargetFactory::class)->forTerm(Term::find('topics::food')->in('de'), 'el');

        expect($fromOtherSite->describe())->toBe(termTarget()->describe());
    });

    it('rejects the default site, an unknown site, a site the taxonomy lacks and an unknown term', function (Closure $build, string $exception) {
        expect($build)->toThrow($exception);
    })->with([
        'default site' => [fn () => termTarget('en'), InvalidTarget::class],
        'unknown site' => [fn () => termTarget('fr'), UnknownSite::class],
        'unknown term' => [fn () => termTarget('el', 'topics::drink'), TargetNotFound::class],
    ]);

    it('rejects a site the taxonomy is not available in', function () {
        $this->makeTaxonomy('tags', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('tags', 'sea', ['en' => ['title' => 'Sea']]);

        termTarget('de', 'tags::sea');
    })->throws(InvalidTarget::class);

    it('round-trips a reference', function () {
        $target = app(TargetFactory::class)->fromReference('term::topics::food::el');

        expect($target)->toBeInstanceOf(TermTarget::class)
            ->and((string) $target->ref())->toBe('term::topics::food::el')
            ->and((string) app(TargetFactory::class)->fromReference($target->ref())->ref())->toBe('term::topics::food::el')
            ->and(TargetRef::parse((string) $target->ref()))->toEqual($target->ref())
            ->and(app(TargetFactory::class)->fromDescription($target->describe())->describe())->toBe($target->describe());
    });

    it('takes the default site\'s reference for no localization', function () {
        app(TargetFactory::class)->fromReference('term::topics::food::en');
    })->throws(NotALocalization::class);

    it('finds no term in a site the taxonomy lacks, or with an unknown slug', function (string $reference) {
        expect(fn () => app(TargetFactory::class)->fromReference($reference))->toThrow(TargetNotFound::class);
    })->with(['term::topics::food::fr', 'term::topics::drink::el']);
});

describe('applying', function () {
    it('reads untranslated, then current after a translation stored under the site, the default site untouched', function () {
        expect(app(StatusResolver::class)->resolve(termTarget())->itemStatus)->toBe(ItemStatus::Untranslated)
            ->and(termStatuses())->toBe(['title' => FieldStatus::Untranslated, 'description' => FieldStatus::Untranslated]);

        $result = translateTerm();
        $term = Term::find('topics::food');

        expect($result->ref)->toEqual(new TargetRef(ContentType::Term, 'topics::food', 'el'))
            ->and($result->created)->toBeFalse()
            ->and($result->slug)->toBeNull()
            ->and($result->applied)->toBe(['title', 'description'])
            ->and($result->skipped)->toBe([])
            ->and($term->in('el')->data()->all())->toBe(['title' => '[T] Food', 'description' => '[T] Eat well.'])
            ->and($term->in('en')->data()->all())->toBe(['title' => 'Food', 'description' => 'Eat well.', 'icon' => 'Fork'])
            ->and($term->in('de')->data()->all())->toBe([])
            ->and($term->in('el')->slug())->toBe('food')
            ->and(termStatuses())->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Current])
            ->and(app(StatusResolver::class)->resolve(termTarget())->itemStatus)->toBe(ItemStatus::Current);

        $state = app(StateRepository::class)->get($result->ref);

        expect($state->hasPending())->toBeFalse()
            ->and($state->sourceSite)->toBe('en')
            ->and($state->field('description')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Eat well.']))
            ->and($state->field('description')->sourceHash)->toBe(Fingerprint::of('Eat well.'));
    });

    it('stores the user and the time with a user, and leaves them out without one', function () {
        translateTerm();

        expect(Term::find('topics::food')->in('el')->data()->keys()->all())->toBe(['title', 'description']);

        $user = $this->signInAdmin();
        Term::find('topics::food')->in('en')->merge(['description' => 'Eat very well.'])->save();
        translateTerm(options: new ApplyOptions(user: $user), plan: ['mode' => Mode::Update]);

        $localized = Term::find('topics::food')->in('el');

        expect($localized->get('updated_by'))->toBe($user->id())
            ->and($localized->get('updated_at'))->toBeInt()
            ->and($localized->get('description'))->toBe('[T] Eat very well.');
    });

    it('updates an outdated field and leaves the current one alone', function () {
        translateTerm();
        Term::find('topics::food')->in('en')->merge(['description' => 'Eat very well.'])->save();

        expect(termStatuses())->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Outdated]);

        $result = translateTerm(plan: ['mode' => Mode::Update]);

        expect($result->applied)->toBe(['description'])
            ->and(termStatuses())->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Current]);
    });

    it('skips a field edited between plan and apply, unless manual edits are overwritten', function () {
        $fields = termAssembled();
        Term::find('topics::food')->in('el')->merge(['title' => 'Χειροποίητο'])->save();

        $result = applyTerm($fields);

        expect($result->skipped)->toBe(['title' => 'target_changed'])
            ->and($result->applied)->toBe(['description'])
            ->and(Term::find('topics::food')->in('el')->get('title'))->toBe('Χειροποίητο')
            ->and(termStatuses()['title'])->toBe(FieldStatus::Manual);

        applyTerm(termAssembled(options: ['mode' => Mode::All, 'overwriteManual' => true]), new ApplyOptions(overwriteManual: true));

        expect(Term::find('topics::food')->in('el')->get('title'))->toBe('[T] Food')
            ->and(termStatuses()['title'])->toBe(FieldStatus::Current);
    });

    it('removes the override of a field whose source was emptied, keeps the rest of the site\'s data, and keeps only the history of its state', function () {
        $user = $this->signInAdmin();
        translateTerm(options: new ApplyOptions(user: $user));
        $term = Term::find('topics::food');
        $term->in('en')->data($term->in('en')->data()->except('description'));
        $term->save();

        expect(termStatuses()['description'])->toBe(FieldStatus::Outdated);

        $fields = termAssembled(options: ['mode' => Mode::Update]);

        expect(array_map(fn (AssembledField $field) => [$field->handle, $field->remove], $fields))->toBe([['description', true]]);

        $translated = app(StateRepository::class)->get(termTarget()->ref())->field('description');
        $result = applyTerm($fields);
        $localized = Term::find('topics::food')->in('el');

        expect($result->applied)->toBe(['description'])
            ->and($localized->data()->keys()->all())->toEqualCanonicalizing(['title', 'updated_by', 'updated_at'])
            ->and(app(StateRepository::class)->get($result->ref)->field('description')->toArray())->toBe(['history' => [
                ['source_hash' => $translated->sourceHash, 'target_hash' => $translated->targetHash],
            ]])
            ->and(termStatuses())->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::NotApplicable]);
    });

    it('throws outside ContentWriter::write() and saves nothing', function () {
        $fields = termAssembled();

        expect(fn () => termTarget()->apply($fields, new ApplyOptions))->toThrow(LogicException::class)
            ->and(Term::find('topics::food')->in('el')->data()->all())->toBe([]);
    });

    it('throws for a field that is no longer in scope, before anything is saved', function () {
        $fields = termAssembled();
        Blueprint::find('taxonomies.topics.topic')->ensureFieldHasConfig('description', ['type' => 'textarea', 'localizable' => false])->save();

        expect(fn () => applyTerm($fields))->toThrow(SourceChanged::class)
            ->and(Term::find('topics::food')->in('el')->data()->all())->toBe([]);
    });

    it('throws for a field whose source changed after it was assembled, before anything is written', function () {
        $fields = termAssembled();
        Term::find('topics::food')->in('en')->merge(['description' => 'Eat very well.'])->save();

        expect(fn () => applyTerm($fields))->toThrow(SourceChanged::class, 'The source of the field [description] changed')
            ->and(Term::find('topics::food')->in('el')->data()->all())->toBe([])
            ->and(app(StateRepository::class)->get(termTarget()->ref())->isEmpty())->toBeTrue();
    });

    it('throws ApplyFailed when a listener cancels the save', function () {
        $fields = termAssembled();
        Event::listen(TermSaving::class, fn () => false);

        expect(fn () => applyTerm($fields))->toThrow(ApplyFailed::class, 'term::topics::food::el');
    });
});

describe('a renamed default slug', function () {
    it('keeps the translations tracked under the new slug, so a later edit of the origin still makes them outdated', function () {
        translateTerm();
        translateTerm('de');
        $this->signInAdmin();
        $statuses = fn (string $site) => array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve(termTarget($site, 'topics::cuisine'))->fields);

        $this->patchJson(cp_route('taxonomies.terms.update', ['topics', 'food', 'en']), [
            'title' => 'Food',
            'description' => 'Eat well.',
            'icon' => 'Fork',
            'slug' => 'cuisine',
            'published' => true,
        ])->assertOk();

        expect(Term::find('topics::food'))->toBeNull()
            ->and($statuses('el'))->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Current])
            ->and($statuses('de'))->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Current])
            ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Term, 'topics::food', 'el'))->isEmpty())->toBeTrue()
            ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Term, 'topics::cuisine', 'el'))->sourceSite)->toBe('en');

        Term::find('topics::cuisine')->in('en')->merge(['description' => 'Eat very well.'])->save();

        $update = app(Planner::class)->plan(termTarget('el', 'topics::cuisine'), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['mode' => Mode::Update]));

        expect($statuses('el'))->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Outdated])
            ->and($update->fieldHandles())->toBe(['description'])
            ->and(app(Pruner::class)->prune(dryRun: true)->orphans)->toBe([]);
    });

    it('saves the rename and moves the state of the other sites when the state file of one does not parse, and the listeners after it still run', function () {
        translateTerm();
        translateTerm('de');
        $this->signInAdmin();
        $food = new TargetRef(ContentType::Term, 'topics::food', 'el');
        $path = StateFixtures::conflict($food);
        $later = [];
        Event::listen(TermSaved::class, function (TermSaved $event) use (&$later) {
            $later[] = $event->term->slug();
        });

        $this->patchJson(cp_route('taxonomies.terms.update', ['topics', 'food', 'en']), [
            'title' => 'Food',
            'description' => 'Eat well.',
            'icon' => 'Fork',
            'slug' => 'cuisine',
            'published' => true,
        ])->assertOk();

        $statuses = array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve(termTarget('de', 'topics::cuisine'))->fields);

        expect(Term::find('topics::cuisine'))->not->toBeNull()
            ->and($later)->toContain('cuisine')
            ->and($statuses)->toBe(['title' => FieldStatus::Current, 'description' => FieldStatus::Current])
            ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Term, 'topics::food', 'de'))->isEmpty())->toBeTrue()
            ->and(fn () => app(StateRepository::class)->get($food))->toThrow(UnreadableState::class, $path);
    });
});

it('fails authorize() for a user without access to the target site', function () {
    $user = $this->signInWithPermissions(['access en site', 'access de site', 'view topics terms', 'edit topics terms']);

    expect(termTarget()->authorize($user))->toBeFalse()
        ->and(termTarget('de')->authorize($user))->toBeTrue();
});

it('fails authorize() for a user who may not edit the taxonomy\'s terms', function () {
    $user = $this->signInWithPermissions(['access en site', 'access el site', 'view topics terms']);

    expect(termTarget()->authorize($user))->toBeFalse();
});
