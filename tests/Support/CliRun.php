<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Tests\TestCase;
use RuntimeException;
use Statamic\Facades\Site;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A tolk:translate of tests/Feature/Console/TranslateCommandTest.php in a process of its own, with its tolk:work workers,
 * so a test can end the command as a crash or `kill -9` does. Each process boots the suite's application on the test's
 * directory (Stache stores, blueprints, state, runs) and file cache, with the job the test wrote to `cli-run.json`. The
 * command writes the site's `please` file into its application, which starts each worker through `cli-run.php`. Every
 * process writes its process id to `pids/{role}` and, once its command returned, the exit code to `ended/{role}`; every
 * model call appends the role to `calls.log`.
 */
final class CliRun extends TestCase
{
    public const string SCRIPT = __DIR__.'/cli-run.php';

    /**
     * @var array{directory: string, cache: string, sites: array, options: array<string, mixed>, delay_ms: int, fatal: bool}
     */
    private static array $job;

    /**
     * `command`, or `worker-{lane}`.
     */
    private static string $role;

    /**
     * @param  'command'|'worker'  $role
     * @param  list<string>  $arguments  a worker's: those `please` got
     */
    public static function main(string $directory, string $role, array $arguments): int
    {
        $files = new Filesystem;
        self::$job = json_decode($files->get("{$directory}/cli-run.json"), true, flags: JSON_THROW_ON_ERROR);
        self::$role = $role === 'worker' ? 'worker-'.self::lane($arguments) : 'command';
        $files->ensureDirectoryExists("{$directory}/pids");
        $files->put("{$directory}/pids/".self::$role, (string) getmypid());

        $process = new self(self::$role);
        $process->setUp();
        Site::setSites(self::$job['sites']);
        $process->fakeModel();

        // No tearDown(): Testbench's needs PHPUnit's runner, and the test deletes this process's files with its own.
        $exitCode = $role === 'worker' ? $process->work($arguments) : $process->translate();

        $files->ensureDirectoryExists("{$directory}/ended");
        $files->put("{$directory}/ended/".self::$role, (string) $exitCode);

        return $exitCode;
    }

    /**
     * Own copy of Testbench's application, inside the test's directory, one per process.
     */
    public static function applicationBasePath()
    {
        $path = self::$job['directory'].'/apps/'.self::$role;

        if (! is_dir($path) && ! (new Filesystem)->copyDirectory(default_skeleton_path(), $path)) {
            throw new RuntimeException("Could not copy Testbench's application to [{$path}].");
        }

        return $path;
    }

    /**
     * The test's directory, so the Stache stores, blueprints, fieldsets, state and runs are the test's.
     */
    protected function temporaryPath(string $path = ''): string
    {
        return rtrim(self::$job['directory'].'/'.$path, '/');
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cache.stores.tolk-test-file' => ['driver' => 'file', 'path' => self::$job['cache']],
            'cache.default' => 'tolk-test-file',
        ]);
    }

    private function fakeModel(): void
    {
        $this->fakeTranslations(function (string $text) {
            file_put_contents(self::$job['directory'].'/calls.log', self::$role.PHP_EOL, FILE_APPEND | LOCK_EX);
            usleep(self::$job['delay_ms'] * 1000);

            return '[T] '.$text;
        });
    }

    private function translate(): int
    {
        (new Filesystem)->put(base_path('please'), implode(PHP_EOL, [
            '<?php',
            '',
            '$argv = [$argv[0], '.var_export(self::$job['directory'], true).", 'worker', ...array_slice(\$argv, 1)];",
            'require '.var_export(self::SCRIPT, true).';',
            '',
        ]));

        if (self::$job['fatal']) {
            // A fatal error, which no catch block sees: memory_limit, reached by one large value. Reached a little at a
            // time, it leaves too little memory for Laravel's own shutdown function, which then ends the process before
            // the command's; the workers cancel the run then, as for kill -9.
            Event::listen(TargetTranslated::class, function () {
                ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));

                return str_repeat('x', 64 * 1024 * 1024);
            });
        }

        return Artisan::call('tolk:translate', ['--yes' => true, ...self::$job['options']]);
    }

    /**
     * @param  list<string>  $arguments  `tolk:work`, the run's id and the options, as `please` got them
     */
    private function work(array $arguments): int
    {
        [$command, $run] = $arguments;
        $parameters = ['run' => $run];

        foreach (array_slice($arguments, 2) as $option) {
            [$name, $value] = explode('=', $option, 2);
            $parameters[$name] = $value;
        }

        return Artisan::call($command, $parameters);
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function lane(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--lane=')) {
                return (int) substr($argument, 7);
            }
        }

        return 1;
    }
}
