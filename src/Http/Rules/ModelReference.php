<?php

namespace Reach\StatamicTolk\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;

/**
 * Checks the `provider:model` format only: whether a model outside the catalog may be used depends on the user (§A14).
 */
final class ModelReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            ModelRef::fromString(is_string($value) ? $value : '');
        } catch (InvalidModelRef) {
            $fail(__('The :attribute must name a model as provider:model.'));
        }
    }
}
