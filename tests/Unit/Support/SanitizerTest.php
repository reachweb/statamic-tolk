<?php

use Reach\StatamicTolk\Support\Sanitizer;

it('redacts what looks like an API key or a bearer token', function (string $message, string $expected) {
    expect(Sanitizer::message($message))->toBe($expected);
})->with([
    'openai key' => ['Incorrect API key provided: sk-proj-Ab12Cd34Ef56Gh78Ij90.', 'Incorrect API key provided: [redacted].'],
    'anthropic key' => ['Key sk-ant-api03-Zx9Yw8Vu7Ts6Rq5Po4Nm3 is invalid', 'Key [redacted] is invalid'],
    'google key' => ['API key AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q not valid.', 'API key [redacted] not valid.'],
    'bearer token' => ['Authorization: Bearer abc.DEF-123_ghi', 'Authorization: Bearer [redacted]'],
    'header' => ['x-api-key: 0123456789abcdef', 'x-api-key: [redacted]'],
    'json field' => ['{"api_key": "0123456789abcdef"}', '{"api_key": "[redacted]"}'],
    'query parameter' => ['GET https://example.com/v1/models?key=whatever&alt=json', 'GET https://example.com/v1/models?key=[redacted]&alt=json'],
]);

it('redacts the configured keys even when they match no pattern', function () {
    config([
        'ai.providers.gemini.key' => 'plain-gemini-secret-0451',
        'ai.providers.mistral.key' => 'mistral-secret-9876',
        'tolk.providers.openai.key' => '  tolk-openai-secret-1234  ',
    ]);

    expect(Sanitizer::message('Keys plain-gemini-secret-0451, mistral-secret-9876 and tolk-openai-secret-1234 failed.'))
        ->toBe('Keys [redacted], [redacted] and [redacted] failed.');
});

it('leaves ordinary messages alone', function (string $message) {
    expect(Sanitizer::message($message))->toBe($message);
})->with([
    'Rate limit reached for requests.',
    'Your credit balance is too low to access the Anthropic API.',
    'Invalid value for field: authorization required.',
    'The model `gpt-6.1-sol` does not exist or you do not have access to it.',
]);

it('collapses whitespace and caps the length', function () {
    $message = Sanitizer::message("Line one\n\n  line two ".str_repeat('x', 1000));

    expect($message)->toStartWith('Line one line two xxx')
        ->and(mb_strlen($message))->toBe(Sanitizer::MAX_LENGTH + 1)
        ->and($message)->toEndWith('…');
});
