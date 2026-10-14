<?php

use Inertia\Testing\AssertableInertia;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Formality;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Facades\YAML;

/**
 * The values the settings page submits: every field of the blueprint, empty ones included.
 */
function tolkSettingsForm(array $values = []): array
{
    return [...Settings::DEFAULTS, 'providers' => null, ...$values];
}

function tolkGlossaryRow(array $values): array
{
    return ['_id' => uniqid('row-'), 'term' => null, 'site' => [], 'translation' => null, 'do_not_translate' => false, 'note' => null, ...$values];
}

function tolkLanguageRow(array $values): array
{
    return ['_id' => uniqid('row-'), 'site' => [], 'formality' => 'default', 'instructions' => null, ...$values];
}

function tolkSettingsFile(): ?array
{
    $path = resource_path('addons/statamic-tolk.yaml');

    return file_exists($path) ? YAML::file($path)->parse() : null;
}

it('renders the settings page for a user with the settings permission', function (array $permissions) {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'sk-ant-secret-key']);
    $this->withSettings(['translator_model' => 'openai:gpt-6-luna', 'site_profile' => 'A small hotel.']);
    $this->signInWithPermissions(['access cp', ...$permissions]);

    $response = $this->get(cp_route('addons.settings.edit', 'statamic-tolk'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PublishForm')
            ->where('submitUrl', cp_route('addons.settings.update', 'statamic-tolk'))
            ->where('blueprint.tabs.0.handle', 'general')
            ->where('values.translator_model', 'openai:gpt-6-luna')
            ->where('values.site_profile', 'A small hotel.')
            ->where('values.providers', null)
            ->where('meta.translator_model.automatic', 'Automatic (Claude Sonnet 5.5)')
            ->where('meta.fallback_model.automatic', 'No fallback')
            ->has('meta.translator_model.catalog', 3)
            ->has('meta.providers.providers', 3)
            ->where('meta.providers.providers.0.configured', true)
            ->where('meta.providers.providers.1.configured', false)
            ->etc());

    expect($response->getContent())->not->toContain('sk-ant-secret-key');
})->with([
    'the addon\'s settings permission' => [['edit reachweb/statamic-tolk settings']],
    'configure addons' => [['configure addons']],
]);

// Core answers a refused page visit with a redirect to the CP index and an error, and a JSON request with a 403.
it('forbids the settings page and its save without the permission', function (array $permissions) {
    $this->signInWithPermissions(['access cp', ...$permissions]);

    $this->getJson(cp_route('addons.settings.edit', 'statamic-tolk'))->assertForbidden();
    $this->get(cp_route('addons.settings.edit', 'statamic-tolk'))
        ->assertRedirect(cp_route('index'))
        ->assertSessionHas('error');
    $this->patchJson(cp_route('addons.settings.update', 'statamic-tolk'), tolkSettingsForm())->assertForbidden();

    expect(tolkSettingsFile())->toBeNull();
})->with([
    'no permission' => [[]],
    'the addon\'s other permissions' => [['translate content', 'run bulk translations']],
]);

it('saves what Settings reads, with booleans as booleans and no providers key', function () {
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);

    $this->patchJson(cp_route('addons.settings.update', 'statamic-tolk'), tolkSettingsForm([
        'translator_model' => ' anthropic:claude-haiku-5-5 ',
        'review_enabled' => true,
        'reviewer_model' => 'gemini:gemini-3.8-flash',
        'fallback_model' => '',
        'offer_on_create' => false,
        'strict_fields' => true,
        'site_profile' => "  Kyma Bay is a small hotel in Chania.\n",
        'language_instructions' => [
            tolkLanguageRow(['site' => ['el'], 'formality' => 'informal', 'instructions' => 'Use the monotonic system.']),
        ],
        'glossary' => [
            tolkGlossaryRow(['term' => 'Kyma Bay', 'do_not_translate' => true]),
            tolkGlossaryRow(['term' => 'Breakfast', 'site' => ['de'], 'translation' => 'Frühstück', 'note' => 'Menu heading']),
        ],
    ]))->assertOk()->assertJson(['saved' => true]);

    $stored = tolkSettingsFile();
    $settings = app(Settings::class)->fresh();

    expect($stored)->not->toHaveKey('providers')
        ->and($stored['translator_model'])->toBe('anthropic:claude-haiku-5-5')
        ->and($stored)->not->toHaveKey('fallback_model')
        ->and($stored['review_enabled'])->toBeTrue()
        ->and($stored['offer_on_create'])->toBeFalse()
        ->and($stored['strict_fields'])->toBeTrue()
        ->and($stored['site_profile'])->toBe('Kyma Bay is a small hotel in Chania.')
        ->and($stored['language_instructions'][0])->toMatchArray(['site' => 'el', 'formality' => 'informal'])
        ->and($stored['glossary'][0])->toHaveKey('id')->toMatchArray(['term' => 'Kyma Bay', 'do_not_translate' => true])
        ->and($stored['glossary'][0])->not->toHaveKey('site')
        ->and($stored['glossary'][1]['site'])->toBe('de')
        ->and($settings->translatorModel())->toEqual(new ModelRef('anthropic', 'claude-haiku-5-5'))
        ->and($settings->reviewerModel())->toEqual(new ModelRef('gemini', 'gemini-3.8-flash'))
        ->and($settings->fallbackModel())->toBeNull()
        ->and($settings->languageInstructions('el'))->toBe(['formality' => Formality::Informal, 'instructions' => 'Use the monotonic system.'])
        ->and(array_column($settings->glossary('de'), 'term'))->toBe(['Breakfast', 'Kyma Bay'])
        ->and(array_column($settings->glossary('el'), 'term'))->toBe(['Kyma Bay']);
});

