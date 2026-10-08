<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Http\Requests\Concerns\ChecksSiteAccess;
use Reach\StatamicTolk\Support\Settings;

/**
 * A site the user may not access is refused; one the banner does not list fails in the controller.
 */
class EditorPushRequest extends FormRequest
{
    use ChecksSiteAccess;

    public function authorize(): bool
    {
        return self::mayAccessSites($this->user(), (array) $this->input('sites', []));
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
            'sites' => ['required', 'list', 'min:1'],
            'sites.*' => ['required', 'string', 'distinct'],
            'mode' => ['required', Rule::enum(Mode::class)->except([Mode::Review])],
            'overwrite_manual' => ['boolean'],
            'review' => ['nullable', 'boolean'],
            'new_status' => ['nullable', Rule::in(Settings::NEW_LOCALIZATION_STATUSES)],
        ];
    }
}
