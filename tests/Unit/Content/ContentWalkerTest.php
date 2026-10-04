<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\GridHandler;
use Reach\StatamicTolk\Content\Handlers\GroupHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Exceptions\LeafCountMismatch;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Statamic\Facades\Field as FieldFacade;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

function walkerWithFakes(): ContentWalker
{
    $registry = new FieldtypeRegistry(app());
    $registry->register('text', FakeTextHandler::class);
    $registry->register('textarea', FakeTextHandler::class);
    $registry->register('group', GroupHandler::class);
    $registry->register('grid', GridHandler::class);

    return new ContentWalker($registry);
}

function heroField(array $labelConfig = ['default' => 'Read more']): Field
{
    return new Field('hero', [
        'type' => 'group',
        'display' => 'Hero',
        'fields' => [
            ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
            ['handle' => 'intro', 'field' => ['type' => 'textarea', 'display' => 'Intro']],
            ['handle' => 'style', 'field' => ['type' => 'select', 'options' => ['light', 'dark']]],
            ['handle' => 'anchor', 'field' => ['type' => 'text', 'tolk_translate' => false]],
            ['handle' => 'button', 'field' => ['type' => 'group', 'display' => 'Button', 'fields' => [
                ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Label', ...$labelConfig]],
            ]]],
            ['handle' => 'faq', 'field' => ['type' => 'grid', 'display' => 'FAQ', 'fields' => [
                ['handle' => 'question', 'field' => ['type' => 'text', 'display' => 'Question']],
                ['handle' => 'answer', 'field' => ['type' => 'textarea', 'display' => 'Answer']],
            ]]],
        ],
    ]);
}

function heroValue(string $buttonLabel = 'Book now'): array
{
    return [
        'style' => 'dark',
        'heading' => 'Welcome',
        'extra' => 'Not in the blueprint',
        'anchor' => 'Welcome section',
        'intro' => 'Rooms by the sea.',
        'button' => ['label' => $buttonLabel],
        'faq' => [
            ['id' => 'row1', 'question' => 'Check-in?', 'answer' => 'From 14:00.'],
            ['id' => 'row2', 'question' => 'Pets?', 'answer' => 'Yes.'],
        ],
    ];
}

function leafTexts(array $leaves): array
{
    return array_map(fn (Leaf $leaf) => $leaf->text, $leaves);
}

it('leaves a field without a handler untouched', function () {
    $walker = walkerWithFakes();
    $never = fn () => throw new LogicException('No leaf expected.');
    $value = ['value' => true, 'label' => 'Visible'];

    expect($walker->mapField(new Field('visible', ['type' => 'toggle']), $value, $never))->toBe($value)
        ->and($walker->mapField(new Field('legacy', ['type' => 'not_installed']), 'Some text', $never))->toBe('Some text')
        ->and($walker->leaves(new Field('visible', ['type' => 'toggle']), $value))->toBe([]);
});

it('returns an identical value when every leaf keeps its text', function () {
    $value = heroValue();

    expect(walkerWithFakes()->mapField(heroField(), $value, fn (Leaf $leaf) => $leaf->text))->toBe($value)
        ->and(walkerWithFakes()->inject(heroField(), $value, leafTexts(walkerWithFakes()->leaves(heroField(), $value))))->toBe($value);
});

it('maps only handled nested fields and keeps every other key in its order', function () {
    $mapped = walkerWithFakes()->mapField(heroField(), heroValue(), fn () => 'X');

    expect($mapped)->toBe([
        'style' => 'dark',
        'heading' => 'X',
        'extra' => 'Not in the blueprint',
        'anchor' => 'Welcome section',
        'intro' => 'X',
        'button' => ['label' => 'X'],
        'faq' => [
            ['id' => 'row1', 'question' => 'X', 'answer' => 'X'],
            ['id' => 'row2', 'question' => 'X', 'answer' => 'X'],
        ],
    ]);
});

it('injects translations in the order leaves() lists the leaves', function () {
    $walker = walkerWithFakes();
    $texts = leafTexts($walker->leaves(heroField(), heroValue()));
    $marked = array_map(fn (string $text) => "«{$text}»", $texts);

    $injected = $walker->inject(heroField(), heroValue(), $marked);

    expect($texts)->toBe(['Welcome', 'Rooms by the sea.', 'Book now', 'Check-in?', 'From 14:00.', 'Pets?', 'Yes.'])
        ->and(leafTexts($walker->leaves(heroField(), $injected)))->toBe($marked)
        ->and($injected['button']['label'])->toBe('«Book now»')
        ->and($injected['faq'][1])->toBe(['id' => 'row2', 'question' => '«Pets?»', 'answer' => '«Yes.»'])
        ->and($injected['anchor'])->toBe('Welcome section');
});

it('throws when the number of translations differs from the number of leaves', function (Field $field, mixed $value, array $translations, string $message) {
    expect(fn () => walkerWithFakes()->inject($field, $value, $translations))->toThrow(LeafCountMismatch::class, $message);
})->with([
    'too few' => fn () => [heroField(), heroValue(), ['A', 'B'], 'The field [hero] has 7 leaves, but 2 translations were given.'],
    'too many' => fn () => [heroField(), heroValue(), array_fill(0, 8, 'A'), 'The field [hero] has 7 leaves, but 8 translations were given.'],
    'none for a text field' => fn () => [new Field('title', ['type' => 'text']), 'Home', [], 'The field [title] has 1 leaves, but 0 translations were given.'],
    'one for a field without a handler' => fn () => [new Field('visible', ['type' => 'toggle']), true, ['A'], 'The field [visible] has 0 leaves, but 1 translations were given.'],
]);

