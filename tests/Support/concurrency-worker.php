<?php

// php concurrency-worker.php {directory} {lane} {workers} {claims} {run id} …: one process of tests/Feature/ConcurrencyTest.php.

use Reach\StatamicTolk\Tests\Support\ConcurrencyWorker;

require __DIR__.'/../../vendor/autoload.php';

// Laravel's exception handler would report the exception and end the process with 0.
try {
    ConcurrencyWorker::main($argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4], array_slice($argv, 5));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);

    exit(1);
}
