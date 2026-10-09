<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Reach\StatamicTolk\Support\Settings;

class UsageResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Settings::editableBy($this->user());
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'date_format:Y-m'],
        ];
    }
}
