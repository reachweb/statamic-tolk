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

## Content model

T2.3, 2026-10-02. The Pest contract tests ran on Statamic **6.35.0** (PHP 8.4.25, Laravel 13.34.0, Testbench 11.3.0) and again on **6.30.0**, D13's floor, in a throwaway clone (`composer require statamic/cms:6.30.0 -W --no-blocking`): 45 of 45 green on both. The Stache experiment ran in the sandbox on 6.35.0 with real processes and the `file` cache. Everything below was run unless a line says it was read.

### What exists now

`tests/Feature/Contracts/`, 45 tests:

- `EntryLocalizationTest` (11): tree placement, the root-page quirk, own data and nulls, the re-save cascade, and the Blink keys of unsaved localizations with the fix.
- `RoundTripTest` (12): `preProcess()` → `process()` on a Replicator, a Grid, a Bard with a set and empty paragraphs, a group and empty values.
- `TermLocalizationTest` (6), `GlobalsLocalizationTest` (4), `NavTreeTest` (3).
- `GitListenersTest` (5): the commit listener switch and the commit call.
- `StacheRefreshTest` (4): what the refresh `ContentWriter` needs resets, and what core's own reset misses. Its `refreshStache()` is the mechanism of "Stache and parallel writers" below.

### Entries

- **Tree placement.** `makeLocalization()` registers an after-save callback that appends the new entry to the target site's tree: under the localized parent's page when the parent is localized there and that page is not the tree's root, otherwise at the end of the top level. The parent is looked up when `makeLocalization()` is called, not at save time. A child localized before its parent lands at the top level and stays there when the parent follows (`['suites', 'rooms']`, not `rooms › suites`).
- **Root quirk.** On a collection with `structure.root: true`, `Tree::root()` is simply the first branch. Localizing `rooms`, `suites` and `honeymoon` into an empty `el` tree gave `[rooms, suites › honeymoon]`. `rooms` became the Greek home page, with URI `/`, and `suites`, whose localized parent is now the root, went to the top level because the root page cannot have children. Localizing `home` afterwards appended it at the end, with URI `/home`; `rooms` stayed the root. Localizing `home` first reproduces the origin tree exactly. No placement can avoid this: in an empty tree, any first entry is the root. So the origin's root must be localized first (§A11 "Create").
- **Own data.** A localization's `data()` holds only its own keys. `has()` is true for an explicit `null`, `get()` never falls back, and `value()` falls back for missing keys but returns `null` for an explicit `null`. A root entry's `fileData()` drops `null`, `''` and `[]`; a localization's keeps them (R4 §2.1 confirmed).
- **Re-save cascade.** Saving an origin saves its direct localizations inside its own `save()`. Their `EntrySaved` fires first, with `isInitial()` false, then the origin's, with `isInitial()` true. Their data is unchanged.
- **Unsaved localizations share Blink keys.** An unsaved `makeLocalization()` result has no id, and two Blink keys built from the id stop being private to it:
  - **Blueprint** (statamic/cms#15509). Before 6.35.0 the key is `entry--blueprint` for every unsaved entry. In a loop that alternately localizes `pages` and `articles` entries, 6.30.0 gave the wrong collection's blueprint to 12 of 24 unsaved localizations, and core's `save()` used that wrong blueprint too (`EntrySaving` listeners, auto titles). From 6.35.0 the key is `entry-obj-{spl_object_id}-blueprint`, and the key of a saved or freed entry is never forgotten. PHP gives a freed object's id to the next object, so in one exploratory loop, where unsaved localizations were made and discarded, the tenth of twelve read another collection's blueprint. The same loop with saves gave 0 of 24 on 6.35.0, so this depends on allocation and cannot be asserted directly; the test plants the stale key instead.
  - **Origin**, on every version: `HasOrigin::origin()` caches under `origin-Entry-{id}`, which is `origin-Entry-` for every unsaved entry. Two unsaved localizations held at once (a page's, then an article's) both resolve the page as their origin. The article's then inherits the page's title, and `blueprint()` throws `BlueprintNotFoundException`, because it looks for the page's blueprint handle in `articles`. `makeLocalization()` itself is safe, because its `origin()` setter forgets the key first.
  - **Fix**, run on 6.30.0 and 6.35.0: give the localization an id right after `makeLocalization()` (`->id(Stache::generateId())`). Both keys then become private to it. The entry still saves as new (`Entry::find($id)` is null, so `EntryCreating` and `EntryCreated` fire), keeps that id, and is placed in the tree. With the fix, 96 of 96 blueprint and origin readings in the alternating loop belonged to the right collection: unsaved and discarded, before `save()`, in `EntryCreating` and in `EntrySaving`.
- **One blueprint object per collection.** `Collection::entryBlueprint()` returns the same Blink-cached `Blueprint` object to every entry of a collection and calls `setParent($entry)` on it. So `$entryA->blueprint()->parent()` is whichever entry resolved its blueprint last, which is not always the entry itself. Code that needs the entry as parent (the round trip, fieldtypes that look at their parent) must call `setParent($entry)` right before `fields()`. Each entry's own `blueprint()` result is cached afterwards, so the parent changes only the first time an entry resolves it.

### Round trip

`roundTrip($v)` = `$entry->blueprint()->setParent($entry)->fields()->addValues([$h => $v])->preProcess()->process()->values()[$h]`. Run on 6.35.0 and 6.30.0:

- **Idempotent.** `roundTrip(roundTrip($v))` equals `roundTrip($v)` exactly, ids included, for every case: the second pass keeps the ids the first one assigned. §A11 step 3 can rely on it.
- **Replicator.** Rows without `id` get an 8-character alphanumeric id, and rows without `enabled` get `enabled: true`; an existing `id` and `enabled: false` are kept. Set fields missing from a row get their default: a toggle without a default becomes `false`, a toggle with `default: true` becomes `true`, and **a text field with a `default` gets its default text**, a new leaf in the source language. This is the same as the CP save (T2.2 check 4), so T2.4's question about defaulted text leaves applies to server-side normalisation too.
- **Grid.** Rows get an `id`, and nothing else is added: a missing field without a default stays missing.
- **Bard.** Sets get `attrs.id` (8 characters, placed before `values`), and set fields missing from a set get their defaults (`cite: 'Source'`, `wide: false`). Empty paragraphs, alignment attributes, marks and headings are kept as they are. The attributes the browser's Bard component adds on a CP save (`textAlign: null`, link `rel`/`target`/`title`, table cell attributes; T2.2 check 2) are **not** added. A later CP save therefore still rewrites a server-normalised Bard value, but changes no text.
- **Group.** Unchanged; missing subfields are not added.
- **Empty values.** A `null` group or grid becomes `[]`, a `null` Bard stays `null`, `[]` stays `[]` and `''` stays `''`. On a localization `[]` is stored and overrides the origin, so "clear" must `remove()` the key rather than write the normalised empty value (§A11 already says so).

### Terms

- `Term::find('topics::food')` returns a `LocalizedTerm` in the taxonomy's **default site**, whatever the current site is. The default site is the first of `$taxonomy->sites()`: with `['el', 'en']`, the default site is `el`, `in('el')` has no origin, and `in('en')->origin()` is the `el` term.
- `->in('el')->merge([...])->save()` stores under `localizations.el` in the term's file and leaves the default site's data alone.
- `LocalizedTerm::updateLastModified()` without a user throws `BadMethodCallException` ("Call to undefined method …LocalizedTerm::remove()"), as R4 §16.2 item 19 read. With a user it stores `updated_by` and `updated_at` in that site's data.
- `LocalizedTerm::has()` is false for an explicit `null` that `data()->has()` reports, so `hasOverride()` must use `data()->has()` (T9.2 already does).
- `Term::query()->where('taxonomy', …)->where('site', 'el')` returns `LocalizedTerm`s in `el`.

### Global sets

- `$set->in('el')` returns the `Variables` of an enabled site, whose `origin()` is the site configured in `$set->origins()`. A site that is not enabled gives `null`.
- `GlobalSet::save()` creates the variables of every enabled site. A site enabled with `sites()` but not saved yet gives an unsaved `Variables` with the id `{handle}::{site}`.
- `merge()->save()` persists; `has()` is false for inherited keys, `get()` does not fall back, `value()` does.
- An explicit `null` is kept in localized variables and dropped from origin variables. This happens in `fileContents()`, not in `fileData()`, unlike entries.

### Navigation

- `($nav->in('el') ?? $nav->makeTree('el'))->tree($branches)->save()` creates the Greek tree; branch ids, titles, urls and children are stored as given.
- A branch whose `entry` is the Greek localization's id resolves to the Greek page: its title and the URL `/el/about`. A branch holding the English id in the Greek tree resolves to the **English** page, with the English title and `/about`. So mapping each entry id to its localization (§A11, T9.4) is required.
- A branch `title` wins over the entry's title.

### Git

- `Statamic\Git\Subscriber::disable()` and `enable()` exist (inherited from `Statamic\Events\Subscriber`) and switch the commit listener off and on. The subscriber is registered only on Pro.
- `Subscriber::withoutListeners($callback)` calls `disable()`, the callback, then `enable()`, with no `try`. When the callback throws, the listener stays off for the rest of the process. A `try`/`finally` around `disable()` / `enable()` re-enables it (`GitListeners::suspended()`, §A11).
- **One commit:** `Statamic\Facades\Git::dispatchCommit(?string $message)`, or `Git::as($user)->dispatchCommit($message)` to name the committer; without `as()` it uses `User::current()`, and then the configured git user. It records the save in the cache and dispatches `Statamic\Git\CommitJob` on `statamic.git.queue_connection`.
- `CommitJob` is now `ShouldBeUnique` (`unique_lock_expiry`, 120 s by default), which R4 §10.4 did not have: a second dispatch while one is pending is dropped (with `Queue::fake()`, two calls push one job). On a real queue the pending job commits everything anyway. On `sync` each job runs at once, so every dispatch commits.
- The `Git` class throws in its constructor when `statamic.git.enabled` is false. The facade caches the instance once it is resolved, so the guard can be stale within a process: check `statamic.git.enabled` and `statamic.git.automatic` on every call.

### Stache and parallel writers

Setup: sandbox on 6.35.0, `STATAMIC_STACHE_WATCHER=false`, `file` cache, `sync` queue. A temporary command (removed afterwards) behaved like a long-lived process. It read the `notes` collection first, loading paths and indexes into memory, then created entries at times set from the outside. A third process then checked every file on disk by slug query and by `Entry::find()` on the id in the file. The watcher is back to `auto` and the sandbox's `git status` is unchanged.

| Run | Writers | Result |
| --- | --- | --- |
| 1. No lock, no refresh | 2 processes × 3 entries, saves 0.5 s apart | 6 files on disk; the 3 entries of the first writer are missing from the index (slug query and `find()`): the second writer saved its stale path map and indexes over them. |
| 2. Lock + core's reset | the same, each save inside `Cache::lock('tolk:write')` after core's between-jobs reset | all 6 found |
| 3. Core's reset, no lock | 2 × 10 entries started at the same moment, 50 ms apart | paths complete, but 3 entries not found by id: overlapping read-modify-write of the `id` index |
| 4. Lock + core's reset, then a new index | another process starts querying `where('spike_tag', …)` after the writer has loaded, then the writer saves | the entry is missing from the new index for good: the writer's memoized index-usage list does not contain it, so its save does not update that index |
| 5. Lock + core's reset + store memo, Blink kept | 2 processes, each checks `existsIn('de')` early, then creates the German localization of the same article inside the lock | **two** German localizations (`olive-oil-tasting.md` and `olive-oil-tasting.1.md`): `existsIn()` reads `entry-descendants-{id}` from Blink, which still held the earlier answer |
| 6. Lock + full refresh | runs 3 to 5 again | every entry found by slug, by id and by the new index; the second localizer saw the first's localization and skipped |

**How core resets between queue jobs** (6.27.1, `Statamic\Stache\ServiceProvider::resetMemoizedStateBetweenJobs()`, read): on `JobProcessing`, only when `Statamic::isWorker()` (argv starts with `queue:` or `horizon:`), `$stache->stores()->each->resetMemoizedState()` (a store forgets `paths`; an aggregate store also resets its child stores) and `app('stache.indexes')->each->resetMemoizedState()` (an index forgets its items). It does not reset a store's memoized index-usage list (`usedIndexes`) or key map (`keys`), and it does not touch Blink. Runs 4 and 5 show that both matter.

**The refresh `ContentWriter` takes** (inside the lock, before the callback; `refreshStache()` in `StacheRefreshTest`):

```php
Stache::stores()->each->resetMemoizedState();
app('stache.indexes')->each->resetMemoizedState();

Stache::stores()
    ->flatMap(fn (Store $store) => $store instanceof AggregateStore ? [$store, ...$store->stores()->all()] : [$store])
    ->each(fn (Store $store) => Closure::bind(function () {
        $this->keys = null;
        $this->usedIndexes = null;
    }, $store, Store::class)());

Blink::clearResolvedInstance(\Statamic\Support\Blink::class);
```

- The second step clears two protected properties of `Statamic\Stache\Stores\Store`, because no public method does. `StacheRefreshTest` fails when Statamic renames them.
- Replacing the store objects is not an option: the repositories, which are singletons, keep references to them.
- The Blink manager is resolved through its facade only, so forgetting the facade's instance drops every Blink store.
- The lock is required as well: run 3 lost entries with the refresh alone.
- The refresh reads the paths and indexes other processes cached. It never rebuilds a store from the files while the cache is warm, so it adds no exposure to the cold multi-site rebuild bug fixed in 6.35.0 (#15550/#15551, a shared traversal filter). A cold rebuild after a host clears the Stache cache hits that bug on 6.30–6.34 with or without the addon.
- `resetMemoizedState()` does not reset `fileChangesHandled`, so with the watcher on, a long-lived process does not look for changed files again. That does not matter here, because every write goes through Stache.
- The stores' key map (`stache::keys/entries`) is shared by all collections. Core's own `stache:refresh` already leaves it incomplete (7 keys after a warm of the sandbox's 24 entries). It only serves duplicate-id detection; the full refresh keeps it complete for the addon's own writes.

Consequences: with the lock and the full refresh, a long-lived process (a CLI worker, a queue lane, a web request that read content before taking the lock) can save safely. Without the Blink flush, a long-lived process can create duplicate localizations. Queue workers get only core's partial reset, so the queue driver also relies on `ContentWriter`.

### Version floor (for T2.4)

- statamic/cms#15509 reaches the addon on 6.30–6.34, where half the unsaved localizations in a mixed loop read the wrong blueprint. On 6.35.0 it reaches the addon rarely, through object-id reuse. The shared origin key affects every version. Assigning an id right after `makeLocalization()` fixes both on every version, so #15509 no longer argues for raising the floor.
- #15551: the refresh does not rebuild stores, so the addon does not trigger the bug; a host-level cold rebuild on a multi-site install still can on 6.30–6.34.
- Still standing from T2.2: 6.30.0 carries GHSA-jppw-r5j3-xf7x, which Composer blocks.
- All 45 contract tests pass on 6.30.0. The only version-specific code is the `entry--blueprint` / `entry-obj-…` key that one test plants, and it plants both.

### What the later tasks take from this

- §A11 "Create" and T4.16: give a new localization its id right after `makeLocalization()`; create no localization other than the origin root's into an empty tree of a collection with a root; normalise with `setParent($entry)` right before `fields()`.
- T4.7: `EntryTarget::fields()` for a missing localization keeps reading the source's blueprint.
- T4.9: the commit call and the config guard.
- T4.16: the refresh above, plus the lock.
- T4.17, T4.18, T8.3: the origin root first and in tree order; `root_missing`.
- T9.2: `data()->has()` and `updateLastModified()` only with a user (already written); T9.4: entry ids must be mapped (confirmed).
- T2.4: the version floor facts above.

### Changes made to TASKS.md (T2.3)

- §A11: the refresh mechanism in "One writer at a time"; step 3 sets the parent before `fields()`; "Create" assigns the id at once and handles collections with a root.
- §A12: run items of a collection with a root also depend on the root's item.
- T4.7: the reason in `EntryTarget::fields()`.
- T4.9: `GitListeners::commit()` names the call and the guard.
- T4.16: `ContentWriter` refresh, the id, `RootMissing`, and three self-check cases.
- T4.17: `RootMissing` is checked before planning.
- T4.18: entries of structured collections in tree order; the sandbox check compares the Greek and English trees.
- T8.3: items of a collection with a root depend on the root's item; `root_missing`.
- T2.4: the version floor question now carries T2.3's answer, and the defaulted-text question notes the server-side round trip.
- Risks table: the parallel-writer, #15509/#15551 and nav-branch rows are settled.

## Reconciliation

T2.4, 2026-10-02. This file was read against §A3–§A14 and the tasks of Phases 4–10 of `TASKS.md`. T2.1–T2.3 had already carried most of their results into the tasks they affect; this section records what was still open and what changed.

### Checked, no change needed

- **Placeholder syntax** (T2.1): `<1>…</1>` / `<x1/>` stays; §A3, T4.3 and the T4.11 prompt rules already say so, and T13.4 repeats the comparison as a stop-and-ask.
- **Batch sizes** (T2.1): the defaults of §A15 stay (6,000 / 10,000 / 1,200). Only T6.11 still claimed that T2.1 had measured them.
- **Fill delay and slug** (T2.2 checks 6 and 9): T5.7 (600 ms) and T5.9 (`keepOriginSlug`) already carry them.
- **Rejected save** (T2.2 check 11): T5.8 already rejects with `PipelineStopped`.
- **Stache refresh** (T2.3): §A11 and T4.16 already carry the lock and the full refresh.
- Terms, global sets, navigation, git and the SDK facts are already in §A10, §A11, T3.7, T4.9, T4.12, T9.2 and T9.4.

### Leaf texts after a CP save: defaulted text leaves

Checks 2–4 of T2.2 and the round trip of T2.3 show that a CP save and the server-side normalisation change no text, with one exception: a set field with a text `default`, added to a blueprint after content was written, puts that default into every existing Replicator row, Grid row and Bard set. On a target this adds leaves in the source language, which would make a machine-translated field read as manual (and stop its updates) and would shift the leaves that reuse and review pair by position. The same happens without any CP save, because §A11 step 3 normalises every value the addon writes, and because the editor delivers pre-processed values.

Decision: **walks over a target value leave out nested fields whose value equals their configured `default`** (§A3 "Target-side walks"). Walks over a source value keep them, so a default an editor left in place (a "Read more" label) is still translated. The rule depends only on the value and the blueprint and is applied the same way when a target hash is produced and when it is checked, so the normalised, the delivered and the stored value all have the same target leaves. Costs, accepted: a translation that equals its field's default is left out on both sides and only loses positional reuse for that field (the alignment guard notices); changing a set field's default later makes stored copies of the old default count as text again. Computed defaults are not compared.

This does not change D27: it is the mechanics of "normalisation by the CP is not tracked", recorded as planner's details on the D27 row.

The opposite direction needs no rule: when the origin itself gains the default (on its next CP save), its source fingerprint changes, its targets read outdated, and Update sends only the new default texts, because every other leaf is reused. The same reasoning covers a related effect seen in T2.2 check 4: the first CP save of origin content written outside the CP adds row ids and `enabled`, so its targets read outdated once and Update rebuilds them without a model call (§A4).

### Other corrections

- Statamic no longer ships `resources/js` (T2.2): the opening note of `TASKS.md` and T8.11 now say to read core's front-end source on GitHub at the installed tag.
- With the refresh of T2.3, a long-lived process may apply, so the CLI no longer needs a `tolk:apply` child process per pass. The command applies in its own process, as the queue lanes and `--concurrency=1` already did, and the warning not to edit content in the CP during a CLI run is gone (§A12, T8.10).
- Every entry of a collection shares one blueprint object (T2.3), so the two remaining places that pre-process or process values for an entry set its parent right before `fields()` (T5.3, T7.5).
- A new localization created in the publish form is published on its first Save when the origin is published, because core's form switches the toggle on (T2.2 check 2). D10 concerns server-side flows; T5.9 now says the addon leaves the toggle alone.
- `laravel/ai` 1.x counts cached tokens in `input` and reasoning tokens in `output` (R2 §12.2, T2.1), so `Pricing` must not bill cache tokens twice or add reasoning on top (T4.12).
- The doctor reports a host-configured `ai.providers.tolk_{provider}` entry, which T3.7 already promised (T9.6).

### Version floor: the owner's decision

T2.2 and T2.3 found that the editor flow and the content model work on 6.30.0, and that the id assigned after `makeLocalization()` fixes #15509 on every version. Still standing: 6.30.0 carries GHSA-jppw-r5j3-xf7x, which Composer blocks by default; on 6.30–6.34 a host-level cold Stache rebuild on a multi-site install can drop entries from the index (#15550, fixed by #15551 in 6.35.0), after which the addon's queries would not see them either; and 6.35.0 adds item actions to the tree view (T8.11). Raising D13 is the owner's decision. Asked in T2.4 with `^6.35` as the recommendation, the owner answered "yes raise the floor" (2026-10-02): the floor is now `^6.35`. The addon's lock and the sandbox already ran 6.35.0, so no installed version changed; the slug steps of T5.9 lose their pre-6.34 branch, and the tree-view actions of T8.11 are always there. The contract tests still plant the pre-6.35 blueprint key as well, which is harmless.

### Changes made to TASKS.md (T2.4)

- Opening note: core's CP front-end source is read on GitHub at the installed tag.
- D27, status column: planner's details for the target-side walk (the decision itself is unchanged).
- §A3: new "Target-side walks" paragraph.
- §A4: `ofLeaves` uses the target-side walk, and "the target's text fingerprint" is defined; the "why" paragraph names the defaulted-text exception and the one-time "outdated" after the first CP save of origin content written outside the CP.
- §A6 and §A7: `T` and the reuse pairing use the target-side walk.
- §A11 step 3: the normalised value has the same target leaves as the assembled one.
- §A12: the CLI driver applies in its own process.
- T4.2: `WalkContext` knows the side; `mapFields()` skips nested fields equal to their default on target walks; `leaves()` and `inject()` take `$target`; a self-check case.
- T4.8, T4.15, T4.16: status, `targetHash`, the alignment guard and the recomputed hash use the target-side walk.
- T4.12: `Pricing` subtracts cache tokens from `input` and never adds reasoning.
- T5.3: parent right before `fields()`; the pending hash equals the assembled one because defaults are left out.
- T5.9: the published toggle of a localization created in the form is core's.
- T6.6, T6.10, T6.11: self-check cases for defaulted set fields; T6.10's memory and T6.11's batch note corrected.
- T7.4, T7.5: review pairs and writes target leaves with the target-side walk; parent before `fields()`.
- T8.10: no `tolk:apply`; the command applies in its own process; the CP-editing warning is removed.
- T8.11: the tree-view redirect is checked in core's front-end source on GitHub.
- T9.6: doctor check 2 reports a host-configured `ai.providers.tolk_{provider}`.
- T13.1: `statuses.md` explains the defaults rule and the one-time "outdated".
- Risks table: the CP-save row is settled.
- After the owner's answer: D13 and its status, "Project at a glance", the `statamic/cms` version pin, §A11 "Create" (the blueprint key described for 6.35.0 and later), T5.9 (slug steps for 6.35.0 only), T8.11 (tree-view actions are always there), and the #15509/#15551 risks row now say `^6.35`. Outside `TASKS.md`: `composer.json` (`statamic/cms ^6.35`, lock hash refreshed), the README requirements, the `CLAUDE.md` stack line, and the sandbox's lock (`composer update reachweb/statamic-tolk`, committed there).

## Addon settings

Checked in T3.4 against Statamic 6.35.0 (`tests/Feature/SettingsTest.php`, and the sandbox's settings page).

- R4 §12.1 left open what `Settings::all()` makes of booleans: `false` becomes `''` and `true` becomes `'1'`. `raw()` keeps them as booleans, and so does the YAML file.
- Once an addon has a settings blueprint, `all()` holds every field's default even when nothing is saved, so "nothing saved" is checked with `raw()`.
- The settings page saves every field of the blueprint, empty ones included (`PublishForm::submit()` returns all values): a text field stores whatever the form sends, an emptied `collections` or `taxonomies` field is stored as `null` (the relationship fieldtype turns `[]` into `null`), and the display-only `info` field as `null`. `Support\Settings` treats a stored `null` as "not set" and uses its default.
- A `sites` field with `max_items: 1` stores a string. Inside a grid an empty one is left out of the row, because the grid removes null values. Every grid row gains an `id`.

## Editor flow

Seen in T5.8, 2026-10-04, in the sandbox on Statamic 6.35.0 with headless Chromium (Playwright 1.63.0).

### An inherited Bard field desyncs itself when the form loads

- The German home page (`pages/de/home`) stores only `title`; its `content` (Bard) is inherited. When the form loads, the Bard field desyncs itself: `content` is in `localizedFields`, the form is dirty (`Statamic.$dirty.has('base')`) without an edit, and its label shows the "desynced" icon. Measured from navigation start, the form turned dirty at about 690 ms, some 30 ms before the banner's status request, so before the banner's second clean-value refresh (600 ms after its mount).
- The next Save posts `content` in `_localized` and stores the origin's Bard JSON, with Bard's normalised attributes (`textAlign: null` on paragraphs and headings, `rel`, `target` and `title` on links), as the localization's own value. The texts are the origin's, in English.
- This is core: the same happens with the addon's CP assets blocked (`page.route('**/vendor/statamic-tolk/**', …)`) and as the restricted editor, who gets no banner. The origin's set already has its `attrs.id`. The Greek articles, which store every localizable field, are not dirty on load and post only their stored keys.
- Because the form is dirty, an in-place site switch from it opens core's "Unsaved Changes" modal (buttons Cancel and Continue).
- Consequences:
  - The editor's `skip` list (T5.8) must not count such a field as an unsaved edit. "Localized in the form although nothing is stored" would; T5.8 counts only fields localized since the clean values were taken (`registry.newlyLocalized()`).
  - Bard is out of scope until Phase 6, so nothing reads the stored copy yet. Once Bard is in scope, a localization saved in the CP holds an override whose texts equal the origin's and that has no state: §A6 makes it **manual** and untracked, so Translate and Update leave the English text alone. T6.11 has to decide how such a copy is treated (risks table).

### Save hooks

- `Statamic.$hooks.on(name, callback, priority = 10)`; callbacks get `(resolve, reject, payload)` and run highest priority first; a rejection reaches the save pipeline as it is.
- `entry.saving` gets `{ collection, values }` and `entry.saved` `{ collection, reference, response }`. Neither names the publish container, and a stacked inline editor's save runs the same hooks. `reference` is the form's `initialReference`, which an in-place site switch does not update; `response.data.data.reference` names the entry that was saved.
- `import { PipelineStopped } from '@statamic/cms/save-pipeline'` works in the addon's build (`__STATAMIC__.savePipeline`). A refused save sends no request, and core shows no toast and logs no error.
- `Statamic.$hooks` and `Statamic.$events` can be used inside `Statamic.booting()`.
- `Statamic.$toast` has `success`, `info` and `error` (no `warning`); the second argument takes `{ duration }`.
- In Playwright, `page.keyboard.press('ControlOrMeta+s')` is core's quick save and stays on the form; the Save & Publish button leaves for the listing (the default "after saving" preference), and `editor/confirm` still completes because the hook resolves only after it.

### The offer and the slug (T5.9)

Seen in T5.9, 2026-10-04, on Statamic 6.35.0 in the sandbox (pages `about` and `dining` created in German through core's origin picker), with core's front-end source read on GitHub at `v6.35.0` (`SlugFieldtype.vue`, `slugs/Slugify.vue`, `ui/Publish/Container.vue`, `entries/PublishForm.vue`, `ui/Modal/Modal.vue`, `Preference.js`).

- `localization.created` passes `{ container }`, the container's exposed instance; its `name` is a plain string (`name: props.name`). The event comes from `editLocalization(...).then(...)`, after the values swap. Read from Vue's scheduler, the banner's `flush: 'post'` watcher has already run its switch by then; `editor/offer.js` waits for either order.
- Core's slug reset (`Slugify.reset()`) slugifies the inherited title and only then turns following on; the slug fieldtype writes `meta.slug.auto: true` at once, but passes the new slug up through its 150 ms debounced update. So for about 150 ms the form shows the origin's slug while following the title. Slugify stops following only when its `to` value differs from the slug it generated, so setting a value equal to the one the form shows changes nothing. Two cases hit this: the window above, and a title whose slug is the origin's slug (`Dining` → `dining`). Setting `''` first, 50 ms later the origin's slug, stops the following in both; the debounced update then carries the origin's slug as the last value. Without the empty step, the browser showed `de-dining` (and, when the poll hit the window, `de-about-us`) until the recheck one second after the fills put the slug back.
- The save posts `_auto_slug: this.meta.slug?.auto ?? false`, and `EntriesController::resolveSlug()` stores the posted slug unless `_auto_slug` is true; whether `slug` is in `_localized` does not matter. A localization is stored as `{slug}.md` right at creation (core's `/localize`), so a translated slug shows as that file being renamed on Save.
- `Statamic.$preferences.set('tolk.offer', false)` posts to `cp/preferences/js`; core's `setPreference()` takes the dotted key through `Arr::set()` and `cleanupPreference()` keeps booleans. After a reload, `Statamic.$preferences.get('tolk.offer', true)` reads `false` and the offer stays away (browser scenario). The CP has no screen to turn it back on.
- Core's `Modal` passes its attributes to the dialog element, emits `dismissed` on Esc or a click outside (before its 300 ms close), and focuses the first focusable element. In the UI kit's `Checkbox` the label covers the box, so a scenario clicks the label text.
- Timing in the scenarios: the status of the German home page can arrive well inside the 600 ms after the banner mounts, before the banner takes the clean values again (T5.6). Text typed in that window counts as loaded, so `editor-guards.spec.js` now waits 700 ms before it types. The specs that save also reset the sandbox when they finish, because `editor-banner.spec.js` reads the German home page's counts without resetting.

### Bard's re-emit and the stored copy (T6.11)

Seen in T6.11, 2026-10-04, on Statamic 6.35.0 in the sandbox with headless Chromium (Playwright 1.63.0).

- Why the German home page's `content` desyncs itself: the seeded English value has a block-level `image` node, and Bard's schema allows images only inline, so the editor wraps it in a paragraph when it loads the value and re-emits the result. The re-emitted value also adds `textAlign: null` to paragraphs and headings and `rel`, `target` and `title` (all `null`) to link marks. Its update desyncs the field. Measured from navigation start, the form turned dirty at about 880 ms, near the banner's mount, so before or after the banner's first clean values and before its 600 ms refresh. A Bard value the schema can hold as it is (the `about` page) does not re-emit on load.
- A value comparison cannot tell this apart from an edit (a paragraph is added), but the text is unchanged. The editor now compares text only (`registry.js` `textOf()`: the strings of a value in key order, without `type`, `id`, `_id` and `marks`), and untranslated fields are compared with the origin's values (`ctx.originValues`), which the form refreshes on every switch. With the previous comparison, a Translate clicked right after the self-desync and before the 600 ms refresh skipped `content` every time (`editor-rich.spec.js`, run against the T6.10 build).
- The copy the next save stores is the origin's Bard value as re-emitted (the image in a paragraph, the `null` attributes). Its leaves, walked as a target, equal the source's: the image's paragraph has no text of its own and the alt is the same leaf either way, and inline descriptors drop `null` attributes. `StatusResolver` reads such an override without a source fingerprint as untranslated (§A6).
- An article whose Replicator or Grid rows have no stored `id` gets new random ids for them each time a translation built from the source is saved: pre-processing generates an `_id` for a row without one (`Replicator::preProcessRow()`), and the source has none to carry over. A CP save of the origin gives them ids; until then, an Update changes those ids in the target's file as well as the edited text.
- Not changed here: the article's `anchor` field holds `ideas`, a single lowercase word, which §A3's rules treat as text, so it is translated (`[el] ideas`), while `special-offers` is left alone. An anchor field should carry `tolk_translate: false` (T6.8); the sandbox's blueprint does not.

### Review in the editor (T7.5)

Seen in T7.5, 2026-10-05, on Statamic 6.35.0 in the sandbox with headless Chromium (Playwright 1.63.0).

- The form's values are in publish-form shape, and only `process()` turns them into what a save stores, as `EntriesController::update()` does (`blueprint()->fields()->addValues($data)->process()->values()`, then `only($request->input('_localized'))` for a localization). For text the walk gives the same leaves either way, but Replicator rows hold `_id` in the form and `id` in storage: a review that kept the form's rows would deliver them with new ids, because `Replicator::preProcessRow()` generates one for a row without `id`. `editor/review` therefore processes the posted values first.
- With the fake engine and no provider keys, the translator and the reviewer both resolve to `anthropic:claude-sonnet-5-5`, so usage has one model key and the summary names it for both lines.
- On `fake-engine-markers` the body is delivered incomplete (`[[invalid]]` and `[[refuse]]`), so it reads outdated and a second review leaves it out; the `[[review]]` paragraph's correction already equals the reviewer's answer, which the pipeline drops. A browser scenario that needs a second correction puts `[[review]]` into the origin's title.
- The browser harness reuses stored logins while they are valid, but the session cookie expires a fixed `SESSION_LIFETIME` (120 minutes) after the login, however active the session is. A login reused shortly before that limit logged the scenarios after it out in the middle of a run; the global setup now renews a login whose cookie expires within 30 minutes.

### Field actions (T8.12)

Seen in T8.12, 2026-10-05, on Statamic 6.35.0 in the sandbox with headless Chromium (Playwright 1.63.0), and in core's source at `v6.35.0`.

- `toFieldActions()` builds the actions and filters them by `visible` inside the fieldtype's `fieldActions` computed, so whatever `visible` reads reactively (the registry's containers, the store's status and phase) re-evaluates it. Non-quick actions go into the dots dropdown, whose items ignore `disabled` (`FieldActions.vue`), so an action that cannot run at the moment has to be hidden.
- The confirmation modal (`confirm` without `fields`) is a `confirmation-modal` with no publish container, so it does not count as another container. With `fields` it mounts a `PublishContainer` named `field-action-modal-{id}`.
- A field inside a Replicator or Bard set has no `localizable` key in its config, so `config.localizable` already rules nested fields out in the sandbox; `fieldPathPrefix` still matters for a blueprint that marks a set field localizable.
- An `entries` field's item link opens the related entry in a stack (`[data-ui-stack-content]`, container `relate-fieldtype-inline-{n}`); Escape closes it. Field headers are `[data-ui-field-header]` with the field's `label[for="field_{path}"]`, and a stack repeats the page form's ids.
- With the field typed into before the action runs, the banner's `skip` lists it as an unsaved edit and the plan skips it as `edited_in_form`; the action therefore passes `replace`, which keeps the requested field out of `skip` (text typed while it runs is still kept by the fill).

### Revisions (T9.1)

Read in core's source at `v6.35.0` and run in Pest and in the sandbox (fake engine, `STATAMIC_REVISIONS_ENABLED=true` and `revisions: true` on `articles` for the check only), 2026-10-05.

- `Revisable::store()` saves the entry (`published(false)`, `updateLastModified($user)`), then, with revisions on, sets its return value to the revision's `save()`. A save that an `EntrySaving` listener cancelled therefore still makes a revision and reads as success. The addon does the two steps itself and checks each one.
- A revision's file is `{key}/{timestamp}.yaml` with the timestamp in seconds, and `revisions()` keys them by that timestamp. Two revisions of one entry in the same second leave only the later one: a localization that is created (store) and published (`publish()`) in one second keeps only its publish revision. The CP shows the same for a human who localizes and publishes within a second.
- `EntriesController::update()` decides on `$entry->fromWorkingCopy()->published()`: published goes to `makeWorkingCopy()->user(...)->save()` with no `updateLastModified()`; unpublished saves the working-copy view directly. `revisionAttributes()` leaves `updated_at` and `updated_by` out of every revision.
- `publishWorkingCopy()` starts from `fromWorkingCopy()`, which is a clone of the entry itself when it has no working copy, so a new localization's in-memory fields are published by `publish()` without a direct save first. It calls `updateLastModified($user)` even with no user, which removes `updated_by`.
- `RevisionSaved` and `RevisionDeleted` are in `ListensForContentEvents`, so Statamic's git subscriber commits on them; inside `ContentWriter::write()` they are suspended like the other content events, and a run on a collection with revisions commits once.
- Tests: `AddonTestCase` does not set the revisions store's directory, so it defaults to Testbench's `storage/statamic/revisions`, shared by every test. The addon's `TestCase` points it at the test's temporary folder.

### Terms (T9.2)

Read in core's source at `v6.35.0` (`TermsController`, `TermActionController`, `resources/js/components/terms/PublishForm.vue`, `pages/taxonomies/Show.vue`) and run in Pest and in the sandbox with the fake engine, 2026-10-05.

- `LocalizedTerm::origin()` returns the term in the default site, also on the default site's term itself; `hasOrigin()` and `isRoot()` tell them apart. Code that reads `origin()` to find out whether an item is a localization must check `hasOrigin()` for terms.
- `LocalizedTerm` has no `remove()`. The addon removes a cleared field with `data($localized->data()->except($handles))`, which keeps every other key of that site (`updated_at`, `updated_by`, `slug`).
- The term form runs `term.saving` (`{ taxonomy, values }`) and `term.saved` (`{ taxonomy, reference, response }`), with the form's `initialReference` as `reference`, as the entry form does. Its response (`Http\Resources\CP\Taxonomies\Term`) has `id`, `title`, `permalink` and `edit_url`, no `reference`; `id` is the same in every site. The form switches sites in place (`editLocalization()` sets `reference`), so after a switch the hook's `reference` names the site the form was opened in. The term form does not replace its values from the response.
- `TermsController::update()` calls `$term->slug($request->slug)` on every save, so a localization saved in the CP stores the slug the form posted in its own data. The topics blueprint's `slug` is not localizable, so the form shows it read-only and posts the default site's slug; it is not regenerated from a translated title (`tests/Browser/terms.spec.js`).
- The term listing posts actions to `taxonomies.terms.actions.run` / `.bulk` with the context `{ taxonomy }`; `TermActionController` resolves each selection with `Term::find()`, the term in its taxonomy's default site. Core's blueprint for a term is cached per term id (`term-{id}-blueprint`) and shared by every site, with the file `Term` as parent; the addon sets the localized term as parent right before `fields()`.
- `$user->can('edit', $localizedTerm)` checks access to the term's site: core registers `TermPolicy` for the `Term` contract, but Laravel's policy guessing resolves `Statamic\Policies\LocalizedTermPolicy` for the `LocalizedTerm` class first, and its `edit` returns false without `access {site} site`. `TermTarget::authorize()` keeps its own site check (§A14).

### Global sets (T9.3)

Read in core's source at `v6.35.0` (`Globals\GlobalSet`, `Globals\Variables`, `Stache\Stores\GlobalVariablesStore`, `GlobalVariablesController`, `GlobalSetVariablesPolicy`) and run in Pest and in the sandbox with the fake engine, 2026-10-06.

- Variables made by `GlobalSet::makeLocalization()` (which `GlobalSet::save()` calls for every new site) hold the set object itself; variables read from their file hold only the set's handle. The Stache caches the first kind with a copy of the set as it was then, so after the set's `sites()` change, `$set->in($site)->origin()` and `hasOrigin()` can still answer from the old origins until the Stache is rebuilt. Seen in Pest with a file cache store: after `sites(['en' => null, 'el' => 'en', 'de' => 'el'])->save()`, `in('de')->origin()` still named `en`. `GlobalsTarget` therefore reads every origin from `$set->origins()` of a freshly found set, never from `Variables::origin()`. Core's own edit page uses `$variables->origin()`, so it can show the stale origin too.
- A set without a blueprint gets `Variables::fallbackBlueprint()`: one `text` field per stored value, none of them localizable. `GlobalsTarget` reports it as `no_blueprint` instead of listing those fields as `not_localizable`, because there is no blueprint to change until one is created.
- `Variables` has `remove()` (`ContainsData`), no `updated_at` / `updated_by` and no revisions. `save()` returns `false` when a `GlobalVariablesCreating` or `GlobalVariablesSaving` listener cancels it.
- `GlobalSetVariablesPolicy::edit` checks access to the variables' site and the `edit {handle} globals` permission; the set's own `edit` is left to `before()` (super users and `configure globals`), as §A14 says.
- `GlobalSet::save()` deletes the variables of a site removed from the set, and `delete()` deletes the variables of every site, each firing `GlobalVariablesDeleted`; `ForgetStateOfDeletedContent` forgets their state on that event.
