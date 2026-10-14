<?php

use Composer\InstalledVersions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

it('decodes the structured translations', function (string $provider) {
    $response = ProviderFixtures::respond($provider);

    expect($response)->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($response->structured['translations'])->toBe(ProviderFixtures::TRANSLATIONS)
        ->and($response['translations'])->toBe(ProviderFixtures::TRANSLATIONS)
        ->and($response->steps->last()->finishReason)->toBe(FinishReason::Stop)
        ->and($response->meta->provider)->toBe("tolk_{$provider}");
})->with(ProviderFixtures::PROVIDERS);

it('reports the model the provider returned, except for gemini, which reports the requested one', function (string $provider, string $model) {
    $body = ProviderFixtures::response($provider);
    $body['model'] = 'reported-model';

    expect(ProviderFixtures::respond($provider, $body)->meta->model)->toBe($model);
})->with([
    ['anthropic', 'reported-model'],
    ['openai', 'reported-model'],
    ['gemini', 'gemini-3.8-flash'],
]);

it('maps usage, cache and reasoning tokens', function (string $provider, array $usage) {
    $response = ProviderFixtures::respond($provider);

    expect($response->usage->toArray())->toBe($usage);
})->with([
    'anthropic: input includes cache reads and writes' => ['anthropic', [
        'input_tokens' => 2520,
        'output_tokens' => 48,
        'cache_read_input_tokens' => 1800,
        'cache_write_input_tokens' => 600,
        'reasoning_tokens' => 0,
    ]],
    'openai' => ['openai', [
        'input_tokens' => 2520,
        'output_tokens' => 60,
        'cache_read_input_tokens' => 1800,
        'cache_write_input_tokens' => 600,
        'reasoning_tokens' => 12,
    ]],
    'gemini: output includes thought tokens, cache writes are not reported' => ['gemini', [
        'input_tokens' => 2520,
        'output_tokens' => 60,
        'cache_read_input_tokens' => 1800,
        'cache_write_input_tokens' => null,
        'reasoning_tokens' => 12,
    ]],
]);

it('reports a truncated response as FinishReason::Length on the last step without throwing', function (string $provider) {
    $response = ProviderFixtures::respond($provider, ProviderFixtures::truncated($provider));

    expect($response->steps)->toHaveCount(1)
        ->and($response->steps->last()->finishReason)->toBe(FinishReason::Length)
        ->and($response->structured)->toBe([]);
})->with(ProviderFixtures::PROVIDERS);

it('returns an empty structured result for undecodable JSON without throwing', function (string $provider) {
    $response = ProviderFixtures::respond($provider, ProviderFixtures::withText($provider, 'Sorry, here is the translation: Καλώς ήρθατε'));

    expect($response->structured)->toBe([])
        ->and($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
})->with(ProviderFixtures::PROVIDERS);

it('decodes JSON wrapped in a markdown code fence', function (string $provider) {
    $json = json_encode(['translations' => ProviderFixtures::TRANSLATIONS], JSON_UNESCAPED_UNICODE);

    $response = ProviderFixtures::respond($provider, ProviderFixtures::withText($provider, "```json\n{$json}\n```"));

    expect($response->structured['translations'])->toBe(ProviderFixtures::TRANSLATIONS);
})->with(ProviderFixtures::PROVIDERS);

it('does not validate the decoded data against the schema', function (string $provider) {
    $response = ProviderFixtures::respond($provider, ProviderFixtures::withText($provider, '{"translations":[{"id":"s1"}],"extra":true}'));

    expect($response->structured)->toBe(['translations' => [['id' => 's1']], 'extra' => true]);
})->with(ProviderFixtures::PROVIDERS);

it('reports a refusal as FinishReason::ContentFilter on anthropic and gemini', function (string $provider) {
    $response = ProviderFixtures::respond($provider, ProviderFixtures::refusal($provider));

    expect($response->steps->last()->finishReason)->toBe(FinishReason::ContentFilter)
        ->and($response->structured)->toBe([]);
})->with(['anthropic', 'gemini']);

it('reports an openai refusal as a normal stop with an empty result, visible only in the raw response', function () {
    $response = ProviderFixtures::respond('openai', ProviderFixtures::refusal('openai'));

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop)
        ->and($response->structured)->toBe([])
        ->and($response->text)->toBe('')
        ->and($response->raw->json('output.1.content.0.type'))->toBe('refusal');
});

it('reports an openai answer stopped by the content filter as Length before 1.2.1 and as ContentFilter from 1.2.1, with the reason in the raw response', function () {
    $response = ProviderFixtures::respond('openai', ProviderFixtures::openaiContentFilter());
    $reason = version_compare((string) InstalledVersions::getVersion('laravel/ai'), '1.2.1', '>=') ? FinishReason::ContentFilter : FinishReason::Length;

    expect($response->steps->last()->finishReason)->toBe($reason)
        ->and($response->structured)->toBe([])
        ->and($response->raw->json('incomplete_details.reason'))->toBe('content_filter');
});
