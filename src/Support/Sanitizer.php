<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Support\Str;

/**
 * The one sanitiser for provider messages (rule 13): every message that is stored or returned passes through it.
 */
final class Sanitizer
{
    public const int MAX_LENGTH = 500;

    public const string REDACTED = '[redacted]';

    private const array PATTERNS = [
        '/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i',
        '/\b((?:x-api-key|x-goog-api-key|api[_-]?key|access[_-]?token|authorization)["\']?\s*[:=]\s*["\']?)[^\s"\',;}&]{8,}/i',
        '/([?&](?:key|api_key|access_token)=)[^&\s"\']+/i',
        '/()\b(?:sk|pk|rk)-[A-Za-z0-9_-]{16,}/',
        '/()\bAIza[0-9A-Za-z_-]{20,}/',
    ];

    public static function message(string $text): string
    {
        $text = str_replace(self::configuredKeys(), self::REDACTED, $text);
        $text = preg_replace(self::PATTERNS, '$1'.self::REDACTED, $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return Str::limit($text, self::MAX_LENGTH, '…');
    }

    /**
     * @return list<string>
     */
    private static function configuredKeys(): array
    {
        return collect([config('ai.providers', []), config('tolk.providers', [])])
            ->flatMap(fn ($providers) => is_array($providers) ? array_values($providers) : [])
            ->map(fn ($provider) => is_array($provider) ? ($provider['key'] ?? null) : null)
            ->filter(fn ($key) => is_string($key) && strlen(trim($key)) >= 8)
            ->map(fn (string $key) => trim($key))
            ->unique()
            ->sortByDesc(fn (string $key) => strlen($key))
            ->values()
            ->all();
    }
}
