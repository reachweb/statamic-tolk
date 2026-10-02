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

    private static ?array $liveEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests();
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        foreach (self::KEYS as $provider => $variable) {
            $app['config']->set("ai.providers.{$provider}.key", $this->liveKey($variable));
        }
    }

    protected function requireProvider(string $provider): void
    {
        $variable = self::KEYS[$provider] ?? null;

        if ($variable === null) {
            $this->fail("Unknown provider [{$provider}].");
        }

        if ($this->liveKey($variable) === null) {
            $this->markTestSkipped("Set {$variable} in the environment or in .env.live to run the live {$provider} tests.");
        }
    }

    private function liveKey(string $variable): ?string
    {
        $value = getenv($variable);

        if (! is_string($value) || $value === '') {
            $value = self::liveEnv()[$variable] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function liveEnv(): array
    {
        if (self::$liveEnv !== null) {
            return self::$liveEnv;
        }

        $path = dirname(__DIR__).'/.env.live';

        return self::$liveEnv = is_file($path) ? Dotenv::parse((string) file_get_contents($path)) : [];
    }
}
