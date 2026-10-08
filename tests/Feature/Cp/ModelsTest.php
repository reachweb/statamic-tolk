<?php

use Reach\StatamicTolk\Ai\ModelCatalog;

beforeEach(function () {
    $this->signInWithPermissions(['access cp', 'translate content']);
});

it('returns the catalog grouped by provider', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'sdk-key']);

    $this->getJson(cp_route('tolk.models.index'))
        ->assertOk()
        ->assertJsonPath('catalog', app(ModelCatalog::class)->grouped())
        ->assertJsonPath('catalog.anthropic.0', [
            'value' => 'anthropic:claude-sonnet-5-5',
            'label' => 'Claude Sonnet 5.5',
            'tier' => 'quality',
            'configured' => true,
        ])
        ->assertJsonPath('catalog.openai.1.configured', false);

    expect(array_keys($this->getJson(cp_route('tolk.models.index'))->json('catalog')))->toBe(['anthropic', 'openai', 'gemini']);
});

it('returns the resolved defaults', function () {
    $this->getJson(cp_route('tolk.models.index'))
        ->assertOk()
        ->assertJsonPath('defaults', [
            'translator' => 'anthropic:claude-sonnet-5-5',
            'reviewer' => 'openai:gpt-6.1-sol',
            'fallback' => null,
        ]);
});

it('returns the defaults the settings name', function () {
    $this->withSettings([
        'translator_model' => 'gemini:gemini-3.8-flash',
        'reviewer_model' => 'anthropic:claude-haiku-5-5',
        'fallback_model' => 'openai:gpt-6-luna',
    ]);

    $this->getJson(cp_route('tolk.models.index'))
        ->assertOk()
        ->assertJsonPath('defaults', [
            'translator' => 'gemini:gemini-3.8-flash',
            'reviewer' => 'anthropic:claude-haiku-5-5',
            'fallback' => 'openai:gpt-6-luna',
        ]);
});

it('returns no defaults when no provider is configured', function () {
    $this->withProviderKeys([]);

    $this->getJson(cp_route('tolk.models.index'))
        ->assertOk()
        ->assertJsonPath('defaults', ['translator' => null, 'reviewer' => null, 'fallback' => null])
        ->assertJsonPath('catalog.anthropic.0.configured', false);
});

it('returns the fake engine\'s translator when no provider is configured', function () {
    $this->withProviderKeys([]);
    config(['tolk.fake_engine' => true]);

    $this->getJson(cp_route('tolk.models.index'))
        ->assertOk()
        ->assertJsonPath('defaults.translator', 'anthropic:claude-sonnet-5-5')
        ->assertJsonPath('defaults.reviewer', 'anthropic:claude-sonnet-5-5');
});

it('serves a user who may only run bulk translations', function () {
    $this->signInWithPermissions(['access cp', 'run bulk translations']);

    $this->getJson(cp_route('tolk.models.index'))->assertOk()->assertJsonStructure(['catalog', 'defaults']);
});
