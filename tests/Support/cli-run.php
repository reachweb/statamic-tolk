<?php

// php cli-run.php {directory} command: the tolk:translate of a test, in a process of its own (CliRun). The command's
// `please` runs `cli-run.php {directory} worker tolk:work {run} --lane=… --parent=…` for each of its workers.

use Reach\StatamicTolk\Tests\Support\CliRun;

require __DIR__.'/../../vendor/autoload.php';

// Laravel's exception handler would report the exception and end the process with 0.
try {
    exit(CliRun::main($argv[1], $argv[2], array_slice($argv, 3)));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);

    exit(3);
}
