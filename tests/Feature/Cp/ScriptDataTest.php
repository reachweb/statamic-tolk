<?php

use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Statamic\Facades\Site;
use Statamic\Statamic;

function tolkScriptData(): array
{
    return Statamic::jsonVariables(request())['tolk'];
}

it('provides the addon\'s data to the CP script', function () {
    expect(tolkScriptData())->toBe([
        'fieldtypes' => ['text', 'textarea'],
        'concurrency' => 4,
        'multisite' => true,
        'fakeEngine' => false,
    ]);
});

it('reads the values when the CP page renders, not at boot', function () {
    app(FieldtypeRegistry::class)->register('markdown', FakeTextHandler::class);
    config(['tolk.concurrency.editor' => 2, 'tolk.fake_engine' => true]);

    expect(tolkScriptData())->toBe([
        'fieldtypes' => ['text', 'textarea', 'markdown'],
        'concurrency' => 2,
        'multisite' => true,
        'fakeEngine' => true,
    ]);
});

it('keeps the editor concurrency at one or more', function () {
    config(['tolk.concurrency.editor' => 0]);

    expect(tolkScriptData()['concurrency'])->toBe(1);
});

it('reports multi-site as off when it is disabled or there is one site', function () {
    config(['statamic.system.multisite' => false]);

    expect(tolkScriptData()['multisite'])->toBeFalse();

    config(['statamic.system.multisite' => true]);
    Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US']]);

    expect(tolkScriptData()['multisite'])->toBeFalse();
});

it('includes the data in the CP page', function () {
    $this->signInAdmin();

    $this->get(cp_route('dashboard'))
        ->assertOk()
        ->assertSee('"tolk":{"fieldtypes":["text","textarea"],"concurrency":4,"multisite":true,"fakeEngine":false}', false);
});
