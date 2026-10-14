<?php

namespace Reach\StatamicTolk\Support;

/**
 * What this PHP process can tell about the web server that runs it: whether a response reaches the browser before the
 * process runs what it was given to do after the response, such as an automatic update without a queue worker (T11.1).
 * Symfony's Response::send() ends the connection only through fastcgi_finish_request() (PHP-FPM, FrankenPHP) or
 * litespeed_finish_request() (LiteSpeed); elsewhere it can only flush, and the browser waits for the process to end.
 */
final readonly class WebServer
{
    /**
     * @param  bool|null  $finishesRequests  whether fastcgi_finish_request() or litespeed_finish_request() exists, null
     *                                       to look
     */
    public function __construct(
        public string $sapi = PHP_SAPI,
        private ?bool $finishesRequests = null,
    ) {}

    /**
     * True when the response is sent before the work after it, false when the browser waits for that work (Apache's
     * mod_php, php-cgi, IIS, php artisan serve), null on the command line, where there is no web server to look at.
     */
    public function endsResponsesEarly(): ?bool
    {
        if ($this->finishesRequests ?? (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
            return true;
        }

        return in_array($this->sapi, ['cli', 'phpdbg', 'embed'], true) ? null : false;
    }
}
