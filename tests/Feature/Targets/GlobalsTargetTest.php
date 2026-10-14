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
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\GlobalsTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;

beforeEach(function () {
    $this->useFileCache();
    $this->fakeTranslations();

    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => 'en'], [
        'tagline' => ['type' => 'text', 'localizable' => true],
        'footer' => ['type' => 'textarea', 'localizable' => true],
        'links' => ['type' => 'grid', 'localizable' => true, 'fields' => [
            ['handle' => 'label', 'field' => ['type' => 'text']],
        ]],
        'phone' => ['type' => 'text', 'display' => 'Phone'],
    ], [
        'en' => ['tagline' => 'By the sea', 'footer' => 'Open all year.', 'links' => [['label' => 'Contact us']], 'phone' => '+30 1234'],
    ]);
});

function globalsTarget(string $site = 'el', string $handle = 'site_settings'): GlobalsTarget
{
    return app(TargetFactory::class)->forGlobalSet($handle, $site);
}

/**
 * @return array<string, FieldStatus>
 */
function globalsStatuses(string $site = 'el'): array
{
    return array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve(globalsTarget($site))->fields);
}

function globalsPlanOptions(array $options = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $options);
}

/**
 * Plans the variables, runs every unit and assembles every planned field from a fresh target.
 *
 * @return list<AssembledField>
 */
function globalsAssembled(string $site = 'el', array $options = []): array
{
    $target = globalsTarget($site);
    $plan = app(Planner::class)->plan($target, globalsPlanOptions($options));
    app(PlanStore::class)->put($plan);
    $pipeline = app(Pipeline::class);

    while (($runnable = $pipeline->runnable($plan)) !== []) {
        $pipeline->runUnit($plan, array_key_first($runnable));
    }

    $fresh = $target->fresh();

    return array_map(fn (string $handle) => $pipeline->assemble($plan, $fresh, $handle), $plan->fieldHandles());
}

function applyGlobals(array $fields, ApplyOptions $options = new ApplyOptions, string $site = 'el'): ApplyResult
{
    return ContentWriter::write(fn () => globalsTarget($site)->apply($fields, $options));
}

function translateGlobals(string $site = 'el', ApplyOptions $options = new ApplyOptions, array $plan = []): ApplyResult
{
    return applyGlobals(globalsAssembled($site, $plan), $options, $site);
}

describe('reading', function () {
    it('translates the variables of a site from the origin the set names for it', function () {
        $target = globalsTarget();

        expect($target->ref())->toEqual(new TargetRef(ContentType::GlobalSet, 'site_settings', 'el'))
            ->and((string) $target->ref())->toBe('globals::site_settings::el')
            ->and($target->type())->toBe(ContentType::GlobalSet)
            ->and($target->exists())->toBeTrue()
            ->and($target->untranslatableReason())->toBeNull()
            ->and($target->sourceSite())->toBe('en')
            ->and($target->targetSite())->toBe('el')
            ->and($target->container())->toBe('site_settings')
            ->and($target->title())->toBe('Site Settings')
            ->and($target->sourceIsLocalization())->toBeFalse()
            ->and($target->editUrl())->toBe(GlobalSet::findByHandle('site_settings')->in('el')->editUrl())
            ->and($target->describe())->toBe(['type' => 'globals', 'source_id' => 'site_settings', 'site' => 'el'])
            ->and($target->fields()->keys()->all())->toBe(['tagline', 'footer', 'links'])
            ->and(collect($target->explainFields())->firstWhere('handle', 'phone')['reason'])->toBe('not_localizable')
            ->and($target->document())->toMatchArray(['type' => 'globals', 'title' => 'Site Settings', 'container' => 'Site Settings', 'url' => null])
            ->and($target->document()['summary'])->toBe('Open all year.');
    });

    it('reads the target\'s own data, explicit nulls included, and the source\'s', function () {
        GlobalSet::findByHandle('site_settings')->in('el')->merge(['tagline' => 'Δίπλα στη θάλασσα', 'footer' => null])->save();

        $target = globalsTarget();

        expect($target->hasOverride('tagline'))->toBeTrue()
            ->and($target->targetValue('tagline'))->toBe('Δίπλα στη θάλασσα')
            ->and($target->hasOverride('footer'))->toBeTrue()
            ->and($target->targetValue('footer'))->toBeNull()
            ->and($target->hasOverride('links'))->toBeFalse()
            ->and($target->targetValue('links'))->toBeNull()
            ->and($target->sourceHasValue('footer'))->toBeTrue()
            ->and($target->sourceValue('footer'))->toBe('Open all year.')
            ->and($target->inheritedValue('footer'))->toBe('Open all year.')
            ->and(globalsTarget('de')->hasOverride('tagline'))->toBeFalse();
    });

    // The variables Stache cached when the set was made still hold de's old origin (en); the target reads the set's.
    it('follows a chain of origins and leaves a field the source still inherits for later', function () {
        $set = GlobalSet::findByHandle('site_settings');
        $set->sites(['en' => null, 'el' => 'en', 'de' => 'el'])->save();
        $set->in('el')->merge(['tagline' => 'Δίπλα στη θάλασσα'])->save();

        $target = globalsTarget('de');
        $plan = app(Planner::class)->plan($target, globalsPlanOptions());

        expect($target->sourceSite())->toBe('el')
            ->and($target->sourceIsLocalization())->toBeTrue()
            ->and($target->sourceHasValue('footer'))->toBeFalse()
            ->and($target->sourceValue('footer'))->toBeNull()
            ->and($target->inheritedValue('footer'))->toBe('Open all year.')
            ->and($target->inheritedValue('tagline'))->toBe('Δίπλα στη θάλασσα')
            ->and($plan->fieldHandles())->toBe(['tagline'])
            ->and($plan->skipped)->toBe(['footer' => 'origin_untranslated', 'links' => 'origin_untranslated']);
    });

    it('builds the form fields with the target or the source as parent', function () {
        $target = globalsTarget();

        expect($target->formFields(['tagline' => 'Δίπλα'])->get('tagline')->value())->toBe('Δίπλα')
            ->and($target->formFields([])->get('tagline')->parent()->locale())->toBe('el')
            ->and($target->formFields([], source: true)->get('tagline')->parent()->locale())->toBe('en');
    });
});

