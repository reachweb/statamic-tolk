<?php

namespace Reach\StatamicTolk\Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Http;

abstract class LiveTestCase extends TestCase
{
    private const array KEYS = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests();
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $path = dirname(__DIR__).'/.env.live';
        $file = is_file($path) ? Dotenv::parse((string) file_get_contents($path)) : [];

        foreach (self::KEYS as $provider => $variable) {
            $key = getenv($variable) ?: ($file[$variable] ?? null);

            $app['config']->set("ai.providers.{$provider}.key", $key ?: null);
        }
    }

    protected function requireProvider(string $provider): void
    {
        $variable = self::KEYS[$provider] ?? null;

        if ($variable === null) {
            $this->fail("Unknown provider [{$provider}].");
        }

        if (config("ai.providers.{$provider}.key") === null) {
            $this->markTestSkipped("Set {$variable} in the environment or in .env.live to run the live {$provider} tests.");
        }
    }
}
