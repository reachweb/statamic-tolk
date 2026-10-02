<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Fields\Field;

function translatableFields(): TranslatableFields
{
    return app(TranslatableFields::class);
}

function reasons(array $explained): array
{
    return collect($explained)->pluck('reason', 'handle')->all();
}

it('explains every top-level field with one reason each', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text', 'localizable' => true],
        'slug' => ['type' => 'slug', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'internal_note' => ['type' => 'textarea'],
        'featured' => ['type' => 'toggle', 'localizable' => true],
        'code' => ['type' => 'text', 'localizable' => true, 'tolk_translate' => false],
        'sku' => ['type' => 'text', 'localizable' => true],
        'tracking' => ['type' => 'text', 'localizable' => true, 'visibility' => 'hidden'],
        'word_count' => ['type' => 'text', 'localizable' => true, 'visibility' => 'computed'],
    ]);

    $explained = translatableFields()->explain($blueprint, ['auto_title' => true, 'excluded_fields' => ['sku']]);

    expect($explained)->toBe([
        ['handle' => 'title', 'display' => 'Title', 'type' => 'text', 'in_scope' => false, 'reason' => 'auto_title'],
        ['handle' => 'slug', 'display' => 'Slug', 'type' => 'slug', 'in_scope' => false, 'reason' => 'slug'],
        ['handle' => 'intro', 'display' => 'Introduction', 'type' => 'textarea', 'in_scope' => true, 'reason' => 'ok'],
        ['handle' => 'internal_note', 'display' => 'Internal Note', 'type' => 'textarea', 'in_scope' => false, 'reason' => 'not_localizable'],
        ['handle' => 'featured', 'display' => 'Featured', 'type' => 'toggle', 'in_scope' => false, 'reason' => 'unsupported_fieldtype'],
        ['handle' => 'code', 'display' => 'Code', 'type' => 'text', 'in_scope' => false, 'reason' => 'disabled'],
        ['handle' => 'sku', 'display' => 'Sku', 'type' => 'text', 'in_scope' => false, 'reason' => 'excluded'],
        ['handle' => 'tracking', 'display' => 'Tracking', 'type' => 'text', 'in_scope' => false, 'reason' => 'hidden'],
        ['handle' => 'word_count', 'display' => 'Word Count', 'type' => 'text', 'in_scope' => false, 'reason' => 'computed'],
    ]);

    $fields = translatableFields()->for($blueprint, ['auto_title' => true, 'excluded_fields' => ['sku']]);

    expect($fields->keys()->all())->toBe(['intro'])
        ->and($fields->get('intro'))->toBeInstanceOf(Field::class)
        ->and($fields->get('intro')->handle())->toBe('intro');
});

it('returns the in-scope fields keyed by handle in blueprint order', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text', 'localizable' => true],
        'internal_note' => ['type' => 'text'],
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'website' => ['type' => 'text', 'localizable' => true, 'input_type' => 'url'],
    ]);

    $fields = translatableFields()->for($blueprint);

    expect($fields->keys()->all())->toBe(['title', 'intro', 'website'])
        ->and($fields->keys()->all())->toBe(collect(translatableFields()->explain($blueprint))->where('in_scope', true)->pluck('handle')->all());
});

it('puts title without localizable out with not_localizable', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text'],
    ]);

    expect(reasons(translatableFields()->explain($blueprint)))->toBe(['title' => 'not_localizable'])
        ->and(translatableFields()->for($blueprint))->toBeEmpty();
});

it('keeps a text field with input_type url in scope, although it yields no leaf', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'website' => ['type' => 'text', 'localizable' => true, 'input_type' => 'url'],
    ]);

    $field = translatableFields()->for($blueprint)->get('website');

    expect($field)->toBeInstanceOf(Field::class)
        ->and(app(ContentWalker::class)->leaves($field, 'Visit our website'))->toBe([]);
});

it('skips title only when the collection generates titles', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    expect(reasons(translatableFields()->explain($blueprint)))->toBe(['title' => 'ok'])
        ->and(reasons(translatableFields()->explain($blueprint, ['auto_title' => false])))->toBe(['title' => 'ok'])
        ->and(reasons(translatableFields()->explain($blueprint, ['auto_title' => true])))->toBe(['title' => 'auto_title']);
});

