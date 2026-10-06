<?php

namespace Reach\StatamicTolk\Fieldtypes;

use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\Providers;
use Statamic\Fields\Fieldtype;

/**
 * Shows whether each provider has an API key and offers a connection test. It stores nothing, and never sends any part
 * of a key to the form.
 */
class ProvidersStatus extends Fieldtype
{
    protected static $handle = 'tolk_providers';

    protected $selectable = false;

    protected $validatable = false;

    protected $defaultable = false;

    public function preload(): array
    {
        return [
            'providers' => app(Providers::class)->statuses(),
            'fake_engine' => FakeEngine::enabled(),
        ];
    }

    public function preProcess($data): mixed
    {
        return null;
    }

    public function process($data): mixed
    {
        return null;
    }
}
