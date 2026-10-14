<?php

use Reach\StatamicTolk\Ai\Agents\SiteProfileAgent;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;
use Statamic\Facades\Addon;

const PROFILE_TEXT = 'Acme Tours runs guided walking tours on Crete for curious travellers. The tone is warm and practical.';

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeEntry('pages', 'en', ['title' => 'Walking tours on Crete']);

    $this->models = [];

    SiteProfileAgent::fake(function (string $prompt, $attachments, $provider, string $model) {
        $this->models[] = $model;

        return ['profile' => PROFILE_TEXT, 'terms' => ['Acme Tours', 'White Mountains Trail']];
    });
});

function siteProfile(): string
{
    return app(Settings::class)->fresh()->siteProfile();
}

/**
 * The site profile as it is stored, and as core reads it: core parses every addon setting as Antlers.
 *
 * @return array{raw: ?string, parsed: ?string}
 */
function storedProfile(): array
{
    $settings = Addon::get(Settings::PACKAGE)->settings();

    return ['raw' => $settings->raw()['site_profile'] ?? null, 'parsed' => $settings->get('site_profile')];
}

it('prints the profile and the suggested terms without saving them', function () {
    $this->artisan('tolk:profile')
        ->expectsOutputToContain('Writing a site profile from the content of English with Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5).')
        ->expectsOutput('Site profile')
        ->expectsOutput(PROFILE_TEXT)
        ->expectsOutput('Names to keep untranslated')
        ->expectsOutput('  • Acme Tours')
        ->expectsOutput('  • White Mountains Trail')
        ->expectsOutputToContain('Add them to the glossary in the Tolk settings')
        ->doesntExpectOutputToContain('Save this profile')
        ->assertExitCode(0);

    expect(siteProfile())->toBe('')
        ->and(app(UsageLog::class)->month()['anthropic:claude-sonnet-5-5']['sources'])->toHaveKey('cli');
});

it('saves the profile with --save after confirmation', function () {
    $this->withSettings(['glossary' => [['term' => 'Acme', 'do_not_translate' => true]]]);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsOutput(PROFILE_TEXT)
        ->expectsConfirmation('Save this profile as the site profile?', 'yes')
        ->expectsOutputToContain('Saved the site profile.')
        ->assertExitCode(0);

    expect(siteProfile())->toBe(PROFILE_TEXT)
        ->and(storedProfile())->toBe(['raw' => PROFILE_TEXT, 'parsed' => PROFILE_TEXT])
        ->and(app(Settings::class)->glossary('el'))->toHaveCount(1);
});

// Core parses every addon setting as Antlers, so such a profile used to stop the command with core's syntax error after
// the provider call, or to be run as a template on every read of the settings.
it('saves a profile with braces between noparse tags, so core keeps it as written', function (string $profile, string $saved, string $stored) {
    SiteProfileAgent::fake(fn () => ['profile' => $profile, 'terms' => []]);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsConfirmation('Save this profile as the site profile?', 'yes')
        ->expectsOutputToContain('Saved the site profile.')
        ->assertExitCode(0);

    expect(siteProfile())->toBe($saved)
        ->and(storedProfile())->toBe(['raw' => '{{ noparse }}'.$stored.'{{ /noparse }}', 'parsed' => $stored]);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsOutputToContain('The settings already hold this site profile.')
        ->assertExitCode(0);
})->with([
    'braces Statamic cannot read' => [
        'We teach Antlers: tags such as {{ and }} wrap variables.',
        'We teach Antlers: tags such as {{ and }} wrap variables.',
        'We teach Antlers: tags such as {{ and }} wrap variables.',
    ],
    'an Antlers tag' => [
        'Our templates print {{ site:name }} in the footer.',
        'Our templates print {{ site:name }} in the footer.',
        'Our templates print {{ site:name }} in the footer.',
    ],
    'noparse tags of its own' => [
        'Write {{ /noparse }}{{ title }} and{{ noparse }} as they are.',
        'Write {{ title }} and as they are.',
        'Write {{ title }} and as they are.',
    ],
    'an @ at the end, which would escape the closing tag' => ['Find us on {{ social }} as @', 'Find us on {{ social }} as @', 'Find us on {{ social }} as @ '],
]);

