<?php

beforeEach(function () {
    $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'access de site']);
});

it('requires the identifying fields of each editor request', function (string $route, string $method, array $required) {
    $this->json($method, cp_route($route))->assertUnprocessable()->assertOnlyJsonValidationErrors($required);
})->with([
    'status' => ['tolk.editor.status', 'GET', ['reference']],
    'plan' => ['tolk.editor.plan', 'POST', ['reference', 'mode']],
    'review' => ['tolk.editor.review', 'POST', ['reference', 'values']],
    'unit' => ['tolk.editor.unit', 'POST', ['plan', 'unit']],
    'confirm' => ['tolk.editor.confirm', 'POST', ['reference']],
    'baseline' => ['tolk.editor.baseline', 'POST', ['reference']],
    'push' => ['tolk.editor.push', 'POST', ['reference', 'sites', 'mode']],
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
    'translator that is not a string' => [['translator' => ['anthropic:claude-sonnet-5-5']], 'translator'],
    'overwrite flag that is not a boolean' => [['overwrite_manual' => 'sometimes'], 'overwrite_manual'],
    'field list that is not a list' => [['fields' => 'title'], 'fields'],
    'skip handle that is not a string' => [['skip' => [['title']]], 'skip.0'],
    'review flag that is not a boolean' => [['review' => 'sometimes'], 'review'],
    'reviewer without a provider' => [['reviewer' => 'gpt-6-luna'], 'reviewer'],
    'reviewer outside the catalog' => [['reviewer' => 'anthropic:claude-opus-5-5'], 'reviewer'],
]);

it('accepts the review switch and a reviewer of the catalog for a plan', function () {
    $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'entry::a', 'mode' => 'missing', 'review' => false, 'reviewer' => 'openai:gpt-6-luna'])
        ->assertJsonMissingValidationErrors();
});

it('refuses an invalid review request', function (array $body, string $error) {
    $this->postJson(cp_route('tolk.editor.review'), ['reference' => 'entry::a', 'values' => [], ...$body])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$error]);
})->with([
    'values that are not a map' => [['values' => 'title'], 'values'],
    'overwrite flag that is not a boolean' => [['overwrite_manual' => 'sometimes'], 'overwrite_manual'],
    'reviewer without a provider' => [['reviewer' => 'claude-haiku-4-5'], 'reviewer'],
    'reviewer that is not a string' => [['reviewer' => ['openai:gpt-6-luna']], 'reviewer'],
]);

it('accepts a reviewer outside the catalog only from a user who may edit the settings', function () {
    $body = ['reference' => 'entry::a', 'values' => [], 'reviewer' => 'anthropic:claude-opus-5-5'];

    $this->postJson(cp_route('tolk.editor.review'), $body)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['reviewer']);

    $this->postJson(cp_route('tolk.editor.review'), [...$body, 'reviewer' => 'openai:gpt-6-luna'])->assertJsonMissingValidationErrors();

    $this->signInWithPermissions(['access cp', 'translate content', 'edit reachweb/statamic-tolk settings']);

    $this->postJson(cp_route('tolk.editor.review'), $body)->assertJsonMissingValidationErrors();
});

it('accepts a translator outside the catalog only from a user who may edit the settings', function () {
    $body = ['reference' => 'entry::a', 'mode' => 'missing', 'translator' => 'anthropic:claude-opus-5-5'];

    $this->postJson(cp_route('tolk.editor.plan'), $body)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['translator']);

    $this->postJson(cp_route('tolk.editor.plan'), [...$body, 'translator' => 'anthropic:claude-haiku-4-5'])->assertJsonMissingValidationErrors();

    $this->signInWithPermissions(['access cp', 'translate content', 'edit reachweb/statamic-tolk settings']);

    $this->postJson(cp_route('tolk.editor.plan'), $body)->assertJsonMissingValidationErrors();
});

it('reports only the format of a malformed translator', function () {
    $this->postJson(cp_route('tolk.editor.plan'), ['reference' => 'entry::a', 'mode' => 'missing', 'translator' => 'claude-opus-5-5'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.translator', [__('The :attribute must name a model as provider:model.', ['attribute' => 'translator'])]);
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

it('accepts the modes and options of a push', function (array $body) {
    $this->postJson(cp_route('tolk.editor.push'), ['reference' => 'entry::a', 'sites' => ['el'], 'mode' => 'missing', ...$body])
        ->assertJsonMissingValidationErrors();
})->with([
    'update' => [['mode' => 'update']],
    'all, overwriting manual edits' => [['mode' => 'all', 'overwrite_manual' => true]],
    'review and drafts' => [['review' => true, 'new_status' => 'draft']],
    'no review, mirrored, two sites' => [['review' => false, 'new_status' => 'mirror', 'sites' => ['el', 'de']]],
]);

it('refuses an invalid push request', function (array $body, string|array $errors) {
    $this->postJson(cp_route('tolk.editor.push'), ['reference' => 'entry::a', 'sites' => ['el'], 'mode' => 'missing', ...$body])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors((array) $errors);
})->with([
    'review mode, which the banner does not push' => [['mode' => 'review'], 'mode'],
    'no site' => [['sites' => []], 'sites'],
    'sites that are not a list' => [['sites' => ['greek' => 'el']], 'sites'],
    'a site twice' => [['sites' => ['el', 'de', 'el']], ['sites.0', 'sites.2']],
    'a site that is not a string' => [['sites' => [['el']]], 'sites.0'],
    'overwrite flag that is not a boolean' => [['overwrite_manual' => 'sometimes'], 'overwrite_manual'],
    'review flag that is not a boolean' => [['review' => 'sometimes'], 'review'],
    'unknown status for new localizations' => [['new_status' => 'published'], 'new_status'],
]);
