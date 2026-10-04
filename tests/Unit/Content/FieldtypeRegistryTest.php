<?php

use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\GroupHandler;
use Reach\StatamicTolk\Exceptions\InvalidFieldHandler;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Statamic\Fields\Field;

it('registers handlers by fieldtype handle', function () {
    $registry = new FieldtypeRegistry(app());
    $registry->register('text', FakeTextHandler::class);
    $registry->register('group', GroupHandler::class);

    expect($registry->has('text'))->toBeTrue()
        ->and($registry->has('group'))->toBeTrue()
        ->and($registry->has('toggle'))->toBeFalse()
        ->and($registry->handles())->toBe(['text', 'group']);
});

it('finds the handler for a field by its type, resolved through the container', function () {
    $registry = new FieldtypeRegistry(app());
    $registry->register('textarea', FakeTextHandler::class);
    $bound = new FakeTextHandler;
    app()->instance(FakeTextHandler::class, $bound);

    expect($registry->for(new Field('intro', ['type' => 'textarea'])))->toBe($bound)
        ->and($registry->for(new Field('visible', ['type' => 'toggle'])))->toBeNull();
});

it('treats a field without a type as text, as Statamic does', function () {
    $registry = new FieldtypeRegistry(app());
    $registry->register('text', FakeTextHandler::class);

    expect($registry->for(new Field('untyped', [])))->toBeInstanceOf(FakeTextHandler::class);
});

it('replaces an earlier registration for the same handle', function () {
    $registry = new FieldtypeRegistry(app());
    $registry->register('text', FakeTextHandler::class);
    $registry->register('text', GroupHandler::class);

    expect($registry->for(new Field('title', ['type' => 'text'])))->toBeInstanceOf(GroupHandler::class)
        ->and($registry->handles())->toBe(['text']);
});

it('refuses a class that is not a field handler', function () {
    (new FieldtypeRegistry(app()))->register('text', stdClass::class);
})->throws(InvalidFieldHandler::class, 'The handler [stdClass] for the fieldtype [text] does not implement Reach\StatamicTolk\Contracts\FieldHandler.');

it('is a singleton', function () {
    expect(app(FieldtypeRegistry::class))->toBe(app(FieldtypeRegistry::class));
});