// Core ends a noparse region at any tag that starts with `{{/noparse` once lower-cased and without spaces, so such a tag
// in the profile, a final "{" or an "@{{{" made the wrapped text fail core's parse: the command stopped with core's
// syntax error after the provider call. Such a profile is stored with its `{{` taken apart, which core reads as text.
it('saves a profile that core could not read between noparse tags with its braces taken apart', function (string $profile, string $saved) {
    SiteProfileAgent::fake(fn () => ['profile' => $profile, 'terms' => []]);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsConfirmation('Save this profile as the site profile?', 'yes')
        ->expectsOutputToContain('Saved the site profile.')
        ->assertExitCode(0);

    expect(siteProfile())->toBe($saved)
        ->and(storedProfile())->toBe(['raw' => $saved, 'parsed' => $saved]);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsOutputToContain('The settings already hold this site profile.')
        ->assertExitCode(0);
})->with([
    'an upper-case end tag' => ['Warm voice. {{ /NOPARSE }} {{ config:app.name }} rest', 'Warm voice. { { /NOPARSE }} { { config:app.name }} rest'],
    'an end tag with a suffix' => ['Warm voice. {{/noparse_x}} rest', 'Warm voice. { {/noparse_x}} rest'],
    'an end tag with a space inside' => ['Warm voice. {{/no parse}} rest', 'Warm voice. { {/no parse}} rest'],
    'a final curly brace' => ['Use {{ title }} or {', 'Use { { title }} or {'],
    'an escaped tag with three braces' => ['Write @{{{ title }}} for {{ title }} in braces.', 'Write @{ { { title }}} for { { title }} in braces.'],
]);

it('saves nothing when the question is declined', function () {
    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsConfirmation('Save this profile as the site profile?', 'no')
        ->expectsOutputToContain('The site profile was not saved.')
        ->assertExitCode(0);

    expect(siteProfile())->toBe('');
});

it('shows the current profile and asks before replacing it', function () {
    $this->withSettings(['site_profile' => 'An older profile.']);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsOutput('Current site profile')
        ->expectsOutputToContain('An older profile.')
        ->expectsConfirmation('Replace the current site profile with this one?', 'yes')
        ->assertExitCode(0);

    expect(siteProfile())->toBe(PROFILE_TEXT);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsOutputToContain('The settings already hold this site profile.')
        ->assertExitCode(0);
});

it('writes with the model of --model', function () {
    $this->artisan('tolk:profile', ['--model' => 'openai:gpt-6.1-sol'])
        ->expectsOutputToContain('with GPT-6.1 Sol (openai:gpt-6.1-sol)')
        ->assertExitCode(0);

    expect($this->models)->toBe(['gpt-6.1-sol']);
});

it('reports an invalid model and a provider without a key', function () {
    $this->artisan('tolk:profile', ['--model' => 'gpt-6.1-sol'])
        ->expectsOutputToContain('Invalid model reference [gpt-6.1-sol].')
        ->assertExitCode(1);

    $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);

    $this->artisan('tolk:profile', ['--model' => 'gemini:gemini-3.8-flash'])
        ->expectsOutputToContain('The AI provider [gemini] has no API key.')
        ->assertExitCode(1);

    expect($this->models)->toBe([]);
});

it('reports a call the provider rejects', function () {
    $exception = ProviderFixtures::failure('anthropic', 401, 'authentication_error', 'invalid x-api-key');
    SiteProfileAgent::fake(fn () => throw $exception);

    $this->artisan('tolk:profile', ['--save' => true])
        ->expectsOutputToContain('The AI provider [anthropic] rejected the request (HTTP 401')
        ->assertExitCode(1);

    expect(siteProfile())->toBe('');
});
