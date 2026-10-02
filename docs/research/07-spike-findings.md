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

## Publish form

T2.2, 2026-10-02. Run in the sandbox on Statamic **6.35.0** (Laravel 13.34.0, PHP 8.4.25), and checks 1–6, 9 and 11 again on **6.30.0**, D13's floor, by pinning the sandbox temporarily (`composer require statamic/cms:6.30.0 -W --no-blocking`; restored afterwards with `git checkout` and `composer install`, lock file identical). The CP was driven by headless Google Chrome through Playwright 1.63.0 (`channel: 'chrome'`) from a temporary folder, as the admin, with one stored session. Everything below was run unless a line says it was read.

### Setup

- Spike code, on a throwaway branch that is deleted: a pushed component `tolk-spike`, pushed on `publish-container-created` for references starting with `entry::`, which calls `injectPublishContext()` and registers its context in a window object for the test script; and a temporary CP endpoint that takes the origin's raw values of every localizable top-level field except `slug` and `date`, upper-cases every text (all strings except identifiers: `type`, `id`, `enabled`, `url`, `style`, `anchor`, `images`, `marks`, `level`, `textAlign`, `href`, `src`) and returns `values` and `meta` from `$entry->blueprint()->setParent($entry)->fields()->addValues($raw)->preProcess()`, the produced raw value and its texts in order. "Fill" calls `setFieldMeta`, `setFieldValue` and `desyncField` per handle. The texts of a stored value are extracted with the same walker.
- Content: the articles `hiking-the-samaria-gorge` (Replicator with a text set whose Bard holds a table, an FAQ set with a Grid, a specs set with a table; a list, a group and a keyed array) and `olive-oil-tasting` (Replicator rows without `id` or `enabled`, a CTA without `open_in_new_tab`), and the pages `about` (Bard with aligned headings and paragraphs, links, a list, a table and a gallery set) and `dining` (Bard with a quote set). German localizations were created in the CP: "Working In" → German → core's "Create Localization" origin picker (three sites, origin behaviour `select`) → Create.
- `vendor/statamic/cms` no longer ships `resources/js`, only built assets. The front-end source behind R3's `[SRC]` paths was read on GitHub at the tags `v6.35.0` and `v6.30.0`.

### Results

| Check | 6.35.0 | 6.30.0 |
| --- | --- | --- |
| 1. Fill updates the form, sync icons flip, form dirty, nothing saved | yes | yes |
| 2. After Save: exactly the filled keys stored; texts equal | yes; texts equal except defaults (check 4) | yes |
| 3. Reopen and save again | texts equal; only `updated_at` changes | same |
| 4. Normalisation matrix | texts survive everywhere, except that a set or row field added to the blueprint later with a text `default` adds that default text | same (seeded article, page with empty paragraphs) |
| 5. Bard re-emits after `setFieldValue` | yes, normalised attributes only | same |
| 6. Fill timing after `localization.created` | 0 and 300 ms lost or partial on save; 600 and 1,000 ms fine | same |
| 7. Live Preview, stacks, read-only | pushed component re-mounts on every open and close; stack container `relate-fieldtype-inline-{n}`; read-only has the same keys | not run |
| 8. Core axios from addon code | works at request time; `Statamic.$app` undefined in `booting()` | not run |
| 9. Slug | auto-follows the title, `_auto_slug` decides; origin slug kept by setting the slug value before the title | auto-follows the title, posted slug stored; same remedy works |
| 10. Progress task and site switch | prompt skipped, unsaved fill discarded | not run |
| 11. Rejected `entry.saving` | form stays usable; core toast unless the rejection is a `PipelineStopped` | same |
| 12. Successful save | values and meta replaced from the response, `localizedFields` kept; mid-save delivery reverted but stays localized | not run |

### Details