it('reports title and slug of a collection entry blueprint', function (bool $autoTitle, string $titleReason) {
    $collection = $this->makeCollection('pages', ['en', 'el'], [
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    if ($autoTitle) {
        $collection->titleFormats('{{ intro }}')->save();
    }

    $collection = Collection::find('pages');
    $blueprint = $collection->entryBlueprint();

    expect(reasons(translatableFields()->explain($blueprint, ['auto_title' => $collection->autoGeneratesTitles()])))
        ->toBe(['title' => $titleReason, 'intro' => 'ok', 'slug' => 'slug']);
})->with([
    'title written by editors' => [false, 'not_localizable'],
    'title generated by the collection' => [true, 'auto_title'],
]);

it('reports the first reason that applies, so not_localizable means the field would otherwise be in scope', function (array $config, array $context, string $reason) {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => $config,
    ]);

    expect(reasons(translatableFields()->explain($blueprint, $context)))->toBe(['title' => $reason]);
})->with([
    'auto title over not localizable' => [['type' => 'text'], ['auto_title' => true], 'auto_title'],
    'auto title over unsupported fieldtype' => [['type' => 'hidden'], ['auto_title' => true], 'auto_title'],
    'unsupported fieldtype over not localizable' => [['type' => 'toggle'], [], 'unsupported_fieldtype'],
    'unsupported fieldtype over hidden' => [['type' => 'toggle', 'localizable' => true, 'visibility' => 'hidden'], [], 'unsupported_fieldtype'],
    'computed over disabled' => [['type' => 'text', 'visibility' => 'computed', 'tolk_translate' => false], [], 'computed'],
    'hidden over excluded' => [['type' => 'text', 'visibility' => 'hidden'], ['excluded_fields' => ['title']], 'hidden'],
    'disabled over excluded' => [['type' => 'text', 'tolk_translate' => false], ['excluded_fields' => ['title']], 'disabled'],
    'excluded over not localizable' => [['type' => 'text'], ['excluded_fields' => ['title']], 'excluded'],
]);

it('never translates the slug, localizable or not', function (array $config) {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', ['slug' => $config]);

    expect(reasons(translatableFields()->explain($blueprint)))->toBe(['slug' => 'slug']);
})->with([
    'slug fieldtype' => [['type' => 'slug', 'localizable' => true]],
    'not localizable' => [['type' => 'slug']],
    'text fieldtype' => [['type' => 'text', 'localizable' => true]],
]);

it('keeps read-only fields and an explicit translate toggle in scope', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'locked' => ['type' => 'text', 'localizable' => true, 'visibility' => 'read_only'],
        'legacy_locked' => ['type' => 'text', 'localizable' => true, 'read_only' => true],
        'toggled' => ['type' => 'text', 'localizable' => true, 'tolk_translate' => true],
        'visible' => ['type' => 'text', 'localizable' => true, 'visibility' => 'visible'],
    ]);

    expect(translatableFields()->for($blueprint)->keys()->all())->toBe(['locked', 'legacy_locked', 'toggled', 'visible']);
});

it('excludes only exact handles', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'intro_text' => ['type' => 'textarea', 'localizable' => true],
        'Intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    expect(reasons(translatableFields()->explain($blueprint, ['excluded_fields' => ['intro']])))
        ->toBe(['intro' => 'excluded', 'intro_text' => 'ok', 'Intro' => 'ok']);
});

it('takes the excluded fields from the context only, never from the settings', function () {
    $this->withSettings(['excluded_fields' => ['intro']]);
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    expect(reasons(translatableFields()->explain($blueprint)))->toBe(['intro' => 'ok']);
});

it('brings a fieldtype into scope once a handler is registered for it', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'snippet' => ['type' => 'code', 'localizable' => true],
    ]);

    expect(reasons(translatableFields()->explain($blueprint)))->toBe(['snippet' => 'unsupported_fieldtype']);

    app(FieldtypeRegistry::class)->register('code', FakeTextHandler::class);

    expect(reasons(translatableFields()->explain($blueprint)))->toBe(['snippet' => 'ok']);
});

it('covers fields imported from a fieldset under their prefixed handles', function () {
    Fieldset::make('seo')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true]],
        ['handle' => 'description', 'field' => ['type' => 'textarea']],
        ['handle' => 'keywords', 'field' => ['type' => 'text', 'localizable' => true]],
    ]])->save();

    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text', 'localizable' => true],
        ['import' => 'seo', 'prefix' => 'seo_'],
    ]);

    expect(reasons(translatableFields()->explain($blueprint, ['auto_title' => true, 'excluded_fields' => ['seo_keywords']])))
        ->toBe([
            'title' => 'auto_title',
            'seo_title' => 'ok',
            'seo_description' => 'not_localizable',
            'seo_keywords' => 'excluded',
        ]);
});
