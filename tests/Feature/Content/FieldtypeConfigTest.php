<?php

use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\GroupHandler;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Reach\StatamicTolk\Tolk;
use Statamic\Fields\Field;

trait RegistersFieldtypesInConfig
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('tolk.fieldtypes', [
            'tolk_test_text' => FakeTextHandler::class,
            'textarea' => FakeTextHandler::class,
        ]);
    }
}

uses(RegistersFieldtypesInConfig::class);

it('applies the handlers from config at boot', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->has('tolk_test_text'))->toBeTrue()
        ->and($registry->for(new Field('code', ['type' => 'tolk_test_text'])))->toBeInstanceOf(FakeTextHandler::class);
});

it('lets config replace a built-in handler', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(new Field('intro', ['type' => 'textarea'])))->toBeInstanceOf(FakeTextHandler::class)
        ->and($registry->for(new Field('title', ['type' => 'text'])))->toBeInstanceOf(TextHandler::class);
});

it('keeps the handler from config when an addon registers the same handle', function () {
    Tolk::registerFieldtype('tolk_test_text', GroupHandler::class);
    Tolk::registerFieldtype('textarea', GroupHandler::class);

    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(new Field('code', ['type' => 'tolk_test_text'])))->toBeInstanceOf(FakeTextHandler::class)
        ->and($registry->for(new Field('intro', ['type' => 'textarea'])))->toBeInstanceOf(FakeTextHandler::class);
});
