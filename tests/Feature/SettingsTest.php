<?php

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Formality;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Support\Settings;
use Statamic\CP\PublishForm;
use Statamic\Facades\Addon;
use Statamic\Facades\YAML;

function tolkSettingsBlueprintFields(): array
{
    $blueprint = YAML::file(dirname(__DIR__, 2).'/resources/blueprints/settings.yaml')->parse();

    return collect($blueprint['tabs'])
        ->flatMap(fn (array $tab) => $tab['sections'])
        ->flatMap(fn (array $section) => $section['fields'])
        ->mapWithKeys(fn (array $field) => [$field['handle'] => $field['field']])
        ->all();
}

it('registers the settings blueprint with its tabs', function () {
    $addon = Addon::get(Settings::PACKAGE);

    expect($addon->hasSettingsBlueprint())->toBeTrue()
        ->and($addon->settingsBlueprint()->tabs()->keys()->all())
        ->toBe(['general', 'context', 'glossary', 'content', 'providers'])
        ->and($addon->settingsBlueprint()->fields()->all()->keys()->all())
        ->toBe([...array_keys(Settings::DEFAULTS), 'providers']);
});

it('has blueprint defaults equal to Settings::DEFAULTS key by key', function () {
    $fields = tolkSettingsBlueprintFields();

    expect(array_keys($fields))->toBe([...array_keys(Settings::DEFAULTS), 'providers']);

    foreach (Settings::DEFAULTS as $handle => $default) {
        expect($fields[$handle]['default'] ?? null)->toBe($default, "The default of [{$handle}] differs.");
    }
});

it('uses the addon\'s fieldtypes for the models, the site profile and the providers', function () {
    $fields = tolkSettingsBlueprintFields();

    expect(collect($fields)->map(fn (array $field) => [$field['type'], $field['role'] ?? null])->filter(fn (array $type) => str_starts_with($type[0], 'tolk_'))->all())
        ->toBe([
            'translator_model' => ['tolk_model', 'translator'],
            'reviewer_model' => ['tolk_model', 'reviewer'],
            'fallback_model' => ['tolk_model', 'fallback'],
            'site_profile' => ['tolk_site_profile', null],
            'providers' => ['tolk_providers', null],
        ]);
});

// The reviewer still matters while review is off, because a single translation can turn review on.
it('shows the reviewer only while review is on, and keeps it while hidden', function () {
    $reviewer = tolkSettingsBlueprintFields()['reviewer_model'];

    expect($reviewer['if'])->toBe(['review_enabled' => 'equals true'])
        ->and($reviewer['always_save'])->toBeTrue();
});

it('gives every field a label and instructions', function () {
    foreach (tolkSettingsBlueprintFields() as $handle => $field) {
        expect($field['display'] ?? '')->not->toBe('', "[{$handle}] has no label.")
            ->and($field['instructions'] ?? '')->not->toBe('', "[{$handle}] has no instructions.");
    }
});

it('uses sites fields with one item inside both grids', function () {
    $fields = tolkSettingsBlueprintFields();

    foreach (['language_instructions', 'glossary'] as $grid) {
        $site = collect($fields[$grid]['fields'])->firstWhere('handle', 'site')['field'];

        expect($fields[$grid]['type'])->toBe('grid')
            ->and($site['type'])->toBe('sites')
            ->and($site['max_items'])->toBe(1);
    }
});

it('returns the defaults when nothing is saved', function () {
    $settings = app(Settings::class);

    expect($settings->translatorModel())->toBeNull()
        ->and($settings->reviewEnabled())->toBeFalse()
        ->and($settings->reviewerModel())->toBeNull()
        ->and($settings->fallbackModel())->toBeNull()
        ->and($settings->newLocalizationStatus())->toBe('draft')
        ->and($settings->offerOnCreate())->toBeTrue()
        ->and($settings->strictFields())->toBeFalse()
        ->and($settings->siteProfile())->toBe('')
        ->and($settings->instructions())->toBe('')
        ->and($settings->languageInstructions('el'))->toBe(['formality' => Formality::Default, 'instructions' => ''])
        ->and($settings->glossary('el'))->toBe([])
        ->and($settings->excludedCollections())->toBe([])
        ->and($settings->excludedTaxonomies())->toBe([])
        ->and($settings->excludedFields())->toBe([]);
});

