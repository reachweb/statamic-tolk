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
        'fieldtypes' => ['text', 'textarea', 'markdown'],
        'concurrency' => 4,
        'multisite' => true,
        'fakeEngine' => false,
        'settingsUrl' => null,
    ]);
});

it('reads the values when the CP page renders, not at boot', function () {
    app(FieldtypeRegistry::class)->register('custom_text', FakeTextHandler::class);
    config(['tolk.concurrency.editor' => 2, 'tolk.fake_engine' => true]);

    expect(tolkScriptData())->toBe([
        'fieldtypes' => ['text', 'textarea', 'markdown', 'custom_text'],
        'concurrency' => 2,
        'multisite' => true,
        'fakeEngine' => true,
        'settingsUrl' => null,
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

it('links the settings only for users who may edit them', function (array $permissions, bool $linked) {
    $this->signInWithPermissions(['access cp', 'translate content', ...$permissions]);

    expect(tolkScriptData()['settingsUrl'])->toBe($linked ? cp_route('addons.settings.edit', 'statamic-tolk') : null);
})->with([
    'an editor' => [[], false],
    'the addon\'s settings permission' => [['edit reachweb/statamic-tolk settings'], true],
    'configure addons' => [['configure addons'], true],
]);

it('includes the data in the CP page', function () {
    $this->signInAdmin();

    $this->get(cp_route('dashboard'))
        ->assertOk()
        ->assertSee('"tolk":{"fieldtypes":["text","textarea","markdown"],"concurrency":4,"multisite":true,"fakeEngine":false,"settingsUrl":'.json_encode(cp_route('addons.settings.edit', 'statamic-tolk')).'}', false);
});
