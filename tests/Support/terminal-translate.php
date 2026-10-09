<?php

// php terminal-translate.php {directory}: the tolk:translate of tests/Feature/Console/TranslateCommandTest.php that asks
// on a pseudo-terminal. It exits with the command's exit code.

use Reach\StatamicTolk\Tests\Support\TerminalTranslate;

require __DIR__.'/../../vendor/autoload.php';

// Laravel's exception handler would report the exception and end the process with 0.
try {
    exit(TerminalTranslate::main($argv[1]));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);

    exit(3);
}