1. **Fill.** Every produced text appeared in the form: the title input, the Markdown editor, Replicator headings, Bard paragraphs and table cells, and on the Details tab the list, the group and the keyed array. Each filled field's label icon changed from "synced" to "desynced", `Statamic.$dirty.has('base')` was true, and the stored file still held only what the localize request wrote (`updated_by`, `updated_at`). Before any fill, a just-created localization is already dirty with `slug` in `localizedFields`: core's slug reset does that about a second after `localization.created` (check 9).
2. **Save.** The stored data held exactly the filled handles, plus `updated_by` and `updated_at`, which core always writes. The texts of every field equalled the produced texts, in order. Other differences from the produced raw value, per fieldtype:
   - `text`, `textarea`, `markdown`, `list`, `group`, keyed `array`, and a `table` inside a Replicator set: none.
   - Replicator: rows without `id` get an 8-character `id`, rows without `enabled` get `enabled: true`, and set fields missing from a row get their default (`open_in_new_tab: false`).
   - Grid: rows get an `id`; missing fields get their default.
   - Bard, added by the Bard component in the browser (check 5): `attrs: {textAlign: null}` on paragraphs and headings that had none, `attrs: {start: 1, type: null}` on ordered lists, `rel`, `target` and `title` (all `null`) on link marks, and `attrs: {colspan: 1, rowspan: 1, colwidth: null, align: null}` on table cells and headers. On save, sets get `attrs.id`. No node was removed and no text changed.
   - The CP's first Save of a new localization also posted `published: true`, because the form sets it when the origin is published (R3 §1.4), so the localization was published on its first save. D10 concerns server-side flows only.