it('lets saved raw values win over the defaults', function () {
    $settings = $this->withSettings([
        'translator_model' => 'openai:gpt-6.1-sol',
        'review_enabled' => true,
        'reviewer_model' => 'anthropic:claude-sonnet-5-5',
        'fallback_model' => 'gemini:gemini-3.8-flash',
        'new_localization_status' => 'mirror',
        'offer_on_create' => false,
        'strict_fields' => true,
        'site_profile' => 'Kyma Bay is a small hotel in Chania.',
        'instructions' => 'Keep it warm.',
        'language_instructions' => [
            ['id' => 'a1', 'site' => 'el', 'formality' => 'informal', 'instructions' => 'Use the monotonic system.'],
        ],
        'glossary' => [
            ['id' => 'g1', 'term' => 'Kyma Bay', 'do_not_translate' => true],
        ],
        'excluded_collections' => ['notes'],
        'excluded_taxonomies' => ['tags'],
        'excluded_fields' => ['internal_note'],
    ]);

    expect($settings->translatorModel())->toEqual(new ModelRef('openai', 'gpt-6.1-sol'))
        ->and($settings->reviewEnabled())->toBeTrue()
        ->and($settings->reviewerModel())->toEqual(new ModelRef('anthropic', 'claude-sonnet-5-5'))
        ->and($settings->fallbackModel())->toEqual(new ModelRef('gemini', 'gemini-3.8-flash'))
        ->and($settings->newLocalizationStatus())->toBe('mirror')
        ->and($settings->offerOnCreate())->toBeFalse()
        ->and($settings->strictFields())->toBeTrue()
        ->and($settings->siteProfile())->toBe('Kyma Bay is a small hotel in Chania.')
        ->and($settings->instructions())->toBe('Keep it warm.')
        ->and($settings->languageInstructions('el'))->toBe(['formality' => Formality::Informal, 'instructions' => 'Use the monotonic system.'])
        ->and($settings->glossary('el'))->toBe([
            ['term' => 'Kyma Bay', 'site' => null, 'translation' => '', 'do_not_translate' => true, 'note' => ''],
        ])
        ->and($settings->excludedCollections())->toBe(['notes'])
        ->and($settings->excludedTaxonomies())->toBe(['tags'])
        ->and($settings->excludedFields())->toBe(['internal_note']);
});

it('keeps booleans as booleans, where core stringifies them', function () {
    $settings = $this->withSettings(['review_enabled' => true, 'offer_on_create' => false, 'strict_fields' => false]);

    $stored = YAML::file(resource_path('addons/statamic-tolk.yaml'))->parse();
    $addonSettings = Addon::get(Settings::PACKAGE)->settings();

    expect($stored)->toBe(['review_enabled' => true, 'offer_on_create' => false, 'strict_fields' => false])
        ->and($addonSettings->raw()['offer_on_create'])->toBeFalse()
        ->and($addonSettings->all()['offer_on_create'])->toBe('')
        ->and($addonSettings->all()['review_enabled'])->toBe('1')
        ->and($settings->reviewEnabled())->toBeTrue()
        ->and($settings->offerOnCreate())->toBeFalse()
        ->and($settings->strictFields())->toBeFalse();
});

it('memoizes the values until fresh() drops them', function () {
    $settings = $this->withSettings(['instructions' => 'First.']);

    expect($settings->instructions())->toBe('First.');

    Addon::get(Settings::PACKAGE)->settings()->set(['instructions' => 'Second.'])->save();

    expect($settings->instructions())->toBe('First.')
        ->and($settings->fresh()->instructions())->toBe('Second.');
});

