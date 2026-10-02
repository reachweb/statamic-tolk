<?php

namespace Reach\StatamicTolk;

use Reach\StatamicTolk\Support\Settings;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/tolk.php', 'tolk');

        $this->app->scoped(Settings::class);
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/tolk.php' => config_path('tolk.php'),
        ], 'tolk-config');
    }
}
