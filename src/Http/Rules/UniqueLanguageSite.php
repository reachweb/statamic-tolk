<?php

namespace Reach\StatamicTolk\Http\Rules;

use Closure;
use Reach\StatamicTolk\Support\Settings;

/**
 * Refuses a second language instruction row for a site: `Settings::languageInstructions()` would use the first only.
 */
final class UniqueLanguageSite extends GridRowRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $site = Settings::siteOf(['site' => $value]);

        if ($site === null) {
            return;
        }

        if (collect($this->rowsBefore($attribute))->contains(fn (array $row) => Settings::siteOf($row) === $site)) {
            $fail(__(':site already has a row. Each language can have one.', ['site' => self::siteName($site)]));
        }
    }
}
