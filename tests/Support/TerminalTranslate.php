<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Console\Signals;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Reach\StatamicTolk\Tests\TestCase;
use Symfony\Component\Console\Output\StreamOutput;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A tolk:translate that asks its question on a terminal, started on a pseudo-terminal through `terminal-translate.php`
 * by tests/Feature/Console/TranslateCommandTest.php. It boots the application of the test suite on the test's runs, with
 * a cache of its own, since Statamic keeps the test's content there, outside the testing environment, so the command
 * asks as it does in a terminal. It translates one entry.
 */
final class TerminalTranslate extends TestCase
{
    public const string SCRIPT = __DIR__.'/terminal-translate.php';

    private static string $directory;

    /**
     * @param  string  $directory  the test's temporary directory
     */
    public static function main(string $directory): int
    {
        self::$directory = $directory;

        $child = new self('translate');
        $child->setUp();

        // No tearDown(): Testbench's needs PHPUnit's runner, and the test deletes the child's files with its own.
        return $child->translate();
    }

    /**
     * Own copy of Testbench's application, inside the test's directory: the child must not share the base path of the
     * process that started it (tests/TestCase.php).
     */
    public static function applicationBasePath()
    {
        $path = self::$directory.'/app';

        if (! is_dir($path)) {
            (new Filesystem)->copyDirectory(default_skeleton_path(), $path);
        }

        return $path;
    }

    protected function temporaryPath(string $path = ''): string
    {
        return rtrim(self::$directory.'/child/'.$path, '/');
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cache.stores.tolk-test-file' => ['driver' => 'file', 'path' => $this->temporaryPath('cache')],
            'cache.default' => 'tolk-test-file',
            'tolk.runs.path' => self::$directory.'/runs',
        ]);
    }

    private function translate(): int
    {
        $this->makeCollection('articles', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeEntry('articles', 'en', ['title' => 'Hello'], 'hello');
        $this->fakeTranslations();

        // In the testing environment Laravel asks Symfony's question instead of Laravel Prompts', and traps no signals.
        $this->app['env'] = 'local';
        Signals::resolveAvailabilityUsing(fn () => true);

        return Artisan::call('tolk:translate', ['--concurrency' => 1, '--collection' => ['articles'], '--site' => ['el']], new StreamOutput(STDOUT));
    }
}