describe('not translatable', function () {
    it('reports a site without an origin and translates nothing there', function () {
        GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => 'en', 'de' => null])->save();

        $target = globalsTarget('de');
        $plan = app(Planner::class)->plan($target, globalsPlanOptions());

        expect($target->untranslatableReason())->toBe(GlobalsTarget::NO_ORIGIN)
            ->and(globalsTarget('en')->untranslatableReason())->toBe(GlobalsTarget::NO_ORIGIN)
            ->and($target->exists())->toBeTrue()
            ->and((string) $target->ref())->toBe('globals::site_settings::de')
            ->and($target->sourceSite())->toBe('de')
            ->and($target->fields()->all())->toBe([])
            ->and($target->explainFields())->toBe([])
            ->and($target->inheritedValue('tagline'))->toBeNull()
            ->and($plan->isEmpty())->toBeTrue()
            ->and(app(StatusResolver::class)->resolve($target)->fields)->toBe([]);
        $this->assertNoModelCalls();
    });

    it('reports a site the set does not have, whose variables do not exist', function () {
        GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => 'en'])->save();
        $user = $this->signInAdmin();

        $target = globalsTarget('de');

        expect($target->untranslatableReason())->toBe(GlobalsTarget::SITE_NOT_IN_SET)
            ->and($target->exists())->toBeFalse()
            ->and($target->ref())->toBeNull()
            ->and($target->editUrl())->toBeNull()
            ->and($target->fields()->all())->toBe([])
            ->and($target->hasOverride('tagline'))->toBeFalse()
            ->and($target->authorize($user))->toBeFalse()
            ->and(app(StatusResolver::class)->resolve($target)->itemStatus)->toBe(ItemStatus::Missing);
    });

    it('reports a set without a blueprint, whose inferred fields are not localizable', function () {
        $this->makeGlobalSet('contact', ['en' => null, 'el' => 'en'], null, ['en' => ['address' => 'Chania harbour']]);

        $target = globalsTarget('el', 'contact');

        expect($target->untranslatableReason())->toBe(GlobalsTarget::NO_BLUEPRINT)
            ->and(GlobalSet::findByHandle('contact')->in('el')->blueprint()->field('address')->isLocalizable())->toBeFalse()
            ->and($target->fields()->all())->toBe([])
            ->and($target->explainFields())->toBe([])
            ->and(app(Planner::class)->plan($target, globalsPlanOptions())->isEmpty())->toBeTrue();
    });
});