it('builds the path and the label trail of nested fields', function () {
    $leaves = walkerWithFakes()->leaves(heroField(), heroValue());

    expect(array_map(fn (Leaf $leaf) => $leaf->path, $leaves))->toBe([
        ['hero', 'heading'],
        ['hero', 'intro'],
        ['hero', 'button', 'label'],
        ['hero', 'faq', 0, 'question'],
        ['hero', 'faq', 0, 'answer'],
        ['hero', 'faq', 1, 'question'],
        ['hero', 'faq', 1, 'answer'],
    ])->and(array_map(fn (Leaf $leaf) => $leaf->label, $leaves))->toBe([
        'Hero › Heading',
        'Hero › Intro',
        'Hero › Button › Label',
        'Hero › FAQ › Question',
        'Hero › FAQ › Answer',
        'Hero › FAQ › Question',
        'Hero › FAQ › Answer',
    ])->and($leaves[0]->kind)->toBe(SegmentKind::Plain);
});

it('starts the path of a top-level field with its handle', function () {
    $leaves = walkerWithFakes()->leaves(new Field('title', ['type' => 'text']), 'Home');

    expect($leaves)->toHaveCount(1)
        ->and($leaves[0]->path)->toBe(['title'])
        ->and($leaves[0]->label)->toBe('Title');
});

it('never maps a nested field with translation switched off', function () {
    $leaves = walkerWithFakes()->leaves(heroField(), heroValue());

    expect(leafTexts($leaves))->not->toContain('Welcome section')
        ->and(array_map(fn (Leaf $leaf) => $leaf->path, $leaves))->not->toContain(['hero', 'anchor']);
});

it('skips a nested field holding its configured default on a target walk only', function () {
    $walker = walkerWithFakes();
    $defaulted = heroValue(buttonLabel: 'Read more');

    expect(leafTexts($walker->leaves(heroField(), $defaulted)))->toContain('Read more')
        ->and(leafTexts($walker->leaves(heroField(), $defaulted, target: true)))->not->toContain('Read more')
        ->and($walker->leaves(heroField(), $defaulted, target: true))->toHaveCount(6)
        ->and(leafTexts($walker->leaves(heroField(), heroValue(buttonLabel: 'Learn more'))))->toContain('Learn more')
        ->and(leafTexts($walker->leaves(heroField(), heroValue(buttonLabel: 'Learn more'), target: true)))->toContain('Learn more');
});

it('leaves a defaulted nested field untouched when injecting into a target', function () {
    $walker = walkerWithFakes();
    $defaulted = heroValue(buttonLabel: 'Read more');
    $translations = array_fill(0, 6, 'X');

    $injected = $walker->inject(heroField(), $defaulted, $translations, target: true);

    expect($injected['button']['label'])->toBe('Read more')
        ->and($injected['heading'])->toBe('X')
        ->and(fn () => $walker->inject(heroField(), $defaulted, $translations))->toThrow(LeafCountMismatch::class);
});

it('never compares a computed default', function () {
    FieldFacade::computedDefault('tolk_test_label', fn () => 'Read more');
    $field = heroField(['default' => 'computed:tolk_test_label']);

    expect($field->fieldtype()->fields()->get('button')->fieldtype()->fields()->get('label')->defaultValue())->toBe('Read more')
        ->and(leafTexts(walkerWithFakes()->leaves($field, heroValue(buttonLabel: 'Read more'), target: true)))->toContain('Read more');
});

it('maps only keys that are both in the fields and in the values', function () {
    $walker = walkerWithFakes();
    $fields = new Fields([
        ['handle' => 'heading', 'field' => ['type' => 'text']],
        ['handle' => 'missing', 'field' => ['type' => 'text']],
    ]);
    $context = new WalkContext($walker);

    expect($walker->mapFields($fields, ['other' => 'Kept', 'heading' => 'Hello'], fn () => 'X', $context->with('row')))
        ->toBe(['other' => 'Kept', 'heading' => 'X']);
});

it('collects the warnings of the last walk', function () {
    $walker = walkerWithFakes();
    $value = heroValue();
    $value['intro'] = ['not', 'a', 'string'];

    $walker->leaves(heroField(), $value);
    expect($walker->warnings())->toBe(['Hero › Intro is not a string.']);

    $walker->leaves(heroField(), heroValue());
    expect($walker->warnings())->toBe([]);
});

it('shares warnings across derived contexts and keeps each context unchanged', function () {
    $context = new WalkContext(walkerWithFakes(), target: true);
    $child = $context->with('body', 'Body')->with(2)->with('question', 'Question');

    $child->warn('Something');

    expect($context->path())->toBe([])
        ->and($context->label())->toBe('')
        ->and($child->path())->toBe(['body', 2, 'question'])
        ->and($child->labels())->toBe(['Body', 'Question'])
        ->and($child->label())->toBe('Body › Question')
        ->and($child->target)->toBeTrue()
        ->and($context->warnings())->toBe(['Something']);
});
