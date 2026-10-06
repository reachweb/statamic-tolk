<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Reach\StatamicTolk\Support\Sanitizer;

trait FailsWithJson
{
    /**
     * Ends the request with a JSON error the CP scripts act on by its `code`; the message is sanitised.
     *
     * @param  array<string, mixed>  $data  more keys for the body
     */
    private function fail(int $status, string $code, string $message, ?int $retryAfter = null, array $data = []): never
    {
        $body = ['message' => Sanitizer::message($message), 'code' => $code, ...$data];
        $headers = [];

        if ($retryAfter !== null) {
            $body['retry_after'] = max(1, $retryAfter);
            $headers['Retry-After'] = (string) $body['retry_after'];
        }

        throw new HttpResponseException(response()->json($body, $status, $headers));
    }
}
