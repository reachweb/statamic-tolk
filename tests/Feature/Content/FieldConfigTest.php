<?php

use Facades\Statamic\Fields\FieldtypeRepository;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Fields\FieldTransformer;

function configFieldHandles(string $fieldtype): array
{
    return FieldtypeRepository::find($fieldtype)->configFields()->all()->keys()->all();
}

function configBlueprintHandles(string $fieldtype): array
{
    return FieldtypeRepository::find($fieldtype)->configBlueprint()->fields()->all()->keys()->all();
}

it('adds both options to the config fields of a text field', function () {
    expect(configFieldHandles('text'))->toContain('tolk_translate', 'tolk_hint')
        ->and(configBlueprintHandles('text'))->toContain('tolk_translate', 'tolk_hint');

    $fields = FieldtypeRepository::find('text')->configFields()->all();

    expect($fields->get('tolk_translate')->config())->toBe(FieldtypeRegistry::CONFIG_FIELDS['tolk_translate'])
        ->and($fields->get('tolk_translate')->defaultValue())->toBeTrue()
        ->and($fields->get('tolk_hint')->config())->toBe(FieldtypeRegistry::CONFIG_FIELDS['tolk_hint'])
        ->and($fields->get('tolk_hint')->defaultValue())->toBeNull();
});

it('adds the options to every fieldtype with a handler, Bard included', function (string $fieldtype) {
    expect(configFieldHandles($fieldtype))->toContain('tolk_translate', 'tolk_hint')
        ->and(configBlueprintHandles($fieldtype))->toContain('tolk_translate', 'tolk_hint');
})->with(['text', 'textarea', 'markdown', 'bard', 'replicator', 'grid', 'group', 'table', 'list', 'array']);

it('leaves fieldtypes without a handler alone', function (string $fieldtype) {
    expect(configFieldHandles($fieldtype))->not->toContain('tolk_translate')->not->toContain('tolk_hint')
        ->and(configBlueprintHandles($fieldtype))->not->toContain('tolk_translate')->not->toContain('tolk_hint');
})->with(['toggle', 'select', 'assets', 'slug', 'code']);

it('skips handles whose fieldtype is not registered and appends each option once', function () {
    $registry = app(FieldtypeRegistry::class);
    $registry->register('tolk_not_a_fieldtype', TextHandler::class);

    $registry->appendConfigFields();

    expect(array_count_values(configBlueprintHandles('text')))->toMatchArray(['tolk_translate' => 1, 'tolk_hint' => 1]);
});

it('stores the toggle only when it is off and reads it through the fieldtype config', function () {
    $submitted = fn (string $handle, array $config) => FieldTransformer::fromVue([
        'type' => 'inline',
        'handle' => $handle,
        'config' => ['type' => 'text', 'display' => str($handle)->headline()->toString(), ...$config],
    ]);

    $off = $submitted('anchor', ['tolk_translate' => false, 'tolk_hint' => null]);
    $on = $submitted('heading', ['tolk_translate' => true, 'tolk_hint' => 'Keep it short']);

    expect($off)->toBe(['handle' => 'anchor', 'field' => ['type' => 'text', 'display' => 'Anchor', 'tolk_translate' => false]])
        ->and($on)->toBe(['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading', 'tolk_hint' => 'Keep it short']]);

    $this->makeBlueprint('collections.pages', 'page', [$off, $on]);
    Blink::flush();

    $fields = Blueprint::find('collections.pages.page')->fields()->all();

    expect($fields->get('anchor')->fieldtype()->config('tolk_translate'))->toBeFalse()
        ->and($fields->get('anchor')->fieldtype()->config('tolk_hint'))->toBeNull()
        ->and($fields->get('heading')->fieldtype()->config('tolk_translate'))->toBeTrue()
        ->and($fields->get('heading')->fieldtype()->config('tolk_hint'))->toBe('Keep it short');
});

