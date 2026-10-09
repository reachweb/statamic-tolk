<?php

use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeTextHandler;
use Statamic\Facades\Site;
use Statamic\Statamic;

function tolkScriptData(): array
{
    return Statamic::jsonVariables(request())['tolk'];
}

function tolkCatalogLabels(): array
{
    return [
        'anthropic:claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'anthropic:claude-haiku-5-5' => 'Claude Haiku 5.5',
        'openai:gpt-6.1-sol' => 'GPT-6.1 Sol',
        'openai:gpt-6-luna' => 'GPT-6 Luna',
        'gemini:gemini-3.8-flash' => 'Gemini 3.8 Flash',
        'gemini:gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite',
    ];
}

it('provides the addon\'s data to the CP script', function () {
    expect(tolkScriptData())->toBe([
        'fieldtypes' => ['text', 'textarea', 'markdown', 'bard', 'replicator', 'grid', 'group', 'table', 'list', 'array'],
        'concurrency' => 4,
        'multisite' => true,
        'fakeEngine' => false,
        'settingsUrl' => null,
        'auditUrl' => null,
        'models' => tolkCatalogLabels(),
    ]);
});

it('reads the values when the CP page renders, not at boot', function () {
    app(FieldtypeRegistry::class)->register('custom_text', FakeTextHandler::class);
    config(['tolk.concurrency.editor' => 2, 'tolk.fake_engine' => true]);

    expect(tolkScriptData())->toBe([
        'fieldtypes' => ['text', 'textarea', 'markdown', 'bard', 'replicator', 'grid', 'group', 'table', 'list', 'array', 'custom_text'],
        'concurrency' => 2,
        'multisite' => true,
        'fakeEngine' => true,
        'settingsUrl' => null,
        'auditUrl' => null,
        'models' => tolkCatalogLabels(),
    ]);
});

it('names the catalog\'s models as the model selects do', function () {
    config(['tolk.models.openai' => [['id' => 'gpt-custom', 'label' => 'GPT Custom'], ['id' => 'gpt-unlabelled'], ['tier' => 'fast']]]);

    expect(tolkScriptData()['models'])->toMatchArray(['openai:gpt-custom' => 'GPT Custom', 'openai:gpt-unlabelled' => 'gpt-unlabelled'])
        ->not->toHaveKey('openai:gpt-6.1-sol');
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

it('links the audit only for users who may run bulk translations', function (array $permissions, bool $linked) {
    $this->signInWithPermissions(['access cp', ...$permissions]);

    expect(tolkScriptData()['auditUrl'])->toBe($linked ? cp_route('tolk.audit') : null);
})->with([
    'an editor' => [['translate content'], false],
    'the settings and blueprints' => [['edit reachweb/statamic-tolk settings', 'configure fields'], false],
    'run bulk translations' => [['run bulk translations'], true],
]);

it('includes the data in the CP page', function () {
    $this->signInAdmin();

    $this->get(cp_route('dashboard'))
        ->assertOk()
        ->assertSee('"tolk":{"fieldtypes":["text","textarea","markdown","bard","replicator","grid","group","table","list","array"],"concurrency":4,"multisite":true,"fakeEngine":false,"settingsUrl":'.json_encode(cp_route('addons.settings.edit', 'statamic-tolk')).',"auditUrl":'.json_encode(cp_route('tolk.audit')).',"models":'.json_encode(tolkCatalogLabels()).'}', false);
});
