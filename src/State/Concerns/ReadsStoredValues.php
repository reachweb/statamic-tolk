<?php

namespace Reach\StatamicTolk\State\Concerns;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * State files live in git and may be edited or merged by hand, so a stored value of the wrong type is read as missing.
 */
trait ReadsStoredValues
{
    private static function storedString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function storedBool(array $data, string $key): bool
    {
        return ($data[$key] ?? false) === true;
    }

    /**
     * @return list<?string>
     */
    private static function storedSegments(array $data): array
    {
        $segments = $data['segments'] ?? [];

        if (! is_array($segments)) {
            return [];
        }

        return array_map(fn (mixed $hash) => is_string($hash) && $hash !== '' ? $hash : null, array_values($segments));
    }

    private static function storedTime(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        try {
            return match (true) {
                // YAML parses an unquoted timestamp to a Unix time.
                is_int($value), is_float($value) => CarbonImmutable::createFromTimestampUTC($value),
                is_string($value) && $value !== '' => CarbonImmutable::parse($value)->utc(),
                default => null,
            };
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private static function timeToString(?CarbonImmutable $time): ?string
    {
        return $time?->toIso8601ZuluString();
    }
}