it('lists nested fields whose handle looks technical', function () {
    ContentFixtures::field('replicator-nested');

    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text', 'localizable' => true],
        'anchor' => ['type' => 'text', 'localizable' => true],
        'page_builder' => [...ContentFixtures::raw('replicator-nested')['field'], 'localizable' => true],
        'sections' => [
            'type' => 'grid',
            'localizable' => true,
            'fields' => [
                ['handle' => 'heading', 'field' => ['type' => 'text']],
                ['handle' => 'css_class', 'field' => ['type' => 'text', 'display' => 'CSS class']],
                ['handle' => 'icon', 'field' => ['type' => 'text']],
                ['handle' => 'background_color', 'field' => ['type' => 'text']],
                ['handle' => 'embed_code', 'field' => ['type' => 'textarea']],
                ['handle' => 'link_text', 'field' => ['type' => 'text']],
                ['handle' => 'key_points', 'field' => ['type' => 'list']],
                ['handle' => 'video_url', 'field' => ['type' => 'text', 'input_type' => 'url']],
                ['handle' => 'layout', 'field' => ['type' => 'select', 'options' => ['wide' => 'Wide']]],
                ['handle' => 'section_id', 'field' => ['type' => 'text', 'tolk_translate' => false]],
            ],
        ],
        'content' => [
            'type' => 'bard',
            'localizable' => true,
            'sets' => ['media' => ['sets' => [
                'video' => ['fields' => [
                    ['handle' => 'caption', 'field' => ['type' => 'text']],
                    ['handle' => 'video_id', 'field' => ['type' => 'text', 'display' => 'Video ID']],
                    ['handle' => 'meta', 'field' => ['type' => 'group', 'fields' => [
                        ['handle' => 'Anchor_ID', 'field' => ['type' => 'text']],
                    ]]],
                ]],
            ]]],
        ],
        'extras' => [
            'type' => 'replicator',
            'sets' => ['main' => ['sets' => ['link' => ['display' => 'Link', 'fields' => [
                ['handle' => 'link', 'field' => ['type' => 'text']],
            ]]]]],
        ],
        'settings' => [
            'type' => 'group',
            'localizable' => true,
            'tolk_translate' => false,
            'fields' => [['handle' => 'anchor', 'field' => ['type' => 'text']]],
        ],
        'tracking' => [
            'type' => 'group',
            'localizable' => true,
            'visibility' => 'hidden',
            'fields' => [['handle' => 'anchor', 'field' => ['type' => 'text']]],
        ],
    ]);

    expect(app(TranslatableFields::class)->nestedTechnicalFields($blueprint))->toBe([
        ['path' => 'page_builder.blocks.text.anchor', 'handle' => 'anchor', 'type' => 'text', 'label' => 'Page builder › Blocks › Text › Anchor'],
        ['path' => 'sections.css_class', 'handle' => 'css_class', 'type' => 'text', 'label' => 'Sections › CSS class'],
        ['path' => 'sections.icon', 'handle' => 'icon', 'type' => 'text', 'label' => 'Sections › Icon'],
        ['path' => 'sections.background_color', 'handle' => 'background_color', 'type' => 'text', 'label' => 'Sections › Background Color'],
        ['path' => 'sections.embed_code', 'handle' => 'embed_code', 'type' => 'textarea', 'label' => 'Sections › Embed Code'],
        ['path' => 'content.video.video_id', 'handle' => 'video_id', 'type' => 'text', 'label' => 'Content › Video › Video ID'],
        ['path' => 'content.video.meta.Anchor_ID', 'handle' => 'Anchor_ID', 'type' => 'text', 'label' => 'Content › Video › Meta › Anchor Id'],
        ['path' => 'extras.link.link', 'handle' => 'link', 'type' => 'text', 'label' => 'Extras › Link › Link'],
    ]);
});

it('finds no technical fields in a blueprint without nested fields', function () {
    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'title' => ['type' => 'text', 'localizable' => true],
        'css_class' => ['type' => 'text'],
    ]);

    expect(app(TranslatableFields::class)->nestedTechnicalFields($blueprint))->toBe([]);
});
