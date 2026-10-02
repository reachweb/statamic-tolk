<?php

namespace Reach\StatamicTolk\Content;

use Reach\StatamicTolk\Content\Markup\Tokenizer;

final class LeafRules
{
    // A URL, a root-relative path, mailto: or tel:, a Statamic reference, a #fragment or hex colour, an email, a class name or key.
    private const array REFERENCES = [
        '/^[a-z][a-z\d+.-]*:\/\/\S*$/i',
        '/^\/\S*$/',
        '/^(?:mailto|tel):\S*$/i',
        '/^(?:entry|asset)::\S+$/',
        '/^#\S+$/',
        '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
        '/^[a-z\d]+(?:[-_][a-z\d]+)+$/',
    ];

    // Antlers tags are carried through untranslated, so only the text around them counts.
    public static function isTranslatable(string $text): bool
    {
        $text = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Tokenizer::withoutAntlers($text)) ?? '';

        if (preg_match('/\p{L}/u', $text) !== 1) {
            return false;
        }

        foreach (self::REFERENCES as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        return true;
    }
}
