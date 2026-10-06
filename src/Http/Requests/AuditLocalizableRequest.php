<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Http\FormRequest;
use Reach\StatamicTolk\Support\Settings;

class AuditLocalizableRequest extends FormRequest
{
    /**
     * Changing a blueprint from the audit needs the addon's settings ability and Statamic's permission to edit
     * blueprints (§A14).
     */
    public static function allows(?Authorizable $user): bool
    {
        return Settings::editableBy($user) && $user->can('configure fields');
    }

    public function authorize(): bool
    {
        return self::allows($this->user());
    }

    public function rules(): array
    {
        return [
            'namespace' => ['required', 'string'],
            'blueprint' => ['required', 'string'],
            'fields' => ['required', 'array', 'list'],
            'fields.*' => ['required', 'string', 'distinct'],
        ];
    }
}
