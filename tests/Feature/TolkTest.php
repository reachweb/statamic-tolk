<?php

use Facades\Statamic\Fields\FieldtypeRepository;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Exceptions\InvalidFieldHandler;
use Reach\StatamicTolk\ServiceProvider;
use Reach\StatamicTolk\Tests\Support\Fieldtypes\NoteFieldtype;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Reach\StatamicTolk\Tolk;
use Statamic\Facades\CP\Nav;
use Statamic\Fields\Field;

it('registers a handler that the walker uses', function () {
    NoteFieldtype::register();
    Tolk::registerFieldtype('tolk_test_note', FakeTextHandler::class);

    $walker = app(ContentWalker::class);
    $note = new Field('note', ['type' => 'tolk_test_note', 'display' => 'Note']);
    $blocks = new Field('blocks', ['type' => 'replicator', 'display' => 'Blocks', 'sets' => ['main' => ['sets' => [
        'aside' => ['display' => 'Aside', 'fields' => [['handle' => 'note', 'field' => ['type' => 'tolk_test_note']]]],
    ]]]]);
    $rows = [['id' => 'a1', 'type' => 'aside', 'note' => 'Quiet rooms']];

    expect(app(FieldtypeRegistry::class)->for($note))->toBeInstanceOf(FakeTextHandler::class)
        ->and($walker->leaves($note, 'Sea view'))->toEqual([new Leaf('Sea view', SegmentKind::Plain, ['note'], 'Note')])
        ->and($walker->inject($note, 'Sea view', ['Θέα στη θάλασσα']))->toBe('Θέα στη θάλασσα')
        ->and($walker->inject($blocks, $rows, ['Ήσυχα δωμάτια']))->toBe([['id' => 'a1', 'type' => 'aside', 'note' => 'Ήσυχα δωμάτια']]);
});

it('adds the per-field options to the fieldtype and puts its fields in scope', function () {
    NoteFieldtype::register();
    Tolk::registerFieldtype('tolk_test_note', FakeTextHandler::class);

    $blueprint = $this->makeBlueprint('collections.pages', 'page', [
        'note' => ['type' => 'tolk_test_note', 'localizable' => true],
        'aside' => ['type' => 'tolk_test_note', 'localizable' => true, 'tolk_translate' => false],
    ]);

    expect(FieldtypeRepository::find('tolk_test_note')->configFields()->all()->keys()->all())->toContain('tolk_translate', 'tolk_hint')
        ->and(app(TranslatableFields::class)->for($blueprint)->keys()->all())->toBe(['note']);
});

it('lets a handler registered before Tolk boots replace a built-in one', function () {
    app()->forgetInstance(FieldtypeRegistry::class);

    Tolk::registerFieldtype('textarea', FakeTextHandler::class);
    // AddonTestCase replaces the CP nav with a mock once the addon has booted.
    Nav::shouldReceive('extend');
    app()->getProvider(ServiceProvider::class)->bootAddon();

    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(new Field('intro', ['type' => 'textarea'])))->toBeInstanceOf(FakeTextHandler::class)
        ->and($registry->for(new Field('title', ['type' => 'text'])))->toBeInstanceOf(TextHandler::class)
        ->and($registry->handles())->toContain('text', 'textarea', 'markdown', 'bard', 'replicator', 'grid', 'group', 'table', 'list', 'array');
});

it('refuses a class that is not a field handler', function () {
    Tolk::registerFieldtype('tolk_test_note', stdClass::class);
})->throws(InvalidFieldHandler::class);
