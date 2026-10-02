<?php

use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Statamic\Fields\Field;

trait RegistersFieldtypesInConfig
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('tolk.fieldtypes', ['tolk_test_text' => FakeTextHandler::class]);
    }
}

uses(RegistersFieldtypesInConfig::class);

it('applies the handlers from config at boot', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->has('tolk_test_text'))->toBeTrue()
        ->and($registry->for(new Field('code', ['type' => 'tolk_test_text'])))->toBeInstanceOf(FakeTextHandler::class);
});
