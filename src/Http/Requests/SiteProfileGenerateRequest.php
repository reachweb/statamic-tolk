<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Http\Rules\ModelReference;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Facades\Addon;

class SiteProfileGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('editSettings', Addon::get(Settings::PACKAGE));
    }

    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string', new ModelReference],
        ];
    }

    public function attributes(): array
    {
        return ['model' => __('translator model')];
    }

    /**
     * The translator the settings form holds, saved or not: an empty `model` is the automatic choice. Without the key,
     * null lets the saved settings decide.
     *
     * @throws NoProviderConfigured
     */
    public function translator(ModelCatalog $catalog): ?ModelRef
    {
        if (! $this->exists('model')) {
            return null;
        }

        return $this->filled('model')
            ? ModelRef::fromString(trim((string) $this->validated('model')))
            : $catalog->automaticTranslator();
    }
}