it('accepts glossary and language rows that only look alike', function () {
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);

    $this->patchJson(cp_route('addons.settings.update', 'statamic-tolk'), tolkSettingsForm([
        'language_instructions' => [
            tolkLanguageRow(['site' => ['el'], 'formality' => 'formal']),
            tolkLanguageRow(['site' => ['de'], 'formality' => 'formal']),
        ],
        'glossary' => [
            tolkGlossaryRow(['term' => 'Agora', 'translation' => 'Agora market']),
            tolkGlossaryRow(['term' => 'Agora', 'site' => ['el'], 'translation' => 'Αγορά']),
            tolkGlossaryRow(['term' => 'Agora', 'site' => ['de'], 'translation' => 'Markt']),
            tolkGlossaryRow(['term' => 'agora', 'site' => ['el'], 'translation' => 'αγορά']),
            tolkGlossaryRow(['term' => 'Kyma Bay', 'translation' => 'Kyma Bay', 'do_not_translate' => true]),
        ],
    ]))->assertOk()->assertJson(['saved' => true]);

    expect(tolkSettingsFile()['glossary'])->toHaveCount(5)
        ->and(tolkSettingsFile()['language_instructions'])->toHaveCount(2);
});

it('refuses settings that would not mean what they say', function (array $values, string $key, string $message) {
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);

    $this->patchJson(cp_route('addons.settings.update', 'statamic-tolk'), tolkSettingsForm($values))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);

    expect(tolkSettingsFile())->toBeNull();
})->with([
    'a translator that is not a model reference' => [
        ['translator_model' => 'mistral:large'],
        'translator_model',
        'must name a model as provider:model',
    ],
    'a status other than draft and mirror' => [
        ['new_localization_status' => 'published'],
        'new_localization_status',
        'is invalid',
    ],
    'a formality outside its three values' => [
        ['language_instructions' => [tolkLanguageRow(['site' => ['el'], 'formality' => 'casual'])]],
        'language_instructions.0.formality',
        'is invalid',
    ],
    'a language row without a site' => [
        ['language_instructions' => [tolkLanguageRow(['formality' => 'formal', 'instructions' => 'Use Sie.'])]],
        'language_instructions.0.site',
        'is required',
    ],
    'a second language row for a site' => [
        ['language_instructions' => [
            tolkLanguageRow(['site' => ['el'], 'formality' => 'formal']),
            tolkLanguageRow(['site' => ['de'], 'formality' => 'formal']),
            tolkLanguageRow(['site' => ['el'], 'formality' => 'informal']),
        ]],
        'language_instructions.2.site',
        'Greek already has a row. Each language can have one.',
    ],
    'a glossary row without a term' => [
        ['glossary' => [tolkGlossaryRow(['term' => '  ', 'translation' => 'Αγορά'])]],
        'glossary.0.term',
        'is required',
    ],
    'a glossary row with neither a translation nor the toggle' => [
        ['glossary' => [tolkGlossaryRow(['term' => 'Agora', 'translation' => ' '])]],
        'glossary.0.translation',
        'Enter a translation, or turn on Do not translate.',
    ],
    'a term twice for one site' => [
        ['glossary' => [
            tolkGlossaryRow(['term' => 'Agora', 'site' => ['el'], 'translation' => 'Αγορά']),
            tolkGlossaryRow(['term' => 'Agora', 'translation' => 'Agora market']),
            tolkGlossaryRow(['term' => ' Agora ', 'site' => ['el'], 'translation' => 'Η αγορά']),
        ]],
        'glossary.2.term',
        '"Agora" is already in the glossary for Greek.',
    ],
    'a term twice for every language' => [
        ['glossary' => [
            tolkGlossaryRow(['term' => 'Kyma Bay', 'do_not_translate' => true]),
            tolkGlossaryRow(['term' => 'Kyma Bay', 'site' => ['el'], 'translation' => 'Κύμα']),
            tolkGlossaryRow(['term' => 'Kyma Bay', 'translation' => 'Kyma Bay Hotel']),
        ]],
        'glossary.2.term',
        '"Kyma Bay" is already in the glossary for every language.',
    ],
]);

