<?php

namespace Reach\StatamicTolk\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\AiServiceProvider;
use Reach\StatamicTolk\ServiceProvider;
use Reach\StatamicTolk\Tests\Support\CreatesContent;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Licensing\Outpost;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use CreatesContent;
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    private ?string $temporaryDirectory = null;

    protected function setUp(): void
    {
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

        parent::tearDown();

        if ($this->temporaryDirectory) {
            (new Filesystem)->deleteDirectory($this->temporaryDirectory);
            $this->temporaryDirectory = null;
        }
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
        foreach (['anthropic', 'openai', 'gemini'] as $provider) {
            config([
                "ai.providers.{$provider}.key" => null,
                "tolk.providers.{$provider}.key" => null,
            ]);
        }

        config($keys);
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

    private function deleteAddonSettingsFile(): void
    {
        (new Filesystem)->delete(resource_path('addons/statamic-tolk.yaml'));
    }
}
