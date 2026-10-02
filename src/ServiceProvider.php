<?php

namespace Reach\StatamicTolk;

use Reach\StatamicTolk\Support\Settings;
use Statamic\Facades\Permission;
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

        $this->registerPermissions();
    }

    private function registerPermissions(): void
    {
        Permission::extend(fn () => Permission::group('tolk', 'Tolk', function () {
            Permission::register('translate content', fn ($permission) => $permission
                ->label(__('Translate content'))
                ->description(__('Translate one item at a time, in the editor or from its listing. Site and edit permissions still apply.')));

            Permission::register('run bulk translations', fn ($permission) => $permission
                ->label(__('Run bulk translations'))
                ->description(__('Start and manage bulk translation runs from listings, the overview and the runs page.')));
        }));
    }
}