// Core parses every addon setting as Antlers when it saves them, so such text used to fail the save with a 500.
it('refuses text that Statamic cannot read as Antlers, and says how to keep it', function (array $values, string $key) {
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);

    $this->patchJson(cp_route('addons.settings.update', 'statamic-tolk'), tolkSettingsForm($values))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => 'put the whole text between {{ noparse }} and {{ /noparse }}']);

    expect(tolkSettingsFile())->toBeNull();
})->with([
    'the site profile' => [['site_profile' => 'Our templates print {{ title'], 'site_profile'],
    'the instructions' => [['instructions' => 'Keep {{ and }} unchanged.'], 'instructions'],
    'a language instruction' => [
        ['language_instructions' => [tolkLanguageRow(['site' => ['el'], 'instructions' => 'Keep {{ if }} as it is.'])]],
        'language_instructions.0.instructions',
    ],
    'a glossary term' => [['glossary' => [tolkGlossaryRow(['term' => 'Kyma {{ Bay', 'do_not_translate' => true])]], 'glossary.0.term'],
    'a glossary translation' => [['glossary' => [tolkGlossaryRow(['term' => 'Agora', 'translation' => 'Αγορά {{'])]], 'glossary.0.translation'],
    'a glossary note' => [
        ['glossary' => [tolkGlossaryRow(['term' => 'Agora', 'translation' => 'Αγορά', 'note' => 'Not {{ x + }}'])]],
        'glossary.0.note',
    ],
    'an excluded field' => [['excluded_fields' => ['internal_note', 'note {{']], 'excluded_fields'],
    'a model typed by hand' => [['translator_model' => 'anthropic:{{model'], 'translator_model'],
]);

it('saves text between noparse tags, and Settings leaves the tags out', function () {
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);

    $this->patchJson(cp_route('addons.settings.update', 'statamic-tolk'), tolkSettingsForm([
        'site_profile' => 'Our templates print {{ site:name }} in the footer.',
        'instructions' => '{{ noparse }}Keep {{ and }} unchanged.{{ /noparse }}',
        'language_instructions' => [tolkLanguageRow(['site' => ['el'], 'instructions' => 'Write {{noparse}}{{{{/noparse}} as it is.'])],
        'glossary' => [tolkGlossaryRow([
            'term' => '{{ noparse }}Kyma {{ Bay{{ /noparse }}',
            'translation' => '{{ noparse }}Κύμα {{ Bay{{ /noparse }}',
            'note' => '{{ noparse }}Not {{ x + }}{{ /noparse }}',
        ])],
    ]))->assertOk()->assertJson(['saved' => true]);

    $settings = app(Settings::class)->fresh();

    expect(tolkSettingsFile()['instructions'])->toBe('{{ noparse }}Keep {{ and }} unchanged.{{ /noparse }}')
        ->and($settings->siteProfile())->toBe('Our templates print {{ site:name }} in the footer.')
        ->and($settings->instructions())->toBe('Keep {{ and }} unchanged.')
        ->and($settings->languageInstructions('el')['instructions'])->toBe('Write {{ as it is.')
        ->and($settings->glossary('el'))->toBe([
            ['term' => 'Kyma {{ Bay', 'site' => null, 'translation' => 'Κύμα {{ Bay', 'do_not_translate' => false, 'note' => 'Not {{ x + }}'],
        ]);
});
