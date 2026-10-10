<?php

namespace Reach\StatamicTolk\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\AiServiceProvider;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\ServiceProvider;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Tests\Support\CreatesContent;
use Reach\StatamicTolk\Tests\Support\FakesTranslation;
use ReflectionClass;
use ReflectionProperty;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Addon;
use Statamic\Facades\Path;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Licensing\Outpost;
use Statamic\Statamic;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use CreatesContent;
    use FakesRoles;
    use FakesTranslation;
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    private ?string $temporaryDirectory = null;

    private static ?string $processBasePath = null;

    protected function setUp(): void
    {
        self::resetStatamic();

        parent::setUp();

        $this->deleteAddonSettingsFile();

        Http::preventStrayRequests();

        Site::setSites([
            'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
            'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
        ]);
        Site::setCurrent('en');

        $this->spy(Outpost::class);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->deleteAddonSettingsFile();
        }

        // Finally: Testbench throws an exception from a beforeApplicationDestroyed() callback at the end of its tearDown().
        try {
            parent::tearDown();
        } finally {
            if ($this->temporaryDirectory) {
                (new Filesystem)->deleteDirectory($this->temporaryDirectory);
                $this->temporaryDirectory = null;
            }
        }
    }

    /**
     * Each process of a parallel run (`--parallel`) gets its own copy of Testbench's application, whose `resources/`
     * holds the addon's settings file and whose `storage/` holds compiled views, so the processes do not share them.
     */
    public static function applicationBasePath()
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

        if (! is_string($token) || $token === '') {
            return parent::applicationBasePath();
        }

        if (self::$processBasePath === null) {
            $files = new Filesystem;
            $path = sys_get_temp_dir().'/tolk-testbench/'.$token;

            $files->deleteDirectory($path);
            $files->copyDirectory(parent::applicationBasePath(), $path);

            self::$processBasePath = $path;
        }

        return self::$processBasePath;
    }

    /**
     * The Stache stores write into the test's temporary directory instead of the shared `tests/__fixtures__/dev-null`,
     * which tearDown() deletes with the rest.
     */
    protected function preventSavingStacheItemsToDisk(): void
    {
        $fixtures = Str::before(Path::tidy($this->fakeStacheDirectory), '/dev-null');
        $this->fakeStacheDirectory = $this->temporaryPath('stache');

        Stache::stores()->each(function ($store) use ($fixtures) {
            $relative = Str::after(Str::after('/'.Path::makeRelative($store->directory()), $fixtures), '/');

            $store->directory($this->fakeStacheDirectory.'/'.$relative);
        });
    }

    protected function deleteFakeStacheDirectory(): void
    {
        //
    }

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'app.key' => 'base64:5K509AaEsr0XODM8D3YFrY6a5WJv9z0oC+z6In8Jbps=',
            'statamic.editions.pro' => true,
            'statamic.system.multisite' => true,
            'statamic.search.indexes' => [
                'default' => ['driver' => 'null'],
                'cp' => ['driver' => 'null'],
            ],
            'ai.providers.anthropic.key' => 'test-key',
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.gemini.key' => 'test-key',
            'statamic.system.blueprints_path' => $this->temporaryPath('blueprints'),
            'statamic.system.fieldsets_path' => $this->temporaryPath('fieldsets'),
            'statamic.forms.forms' => $this->temporaryPath('forms'),
            'statamic.users.repositories.file.paths.roles' => $this->temporaryPath('users/roles.yaml'),
            'statamic.users.repositories.file.paths.groups' => $this->temporaryPath('users/groups.yaml'),
            'statamic.stache.stores.revisions.directory' => $this->temporaryPath('revisions'),
            'tolk.providers.anthropic.key' => null,
            'tolk.providers.openai.key' => null,
            'tolk.providers.gemini.key' => null,
            'tolk.fake_engine' => false,
            'tolk.state.path' => $this->temporaryPath('state'),
            'tolk.runs.path' => $this->temporaryPath('runs'),
            'tolk.usage.path' => $this->temporaryPath('usage'),
        ]);
    }

    protected function temporaryPath(string $path = ''): string
    {
        $this->temporaryDirectory ??= sys_get_temp_dir().'/tolk-tests/'.Str::random(16);

        return rtrim($this->temporaryDirectory.'/'.$path, '/');
    }

    /**
     * Clears every provider key, in both configs, then sets the given config values.
     */
    protected function withProviderKeys(array $keys = []): void
    {
        foreach (ModelRef::PROVIDERS as $provider) {
            config([
                "ai.providers.{$provider}.key" => null,
                "tolk.providers.{$provider}.key" => null,
            ]);
        }

        config($keys);
    }

    /**
     * Saves the given addon settings and returns the Settings instance, freshly read.
     */
    protected function withSettings(array $values = []): Settings
    {
        Addon::get(Settings::PACKAGE)->settings()->set($values)->save();

        return app(Settings::class)->fresh();
    }

    protected function useFileCache(): void
    {
        config([
            'cache.stores.tolk-test-file' => [
                'driver' => 'file',
                'path' => $this->temporaryPath('cache'),
            ],
            'cache.default' => 'tolk-test-file',
        ]);

        $this->app->forgetInstance('cache.store');
    }

    protected function signInAdmin(): UserContract
    {
        return $this->signIn(User::make()->email('admin@example.com')->makeSuper());
    }

    protected function signInWithPermissions(array $permissions): UserContract
    {
        $this->setTestRoles(['tolk_test' => $permissions]);

        return $this->signIn(User::make()->email('editor@example.com')->assignRole('tolk_test'));
    }

    protected function signInUserWithoutPermissions(): UserContract
    {
        return $this->signIn(User::make()->email('user@example.com'));
    }

    private function signIn(UserContract $user): UserContract
    {
        $user->save();

        $this->actingAs($user);

        return $user;
    }

    /**
     * Statamic keeps what providers register on boot (the addon's routes, scripts, script data, booted callbacks) in
     * static properties, which outlive each test's application. Without a reset, test N registers the addon's routes N
     * times, and every earlier application stays in memory through the providers those closures are bound to.
     */
    private static function resetStatamic(): void
    {
        foreach ((new ReflectionClass(Statamic::class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if ($property->hasDefaultValue()) {
                $property->setValue(null, $property->getDefaultValue());
            }
        }
    }

    private function deleteAddonSettingsFile(): void
    {
        (new Filesystem)->delete(resource_path('addons/statamic-tolk.yaml'));
    }
}
