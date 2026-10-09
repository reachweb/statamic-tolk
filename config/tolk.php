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
    | Model catalog
    |--------------------------------------------------------------------------
    |
    | The models offered per provider. The first model of each tier is the
    | provider's default for that tier. `options` are merged into the request
    | as they are, so they must never contain `output_config` (Anthropic),
    | `text` (OpenAI) or `response_format` (Gemini): each would replace the
    | structured output format. Never add temperature, top_p or top_k.
    | OpenAI's models from GPT-5.6 on also get `prompt_cache_options` in
    | explicit mode, unless their options hold a `prompt_cache_options`.
    | `reasoning_headroom` is the number of output tokens reserved for
    | thinking, 0 when the model does not think with these options. Prices
    | are USD per million tokens; `cache_read` and `cache_write` are needed
    | only where a model's differ from 10% and 125% of its input price.
    |
    */

    'models' => [
        'anthropic' => [
            [
                'id' => 'claude-sonnet-5-5',
                'label' => 'Claude Sonnet 5.5',
                'tier' => 'quality',
                'options' => ['thinking' => ['type' => 'between_tools']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 0,
                'price' => ['input' => 2.0, 'output' => 10.0],
            ],
            [
                'id' => 'claude-haiku-5-5',
                'label' => 'Claude Haiku 5.5',
                'tier' => 'fast',
                'options' => ['thinking' => ['type' => 'disabled']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 0,
                // Anthropic's rate for prompts up to 100,000 tokens (0.5 / 2.5 above that); Tolk's batches stay far below.
                'price' => ['input' => 0.1, 'output' => 0.5],
            ],
        ],
        'openai' => [
            [
                'id' => 'gpt-6.1-sol',
                'label' => 'GPT-6.1 Sol',
                'tier' => 'quality',
                'options' => ['reasoning' => ['effort' => 'low']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 4000,
                // OpenAI bills Sol's cache reads at 5% of its input price, where most models read at 10%.
                'price' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.1],
            ],
            [
                'id' => 'gpt-6-luna',
                'label' => 'GPT-6 Luna',
                'tier' => 'fast',
                'options' => ['reasoning' => ['effort' => 'none']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 0,
                'price' => ['input' => 0.1, 'output' => 0.5],
            ],
        ],
        'gemini' => [
            [
                'id' => 'gemini-3.8-flash',
                'label' => 'Gemini 3.8 Flash',
                'tier' => 'quality',
                'options' => ['thinking_level' => 'low'],
                'max_output_tokens' => 65536,
                'reasoning_headroom' => 0,
                // Google charges 0.75 / 3.75 until 2026-12-31; this is the price from 2027-01-01.
                'price' => ['input' => 1.5, 'output' => 7.5],
            ],
            [
                'id' => 'gemini-3.5-flash-lite',
                'label' => 'Gemini 3.5 Flash-Lite',
                'tier' => 'fast',
                'options' => [],
                'max_output_tokens' => 65536,
                'reasoning_headroom' => 0,
                'price' => ['input' => 0.3, 'output' => 2.5],
            ],
        ],
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
