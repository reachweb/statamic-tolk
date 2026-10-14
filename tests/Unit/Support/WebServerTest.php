<?php

use Reach\StatamicTolk\Support\WebServer;

it('tells whether a response reaches the browser before the work after it', function (string $sapi, bool $finishes, ?bool $expected) {
    expect((new WebServer($sapi, $finishes))->endsResponsesEarly())->toBe($expected);
})->with([
    'PHP-FPM' => ['fpm-fcgi', true, true],
    'FrankenPHP' => ['frankenphp', true, true],
    'LiteSpeed' => ['litespeed', true, true],
    'Apache mod_php' => ['apache2handler', false, false],
    'php-cgi' => ['cgi-fcgi', false, false],
    'php artisan serve' => ['cli-server', false, false],
    'the command line' => ['cli', false, null],
    'phpdbg' => ['phpdbg', false, null],
]);

it('looks at this process when it is not told', function () {
    expect((new WebServer)->sapi)->toBe(PHP_SAPI)
        ->and((new WebServer)->endsResponsesEarly())->toBeNull();
});
