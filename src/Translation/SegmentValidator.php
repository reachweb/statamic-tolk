<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Enums\SegmentKind;

/**
 * Checks one translated segment. A problem is phrased for the model, because it becomes the note of a repair (§A10).
 */
final class SegmentValidator
{
    /**
     * @param  array{text: string, kind: string, max_length?: ?int}  $segment
     */
    public function validate(array $segment, string $translation): ?string
    {
        $problems = array_filter([$this->placeholders($segment, $translation), $this->length($segment, $translation)]);

        return $problems === [] ? null : implode(' ', $problems);
    }

    /**
     * The rules of §A3.
     */
    public function placeholders(array $segment, string $translation): ?string
    {
        return Placeholders::validate((string) $segment['text'], $translation, SegmentKind::from($segment['kind']));
    }

    /**
     * The segment's `max_length`, counted on the text a reader sees: placeholders do not count, and an escaped
     * character counts once.
     */
    public function length(array $segment, string $translation): ?string
    {
        $max = $segment['max_length'] ?? null;

        if (! is_int($max) || $max < 1) {
            return null;
        }

        $visible = Placeholders::strip($translation);
        $length = mb_strlen(SegmentKind::from($segment['kind'])->escapes() ? Tokenizer::unescape($visible) : $visible);

        return $length > $max ? "The translation has {$length} characters; it must not exceed {$max}." : null;
    }
}
