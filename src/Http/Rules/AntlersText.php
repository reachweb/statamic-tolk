<?php

namespace Reach\StatamicTolk\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Statamic\Facades\Antlers;
use Statamic\View\Cascade;
use Throwable;

/**
 * Refuses text that core cannot parse as Antlers. Core parses every addon setting as Antlers when it saves and when it
 * reads them (`Statamic\Addons\Settings::resolveAntlersValue()`), so such text fails the save, after validation.
 */
final class AntlersText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (Arr::flatten(Arr::wrap($value)) as $text) {
            if (! is_string($text)) {
                continue;
            }

            try {
                Antlers::parse($text, ['config' => Cascade::config()]);
            } catch (Throwable) {
                $fail(__('Statamic reads {{ and }} in settings as Antlers, and cannot read this text. To keep them as written, put the whole text between {{ noparse }} and {{ /noparse }}.'));

                return;
            }
        }
    }
}
