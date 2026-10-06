<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Support\Settings;

class ProviderTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Settings::editableBy($this->user());
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(ModelRef::PROVIDERS)],
        ];
    }
}