3. **Save again.** After reopening the saved localization and saving without an edit, every text was still equal, and the only stored difference was `updated_at`. The reopened form posts `_localized` = the stored keys and, on 6.35.0, `_auto_slug: false`.
4. **Content matrix.** Texts were equal in every case below except the defaults case.
   - Seeded content never saved in the CP (Samaria, olive oil): the differences of check 2.
   - The same after one CP save of the origin (Samaria): that save itself added only Replicator and Grid `id`s and `enabled` to the origin. Bard does not re-emit a value it was mounted with, so the origin's Bard JSON stayed as seeded. The localization's differences then shrank to Bard's table-cell attributes.
   - Replicator rows without ids (olive oil): `id`, `enabled` and the CTA's `open_in_new_tab: false` added.
   - Fields added to sets after the content was written (a toggle and a text field with a `default` in the Replicator `text` set, a text field with a default in the FAQ Grid, and a toggle and a defaulted text field in the pages' Bard `quote` set): the toggles were stored as `false`, and **every existing row or set received the default text** (`kicker: 'Read this'`, `note: 'Updated'`, `cite: 'Source'`). So the stored field has more text leaves than the produced value, in the source language. The defaults are already in the endpoint's `preProcess()` output, so a server-side write that normalises the value adds them too. The origin gains them on its next CP save as well, which changes its source fingerprint.
   - An empty Bard paragraph and an empty right-aligned paragraph (inserted into `about` with `saveQuietly()`): both kept, the first with `attrs.textAlign: null`, the second with its alignment.
   - Aligned paragraphs and headings: alignment kept; unaligned ones gain `textAlign: null`.
5. **Bard re-emit.** Yes. Within the fieldtype's update debounce after `setFieldValue`, the Bard component emits its own JSON with the attributes listed in check 2. The update goes through the field's update handler, which also desyncs the root field. List and array fields re-emit too (check 6). So the form value right after a fill is not deep-equal to the delivered value for a Bard field, although every text is.
6. **Timing.** `localization.created` fires after the new localization's values, meta and reference are in the container. Fill data was fetched in advance, so only the delay differed:
   - 0 ms: the values were shown, but `desyncField` did nothing, so no field was marked localized and the form was not dirty. Save posted `_localized: [slug]` and **stored nothing**.
   - 300 ms: shown, not marked at the time. Later, the Replicator, list and array fields marked themselves through their own re-emit, so `body`, `features` and `facts` were stored and `title`, `excerpt` and `meta` were silently lost.
   - 600 ms and 1,000 ms: every field was localized and dirty, and every field was stored.
   - The same results on 6.30.0. The window is the 500 ms after `editLocalization()`, as read in R3 §1.3, and the guard in `desyncField` exists on 6.30.0.
7. **Live Preview, stacks, read-only.**
   - Live Preview: opening it and closing it each mount a new instance of the pushed component and unmount the old one, so local state is lost. The container is not re-created (no second `publish-container-created`), the pushed component object survives, `injectPublishContext()` works in the new instance, and a fill after closing works.
   - Stacked inline editor (an `entries` field on the article, inline edit of a related page): the stack's container is named `relate-fieldtype-inline-{n}` with a counter (`relate-fieldtype-inline-1360`). It fires `publish-container-created` with an `entry::` reference, so a component pushed on every `entry::` container also appears inside the stack. Its context has `parentContainer` (the `base` container) and the same keys.
   - Read-only form (the editor role without `edit articles entries` and `edit other authors articles entries`; the article's author is another user, so both are needed): the same 41 context keys, `readOnly.value === true`, no Save button, read-only inputs. The pushed component still renders, so it must hide itself.
   - Context keys on 6.35.0, base container: those of R3 §4.2 plus `isWorkingCopy` and `revisionsEnabled` (the form's `provide`). The `publish-container-created` payload has the keys R3 §2.2 lists. The `localization.created` container exposes `clearDirtyState`, `desyncField`, `meta`, `name`, `pushComponent`, `revealerFields`, `saved`, `saving`, `setExtraValues`, `setFieldValue`, `setMeta`, `setValues`, `values`, `visibleValues`; 6.30.0 does not expose `meta` (read).
8. **Axios.** `typeof Statamic.$app` is `'undefined'` inside `Statamic.booting()`. Read at request time, `Statamic.$app.config.globalProperties.$axios.post(cp_url('tolk/…'))` reached the addon's CP route with the CSRF header and returned 200.
9. **Slug.** Article `hiking-the-samaria-gorge`, German, translated title "Wanderung durch die Samaria-Schlucht".
   - At `localization.created` the slug field holds the origin's slug and `meta.slug` has no `auto` key. About a second later core's reset has slugified the inherited title (`hiking-the-samaria-gorge-a-practical-guide`), `meta.slug.auto` is `true` (6.34 and later), `slug` is in `localizedFields` and the form is dirty.
   - Title filled, nothing else: the slug follows the title once the asynchronous slugify request returns (`wanderung-durch-die-samaria-schlucht`). On 6.35.0 the save posts `_auto_slug: true` and the server derives the slug from the posted title; on 6.30.0 the server stores the posted slug. Either way the stored slug is translated.
   - Slug value set to the origin's slug **before** the title (after the reset): the Slugify component stops following (its `to` watcher sets `shouldSlugify = false`, and on 6.34 and later the slug fieldtype then writes `meta.slug.auto: false`); the slug stays, the save posts `_auto_slug: false` on 6.35.0, and the origin's slug is stored on both versions.
   - Slug value set after the title, once the title's slugify has finished: the same result.
   - Slug value and `meta.slug.auto: false` set right after the title, while the title's slugify request was still running: the late response overwrote the slug with the translated one, `_auto_slug: false` was posted with it, and the translated slug was stored. Setting `meta.slug.auto` by hand is neither needed nor sufficient.
   - Slug and title set right at `localization.created`, inside the reset: the reset's slugify finished afterwards and switched automatic mode back on. 6.35.0 stored a slug derived from the translated title, 6.30.0 the slug of the inherited English title.
10. **Progress task.** Control: with the form dirty, clicking English opens core's "Unsaved Changes" modal. With `Statamic.$progress.loading('tolk-spike', true)` active, Save & Publish is disabled, and clicking English switches at once without the modal, so the unsaved fill is gone. `Statamic.$dirty` still lists `base` afterwards.
11. **Rejected save hook.** `entry.saving` rejected with a string: no request is sent, core shows the toast "Something went wrong" and logs the rejection reason as a console error, the Save button stays enabled, the form stays dirty, and the next Save succeeds. Rejected with `new PipelineStopped()` imported from `@statamic/cms/save-pipeline` (the hook runner forwards the rejection value, and the form's `catch` stays silent for that class): no core toast and no console error, only the toast the hook showed itself; Save works next time. The same on 6.30.0.
12. **Successful save.**
    - The save pipeline's request step replaces the container's values from the response (`setValues(resetValuesFromResponse(…))`) and, on 6.34 and later, its meta. `localizedFields` is kept, and the dirty flag is cleared.
    - With the request held for 2.5 s, a title set after the click went back to the posted title. An `excerpt` set and desynced after the click went back to the inherited English excerpt but **stayed in `localizedFields`**, and the next save stored the English excerpt as the localization's own value. This is exactly the risk T5.8's save guard addresses.
    - With the user's default `after_save` preference (`listing`), the Save button navigates to the collection listing about 300 ms after the response, after the `entry.saved` hooks have run. Quick save (⌘S) and the "continue editing" preference stay on the form.

### Other observations

- Core logs `TypeError: Cannot read properties of undefined (reading 'some')` (in `comboboxOptions`) twice on every in-place site switch or localization creation of an article, without the addon, on 6.30.0 and 6.35.0. The CP also requests a missing font, `intervariable-italic.woff2` (404).
- More than about five CP logins a minute answer 429, so browser tests must reuse stored sessions.
- On the "Unsaved Changes" modal, `getByText('Unsaved Changes')` matches two elements; Live Preview's close button is an icon-only button at the end of its header.
- Security advisory GHSA-jppw-r5j3-xf7x (CVE-2026-71293, medium: two-factor recovery codes exposed through dynamic Antlers rendering) affects `statamic/cms` `>=6.0.0-alpha.1,<=6.30.0`. Composer 2.10 refuses to install 6.30.0 by default, so `^6.30` resolves to 6.31.0 or later unless a host turns blocking off.

### Version floor

Nothing in checks 1–6, 9 and 11 fails on 6.30.0, and the remedies above work on both versions. The floor therefore does not have to rise for the editor flow. Three facts still argue for raising it, which is the owner's decision (D13): 6.30.0 itself carries the advisory above, and 6.35.0 fixes statamic/cms#15509 (unsaved entries share one cached blueprint) and #15551 (a cold multi-site Stache rebuild drops entries), which T2.3 tests. Recommendation: `^6.35`. T2.4 asks the owner once T2.3 has shown whether the two bugs reach the addon.

### What the later tasks take from this

- The fill delay of 600 ms after `localization.created`, a site switch or a save holds on 6.30.0 and 6.35.0. Writes inside the window are shown but not saved (T5.7).
- Undo and the delivered-value comparison must not expect the form's Bard value to equal the delivered JSON (check 5, T5.7).
- The save guard can reject cleanly; reject with `PipelineStopped` (T5.8).
- The `entry.saved` hook runs before the form leaves for the listing (T5.8).
- `keepOriginSlug` sets the slug value to the origin's after core's reset and before the title is filled (T5.9).
- Delivered values already contain defaults of set fields missing from the raw value, which are new text leaves (check 4, T5.3, T2.4).
- Live Preview re-mounts the banner (T5.6); stacked editors fire `publish-container-created` with `entry::` references (T5.6); a read-only form still renders the banner (T5.6).

### Changes made to TASKS.md (T2.2)

- T5.1: after-save navigation, quick save, core console noise, login throttling, selectors.
- T5.3: the pending `target_hash` comes from the value as it will be stored, defaults included.
- T5.6: Live Preview re-mounts, stacked editors, read-only forms.
- T5.7: the 600 ms delay is verified; `delivered` holds the form's value after the fieldtype's re-emit.
- T5.8: reject with `PipelineStopped`; `entry.saved` awaits the confirm request; check 10 and 12 confirmed.
- T5.9: how `keepOriginSlug` keeps the origin's slug, by version.
- T2.4: the version floor question and the defaulted-text leaves (D27).
- Risks table: the rows this spike settles.
