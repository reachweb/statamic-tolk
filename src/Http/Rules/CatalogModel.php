<?php

namespace Reach\StatamicTolk\Http\Rules;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Validation\ValidationRule;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Support\Settings;

/**
 * A model outside the catalog is accepted only from a user who may edit the addon's settings (§A14). The format is
 * ModelReference's to check.
 */
final readonly class CatalogModel implements ValidationRule
{
    public function __construct(private ?Authorizable $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $model = ModelRef::fromString(is_string($value) ? $value : '');
        } catch (InvalidModelRef) {
            return;
        }

        if (app(ModelCatalog::class)->has($model) || Settings::editableBy($this->user)) {
            return;
        }

        $fail(__('The :attribute must be a model of the catalog.'));
    }
}
