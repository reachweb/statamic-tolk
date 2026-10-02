<?php

it('reaches the live suite', function (string $provider) {
    $this->requireProvider($provider);

    expect(config("ai.providers.{$provider}.key"))->not->toBe('test-key');
})->with(['anthropic', 'openai', 'gemini']);
