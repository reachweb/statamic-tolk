<?php

namespace Reach\StatamicTolk\Http\Rules;

use Closure;
use Reach\StatamicTolk\Support\Settings;

/**
 * A glossary row needs a translation or the do-not-translate toggle; `Settings::glossary()` drops a row with neither.
 */
final class GlossaryTranslation extends GridRowRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $row = $this->row($attribute);

        if (Settings::text($value) === '' && ! filter_var($row['do_not_translate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $fail(__('Enter a translation, or turn on Do not translate.'));
        }
    }
}
