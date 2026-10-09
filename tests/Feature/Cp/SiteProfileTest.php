<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Ai\SiteProfileGenerator;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

const SITE_PROFILE_KEY = 'sk-ant-api03-Profile-Key-0123456789abcdef';

beforeEach(function () {
    $this->useFileCache();
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);

    $this->makeCollection('tours', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true], 'body' => ['type' => 'textarea', 'localizable' => true]]);
    $this->makeEntry('tours', 'en', ['title' => 'Samaria Gorge', 'body' => 'A full day walk with Acme Tours guides.']);
});

function generateSiteProfile(array $body = []): TestResponse
{
    return test()->postJson(cp_route('tolk.site-profile.generate'), $body);
}

it('writes a profile from the site\'s content with the translator of the settings, and saves nothing', function () {
    $seen = $this->fakeSiteProfile([
        'profile' => 'Acme Tours runs walking tours on Crete. Book at https://acme.test/book now.',
        'terms' => ['Acme Tours', '<b>Samaria Pass</b>', 'https://acme.test'],
    ]);

    generateSiteProfile()->assertOk()->assertExactJson([
        'profile' => 'Acme Tours runs walking tours on Crete. Book at now.',
        'terms' => ['Acme Tours', 'Samaria Pass'],
        'model' => 'anthropic:claude-sonnet-5-5',
        'label' => 'Claude Sonnet 5.5',
    ]);

    expect($seen->models)->toBe(['claude-sonnet-5-5'])
        ->and($seen->prompts[0])->toBe(app(SiteProfileGenerator::class)->content())
        ->and($seen->prompts[0])->toContain('A full day walk with Acme Tours guides.')
        ->and(app(Settings::class)->fresh()->siteProfile())->toBe('');
});

it('records the call in the usage log as the editor\'s', function () {
    $this->fakeSiteProfile();

    generateSiteProfile()->assertOk();

    $month = app(UsageLog::class)->month();

    expect(array_keys($month))->toBe(['anthropic:claude-sonnet-5-5'])
        ->and($month['anthropic:claude-sonnet-5-5']['calls'])->toBe(1)
        ->and(array_keys($month['anthropic:claude-sonnet-5-5']['sources']))->toBe(['editor']);
});

it('uses the translator the form holds, saved or not, and its automatic choice when it is empty', function (array $body, string $model) {
    $seen = $this->fakeSiteProfile();
    $this->withSettings(['translator_model' => 'gemini:gemini-3.8-flash']);

    generateSiteProfile($body)->assertOk()->assertJsonPath('model', $model);

    expect($seen->models)->toBe([explode(':', $model)[1]]);
})->with([
    'no translator sent' => [[], 'gemini:gemini-3.8-flash'],
    'a translator' => [['model' => ' openai:gpt-6.1-sol '], 'openai:gpt-6.1-sol'],
    'a model outside the catalog' => [['model' => 'anthropic:claude-opus-5-5'], 'anthropic:claude-opus-5-5'],
    'automatic' => [['model' => null], 'anthropic:claude-sonnet-5-5'],
    'automatic, as an empty text' => [['model' => ''], 'anthropic:claude-sonnet-5-5'],
]);

it('refuses a translator that is not a model reference', function (mixed $model) {
    $this->fakeSiteProfile();

    generateSiteProfile(['model' => $model])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['model' => 'The translator model must name a model as provider:model.']);

    $this->assertNoModelCalls();
})->with(['claude', 'anthropic:', 'nobody:model']);

it('answers 422 when the saved translator is not a model reference', function () {
    $this->fakeSiteProfile();
    $this->withSettings(['translator_model' => 'claude']);

    generateSiteProfile()->assertUnprocessable()->assertJson(['code' => 'invalid_model'])->assertJsonPath('message', fn (string $message) => str_contains($message, 'claude'));

    $this->assertNoModelCalls();
});

it('answers 422 when the translator\'s provider has no key, or no provider has one', function () {
    $this->fakeSiteProfile();
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);

    generateSiteProfile(['model' => 'openai:gpt-6.1-sol'])
        ->assertUnprocessable()
        ->assertExactJson(['code' => 'not_configured', 'message' => 'The AI provider [openai] has no API key. Set OPENAI_API_KEY or TOLK_OPENAI_API_KEY in .env.']);

    $this->withProviderKeys();

    generateSiteProfile()->assertUnprocessable()->assertJson(['code' => 'not_configured']);
    generateSiteProfile(['model' => null])->assertUnprocessable()->assertJson(['code' => 'not_configured']);

    $this->assertNoModelCalls();
});

it('answers 422 when the catalog has no model for the automatic choice', function () {
    $this->fakeSiteProfile();
    config(['tolk.models.anthropic' => [['id' => 'claude-sonnet-5-5', 'tier' => 'fast']]]);
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);

    generateSiteProfile()
        ->assertUnprocessable()
        ->assertExactJson(['code' => 'no_model', 'message' => 'The model catalog (tolk.models) has no quality model for [anthropic].']);

    $this->assertNoModelCalls();
});

it('answers 422 with a readable message when the provider rejects the call, without the key', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => SITE_PROFILE_KEY]);
    ProviderFixtures::fake('anthropic', ProviderFixtures::error('anthropic', 401, 'authentication_error', 'invalid x-api-key '.SITE_PROFILE_KEY), 401);

    $response = generateSiteProfile()->assertUnprocessable()->assertJson(['code' => 'failed']);

    expect($response->json('message'))->toContain('The AI provider [anthropic] rejected the request (HTTP 401')
        ->and($response->getContent())->not->toContain(SITE_PROFILE_KEY)
        ->not->toContain(substr(SITE_PROFILE_KEY, -12));
});

it('answers 422 when the answer holds no profile', function () {
    $this->fakeSiteProfile(['profile' => 'https://acme.test', 'terms' => ['Acme']]);

    generateSiteProfile()
        ->assertUnprocessable()
        ->assertExactJson(['code' => 'failed', 'message' => 'The answer of [anthropic:claude-sonnet-5-5] held no site profile. Try again, or try another model.']);
});

it('gives the call 120 seconds and raises a lower time limit above it', function () {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(ProviderFixtures::withText('anthropic', '{"profile":"A website.","terms":[]}'));
    });

    try {
        set_time_limit(30);
        generateSiteProfile()->assertOk()->assertJsonPath('profile', 'A website.');

        expect($options['timeout'])->toBe(SiteProfileGenerator::TIMEOUT)->toBe(120)
            ->and(ini_get('max_execution_time'))->toBe('150');
    } finally {
        set_time_limit(0);
    }
});

it('answers with the fake engine without calling a model or needing a key', function () {
    $this->withProviderKeys();
    config(['tolk.fake_engine' => true, 'tolk.fake_delay_ms' => 0]);

    $response = generateSiteProfile(['model' => 'gemini:gemini-3.8-flash'])->assertOk();

    expect($response->json('profile'))->toStartWith('[profile] English is a website.')
        ->and($response->json('terms'))->toBe(['English'])
        ->and($response->json('model'))->toBe('gemini:gemini-3.8-flash');
    $this->assertNoModelCalls();
});

it('needs the addon\'s settings ability', function () {
    $seen = $this->fakeSiteProfile();

    $this->signInWithPermissions(['access cp', 'translate content', 'run bulk translations']);
    generateSiteProfile()->assertForbidden();

    $this->signInWithPermissions(['access cp', 'configure addons']);
    generateSiteProfile()->assertOk();

    expect($seen->prompts)->toHaveCount(1);
});