describe('the factory', function () {
    it('accepts the set or its handle', function () {
        expect(app(TargetFactory::class)->forGlobalSet(GlobalSet::findByHandle('site_settings'), 'el')->describe())->toBe(globalsTarget()->describe());
    });

    it('rejects an unknown site and an unknown set', function (Closure $build, string $exception) {
        expect($build)->toThrow($exception);
    })->with([
        'unknown site' => [fn () => globalsTarget('fr'), UnknownSite::class],
        'unknown set' => [fn () => globalsTarget('el', 'footer'), TargetNotFound::class],
    ]);

    it('round-trips a reference', function () {
        $target = app(TargetFactory::class)->fromReference('globals::site_settings::el');

        expect($target)->toBeInstanceOf(GlobalsTarget::class)
            ->and((string) $target->ref())->toBe('globals::site_settings::el')
            ->and((string) $target->ref())->toBe(GlobalSet::findByHandle('site_settings')->in('el')->reference())
            ->and((string) app(TargetFactory::class)->fromReference($target->ref())->ref())->toBe('globals::site_settings::el')
            ->and(TargetRef::parse((string) $target->ref()))->toEqual($target->ref())
            ->and(app(TargetFactory::class)->fromDescription($target->describe())->describe())->toBe($target->describe());
    });

    it('takes the reference of a site without an origin for no localization', function () {
        app(TargetFactory::class)->fromReference('globals::site_settings::en');
    })->throws(NotALocalization::class);

    it('finds no variables in a site the set does not have, or of an unknown set', function (string $reference) {
        GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => 'en'])->save();

        expect(fn () => app(TargetFactory::class)->fromReference($reference))->toThrow(TargetNotFound::class);
    })->with(['globals::site_settings::de', 'globals::footer::el']);
});

