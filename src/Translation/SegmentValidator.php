<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Enums\SegmentKind;

/**
 * Checks one translated segment. A problem is phrased for the model, because it becomes the note of a repair (§A10).
 */
final class SegmentValidator
{
    /**
     * @param  array{text: string, kind: string, max_length?: ?int, plural_form?: bool}  $segment
     */
    public function validate(array $segment, string $translation): ?string
    {
        return $this->join([$this->structure($segment, $translation), $this->length($segment, $translation)]);
    }

    /**
     * What makes a translation unusable, so that it gets a repair and then fails (§A10): the placeholder rules, and a
     * `|` in one form of a pluralised lang string, which would add a form and shift the forms after it (§A16).
     */
    public function structure(array $segment, string $translation): ?string
    {
        return $this->join([$this->placeholders($segment, $translation), $this->pluralForm($segment, $translation)]);
    }

    /**
     * The rules of §A3.
     */
    public function placeholders(array $segment, string $translation): ?string
    {
        return Placeholders::validate((string) $segment['text'], $translation, SegmentKind::from($segment['kind']));
    }

    public function pluralForm(array $segment, string $translation): ?string
    {
        return ($segment['plural_form'] ?? false) === true && str_contains($translation, '|')
            ? 'The translation contains |, which divides this string into its parts. Translate only this part, without a | character.'
            : null;
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

        $length = mb_strlen(Placeholders::visible($translation, SegmentKind::from($segment['kind'])));

        return $length > $max ? "The translation has {$length} characters; it must not exceed {$max}." : null;
    }

    /**
     * @param  list<?string>  $problems
     */
    private function join(array $problems): ?string
    {
        $problems = array_filter($problems);

        return $problems === [] ? null : implode(' ', $problems);
    }
}
