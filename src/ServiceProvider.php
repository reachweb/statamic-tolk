<?php

namespace Reach\StatamicTolk;

use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\TranslationEngine;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\ArrayHandler;
use Reach\StatamicTolk\Content\Handlers\BardHandler;
use Reach\StatamicTolk\Content\Handlers\GridHandler;
use Reach\StatamicTolk\Content\Handlers\GroupHandler;
use Reach\StatamicTolk\Content\Handlers\ListHandler;
use Reach\StatamicTolk\Content\Handlers\MarkdownHandler;
use Reach\StatamicTolk\Content\Handlers\ReplicatorHandler;
use Reach\StatamicTolk\Content\Handlers\TableHandler;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Runs\FileRunRepository;
use Reach\StatamicTolk\State\FileStateRepository;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Facades\Addon;
use Statamic\Facades\Permission;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    private const array HANDLERS = [
        'text' => TextHandler::class,
        'textarea' => TextHandler::class,
        'markdown' => MarkdownHandler::class,
        'bard' => BardHandler::class,
        'replicator' => ReplicatorHandler::class,
        'grid' => GridHandler::class,
        'group' => GroupHandler::class,
        'table' => TableHandler::class,
        'list' => ListHandler::class,
        'array' => ArrayHandler::class,
    ];

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
        $this->app->singleton(FieldtypeRegistry::class, fn ($app) => $this->builtInHandlers(new FieldtypeRegistry($app)));
        $this->app->singleton(StateRepository::class, FileStateRepository::class);
        $this->app->singleton(RunRepository::class, FileRunRepository::class);
        $this->app->bind(Engine::class, fn ($app) => $app->make(FakeEngine::enabled() ? FakeEngine::class : TranslationEngine::class));
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/tolk.php' => config_path('tolk.php'),
        ], 'tolk-config');

        $this->registerPermissions();
        $this->registerFieldtypes();
        $this->provideScriptData();
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

    // The built-in handlers come with the registry, so an addon that boots before this one (Tolk::registerFieldtype()) can replace them.
    private function builtInHandlers(FieldtypeRegistry $registry): FieldtypeRegistry
    {
        foreach (self::HANDLERS as $handle => $handlerClass) {
            $registry->register($handle, $handlerClass);
        }

        return $registry;
    }

    private function registerFieldtypes(): void
    {
        $registry = $this->app->make(FieldtypeRegistry::class);

        foreach (config('tolk.fieldtypes', []) as $handle => $handlerClass) {
            $registry->register($handle, $handlerClass);
        }

        $registry->appendConfigFields();
    }

    private function provideScriptData(): void
    {
        Statamic::provideToScript(['tolk' => fn () => [
            'fieldtypes' => app(FieldtypeRegistry::class)->handles(),
            'concurrency' => max(1, (int) config('tolk.concurrency.editor')),
            'multisite' => Site::multiEnabled() && Site::hasMultiple(),
            'fakeEngine' => FakeEngine::enabled(),
            'settingsUrl' => $this->settingsUrl(),
        ]]);
    }

    private function settingsUrl(): ?string
    {
        $addon = Addon::get(Settings::PACKAGE);

        return $addon && User::current()?->can('editSettings', $addon) ? $addon->settingsUrl() : null;
    }
}
