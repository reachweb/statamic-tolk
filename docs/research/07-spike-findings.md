# 07 — Spike findings

Findings from the Phase 2 verification spikes (T2.1–T2.3). Reports 01–06 stay as written; where this file disagrees with them, this file is newer. T2.4 reconciles `TASKS.md` with everything here.

## SDK

T2.1, 2026-10-02. Run on PHP 8.4.25, `laravel/ai` v1.0.1 (commit `127fce8`), Laravel 13.34.0, Statamic 6.35.0, Pest 4.7.8, Testbench 11.3.0. Everything below was run, not inferred, unless a line says otherwise. Response shapes for OpenAI and Gemini come from the SDK's own tests at the `v1.0.1` tag (`tests/Helpers.php`, `tests/Feature/Providers/{OpenAi,Gemini}/*`), which are not shipped in `vendor/`.

### What exists now

- `tests/Support/Agents/ProbeAgent.php`: a structured agent (`#[Strict]`, `HasStructuredOutput`, `HasProviderOptions`) whose `provider()` returns `Ai::build()` with the name `tolk_{provider}` and the key read from `ai.providers.{provider}.key`, plus `store: false` for OpenAI. Schema: `translations`, a required array of objects with required string `id` and `text`.
- `tests/Fixtures/providers/{anthropic,openai,gemini}.json`: one successful structured response per provider, with cache and reasoning usage. `tests/Support/ProviderFixtures.php` derives every other case from them (`withText()`, `truncated()`, `refusal()`, `error()`), fakes the right host, returns the one sent request, and holds the catalog models (`MODELS`) that the tests and the live probe use. T4.10 and T4.12 build on these.
- Contract tests in `tests/Feature/Sdk/`: `RequestShapeTest` (24 tests), `ResponseHandlingTest` (24), `ExceptionMappingTest` (53), `FakeTest` (14): 115 tests, all green.
- The live probe `tests/Live/ProviderProbeTest.php` (group `live`, see the last subsection), with `tests/Support/LiveProbe.php`, the §A3 checker `tests/Support/PlaceholderCheck.php` (unit-tested in `tests/Unit/Support/PlaceholderCheckTest.php`) and its data in `tests/Fixtures/probe/`.

### Requests

- **Endpoints and keys.** Anthropic `POST https://api.anthropic.com/v1/messages` with `x-api-key`; OpenAI `POST https://api.openai.com/v1/responses` with `Authorization: Bearer`; Gemini `POST https://generativelanguage.googleapis.com/v1beta/interactions` with `x-goog-api-key`. The key comes from the `Ai::build()` array.
- **Structured output** is native on all three and carries the same schema: `additionalProperties: false` on both objects, `required: ['translations']` and `required: ['id', 'text']`. Anthropic sends `output_config.format = {type: json_schema, schema}`; OpenAI `text.format = {type: json_schema, name, schema, strict: true}` (`strict` comes from `#[Strict]`); Gemini `response_format = {type: text, mime_type: application/json, schema}`.
- **No sampling parameters** are sent by any provider for either tier: no `temperature`, `top_p` or `top_k`, at the top level or in Gemini's `generation_config`.
- **Output cap.** Anthropic sends `max_tokens` from `maxTokens()`, and **64,000 when the agent sets none**. OpenAI sends `max_output_tokens` and Gemini `generation_config.max_output_tokens` only when set.
- **Timeout.** `timeout()` becomes the Guzzle `timeout` option of the request (checked through the options `Http::fake()` hands its callback); `connect_timeout` is Laravel's default of 10 s. Neither appears in the body.
- **Catalog options as they arrive:**
  - Sonnet 5.5: `thinking: {type: between_tools}` at the top level. Haiku 4.5: no `thinking`, and `output_config` holds only `format`.
  - OpenAI: `reasoning: {effort: low}` (Sol) and `{effort: none}` (Luna) at the top level, `store: false` from the `Ai::build()` config, and `include: ['reasoning.encrypted_content']`, which the SDK adds for every `gpt-6*` model. No `previous_response_id`, no tools.
  - Gemini: `thinking_level` arrives as `generation_config.thinking_level`; a nested `generation_config` option is flattened into it. `store: false` is always sent. Flash-Lite with no cap sends no `generation_config` at all.
- **Shallow merge, confirmed.** Provider options are merged into the request with `array_merge`, so an option replaces a whole top-level key the SDK built:
  - Anthropic: `output_config: {effort: low}` alone leaves `output_config = {effort: low}` and **drops the structured-output format** (R2 §11.2 was right). Supplying both keys, `output_config: {effort, format}` with the format built exactly as the SDK builds it (`AnthropicSchemaSanitizer::sanitize((new ObjectSchema($schema))->toSchema())`), sends both; a test pins that this equals the SDK's own format.
  - OpenAI: an option `text` (for example `{verbosity: low}`) drops `text.format` in the same way.
  - Gemini (read in source, not tested): `response_format` is one of the keys hoisted to the top level, so an option of that name would replace the schema too.
  - So the catalog options must never contain `output_config` (Anthropic), `text` (OpenAI) or `response_format` (Gemini).

