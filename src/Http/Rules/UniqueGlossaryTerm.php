<?php

namespace Reach\StatamicTolk\Http\Rules;

use Closure;
use Reach\StatamicTolk\Support\Settings;

/**
 * Refuses a second row with the same term (exact, after trimming) for the same site, or for every site: the glossary
 * keeps both and the prompt would show two lines for one term. A site's row may override a row for every site.
 */
final class UniqueGlossaryTerm extends GridRowRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $term = Settings::text($value);
        $site = Settings::siteOf($this->row($attribute));

        $taken = collect($this->rowsBefore($attribute))->contains(
            fn (array $row) => Settings::text($row['term'] ?? null) === $term && Settings::siteOf($row) === $site
        );

        if (! $taken) {
            return;
        }

        $fail($site === null
            ? __('":term" is already in the glossary for every language.', ['term' => $term])
            : __('":term" is already in the glossary for :site.', ['term' => $term, 'site' => self::siteName($site)]));
    }
}
