<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Facades\Addon;

class ProviderTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('editSettings', Addon::get(Settings::PACKAGE));
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(ModelRef::PROVIDERS)],
        ];
    }
}
