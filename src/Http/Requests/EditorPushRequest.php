<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Support\Settings;

class EditorPushRequest extends FormRequest
{
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