it('is bound as a scoped singleton', function () {
    $settings = app(Settings::class);

    expect(app(Settings::class))->toBe($settings);

    app()->forgetScopedInstances();

    expect(app(Settings::class))->not->toBe($settings);
});

it('reads what the settings page saves', function () {
    $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text']]);
    $this->makeTaxonomy('tags', ['en', 'el']);

    $values = PublishForm::make(Addon::get(Settings::PACKAGE)->settingsBlueprint())->submit([
        'translator_model' => 'anthropic:claude-haiku-5-5',
        'review_enabled' => true,
        'reviewer_model' => null,
        'fallback_model' => null,
        'new_localization_status' => 'mirror',
        'offer_on_create' => false,
        'strict_fields' => true,
        'site_profile' => null,
        'instructions' => 'Keep it warm.',
        'language_instructions' => [
            ['_id' => 'row-1', 'site' => ['de'], 'formality' => 'formal', 'instructions' => 'Use Sie.'],
        ],
        'glossary' => [
            ['_id' => 'row-2', 'term' => 'Agora', 'site' => [], 'translation' => '', 'do_not_translate' => true, 'note' => null],
            ['_id' => 'row-3', 'term' => 'Breakfast', 'site' => ['el'], 'translation' => 'Πρωινό', 'do_not_translate' => false, 'note' => 'Menu heading'],
        ],
        'excluded_collections' => ['pages'],
        'excluded_taxonomies' => ['tags'],
        'excluded_fields' => ['internal_note'],
        'providers' => null,
    ]);

    $settings = $this->withSettings($values);

    $stored = YAML::file(resource_path('addons/statamic-tolk.yaml'))->parse();

    expect($stored['review_enabled'])->toBeTrue()
        ->and($stored['offer_on_create'])->toBeFalse()
        ->and($stored['language_instructions'][0]['site'])->toBe('de')
        ->and($stored['glossary'][0])->not->toHaveKey('site')
        ->and($settings->translatorModel())->toEqual(new ModelRef('anthropic', 'claude-haiku-5-5'))
        ->and($settings->reviewEnabled())->toBeTrue()
        ->and($settings->reviewerModel())->toBeNull()
        ->and($settings->newLocalizationStatus())->toBe('mirror')
        ->and($settings->offerOnCreate())->toBeFalse()
        ->and($settings->strictFields())->toBeTrue()
        ->and($settings->siteProfile())->toBe('')
        ->and($settings->languageInstructions('de'))->toBe(['formality' => Formality::Formal, 'instructions' => 'Use Sie.'])
        ->and($settings->languageInstructions('el'))->toBe(['formality' => Formality::Default, 'instructions' => ''])
        ->and($settings->glossary('el'))->toBe([
            ['term' => 'Agora', 'site' => null, 'translation' => '', 'do_not_translate' => true, 'note' => ''],
            ['term' => 'Breakfast', 'site' => 'el', 'translation' => 'Πρωινό', 'do_not_translate' => false, 'note' => 'Menu heading'],
        ])
        ->and($settings->glossary('de'))->toBe([
            ['term' => 'Agora', 'site' => null, 'translation' => '', 'do_not_translate' => true, 'note' => ''],
        ])
        ->and($settings->excludedCollections())->toBe(['pages'])
        ->and($settings->excludedTaxonomies())->toBe(['tags'])
        ->and($settings->excludedFields())->toBe(['internal_note']);
});

