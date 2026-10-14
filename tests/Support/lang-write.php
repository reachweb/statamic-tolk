<?php

// php -d {ini} lang-write.php < {write as JSON}: LangFiles::write() in a process of its own, which
// tests/Feature/Support/LangFilesTest.php starts with an ini setting a running process cannot change. It boots no addon:
// a Laravel application on the test's lang path, with Laravel's error handling, which throws a warning as an exception.
// It prints the keys write() dropped.

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Reach\StatamicTolk\Support\LangFiles;

require __DIR__.'/../../vendor/autoload.php';

try {
    $write = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $app = new Application($write['directory']);
    $app['env'] = 'testing';
    $app->useLangPath($write['directory'].'/lang');
    (new HandleExceptions)->bootstrap($app);
    $files = new LangFiles(new Filesystem);

    echo json_encode($files->write($write['lang'], $write['group'], $write['set'], [], $files->contents($write['from'], $write['group'])), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);

    exit(1);
}
