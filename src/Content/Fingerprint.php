<?php

namespace Reach\StatamicTolk\Content;

final class Fingerprint
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function of(mixed $value): string
    {
        return substr(hash('sha256', json_encode(self::canonical($value), self::JSON_FLAGS)), 0, 16);
    }

    public static function ofLeaves(array $texts): string
    {
        return substr(hash('sha256', json_encode(array_values($texts), self::JSON_FLAGS)), 0, 16);
    }

    public static function ofText(string $text): string
    {
        return substr(hash('sha256', $text), 0, 12);
    }

    public static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? array_map(self::canonical(...), $value)
                : self::canonicalMap($value);
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::decimal($value),
            is_string($value) => str_replace("\r\n", "\n", $value),
            default => self::canonical(json_decode(json_encode($value, self::JSON_FLAGS), true, flags: JSON_THROW_ON_ERROR)),
        };
    }

    private static function canonicalMap(array $map): array
    {
        ksort($map, SORT_STRING);

        $canonical = [];

        foreach ($map as $key => $item) {
            $item = self::canonical($item);

            if ($item !== null && $item !== '' && $item !== []) {
                $canonical[$key] = $item;
            }
        }

        return $canonical;
    }

    private static function decimal(float $number): string
    {
        if (! is_finite($number)) {
            return (string) $number;
        }

        // The shortest scientific form that parses back to the same float, independent of the precision ini settings.
        for ($precision = 0; $precision < 17; $precision++) {
            $scientific = sprintf("%.{$precision}e", $number);

            if ((float) $scientific === $number) {
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', $scientific);
        $digits = ltrim(str_replace('.', '', $mantissa), '-');
        $point = 1 + (int) $exponent;

        $plain = match (true) {
            $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)),
            default => substr($digits, 0, $point).'.'.substr($digits, $point),
        };

        if (str_contains($plain, '.')) {
            $plain = rtrim(rtrim($plain, '0'), '.');
        }

        return ($number < 0 && $plain !== '0' ? '-' : '').$plain;
    }
}