it('falls back to the defaults for empty and unknown values', function () {
    $settings = $this->withSettings([
        'translator_model' => '  ',
        'review_enabled' => null,
        'new_localization_status' => 'published',
        'offer_on_create' => null,
        'site_profile' => null,
        'language_instructions' => null,
        'glossary' => null,
        'excluded_collections' => null,
        'excluded_fields' => [' internal_note ', '', 'internal_note', null],
    ]);

    expect($settings->translatorModel())->toBeNull()
        ->and($settings->reviewEnabled())->toBeFalse()
        ->and($settings->newLocalizationStatus())->toBe('draft')
        ->and($settings->offerOnCreate())->toBeTrue()
        ->and($settings->siteProfile())->toBe('')
        ->and($settings->languageInstructions('el'))->toBe(['formality' => Formality::Default, 'instructions' => ''])
        ->and($settings->glossary('el'))->toBe([])
        ->and($settings->excludedCollections())->toBe([])
        ->and($settings->excludedFields())->toBe(['internal_note']);
});

it('throws on a model reference that does not parse', function () {
    $this->withSettings(['translator_model' => 'mistral:large'])->translatorModel();
})->throws(InvalidModelRef::class, 'Invalid model reference [mistral:large]');

it('returns the glossary rows of a site and of all sites, sorted by term', function () {
    $rows = [
        ['id' => '1', 'term' => 'zeus', 'translation' => 'Δίας'],
        ['id' => '2', 'term' => 'Agora', 'site' => 'el', 'translation' => 'Αγορά'],
        ['id' => '3', 'term' => 'Agora', 'translation' => 'Agora market', 'note' => 'The covered market'],
        ['id' => '4', 'term' => 'Kyma Bay', 'do_not_translate' => true],
        ['id' => '5', 'term' => 'Breakfast', 'site' => 'de', 'translation' => 'Frühstück'],
        ['id' => '6', 'term' => '', 'translation' => 'No term'],
        ['id' => '7', 'term' => 'Nothing', 'translation' => '', 'do_not_translate' => false],
        ['id' => '8', 'term' => 'agora', 'site' => ['el'], 'translation' => 'αγορά'],
    ];

    $greek = $this->withSettings(['glossary' => $rows])->glossary('el');
    $reversed = $this->withSettings(['glossary' => array_reverse($rows)])->glossary('el');

    expect($greek)->toBe([
        ['term' => 'Agora', 'site' => null, 'translation' => 'Agora market', 'do_not_translate' => false, 'note' => 'The covered market'],
        ['term' => 'Agora', 'site' => 'el', 'translation' => 'Αγορά', 'do_not_translate' => false, 'note' => ''],
        ['term' => 'agora', 'site' => 'el', 'translation' => 'αγορά', 'do_not_translate' => false, 'note' => ''],
        ['term' => 'Kyma Bay', 'site' => null, 'translation' => '', 'do_not_translate' => true, 'note' => ''],
        ['term' => 'zeus', 'site' => null, 'translation' => 'Δίας', 'do_not_translate' => false, 'note' => ''],
    ])
        ->and($reversed)->toBe($greek)
        ->and(array_column(app(Settings::class)->glossary('de'), 'term'))->toBe(['Agora', 'Breakfast', 'Kyma Bay', 'zeus']);
});

it('returns the language instructions of the first row for the site', function () {
    $settings = $this->withSettings(['language_instructions' => [
        ['id' => '1', 'formality' => 'formal', 'instructions' => 'No site.'],
        ['id' => '2', 'site' => 'el', 'formality' => 'casual', 'instructions' => ' Use the monotonic system. '],
        ['id' => '3', 'site' => 'el', 'formality' => 'formal', 'instructions' => 'Second row.'],
        ['id' => '4', 'site' => 'de', 'formality' => 'formal'],
    ]]);

    expect($settings->languageInstructions('el'))->toBe(['formality' => Formality::Default, 'instructions' => 'Use the monotonic system.'])
        ->and($settings->languageInstructions('de'))->toBe(['formality' => Formality::Formal, 'instructions' => ''])
        ->and($settings->languageInstructions('en'))->toBe(['formality' => Formality::Default, 'instructions' => '']);
});
