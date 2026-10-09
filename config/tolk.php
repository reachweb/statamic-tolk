<?php

/*
| Developer config for Tolk. Everything an editor may change (models, review,
| instructions, glossary, exclusions) is a setting in the Control Panel under
| Tolk, stored in resources/addons/statamic-tolk.yaml, and does not belong here.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Provider keys
    |--------------------------------------------------------------------------
    |
    | Optional overrides. When a key or url is empty, Tolk uses the one in
    | config/ai.php (ai.providers.{provider}.key and .url). Keys come from the
    | environment only; they are never stored in settings.
    |
    */

    'providers' => [
        'anthropic' => [
            'key' => env('TOLK_ANTHROPIC_API_KEY'),
            'url' => null,
        ],
        'openai' => [
            'key' => env('TOLK_OPENAI_API_KEY'),
            'url' => null,
        ],
        'gemini' => [
            'key' => env('TOLK_GEMINI_API_KEY'),
            'url' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Tolk's model catalog ships with Tolk and is updated with it. List models
    | here only to add one or to change one of Tolk's: an entry with the id of
    | a Tolk model replaces the keys it names and keeps the others. Models you
    | add follow Tolk's, so Tolk's first model of each tier stays the default.
    | Each entry has an `id` and any of `label`, `tier` (quality or fast),
    | `options`, `max_output_tokens`, `reasoning_headroom` and `price` (USD
    | per million tokens: input, output, cache_read, cache_write). `options`
    | are merged into the request, so they must never contain `output_config`
    | (Anthropic), `text` (OpenAI) or `response_format` (Gemini), nor
    | temperature, top_p or top_k.
    |
    */

    'models' => [
        'anthropic' => [],
        'openai' => [],
        'gemini' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Requests and batches
    |--------------------------------------------------------------------------
    |
    | `web` applies to the editor's requests and to runs driven by the browser
    | or a queue, `background` to the CLI. Timeouts are seconds per provider
    | call. Batches are measured in source characters; `min_chars` is the
    | floor when the editor splits a small entry into parallel batches.
    |
    */

    'http' => [
        'timeout' => [
            'web' => 45,
            'background' => 180,
        ],
    ],

    'batch' => [
        'max_chars' => [
            'web' => 4000,
            'background' => 10000,
        ],
        'min_chars' => 1200,
        'max_segments' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries and concurrency
    |--------------------------------------------------------------------------
    |
    | A unit that fails with a transient error is tried `max_attempts` times,
    | with exponential backoff between the two delay bounds, before the
    | fallback model takes over. `editor` is the number of parallel unit
    | requests in the entry editor; the others are lanes per run driver.
    | Browser lanes each hold a PHP worker for the whole run.
    |
    */

    'retry' => [
        'max_attempts' => 3,
        'base_delay_ms' => 1000,
        'max_delay_ms' => 20000,
    ],

    'concurrency' => [
        'editor' => 4,
        'browser' => 2,
        'queue' => 4,
        'cli' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation state
    |--------------------------------------------------------------------------
    |
    | What Tolk remembers about each translated field. The file driver keeps
    | it next to the content, so it travels with the content in git. Do not
    | edit these files by hand.
    |
    */

    'state' => [
        'driver' => 'file',
        'path' => base_path('content/tolk'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    |
    | Bulk translations. `execution` is `auto` (queue jobs when the queue
    | connection has a worker, so not `sync`, `deferred`, `background` or
    | `null`; otherwise the browser drives the run), `queue` or `browser`;
    | `queue.connection` and `.name` default to the app's. Each queue lane job
    | ends before the connection's `retry_after` (90 seconds when it sets
    | none; for SQS, set it to the queue's visibility timeout), which should
    | stay above `http.timeout.web` plus 40 seconds.
    | `tick_seconds` is the window in which one web request may start new
    | units; `claim_ttl` is how long a queue or CLI worker holds an item.
    | `tolk:prune` stops the runs that made no progress for `claim_ttl`
    | seconds and deletes those without activity for `retention_days` days.
    | Monthly usage logs are kept under `usage.path`. The `file` driver
    | rewrites a run's index on every step, so it suits runs of up to a few
    | thousand items.
    |
    */

    'runs' => [
        'driver' => 'file',
        'path' => storage_path('statamic/tolk/runs'),
        'retention_days' => 30,
        'tick_seconds' => 5,
        'claim_ttl' => 300,
        'execution' => 'auto',
        'queue' => [
            'connection' => null,
            'name' => null,
        ],
    ],

    'usage' => [
        'path' => storage_path('statamic/tolk/usage'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Saving content
    |--------------------------------------------------------------------------
    |
    | Content is saved by one writer at a time, under a global lock that is
    | held for at most `lock_seconds`; a writer waits up to `wait_seconds`.
    |
    */

    'write' => [
        'lock_seconds' => 120,
        'wait_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Plans, locks, throttles and scan results live in this cache store
    | (null = the default store). It must support locks. `plan_ttl` is how
    | many seconds a plan and its results are kept.
    |
    */

    'cache_store' => null,

    'plan_ttl' => 7200,

    /*
    |--------------------------------------------------------------------------
    | Fieldtypes
    |--------------------------------------------------------------------------
    |
    | Handlers for custom fieldtypes, as `fieldtype handle => handler class`.
    |
    */

    'fieldtypes' => [],

    /*
    |--------------------------------------------------------------------------
    | Estimates
    |--------------------------------------------------------------------------
    |
    | Characters per token, used to estimate tokens and cost before a run.
    |
    */

    'tokens' => [
        'chars_per_token' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fake engine
    |--------------------------------------------------------------------------
    |
    | For development and browser tests: a deterministic engine that makes no
    | provider calls and returns "[lang] text" after `fake_delay_ms`. It is
    | honoured only in the local and testing environments
    | (Ai\FakeEngine::enabled()) and ignored everywhere else.
    |
    */

    'fake_engine' => env('TOLK_FAKE_ENGINE', false),

    'fake_delay_ms' => 300,

];