**Decision on Anthropic effort: dropped.** The catalog options for `claude-sonnet-5-5` are `thinking: {type: between_tools}` only. Reasons: keeping `effort: low` would mean rebuilding the SDK's internal format in the agent (two gateway classes) and keeping it identical across SDK releases; with `between_tools` Sonnet 5.5 does no up-front thinking at the default effort `high` or below (R6 §1.7), so `low` would buy no thinking time; and R6 §1.3 reports that Sonnet 5.5 at `low` or `medium` effort with structured output sometimes thinks until `max_tokens`. If a live measurement ever shows a benefit, the two-key form is tested and works.

### Responses

- `$response->structured['translations']` (and `$response['translations']`) holds the decoded list for all three, as a `StructuredAgentResponse`. `$response->meta->provider` is the on-demand name `tolk_{provider}`.
- `$response->meta->model` is the model the API reports for Anthropic and OpenAI (it can be a dated snapshot rather than the alias that was requested), and the requested id for Gemini. Key usage and prices by the requested `ModelRef`, not by `meta->model`.
- Usage (`$response->usage->toArray()`), per provider:
  - Anthropic: `input_tokens` = `input_tokens` + `cache_read_input_tokens` + `cache_creation_input_tokens`; cache read and write reported; reasoning from `output_tokens_details.thinking_tokens`.
  - OpenAI: input, output, `input_tokens_details.cached_tokens`, `input_tokens_details.cache_write_tokens`, `output_tokens_details.reasoning_tokens`.
  - Gemini: input `total_input_tokens`; **output = `total_output_tokens` + `total_thought_tokens`**; cache read `total_cached_tokens`; cache write always `null`.
- **Truncation** (Anthropic `stop_reason: max_tokens`, OpenAI `status: incomplete`, Gemini `status: incomplete`) gives one step with `FinishReason::Length` on `$response->steps->last()`, `structured === []`, and no exception.
- **Undecodable JSON** gives `structured === []` and `FinishReason::Stop`, without an exception. JSON inside a Markdown code fence is decoded. Decoded data is **not validated** against the schema: a missing `text` or an extra top-level key comes through as is.
- **Refusals.** Anthropic (`stop_reason: refusal`) and Gemini (a failed interaction whose error mentions safety or blocking) give `FinishReason::ContentFilter` with `structured === []`. **OpenAI does not**: a `refusal` content item gives `FinishReason::Stop`, `text === ''` and `structured === []`, so it looks exactly like undecodable output. Only `$response->raw` shows it (`output[*].content[*].type === 'refusal'`), and `raw` is `null` on agent fakes.

### Exceptions

| Condition | Thrown | Failoverable | Notes |
| --- | --- | --- | --- |
| 429, all providers | `RateLimitedException` ("Application rate limited by AI provider [tolk_x].") | yes | `getPrevious()` is the `RequestException`; `->response->header('retry-after')` and the error body are readable. A missing header reads as `''`, not `null`. |
| 429 with a spend-cap or quota body | the same `RateLimitedException` | yes | Only the body tells it apart: `error.details.error_code` (Anthropic), `error.code` (OpenAI). The Gemini quota body shape is a guess (`error.details[].reason`); verify it live. |
| 502, 503, 504, all providers; 529 Anthropic only | `ProviderOverloadedException` | yes | 529 from OpenAI or Gemini is a plain `RequestException`. |
| 402, all providers | `InsufficientCreditsException` | yes | |
| Anthropic, any other error status whose message contains `credit balance`, `insufficient`, `quota exceeded`, `exceeded your current quota`, `billing` or `usage limit` | `InsufficientCreditsException` | yes | For example a 400 "Your credit balance is too low". |
| 400, 401, 403, 404, 408, 413, 500 | `Illuminate\Http\Client\RequestException` | no | The response is on `$exception->response`. |
| Connection failure | `ProviderConnectionException` ("Could not connect to AI provider [tolk_x].") | yes | `getPrevious()` is an `Illuminate\Http\Client\ConnectionException`, which has no `response` property. |
| Timeout | the same `ProviderConnectionException` | yes | Nothing but the previous exception's message (`cURL error 28: Operation timed out …`) tells it apart. With real cURL the Guzzle `ConnectException` behind it also carries `getHandlerContext()['errno'] === 28` (read in source; a faked failure has no handler context). |
| An error inside a 200 response (OpenAI `status: failed`; a Gemini interaction that failed for another reason than blocking) | `Laravel\Ai\Exceptions\AiException` ("Anthropic Error: [type] message" and similar) | no | §A10 had no row for it. |

