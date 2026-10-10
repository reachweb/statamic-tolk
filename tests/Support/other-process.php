<?php

// php other-process.php < {job as JSON}: the other process of CreatesContent's …Elsewhere() helpers (OtherProcess).

use Reach\StatamicTolk\Tests\Support\OtherProcess;

require __DIR__.'/../../vendor/autoload.php';

// Laravel's exception handler would report the exception and end the process with 0.
try {
    echo json_encode(OtherProcess::main(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);

    exit(1);
}
