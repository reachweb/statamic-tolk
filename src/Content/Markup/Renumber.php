<?php

namespace Reach\StatamicTolk\Content\Markup;

use Reach\StatamicTolk\Content\Leaf;

final class Renumber
{
    private const string IDS = '/<(\/?)(\d+)>|<(x\d+)\/>/';

    public static function like(Leaf $target, Leaf $source): ?string
    {
        $ids = self::correspondence($target->placeholders, $source->placeholders);

        return $ids === null ? null : self::rewrite($target->text, $ids);
    }

    public static function back(string $text, Leaf $target, Leaf $source): ?string
    {
        $ids = self::correspondence($target->placeholders, $source->placeholders);

        return $ids === null ? null : self::rewrite($text, array_flip($ids));
    }

    /**
     * Pairs each target placeholder with the first unused source placeholder of the same type and descriptor.
     *
     * @return array<string, string>|null
     */
    private static function correspondence(array $target, array $source): ?array
    {
        $ids = [];

        foreach ($target as $id => $descriptor) {
            $match = null;

            foreach ($source as $candidate => $candidateDescriptor) {
                if ($candidateDescriptor === $descriptor && self::isAtom($candidate) === self::isAtom($id)) {
                    $match = $candidate;

                    break;
                }
            }

            if ($match === null) {
                return null;
            }

            $ids[(string) $id] = (string) $match;
            unset($source[$match]);
        }

        return $ids;
    }

    private static function rewrite(string $text, array $ids): ?string
    {
        $complete = true;

        $rewritten = preg_replace_callback(self::IDS, function (array $match) use ($ids, &$complete): string {
            $id = $match[3] ?? $match[2];

            if (! isset($ids[$id])) {
                $complete = false;

                return $match[0];
            }

            return $match[3] !== null ? "<{$ids[$id]}/>" : "<{$match[1]}{$ids[$id]}>";
        }, $text, flags: PREG_UNMATCHED_AS_NULL);

        return $complete ? $rewritten : null;
    }

    private static function isAtom(string|int $id): bool
    {
        return str_starts_with((string) $id, 'x');
    }
}
