<?php

beforeEach(function () {
    $this->signInWithPermissions(['access cp', 'translate content']);
});

it('requires the identifying fields of each editor request', function (string $route, string $method, array $required) {
    $this->json($method, cp_route($route))->assertUnprocessable()->assertOnlyJsonValidationErrors($required);
})->with([
    'status' => ['tolk.editor.status', 'GET', ['reference']],
    'plan' => ['tolk.editor.plan', 'POST', ['reference', 'mode']],
    'unit' => ['tolk.editor.unit', 'POST', ['plan', 'unit']],
    'confirm' => ['tolk.editor.confirm', 'POST', ['reference']],
    'baseline' => ['tolk.editor.baseline', 'POST', ['reference']],
]);

it('accepts the translation modes of the editor for a plan', function (string $mode) {
    $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'entry::a', 'mode' => $mode])->assertJsonMissingValidationErrors();
})->with(['missing', 'update', 'all']);

it('refuses an invalid plan request', function (array $body, string $error) {
    $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'entry::a', 'mode' => 'missing', ...$body])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$error]);
})->with([
    'review mode, which has its own endpoint' => [['mode' => 'review'], 'mode'],
    'unknown mode' => [['mode' => 'everything'], 'mode'],
    'translator without a provider' => [['translator' => 'gpt-6-luna'], 'translator'],
    'translator of an unsupported provider' => [['translator' => 'mistral:mistral-large'], 'translator'],
    'overwrite flag that is not a boolean' => [['overwrite_manual' => 'sometimes'], 'overwrite_manual'],
    'field list that is not a list' => [['fields' => 'title'], 'fields'],
    'skip handle that is not a string' => [['skip' => [['title']]], 'skip.0'],
]);

it('accepts a translator outside the catalog at validation', function () {
    $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'entry::a', 'mode' => 'missing', 'translator' => 'anthropic:claude-opus-5-5'])
        ->assertJsonMissingValidationErrors();
});

it('refuses a malformed confirm or baseline request', function (string $route, array $body, string $error) {
    $this->postJson(cp_route($route), ['reference' => 'entry::a', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'delivered handle that is not a string' => ['tolk.editor.confirm', ['delivered' => [['title']]], 'delivered.0'],
    'baseline field that is not a string' => ['tolk.editor.baseline', ['fields' => ['title', ['intro']]], 'fields.1'],
    'protect flag that is not a boolean' => ['tolk.editor.baseline', ['protect' => 'yes'], 'protect'],
]);
