<?php

namespace Reach\StatamicTolk\Fieldtypes;

use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Http\Rules\AntlersText;
use Reach\StatamicTolk\Http\Rules\ModelReference;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Fields\Fieldtype;

/**
 * A model setting, stored as `provider:model`, or null for the automatic choice of its role.
 */
class ModelSelect extends Fieldtype
{
    protected static $handle = 'tolk_model';

    protected $selectable = false;

    protected function configFieldItems(): array
    {
        return [
            'role' => [
                'display' => __('Role'),
                'instructions' => __('The model setting this field holds, which decides what its automatic choice is.'),
                'type' => 'select',
                'options' => [
                    'translator' => __('Translator'),
                    'reviewer' => __('Reviewer'),
                    'fallback' => __('Fallback'),
                ],
                'default' => 'translator',
            ],
        ];
    }

    public function preload(): array
    {
        return [
            'catalog' => app(ModelCatalog::class)->grouped(),
            'automatic' => $this->automaticLabel(),
            'fake_engine' => FakeEngine::enabled(),
        ];
    }

    public function preProcess($data): ?string
    {
        return self::reference($data);
    }

    public function process($data): ?string
    {
        return self::reference($data);
    }

    public function preProcessValidatable($value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    public function rules(): array
    {
        return [new ModelReference, new AntlersText];
    }

    private function automaticLabel(): string
    {
        if ($this->config('role') === 'fallback') {
            return __('No fallback');
        }

        $catalog = app(ModelCatalog::class);

        try {
            $model = $this->config('role') === 'reviewer'
                ? $catalog->automaticReviewer($catalog->resolveTranslator(app(Settings::class)))
                : $catalog->automaticTranslator();
        } catch (NoProviderConfigured|MissingDefaultModel|InvalidModelRef) {
            return __('Automatic');
        }

        return __('Automatic (:model)', ['model' => $catalog->label($model)]);
    }

    private static function reference(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