### Fakes

- `ProbeAgent::fake(Closure)` calls the closure with `(string $prompt, Collection $attachments, TextProvider $provider, string $model)`; the provider is the on-demand `tolk_{provider}` and the model is the agent's. A closure typed `AgentPrompt $prompt`, as the docs show, throws a `TypeError` (R2 §20 item 1 confirmed).
- A closure or a list that returns an array yields a `StructuredAgentResponse`; usage is all zero (cache and reasoning `null`), `raw` is `null`, `meta->model` is the requested model. Lists answer in sequence. With no response left the fake generates schema-shaped data; after `preventStrayPrompts()` it throws `RuntimeException('Attempted prompt [...] without a fake agent response.')`.
- The fake intercepts although `provider()` returns an on-demand provider: no HTTP request is made (`Http::assertNothingSent()`), and no API key is needed (`ai.providers.anthropic.key = null` works).
- `assertPrompted()` receives a `Laravel\Ai\Prompts\AgentPrompt` with `prompt`, `timeout`, `model`, `provider` (name `tolk_x`, driver `x`) and `agent`. Prompts are recorded per agent class.
- `Ai::build()` with the same `name` can be called on every prompt; only a name that is configured in `ai.providers` or equals a `Lab` value is refused. So a host that configures its own `ai.providers.tolk_anthropic` would break the addon (T3.7).

### Live probe: deferred

No provider key was available on 2026-10-02 (none in the environment, no `.env.live`, none in the sandbox `.env`), so `composer test:live` ran with every probe skipped (21 skips) and no live finding exists yet. Therefore:

- The catalog options stay as in "Version pins", except Anthropic effort, which is dropped (above). Whether any option causes a 400 is unverified.
- `reasoning_headroom` stays at the T3.3 values (0 for Haiku, Luna and Sonnet with `between_tools`; 4,000 for GPT-6.1 Sol and Gemini 3.8 Flash).
- `batch.max_chars.web` 6,000, `.background` 10,000 and `batch.min_chars` 1,200 stay.
- The placeholder syntax stays `<1>…</1>` / `<x1/>`.
- T13.4 repeats the probe with the owner's keys. Changing the placeholder syntax at that point would touch built code, so it is a stop-and-ask there.

What the probe does, for each provider with a key, on the quality and the fast model with the catalog options (`ProviderFixtures::MODELS`):

1. One tiny call into Greek; records latency, usage and finish reason. On a 400 it repeats the call without options and records both.
2. The twenty inline segments of `tests/Fixtures/probe/placeholders.json` (one to four placeholders each, two with `<br/>`) into Greek and German, once as `<1>…</1>` / `<x1/>` and once as `<g1>…</g1>` / `<x1/>`, each as one call. Every segment is checked with the §A3 rules for inline segments (balanced and unnested pairs, every source pair present, no empty pair, every atom exactly once, same number of `<br/>`, no unknown id or tag, not empty, not over 4 × length + 200); missing ids count as failures.
3. The 5,990 characters of `tests/Fixtures/probe/latency.json` (13 paragraphs) as one call, and as four calls of 1,363–1,813 characters. The four run one after another, so the report gives the slowest of the four (what four parallel requests would take, as long as the provider does not throttle four at once) and their sum.

Results go to `build/live-probe/{provider}-{tier}.json` (git-ignored), merged per section. A dry run against the agent fake, with one segment dropped and one stripped of its placeholders, produced all six reports and flagged exactly those two segments.

### Changes made to TASKS.md (T2.1)

- Version pins, catalog table: Sonnet 5.5 options are `thinking: {type: between_tools}` only; the column now records what T2.1 verified and what T13.4 still has to; a line below the table names the option keys that would replace the structured-output format.
- §A10: `AiException` (an error inside a 200 response) is handled like HTTP 500; an OpenAI refusal is recognised from the raw response; HTTP 413 arrives as a plain `RequestException`.
- T3.3: catalog options must not contain the keys that replace the structured-output format; `ProviderFixtures::MODELS` follows the catalog.
- T3.7: the provider names `tolk_{provider}` must not be configured by the host.
- T4.3: placeholder syntax `<1>` kept.
- T4.10: the T2.1 fixtures and helpers named; the timeout can be asserted through the options an `Http::fake()` callback receives.
- T4.12: OpenAI refusal, unvalidated decoded items, timeout detection, `AiException`, empty `retry-after`, 529 only from Anthropic, Anthropic's credit messages, requested model for usage; the self-check gains the refusal, `AiException` and timeout cases.
- T13.4: where the probe writes its reports and what to update from them.
- Risks table: the effort overwrite and the fakes are settled; option acceptance and placeholder fidelity wait for T13.4.