describe('applying', function () {
    it('reads untranslated while it inherits, then current after a translation stored under the site, the origin untouched', function () {
        expect(app(StatusResolver::class)->resolve(globalsTarget())->itemStatus)->toBe(ItemStatus::Untranslated)
            ->and(globalsStatuses())->toBe(['tagline' => FieldStatus::Untranslated, 'footer' => FieldStatus::Untranslated, 'links' => FieldStatus::Untranslated]);

        $result = translateGlobals();
        $set = GlobalSet::findByHandle('site_settings');
        $greek = $set->in('el')->data()->all();

        expect($result->ref)->toEqual(new TargetRef(ContentType::GlobalSet, 'site_settings', 'el'))
            ->and($result->created)->toBeFalse()
            ->and($result->slug)->toBeNull()
            ->and($result->applied)->toBe(['tagline', 'footer', 'links'])
            ->and($result->skipped)->toBe([])
            ->and(array_keys($greek))->toBe(['tagline', 'footer', 'links'])
            ->and($greek['tagline'])->toBe('[T] By the sea')
            ->and($greek['footer'])->toBe('[T] Open all year.')
            ->and($greek['links'])->toHaveCount(1)
            ->and($greek['links'][0])->toMatchArray(['label' => '[T] Contact us'])
            ->and($greek['links'][0]['id'])->toBeString()
            ->and($set->in('en')->data()->all())->toBe(['tagline' => 'By the sea', 'footer' => 'Open all year.', 'links' => [['label' => 'Contact us']], 'phone' => '+30 1234'])
            ->and($set->in('de')->data()->all())->toBe([])
            ->and(globalsStatuses())->toBe(['tagline' => FieldStatus::Current, 'footer' => FieldStatus::Current, 'links' => FieldStatus::Current])
            ->and(app(StatusResolver::class)->resolve(globalsTarget())->itemStatus)->toBe(ItemStatus::Current);

        $state = app(StateRepository::class)->get($result->ref);

        expect($state->hasPending())->toBeFalse()
            ->and($state->sourceSite)->toBe('en')
            ->and(app(StateRepository::class)->path($result->ref))->toEndWith('globals/site_settings/el.yaml')
            ->and($state->field('footer')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Open all year.']))
            ->and($state->field('footer')->sourceHash)->toBe(Fingerprint::of('Open all year.'));
    });

    it('updates an outdated field and leaves the current ones alone', function () {
        translateGlobals();
        GlobalSet::findByHandle('site_settings')->in('en')->merge(['footer' => 'Open from April to October.'])->save();

        expect(globalsStatuses())->toBe(['tagline' => FieldStatus::Current, 'footer' => FieldStatus::Outdated, 'links' => FieldStatus::Current]);

        $result = translateGlobals(plan: ['mode' => Mode::Update]);

        expect($result->applied)->toBe(['footer'])
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->get('footer'))->toBe('[T] Open from April to October.')
            ->and(globalsStatuses())->toBe(['tagline' => FieldStatus::Current, 'footer' => FieldStatus::Current, 'links' => FieldStatus::Current]);
    });

    it('skips a field edited between plan and apply, unless manual edits are overwritten', function () {
        $fields = globalsAssembled();
        GlobalSet::findByHandle('site_settings')->in('el')->merge(['tagline' => 'Χειροποίητο'])->save();

        $result = applyGlobals($fields);

        expect($result->skipped)->toBe(['tagline' => 'target_changed'])
            ->and($result->applied)->toBe(['footer', 'links'])
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->get('tagline'))->toBe('Χειροποίητο')
            ->and(globalsStatuses()['tagline'])->toBe(FieldStatus::Manual);

        applyGlobals(globalsAssembled(options: ['mode' => Mode::All, 'overwriteManual' => true]), new ApplyOptions(overwriteManual: true));

        expect(GlobalSet::findByHandle('site_settings')->in('el')->get('tagline'))->toBe('[T] By the sea')
            ->and(globalsStatuses()['tagline'])->toBe(FieldStatus::Current);
    });

    it('removes the override of a field whose source was emptied, keeps the rest, and keeps only the history of its state', function () {
        translateGlobals();
        GlobalSet::findByHandle('site_settings')->in('en')->remove('footer')->save();

        expect(globalsStatuses()['footer'])->toBe(FieldStatus::Outdated);

        $fields = globalsAssembled(options: ['mode' => Mode::Update]);

        expect(array_map(fn (AssembledField $field) => [$field->handle, $field->remove], $fields))->toBe([['footer', true]]);

        $translated = app(StateRepository::class)->get(globalsTarget()->ref())->field('footer');
        $result = applyGlobals($fields);

        expect($result->applied)->toBe(['footer'])
            ->and(array_keys(GlobalSet::findByHandle('site_settings')->in('el')->data()->all()))->toBe(['tagline', 'links'])
            ->and(app(StateRepository::class)->get($result->ref)->field('footer')->toArray())->toBe(['history' => [
                ['source_hash' => $translated->sourceHash, 'target_hash' => $translated->targetHash],
            ]])
            ->and(globalsStatuses()['footer'])->toBe(FieldStatus::NotApplicable);
    });

    it('throws outside ContentWriter::write() and saves nothing', function () {
        $fields = globalsAssembled();

        expect(fn () => globalsTarget()->apply($fields, new ApplyOptions))->toThrow(LogicException::class)
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->data()->all())->toBe([]);
    });

    it('throws for a field that is no longer in scope, before anything is saved', function () {
        $fields = globalsAssembled();
        Blueprint::find('globals.site_settings')->ensureFieldHasConfig('footer', ['type' => 'textarea', 'localizable' => false])->save();

        expect(fn () => applyGlobals($fields))->toThrow(SourceChanged::class)
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->data()->all())->toBe([]);
    });

    it('throws for a field whose source changed after it was assembled, before anything is written', function () {
        $fields = globalsAssembled();
        GlobalSet::findByHandle('site_settings')->in('en')->merge(['footer' => 'Open from April to October.'])->save();

        expect(fn () => applyGlobals($fields))->toThrow(SourceChanged::class, 'The source of the field [footer] changed')
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->data()->all())->toBe([])
            ->and(app(StateRepository::class)->get(globalsTarget()->ref())->isEmpty())->toBeTrue();
    });

    it('throws ApplyFailed when a listener cancels the save', function () {
        $fields = globalsAssembled();
        Event::listen(GlobalVariablesSaving::class, fn () => false);

        expect(fn () => applyGlobals($fields))->toThrow(ApplyFailed::class, 'globals::site_settings::el');
    });
});

describe('authorize()', function () {
    it('passes a user who may edit the set in the target site', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit site_settings globals']);

        expect(globalsTarget()->authorize($user))->toBeTrue();
    });

    it('fails a user without access to the target site', function () {
        $user = $this->signInWithPermissions(['access en site', 'access de site', 'edit site_settings globals']);

        expect(globalsTarget()->authorize($user))->toBeFalse()
            ->and(globalsTarget('de')->authorize($user))->toBeTrue();
    });

    it('fails a user who may not edit the set\'s variables', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit footer globals']);

        expect(globalsTarget()->authorize($user))->toBeFalse();
    });
});
