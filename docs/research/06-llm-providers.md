# 06 — LLM providers for the translation addon: Anthropic Claude, OpenAI, Google Gemini

Research date: 2026-10-01. Read-only web research against the vendors' own documentation.

## How to read this report

- Every figure carries a source tag such as `[A-models]`. Tags resolve to URLs in the "Sources" list at the top of each provider section.
- Verification method: pages were fetched on 2026-10-01. Wherever the vendor serves a Markdown twin of the page (`platform.claude.com/...md`, `developers.openai.com/...md`, `ai.google.dev/...md.txt`), figures were read from that verbatim text rather than from a summary.
- "Could not verify" means the vendor documentation consulted does not state the fact. Nothing in this report is filled in from memory.
- Sections titled "Recommendation" are my judgement, with reasoning. Everything else is reported fact.
- All three lineups changed within the last weeks (Claude Sonnet 5.5 was released 2026-09-28 `[A-sonnet]`; Gemini 3.8 Flash "Latest update: September 2026" `[G-38]`). Keep model IDs configurable.

## 0. Decision summary

| | Anthropic | OpenAI | Google Gemini |
|---|---|---|---|
| Fast default (recommendation) | `claude-haiku-4-5` (thinking off by default) | `gpt-6-luna` with `reasoning.effort: "none"` | `gemini-3.5-flash-lite` (default thinking `minimal`) |
| Quality default (recommendation) | `claude-sonnet-5-5` with `effort: "low"` and `thinking: {"type":"between_tools"}` | `gpt-6.1-sol` with `reasoning.effort: "low"` | `gemini-3.8-flash` with `thinkingLevel: "low"` |
| Optional reviewer (recommendation) | `claude-opus-5-5` at `effort: "low"` or `"medium"` | `gpt-6-astra` at `low` | `gemini-3.8-flash` at `medium`; `gemini-3.1-pro-preview` is preview only |
| Price in / out per 1M tokens (fast; quality) | $1 / $5; $2 / $10 `[A-models]` | $0.10 / $0.50; $2 / $10 `[O-pricing]` | $0.30 / $2.50; $0.75 / $3.75 until 2026-12-31, then $1.50 / $7.50 `[G-pricing]` |
| Max output tokens (fast; quality) | 64K; 128K `[A-models]` | 128,000; 128,000 `[O-luna]` `[O-sol]` | 65,536; 65,536 `[G-35lite]` `[G-38]` |
| Structured output switch | `output_config.format` `{type:"json_schema", schema}` `[A-so]` | `text.format` `{type:"json_schema", strict:true, schema}` (Responses) or `response_format` (Chat Completions) `[O-so]` | `responseMimeType: "application/json"` + `responseJsonSchema` (generateContent) `[G-so]` |
| Dynamic keys in schema | No (`additionalProperties` must be `false`) `[A-so]` | No (`additionalProperties: false` required, all fields required) `[O-so]` | Yes on paper (`additionalProperties` may be a boolean or a schema) `[G-so]` |
| Prompt cache for a shared system prompt | Explicit `cache_control` on the last static system block; automatic caching does not fit this shape `[A-cache]` | Explicit breakpoint (`prompt_cache_options.mode: "explicit"` + `prompt_cache_breakpoint`) on a developer-message content block; implicit mode does not fit this shape `[O-cache]` | Implicit caching is automatic but not guaranteed; explicit `cachedContent` objects guarantee it (generateContent only) `[G-cache]` |
| Minimum cacheable prefix | 512 tokens (Sonnet 5.5, Opus 5.5, Fable 5.1); 4,096 (Haiku 4.5) `[A-cache]` | 1,024 tokens (GPT-5.6 and later, incl. GPT-6) `[O-cache]` | 4,096 tokens (3.8/3.7/3.6/3.5 Flash, 3.1 Pro Preview); Flash-Lite: could not verify `[G-cache]` |
| Cache TTL | 5 min (refreshed on use) or 1 h `[A-cache]` | 30 min after last write or reuse `[O-cache]` | Implicit: could not verify; explicit: default 1 h, configurable `[G-cache]` |
| Cache price | Write 1.25x (5 min) / 2x (1 h); read 0.1x (0.05x Opus 5.5, 0.025x Fable 5.1) `[A-pricing]` | Write 1.25x; read 0.1x (0.05x on GPT-6.1 Sol) `[O-cache]` | Cached tokens about 10% of input price; explicit caches add storage per hour `[G-pricing]` |
| Entry-tier limits | Start tier: 1,000 RPM, 2,000,000 ITPM, 400,000 OTPM per model `[A-limits]` | Tier 1: 500 RPM, 500,000 TPM per GPT-6 model `[O-luna]` `[O-sol]` `[O-astra]` | Not published in docs (AI Studio only); Tier 1 spend limit $10 per 10 minutes `[G-limits]` |
| Batch | 50% off, most batches under 1 h, 24 h expiry `[A-batch]` | 50% off, 24 h window `[O-batch]` | 50% off, 24 h target, expires at 48 h `[G-batch]` |
| Training on API data by default | No `[A-priv-train]` | No `[O-data]` | Paid tier: no. Free tier: yes `[G-terms]` |

Three provider-side traps that affect the addon design directly:

1. Do not send `temperature`, `top_p` or `top_k`. Claude Opus 4.7 and later (including Sonnet 5.5, Opus 5.5, Fable 5.1) return a 400 for non-default values `[A-deprec]` `[A-thinking]`; OpenAI says to remove `temperature` and `top_p` on GPT-6 whenever reasoning effort is not `none` `[O-gpt6]`; Google says to strip them for Gemini 3.8 Flash and "strongly recommend[s]" defaults for all Gemini 3.x `[G-latest]` `[G-g3]`.
2. Structured output must use the native schema feature, not forced tool calls. Claude Opus 5.5, Sonnet 5.5 and Fable 5.1 reject `tool_choice` of type `any` or `tool` with a 400 `[A-errors]`. On OpenAI, GPT-6 Astra and GPT-6.1 Sol do not support tool calling in Chat Completions at all `[O-gpt6]`.
3. A shared system prompt is not cached automatically on Anthropic or OpenAI when the user message differs per request. Both need an explicit breakpoint at the end of the static part (details in 1.4 and 2.4).

---

## 1. Anthropic (Claude API)

Sources:

- `[A-models]` https://platform.claude.com/docs/en/about-claude/models/overview
- `[A-choose]` https://platform.claude.com/docs/en/about-claude/models/choosing-a-model
- `[A-pricing]` https://platform.claude.com/docs/en/about-claude/pricing
- `[A-deprec]` https://platform.claude.com/docs/en/about-claude/model-deprecations
- `[A-haiku]` https://platform.claude.com/docs/en/models/haiku-4-5/overview
- `[A-sonnet]` https://platform.claude.com/docs/en/models/sonnet-5-5/overview
- `[A-so]` https://platform.claude.com/docs/en/build-with-claude/structured-outputs
- `[A-cache]` https://platform.claude.com/docs/en/build-with-claude/prompt-caching
- `[A-limits]` https://platform.claude.com/docs/en/api/rate-limits
- `[A-errors]` https://platform.claude.com/docs/en/api/errors
- `[A-batch]` https://platform.claude.com/docs/en/build-with-claude/batch-processing
- `[A-effort]` https://platform.claude.com/docs/en/build-with-claude/effort
- `[A-thinking]` https://platform.claude.com/docs/en/build-with-claude/thinking
- `[A-s55]` https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-sonnet-5-5
- `[A-latency]` https://platform.claude.com/docs/en/test-and-evaluate/strengthen-guardrails/reduce-latency
- `[A-multi]` https://platform.claude.com/docs/en/build-with-claude/multilingual-support
- `[A-prompt]` https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-prompting-best-practices
- `[A-retention]` https://platform.claude.com/docs/en/manage-claude/api-and-data-retention
- `[A-priv-ret]` https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data
- `[A-priv-train]` https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training

### 1.1 Current lineup

All rows from `[A-models]` unless tagged otherwise.

| Model | API ID (alias) | Vendor positioning | Comparative latency | Context | Max output | Input / output per MTok | Thinking | Default effort |
|---|---|---|---|---|---|---|---|---|
| Claude Fable 5.1 | `claude-fable-5-1` | "For demanding reasoning and long-horizon agentic work"; "most capable model open to all customers" `[A-choose]` | Slower | 1M | 128K | $10 / $50 | Adaptive (always on) | `high` |
| Claude Opus 5.5 | `claude-opus-5-5` | "For long-running agentic coding and knowledge work"; "start with Claude Opus 5.5 for most workloads" | Moderate | 1M | 128K | $4 / $20 | Adaptive (always on) | `medium` |
| Claude Sonnet 5.5 | `claude-sonnet-5-5` | "The best combination of speed and intelligence" | Fast | 1M | 128K | $2 / $10 | Adaptive | `high` |
| Claude Haiku 4.5 | `claude-haiku-4-5-20251001` (alias `claude-haiku-4-5`) | "The fastest model with near-frontier intelligence" | Fastest | 200K | 64K | $1 / $5 | Extended (manual, off by default) | Not supported |

Notes:

- IDs: "Every Claude model ID is a pinned snapshot, including the dateless IDs used from the 4.6 generation on." Only Haiku 4.5 has a separate alias `[A-models]`.
- Legacy but still available: Fable 5, Opus 5, Opus 4.8, 4.7, 4.6, 4.5, Sonnet 5, Sonnet 4.6. Sonnet 4.5 was deprecated on 2026-09-30 and retires 2026-11-30 `[A-deprec]`.
- Haiku 4.5: status "Active", released 2025-10-15, retirement "Not sooner than October 15, 2026" `[A-haiku]` `[A-deprec]`. That commitment window ends in two weeks. No deprecation has been announced, and Anthropic gives "at least 60 days' notice before model retirement" `[A-deprec]`. No newer Haiku is listed.
- Sonnet 5.5: released 2026-09-28 `[A-sonnet]`.
- Tokenizer: "Claude 4.7 and later models ... use a newer tokenizer ... This tokenizer produces approximately 30% more tokens for the same text." Haiku 4.5 uses the previous tokenizer `[A-pricing]`. The real price gap between Haiku 4.5 and Sonnet 5.5 for the same text is therefore larger than the 2x list-price gap (inference from those two statements).
- 1M context is billed at standard rates on Claude 4.6 and later (no long-context surcharge) `[A-pricing]`.
- Published speed: only the comparative latency labels above. "Actual latency depends on prompt length, output length, and thinking effort" `[A-models]`. No tokens-per-second figure is published (could not verify one). Fast mode ("up to 2.5x higher output speed at premium pricing") exists only for Opus 5.5, Opus 5 and Opus 4.8, as a research preview `[A-choose]` `[A-pricing]`.
- Latency guide: "For speed-critical applications, Claude Haiku 4.5 offers the fastest response times" `[A-latency]`.

### 1.2 Recommendation: fast and quality defaults

- Fast: `claude-haiku-4-5`. It is the vendor's "Fastest" tier and "lowest latency and price" `[A-choose]`, and thinking is off unless requested `[A-thinking]`. Caveats: 64K output cap, 4,096-token cache minimum (1.4), older tokenizer, and the retirement note above.
- Quality: `claude-sonnet-5-5` with `output_config.effort: "low"` and `thinking: {"type": "between_tools"}`. Sonnet 5.5 is positioned for "content creation" and "Speed and capability for everyday ... workloads" `[A-choose]`. Anthropic's own guidance for latency-sensitive work is to "start with `medium` or `low`" `[A-effort]`. If quality at `low` is not enough, move to `medium` with adaptive thinking.
- Reviewer for the optional second pass: `claude-opus-5-5` at `effort: "low"` or `"medium"`. Thinking cannot be turned off on Opus 5.5, so effort is the only latency control `[A-effort]`.
- Not a default: Fable 5.1 ($10 / $50, "Slower", and it requires 30-day retention, so it is unavailable under zero data retention) `[A-retention]`.

### 1.3 Structured outputs

- Enable with `output_config.format = {"type": "json_schema", "schema": {...}}`. Generally available, no beta header. The older `output_format` parameter is deprecated `[A-so]`.
- Supported on all current models including `claude-haiku-4-5-20251001`, `claude-sonnet-5-5`, `claude-opus-5-5`, `claude-fable-5-1` `[A-so]`.
- Mechanism: "constrained decoding"; "Always valid: No more `JSON.parse()` errors" `[A-so]`.
- Schema limits `[A-so]`:
  - `additionalProperties` "must be set to `false` for objects". Anything else is unsupported, so dynamic keys cannot be expressed.
  - Not supported: recursive schemas, `minimum` / `maximum` / `multipleOf`, `minLength` / `maxLength`, array constraints beyond `minItems` of 0 or 1, external `$ref`.
  - Explicit complexity limits per request: 24 optional parameters in total, 16 parameters with union types (`anyOf` or type arrays), 20 strict tools.
  - Internal grammar-size limits: 400 "Schema is too complex for compilation"; compilation timeout 180 seconds.
  - No nesting-depth or property-count number is published (could not verify). The advice is "Simplify nested structures ... Flatten structures where possible".
  - Property order: required properties first, then optional ones.
- Latency: "The first time you use a specific schema, there is additional latency while the grammar compiles"; compiled grammars are "cached for 24 hours from last use". Changing only `name` or `description` does not invalidate that cache `[A-so]`.
- Token cost: an extra system prompt describing the format is injected. "Changing the `output_config.format` parameter will invalidate any prompt cache for that conversation thread" `[A-so]`.
- Streaming: works ("Stream structured outputs like normal responses"). Batch: works. Incompatible with citations and with message prefill `[A-so]`.
- Failure modes `[A-so]`:
  - `stop_reason: "max_tokens"`: "The output may be incomplete and not match your schema".
  - `stop_reason: "refusal"`: 200 status, billed, output may not match the schema.
  - Enum and const casing is not guaranteed.
- Sonnet 5.5 specific: "With structured outputs at `low` and `medium` effort, the model occasionally keeps thinking until it reaches `max_tokens`. ... Treat any response whose `stop_reason` is `"max_tokens"` as failed, even if its text holds valid JSON, and retry." `[A-s55]`. With `between_tools` and no tools there is no up-front thinking at all `[A-s55]`, which sidesteps this.
- Forced tool use as a structured-output workaround is not available on Opus 5.5, Sonnet 5.5 or Fable 5.1 (400 error) `[A-errors]`.

### 1.4 Prompt caching

- Two modes `[A-cache]`:
  - Explicit: `cache_control: {"type": "ephemeral"}` on a content block. Up to 4 breakpoints.
  - Automatic: one top-level `cache_control` field; the breakpoint goes on the last cacheable block.
- For a shared system prompt followed by a different user message, automatic caching does not work. The doc says so directly: automatic caching "places the breakpoint on the last cacheable block, which in this structure is the one that changes every request, so use an explicit breakpoint" `[A-cache]`. "Cache writes happen only at your breakpoint" `[A-cache]`.
- What must match: "Cache hits require 100% identical prompt segments, including all text and images up to and including the block marked with cache control." Prefix order is `tools`, then `system`, then `messages` `[A-cache]`.
- What else invalidates `[A-cache]` `[A-so]` `[A-thinking]`: tool definitions (everything); thinking configuration and `output_config.effort` (rendered into the prompt; "Treat any thinking or top-level effort change as starting the cache over"); `tool_choice` and presence of images (messages cache); `output_config.format`.
- Minimum cacheable prefix `[A-cache]`: 512 tokens for Fable 5.1, Opus 5.5, Opus 5, Sonnet 5.5, Fable 5; 1,024 for Opus 4.8, Sonnet 5, Sonnet 4.6; 4,096 for Haiku 4.5. Below the minimum the request is processed without caching "and no error is returned"; check `cache_creation_input_tokens` and `cache_read_input_tokens`.
- TTL `[A-cache]`: 5 minutes by default, "refreshed for no additional cost each time the cached content is used"; measured from the start of the request. 1-hour option via `"ttl": "1h"`.
- Price multipliers `[A-pricing]`: 5-minute write 1.25x, 1-hour write 2x, read 0.1x (0.05x on Opus 5.5, 0.025x on Fable 5.1). Sonnet 5.5: $2.50 / $4 / $0.20 per MTok. Haiku 4.5: $1.25 / $2 / $0.10.
- Parallel requests: "a cache entry only becomes available after the first response begins. If you need cache hits for parallel requests, wait for the first response before sending subsequent requests." `[A-cache]`
- Pre-warming: `max_tokens: 0` writes the cache without generating output, but it is rejected when `output_config.format` (structured outputs) or `stream: true` is set `[A-cache]`. Whether a pre-warm sent without `output_config.format` shares a cache entry with later requests that include it: could not verify. Given the invalidation note in 1.3, assume it does not.
- Isolation: per workspace on the Claude API `[A-cache]`.
- Rate-limit benefit: cache reads do not count toward ITPM on current models `[A-limits]`.

### 1.5 Rate limits and errors

- Dimensions: RPM, input tokens per minute (ITPM), output tokens per minute (OTPM), per model class, enforced at organisation level with a token bucket `[A-limits]`.
- Tiers and monthly spend caps `[A-limits]`: Start $500, Build $1,000, Scale $200,000, Custom. "New organizations ... may start in the Evaluation tier, with limits below the standard limits shown on this page"; those limits are not published (could not verify).
- Start tier `[A-limits]`:

| Model | RPM | ITPM | OTPM |
|---|---|---|---|
| Opus 5.5, Sonnet 5.5, Haiku 4.5 (each its own bucket) | 1,000 | 2,000,000 | 400,000 |
| Fable 5.x (5.1 and 5 combined) | 1,000 | 500,000 | 100,000 |

- For Opus 5.5, Sonnet 5.5 and Haiku 4.5: Build tier 5,000 / 5,000,000 / 1,000,000; Scale tier 10,000 / 10,000,000 / 2,000,000. Fable 5.x is lower (Build 2,000 / 1,500,000 / 300,000) `[A-limits]`.
- ITPM counts `input_tokens` plus `cache_creation_input_tokens`; cache reads are excluded. OTPM counts generated tokens only; "there is no rate limit downside to setting a higher `max_tokens` value" `[A-limits]`.
- Short-window enforcement: "a rate of 60 requests per minute (RPM) might be enforced as 1 request per second". Acceleration limits: "ramp up your traffic gradually" `[A-limits]`.
- Headers `[A-limits]`: `retry-after`; `anthropic-ratelimit-requests-limit|remaining|reset`; `anthropic-ratelimit-tokens-limit|remaining|reset`; `anthropic-ratelimit-input-tokens-limit|remaining|reset`; `anthropic-ratelimit-output-tokens-limit|remaining|reset` (remaining values rounded to the nearest thousand; reset in RFC 3339).
- Errors `[A-errors]`:
  - 429 `rate_limit_error`: rate limit, with `retry-after`. The monthly spend-cap variant has no `retry-after`, carries `error.details.error_code: "enforced_spend_limit_reached"`, and must not be retried `[A-limits]`.
  - 529 `overloaded_error`: "The API is temporarily overloaded."
  - 500 `api_error`: "Retry the request with exponential backoff".
  - 504 `timeout_error`; 413 `request_too_large` (Messages API limit 32 MB).
  - A user-configured spend limit returns 400 `invalid_request_error`.
- Retry guidance: "The official SDK automatically retries transient failures (such as connection errors, rate limits, and 5xx server errors) with exponential backoff, twice by default, honoring the `retry-after` header when present." `[A-errors]`
- Long requests: use streaming or batches for requests "over 10 minutes"; SDKs require streaming when `max_tokens` is greater than 21,333 (client-side check) `[A-errors]` `[A-thinking]`.

### 1.6 Message Batches API

- 50% of standard prices on all usage; stacks with prompt caching `[A-batch]` `[A-pricing]`. Batch prices: Sonnet 5.5 $1 / $5, Haiku 4.5 $0.50 / $2.50, Opus 5.5 $2 / $10 `[A-pricing]`.
- Turnaround: "most batches completing within 1 hour"; results available when all requests finish or after 24 hours; "Batches expire if processing does not complete within 24 hours" `[A-batch]`.
- Limits: 100,000 requests or 256 MB per batch. Start tier: 200,000 batch requests in the processing queue, 1,000 RPM on the batch endpoints `[A-batch]` `[A-limits]`. Batch usage "does not affect rate limits in the Messages API" `[A-batch]`.
- Results kept 29 days after creation; order not guaranteed, match on `custom_id` (1 to 64 characters, `^[a-zA-Z0-9_-]{1,64}$`) `[A-batch]`.
- Result types: `succeeded`, `errored`, `canceled`, `expired`; only succeeded requests are billed `[A-batch]`.
- All active models supported. Not allowed inside a batch: `stream: true`, `speed`, `max_tokens: 0` `[A-batch]`.
- Caching inside batches is best effort: "cache hit rates ranging from 30% to 98%"; Anthropic suggests the 1-hour TTL for batches `[A-batch]`.

### 1.7 Thinking and effort

- Effort: `output_config.effort` with `low`, `medium`, `high`, `xhigh`, `max`. Default `high`, except Opus 5.5 (`medium`). Not supported on Haiku 4.5 `[A-effort]` `[A-models]`.
- Thinking modes by model `[A-thinking]`:

| Model | No `thinking` field | `adaptive` | `enabled` + `budget_tokens` | `between_tools` | `disabled` |
|---|---|---|---|---|---|
| Opus 5.5, Fable 5.1 | Adaptive | Adaptive | 400 | 400 | 400 |
| Sonnet 5.5 | Adaptive | Adaptive | 400 | Up-front thinking off (at `high` effort or below) | 400 |
| Haiku 4.5 | Thinking off | 400 | Extended thinking | 400 | Thinking off |

- Thinking tokens are billed as output and count toward `max_tokens`, even when the text is not returned (`display` defaults to `"omitted"` on the 5.x models) `[A-thinking]`.
- Minimising latency:
  - Haiku 4.5: send no `thinking` field.
  - Sonnet 5.5: `thinking: {"type": "between_tools"}` is "the lowest thinking setting"; it takes no other field; it returns 400 at `xhigh` or `max` `[A-s55]`. Otherwise lower effort: "At `low`, it skips thinking on most simple requests"; "From `medium` up, the model thinks briefly before almost every reply" `[A-s55]`. "Asking it in the system prompt to think less doesn't reliably reduce its thinking" `[A-s55]`.
  - Opus 5.5 and Fable 5.1: thinking cannot be disabled; lower effort `[A-effort]`.
- "Effort is a behavioral signal, not a strict token budget"; `max_tokens` is the hard ceiling `[A-effort]` `[A-thinking]`.

### 1.8 Translation and localisation guidance

- Multilingual page `[A-multi]`:
  - State the target language explicitly; "The most reliable place to do this is the system prompt".
  - "To translate between two specific languages, name both: `Translate the user's message from German to Korean. Respond with only the translation.`"
  - "For enhanced fluency, you can prompt Claude to use 'idiomatic speech as if it were a native speaker.'"
  - "Submit text in its native script rather than transliteration".
  - "Effective communication often requires cultural and regional awareness beyond pure translation."
  - Relative performance table (MMLU, older models with extended thinking): Haiku 4.5 at 96.4% (Spanish), 95.7% (French), 94.3% (German), 93.5% (Japanese), 92.5% (Arabic) of English. No figures are published for the 5.x models (could not verify).
- General prompting `[A-prompt]`: wrap instructions, context and input in XML tags; "Put longform data at the top ... above your query, instructions, and examples"; "Queries at the end can improve response quality by up to 30 percent"; "Include 3–5 examples" inside `<example>` tags.
- HTML or markup handling and glossary usage: no Anthropic documentation found (could not verify).

### 1.9 Data handling

- Training: "By default, we will not use your inputs or outputs from our commercial products (e.g. ... Anthropic API ...) to train our models." Exception: explicit feedback or opt-in `[A-priv-train]`.
- Retention: "For Anthropic API users, we automatically delete inputs and outputs on our backend within 30 days of receipt or generation", except zero-data-retention agreements, usage-policy enforcement and legal requirements `[A-priv-ret]`.
- Flagged content: inputs and outputs "for up to 2 years", trust and safety classification scores "for up to 7 years" `[A-priv-ret]`.
- The platform docs word it differently: "Conversation content ... is not retained by default; the exception is Covered Models, which require 30-day retention" (Fable and Mythos models) `[A-retention]`. For addon documentation, the conservative statement is "up to 30 days unless a ZDR arrangement is in place".
- ZDR is by arrangement with Anthropic sales, per organisation `[A-retention]`.
- Feature-specific: Batch API stores requests and responses "for up to 29 days" and is not ZDR-eligible; structured-output schemas are cached up to 24 hours; prompt-cache entries are held in memory for the TTL `[A-retention]` `[A-batch]` `[A-so]`.

---

## 2. OpenAI

Sources:

- `[O-models]` https://developers.openai.com/api/docs/models
- `[O-astra]` https://developers.openai.com/api/docs/models/gpt-6-astra
- `[O-sol]` https://developers.openai.com/api/docs/models/gpt-6.1-sol
- `[O-luna]` https://developers.openai.com/api/docs/models/gpt-6-luna
- `[O-gpt6]` https://developers.openai.com/api/docs/guides/latest-model
- `[O-select]` https://developers.openai.com/api/docs/guides/model-selection
- `[O-pricing]` https://developers.openai.com/api/docs/pricing
- `[O-so]` https://developers.openai.com/api/docs/guides/structured-outputs
- `[O-cache]` https://developers.openai.com/api/docs/guides/prompt-caching
- `[O-limits]` https://developers.openai.com/api/docs/guides/rate-limits
- `[O-errors]` https://developers.openai.com/api/docs/guides/error-codes
- `[O-batch]` https://developers.openai.com/api/docs/guides/batch
- `[O-flex]` https://developers.openai.com/api/docs/guides/flex-processing
- `[O-fast]` https://developers.openai.com/api/docs/guides/fast-mode
- `[O-reason]` https://developers.openai.com/api/docs/guides/reasoning
- `[O-prompt]` https://developers.openai.com/api/docs/guides/prompt-engineering
- `[O-latency]` https://developers.openai.com/api/docs/guides/latency-optimization
- `[O-data]` https://developers.openai.com/api/docs/guides/your-data
- `[O-ref-resp]` https://developers.openai.com/api/reference/resources/responses/methods/create
- `[O-ref-chat]` https://developers.openai.com/api/reference/resources/chat

Note: `platform.openai.com/docs/...` now redirects (301) to `developers.openai.com/api/docs/...`.

### 2.1 Current lineup

| Model | API ID | Vendor positioning | Context | Max input | Max output | Input / cached / cache write / output per 1M | `reasoning.effort` values |
|---|---|---|---|---|---|---|---|
| GPT-6 Astra | `gpt-6-astra` | "Our most capable model for the most demanding work"; "Highest intelligence" `[O-gpt6]` | 1,050,000 | 922,000 | 128,000 | $10 / $1 / $12.5 / $50 | `low`, `medium`, `high`, `xhigh`, `max` (no `none`) `[O-astra]` |
| GPT-6.1 Sol | `gpt-6.1-sol` | "Near-Astra performance for complex work at a lower cost"; "Balanced speed, cost, and intelligence" `[O-gpt6]` | 1,050,000 | 922,000 | 128,000 | $2 / $0.10 / $2.5 / $10 | `low`, `medium` (default), `high`, `xhigh`, `max`; `none` and `minimal` unsupported `[O-sol]` |
| GPT-6 Luna | `gpt-6-luna` | "Our most efficient model for focused, high-volume tasks"; "Fastest and most cost-effective" `[O-gpt6]` | 1,050,000 | 922,000 | 128,000 | $0.10 / $0.01 / $0.125 / $0.50 | `none`, `low`, `medium` (default), `high`, `xhigh`, `max` `[O-luna]` |

Specs and prices from `[O-astra]`, `[O-sol]`, `[O-luna]`, confirmed against `[O-pricing]`.

Notes:

- Aliases and snapshots: each page lists a single snapshot equal to the ID (for example "Default snapshot: `gpt-6-luna`"). No dated snapshots are listed.
- Vendor guidance: "If you're not sure where to start, use GPT-6 Astra ... Choose GPT-6.1 Sol to balance intelligence and cost, or GPT-6 Luna for cost-sensitive, high-volume workloads." `[O-models]`
- Still priced and available: `gpt-6-sol` ($2 / $10), `gpt-5.6-sol` ($4 / $20), `gpt-5.6-terra` ($2 / $12), `gpt-5.6-luna` ($0.20 / $1.20), and the GPT-5.x and GPT-4.1 families `[O-pricing]`.
- Price modifiers `[O-pricing]` `[O-luna]`: prompts over 272K input tokens cost 2x input and cache and 1.5x output for the whole request; Batch and Flex are 50% of standard; Fast mode is 2x; regional processing adds 10%.
- Knowledge cutoff: Astra and 6.1 Sol 2026-04-30; Luna 2026-05-18.
- API surface: all three support Responses, Chat Completions and Batch. "GPT-6 Astra and GPT-6.1 Sol support Chat Completions, but tool calling requires Responses. GPT-6 Sol and GPT-6 Luna support function calling in Chat Completions only with `reasoning_effort: "none"`." `[O-gpt6]`
- Published speed: no tokens-per-second figure (could not verify). Fast mode (`service_tier: "fast"` or `"priority"`) "delivers up to 2.5× faster speeds" at 2x price and shares the standard rate limit `[O-fast]`. Flex is slower and cheaper `[O-flex]`.

### 2.2 Recommendation: fast and quality defaults

- Fast: `gpt-6-luna` with `reasoning.effort: "none"`. It is the vendor's "Fastest and most cost-effective" tier and the only one of the three GPT-6 models that accepts `none`. OpenAI describes `none` as for "Latency-critical tasks that do not benefit from any reasoning" `[O-reason]`. If quality needs it, step to `low`.
- Quality: `gpt-6.1-sol` with `reasoning.effort: "low"` (its lowest setting). OpenAI describes `low` as "Efficient reasoning with a modest latency increase" `[O-reason]`.
- Reviewer: `gpt-6.1-sol` at `medium`, or `gpt-6-astra` at `low`. OpenAI's own matrix lists "Astra · Low: Concise writing and content adaptation that preserve facts and nuance" `[O-select]`, but at 5x the price of Sol.
- The default effort for GPT-6 Astra is not stated on its model page (could not verify). Always set effort explicitly.

### 2.3 Structured outputs

- Enable `[O-so]`: Responses API `text: { format: { type: "json_schema", "strict": true, "schema": ... } }`; Chat Completions `response_format: {type: "json_schema", ...}`. JSON mode (`json_object`) guarantees valid JSON only, not schema adherence.
- Schema subset `[O-so]`:
  - Root must be an object and must not be `anyOf`.
  - "All fields must be `required`"; optional fields are emulated with a `null` union.
  - "`additionalProperties: false` must always be set in objects". Dynamic keys cannot be expressed.
  - "A schema may have up to 5000 object properties total, with up to 10 levels of nesting."
  - "total string length of all property names, definition names, enum values, and const values cannot exceed 120,000 characters."
  - "up to 1000 enum values across all enum properties"; 15,000 characters for a single string enum with more than 250 values.
  - Supported string keywords listed: `pattern`, `format`. Number: `multipleOf`, `maximum`, `exclusiveMaximum`, `minimum`, `exclusiveMinimum`. Array: `minItems`, `maxItems`.
  - Not supported: `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`, `then`, `else`.
  - Whether `minLength` and `maxLength` work on non-fine-tuned models is ambiguous in the page (not in the supported list, but listed only as unsupported "for fine-tuned models"): could not verify.
  - `$defs` and recursive schemas are supported. Output keys follow schema order.
- Latency: "the first request you make with any schema will have additional latency as our API processes the schema, but subsequent requests with the same schema will not" `[O-so]`.
- Streaming: supported; "We recommend relying on the SDKs to handle streaming with Structured Outputs" `[O-so]`.
- Failure modes:
  - Truncation: Responses API `status: "incomplete"` with `incomplete_details.reason: "max_output_tokens"` `[O-so]`; Chat Completions `finish_reason: "length"` `[O-ref-chat]`.
  - Refusal: a `refusal` content item instead of schema-conformant output `[O-so]`.
  - Reasoning can exhaust the budget first: truncation "might occur before any visible output tokens are produced" `[O-reason]`.
  - "The model will always try to adhere to the provided schema, which can result in hallucinations if the input is completely unrelated to the schema." `[O-so]`
- The schema is part of the cache prefix (see 2.4).

### 2.4 Prompt caching

GPT-5.6 and later (which includes all GPT-6 models) behave differently from earlier models `[O-cache]`:

- Modes: `prompt_cache_options.mode` is `implicit` (default) or `explicit`. Explicit breakpoints are set with `prompt_cache_breakpoint: { "mode": "explicit" }` on a content block. Up to four cache writes per request. Both exist in the Responses API and in Chat Completions `[O-ref-resp]` `[O-ref-chat]`.
- "Top-level `instructions` cannot contain an explicit breakpoint. To mark reusable developer instructions, place them in an `input_text` block inside a developer message." `[O-cache]`
- Implicit mode puts one breakpoint "at the end of the latest eligible message", which is the user message. For a static system prompt plus a varying user message this does not produce hits. The guide states: "If requests share a long prefix but have different suffixes, caching the first complete request implicitly-only does not make the shorter shared prefix reusable. ... To remediate, place an explicit breakpoint after the static content in both requests." `[O-cache]`
- OpenAI's own example for this shape (a single-turn judge with a fixed rubric) uses explicit-only mode with the breakpoint after the fixed part, so the variable part "is not written to the cache, avoiding a cache-write charge" `[O-cache]`.
- Minimum: "1,024 tokens for GPT-5.6 and later"; hidden OpenAI system tokens do not count `[O-cache]`.
- TTL: `prompt_cache_options.ttl`, only value `"30m"`; "eligible for reuse for 30 minutes after its most recent write or reuse" `[O-cache]`.
- Price: "cache writes cost 1.25× the standard, uncached input-token rate. Subsequent reads cost 0.1× that rate on most of these models and 0.05× on GPT-6.1 Sol" `[O-cache]`. Earlier models have no write charge.
- What must match: "the entire rendered prefix". Settings that change it: `model`, `tools`, `parallel_tool_calls`, `text.format` (the output schema), `reasoning.effort`, `text.verbosity`, `context_management` `[O-cache]`.
- Scope: "Caches are not shared across organizations and cannot be reused across regional processing boundaries" `[O-cache]`. `prompt_cache_key` is optional on GPT-5.6 and later (separate accounting only).
- Pre-warming: `prompt_cache_options.prewarm: true` in a Responses API request prepares the cache "without generating output"; billed at the cache-write rate `[O-cache]`. The Chat Completions reference lists only `mode` and `ttl` `[O-ref-chat]`.
- Parallel requests: when a new entry becomes visible to concurrent requests is not documented (could not verify). Routing note: "traffic above 15 requests per minute can lead to overflow routing" to other machines `[O-cache]`.
- Reporting: `usage.input_tokens_details.cached_tokens` and `cache_write_tokens` `[O-cache]`.
- Rate limits: "Cached input tokens still count toward tokens-per-minute limits." `[O-cache]`

### 2.5 Rate limits and errors

- Dimensions: RPM, RPD, TPM, TPD; per organisation and project; per model `[O-limits]`.
- Usage tiers `[O-limits]`: Tier 1 ($5 paid, $100 per month usage limit), Tier 2 ($50, $500), Tier 3 ($100, $1,000), Tier 4 ($250, $5,000), Tier 5 ($1,000, $200,000).
- Per-model limits:

| Model | Tier 1 | Tier 2 | Tier 3 | Tier 1 batch queue |
|---|---|---|---|---|
| `gpt-6-luna` `[O-luna]` | 500 RPM, 500,000 TPM | 5,000 RPM, 2,000,000 TPM | 5,000 RPM, 4,000,000 TPM | 5,000,000 tokens |
| `gpt-6.1-sol` `[O-sol]` | 500 RPM, 500,000 TPM | 5,000 RPM, 1,000,000 TPM | 5,000 RPM, 2,000,000 TPM | could not verify (not on the page) |
| `gpt-6-astra` `[O-astra]` | 500 RPM, 500,000 TPM | 5,000 RPM, 1,000,000 TPM | 5,000 RPM, 2,000,000 TPM | 1,500,000 tokens |

- TPM accounting: "Your rate limit is calculated as the maximum of `max_tokens` and the estimated number of tokens based on the character count of your request. Try to set the `max_tokens` value as close to your expected response size as possible." `[O-limits]` A large output cap therefore consumes TPM budget.
- Headers `[O-limits]`: `x-ratelimit-limit-requests`, `x-ratelimit-limit-tokens`, `x-ratelimit-remaining-requests`, `x-ratelimit-remaining-tokens`, `x-ratelimit-reset-requests`, `x-ratelimit-reset-tokens`, project-scoped variants (`x-ratelimit-*-project-tokens`), and `Retry-After`.
- Errors `[O-errors]` `[O-limits]`:
  - 429 "Rate limit reached for requests": pace requests, follow `Retry-After`.
  - 429 `rate_limit_error` / `slow_down`: traffic increased too quickly; "can occur even when your traffic is within its ... limits". Rule of thumb: above 1 million input TPM, "increase it by no more than 50% every 15 minutes".
  - 429 `credit_balance_exhausted`, `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`, `organization_usage_limit_exceeded`: do not retry.
  - 500: retry after a brief wait.
  - 503 `service_unavailable_error` / `server_is_overloaded`: follow `Retry-After`, then retry.
  - Flex only: `429 Resource Unavailable`, not charged `[O-flex]`.
- Retry guidance `[O-limits]`: "follow `Retry-After` when the header is present ... If it's missing or invalid, fall back to exponential backoff with jitter. Limit both the number of attempts and the total time spent retrying." Official SDKs retry eligible 429 and 503 responses automatically. "unsuccessful requests contribute to your per-minute limit".

### 2.6 Batch API and Flex

- Batch `[O-batch]`: "50% cost discount"; "Each batch completes within 24 hours (and often more quickly)"; endpoints include `/v1/responses` and `/v1/chat/completions`; JSONL input with a unique `custom_id` per line; "each input file can only include requests to a single model".
- Limits `[O-batch]`: 50,000 requests per batch, 200 MB input file, 2,000 batches per hour, plus a per-model queued-token limit (table in 2.5). "The Batch API currently has no output-token limit." Batch usage does not consume the synchronous rate limits.
- Expiry: unfinished requests are cancelled, completed ones are returned and charged. Output order "may not match"; the output file is deleted 30 days after completion `[O-batch]`.
- Batch prices list cached-input and cache-write rates, so caching applies `[O-pricing]`; hit behaviour inside batches is not described (could not verify).
- Flex `[O-flex]`: `service_tier: "flex"` on a normal synchronous request, priced at Batch rates, "slower response times and occasional resource unavailability", beta. Raise the client timeout (SDK default 10 minutes). This is the simplest cost lever for a CLI because it needs no batch plumbing.

### 2.7 Reasoning controls

- `reasoning.effort` (Responses) or `reasoning_effort` (Chat Completions). Values are model-dependent: `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max` `[O-reason]`.
- Defaults: "GPT-6.1 Sol, GPT-6 Sol, and GPT-6 Luna also default to `medium` reasoning effort" `[O-reason]`. Astra: could not verify.
- "GPT-6 Astra and GPT-6.1 Sol do not support `none`; use `low` instead. GPT-6 Sol and GPT-6 Luna support `none`." Sending `none` to Astra returns HTTP 400 `[O-gpt6]` `[O-reason]`.
- `reasoning.mode`: `standard` (default) or `pro` on GPT-5.6 and GPT-6 `[O-reason]`.
- Reasoning tokens are billed as output and count against `max_output_tokens`. "OpenAI recommends reserving at least 25,000 tokens for reasoning and outputs when you start experimenting with these models." `[O-reason]`
- "When reasoning effort is not `none`, remove `temperature`, `top_p`, and `top_logprobs`." `[O-gpt6]`
- Changing `reasoning.effort` or `text.verbosity` changes the cache prefix `[O-cache]`.

### 2.8 Translation and localisation guidance

- No text-translation guide exists in the API docs index; the only translation material is for realtime speech (`gpt-realtime-translate`). Checked the guides index and the Cookbook index (could not verify any text-translation guidance).
- General prompt structure `[O-prompt]`: developer message with sections for identity, instructions, examples and context; "Markdown headers and lists" for sections and "XML tags" to delimit content; "keep content that you expect to use over and over in your API requests at the beginning of your prompt".
- Latency guide `[O-latency]`: "Maximize shared prompt prefix, by putting dynamic portions ... later in the prompt"; for output that needs post-processing such as translation, "consider processing it in chunks".
- Structured-output tip `[O-so]`: tell the model what to return when the input cannot produce a valid result.

### 2.9 Data handling

- Training: "As of March 1, 2023, data sent to the OpenAI API is not used to train or improve OpenAI models (unless you explicitly opt in to share data with us)." `[O-data]`
- Abuse-monitoring logs: "retained for up to 30 days, unless longer retention is required by law" `[O-data]`.
- Application state: `/v1/responses` stores responses for 30 days by default because `store` "Defaults to true when omitted" `[O-data]` `[O-ref-resp]`. Send `store: false` from a CMS addon. `/v1/chat/completions` has no application-state retention by default `[O-data]`.
- `/v1/batches`: application state kept "Until deleted"; not ZDR-eligible `[O-data]`.
- Zero Data Retention and Modified Abuse Monitoring require prior approval `[O-data]`.
- Prompt-cache state: encrypted KV tensors on GPU-local storage, "not retained after the 24-hour expiration" `[O-data]`.

---

## 3. Google Gemini (Gemini Developer API)

Sources:

- `[G-models]` https://ai.google.dev/gemini-api/docs/models
- `[G-38]` https://ai.google.dev/gemini-api/docs/models/gemini-3.8-flash
- `[G-35lite]` https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite
- `[G-31lite]` https://ai.google.dev/gemini-api/docs/models/gemini-3.1-flash-lite
- `[G-31pro]` https://ai.google.dev/gemini-api/docs/models/gemini-3.1-pro-preview
- `[G-latest]` https://ai.google.dev/gemini-api/docs/generate-content/latest-model
- `[G-pricing]` https://ai.google.dev/gemini-api/docs/pricing
- `[G-so]` https://ai.google.dev/gemini-api/docs/generate-content/structured-output
- `[G-cache]` https://ai.google.dev/gemini-api/docs/generate-content/caching
- `[G-cache-int]` https://ai.google.dev/gemini-api/docs/caching
- `[G-limits]` https://ai.google.dev/gemini-api/docs/rate-limits
- `[G-trouble]` https://ai.google.dev/gemini-api/docs/troubleshooting
- `[G-errors]` https://ai.google.dev/gemini-api/docs/api-errors
- `[G-batch]` https://ai.google.dev/gemini-api/docs/batch-api
- `[G-flex]` https://ai.google.dev/gemini-api/docs/generate-content/flex-inference
- `[G-think]` https://ai.google.dev/gemini-api/docs/generate-content/thinking
- `[G-g3]` https://ai.google.dev/gemini-api/docs/generate-content/gemini-3
- `[G-prompt]` https://ai.google.dev/gemini-api/docs/prompting-strategies
- `[G-terms]` https://ai.google.dev/gemini-api/terms
- `[G-abuse]` https://ai.google.dev/gemini-api/docs/usage-policies
- `[G-zdr]` https://ai.google.dev/gemini-api/docs/zdr
- `[G-migrate]` https://ai.google.dev/gemini-api/docs/migrate-to-interactions
- `[G-cloud]` https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/translate/translate-text

API note: Google now recommends the Interactions API "for all new development", while "`generateContent` remains fully supported" `[G-migrate]`. The docs index labels generateContent pages "legacy". Batch and explicit caching exist only on generateContent `[G-batch]` `[G-cache-int]`. The facts below use the generateContent pages unless noted.

### 3.1 Current lineup

Text models. Every model below has input limit 1,048,576 and output limit 65,536 tokens (each model page). Prices are paid-tier standard, per 1M tokens `[G-pricing]`.

| Model | Model code | Status | Vendor positioning `[G-models]` | Input / output | Cached input | Default thinking `[G-think]` |
|---|---|---|---|---|---|---|
| Gemini 3.8 Flash | `gemini-3.8-flash` | Stable (GA) | "Our most intelligent Flash model, engineered for long-horizon software engineering, autonomous agents, and complex enterprise workflows" | $0.75 / $3.75 through 2026-12-31; $1.50 / $7.50 from 2027-01-01 | $0.075, then $0.15 | `medium`; `minimal` returns an error |
| Gemini 3.7 Flash | `gemini-3.7-flash` | Stable | "previous-generation Flash model for complex coding, agentic workflows" | same as 3.8 Flash | same | `medium`; `minimal` returns an error |
| Gemini 3.6 Flash | `gemini-3.6-flash` | Stable | "previous-generation Flash model, balancing speed and multimodal capabilities" | same as 3.8 Flash | same | `medium` |
| Gemini 3.5 Flash | `gemini-3.5-flash` | Stable | "legacy Flash model" | $1.50 / $9.00 | $0.15 | `medium` |
| Gemini 3.5 Flash-Lite | `gemini-3.5-flash-lite` | Stable | "Our fastest, most cost-effective 3.5 model for high-throughput execution" | $0.30 / $2.50 | $0.03 | `minimal` |
| Gemini 3.1 Flash-Lite | `gemini-3.1-flash-lite` | Stable | "low-latency, cost-effective ... optimized for high-frequency, lightweight tasks" `[G-31lite]` | $0.25 / $1.50 | $0.025 | `minimal` |
| Gemini 3.1 Pro | `gemini-3.1-pro-preview` | Preview | "Advanced intelligence, complex problem-solving skills" | $2 / $12 up to 200k prompt tokens; $4 / $18 above | $0.20 / $0.40 | `high`; cannot be disabled |
| Gemini 3 Flash | `gemini-3-flash-preview` | Preview | — | not checked | — | `high` |

Notes:

- There is no stable Pro model in the Gemini 3 family. The only Pro is a preview, and "Preview models ... might come with more restrictive rate limits and will be deprecated with at least 2 weeks notice" `[G-models]`.
- Gemini 2.5 models are restricted "to users who have actively used them in the past". "For any new projects, use our latest models: 3.5 Flash-Lite or 3.8 Flash." `[G-models]`
- Aliases: `latest` aliases such as `gemini-flash-latest` are "hot-swapped with every new release" with two weeks' notice. "Most production apps should use a specific stable model." `[G-models]`
- Output price includes thinking tokens `[G-pricing]`.
- Other tiers `[G-pricing]` `[G-flex]`: Batch and Flex 50% off; Priority 75–100% more than standard.
- Published speed: no tokens-per-second figure (could not verify). Flex targets "Minutes (1–15 min target)", standard "Seconds to minutes" `[G-flex]`.
- A code sample in the Interactions thinking guide references `gemini-3.8-pro`, which is not on the models page (could not verify that it exists).

### 3.2 Recommendation: fast and quality defaults

- Fast: `gemini-3.5-flash-lite`. It is Google's named choice for new projects next to 3.8 Flash, positioned as "fastest, most cost-effective", with `minimal` thinking by default. A cheaper alternative is `gemini-3.1-flash-lite` ($0.25 / $1.50), for which Google explicitly lists translation as a best-suited use case `[G-31lite]`.
- Quality: `gemini-3.8-flash` with `thinkingLevel: "low"`. It is the newest GA model; Google describes low as reducing "time-to-answer for latency-critical tasks like ... writing drafts" `[G-latest]`. Its price doubles on 2027-01-01.
- Reviewer: `gemini-3.8-flash` at `medium`. `gemini-3.1-pro-preview` only as an opt-in, because it is a preview with default `high` thinking.

### 3.3 Structured outputs

- Enable on generateContent with `responseMimeType: "application/json"` plus `responseJsonSchema` (SDK config keys `response_mime_type`, `response_json_schema`) `[G-so]` `[G-31lite]`. On the Interactions API it is `response_format` with `mime_type: "application/json"` and `schema`.
- Supported on all Gemini 3 text models (each model page lists "Structured outputs: Supported"). The support table on the structured-output page itself is stale and omits 3.8 Flash.
- Schema subset `[G-so]`: types `string`, `number`, `integer`, `boolean`, `object`, `array`, `null`; `enum`; `format` (`date-time`, `date`, `time`); `minimum`, `maximum`; `items`, `prefixItems`, `minItems`, `maxItems`; `properties`, `required`; "`additionalProperties`: ... Can be a boolean or a schema." `anyOf` and `$ref` recursion appear in the examples.
- Limits `[G-so]`: "Not all features of the JSON Schema specification are supported. The model ignores unsupported properties." and "The API may reject very large or deeply nested schemas." No numeric limit for depth, property count or string length is published (could not verify).
- Output follows the key order of the schema `[G-so]`.
- Streaming: supported; "The streamed chunks will be valid partial JSON strings" `[G-so]`.
- Semantics are not guaranteed: "it does not guarantee the values are semantically correct. Always validate" `[G-so]`.
- Failure modes:
  - Truncation: `finish_reason: MAX_TOKENS` "and returns truncated or empty output (while still billing for any thinking tokens generated)" `[G-think]`.
  - Repeated newlines in structured output "When the model input contains unicode or escape sequences like `\u` or `\t`". Fix: send UTF-8 instead of `\u` escapes `[G-trouble]`.
  - Repeated text "When the model output has a different order for the fields than the defined structured schema". Fix: "Don't specify the order of fields in your prompt" and "Make all output fields required" `[G-trouble]`.
  - Unsupported keywords are silently ignored, so a constraint may not be enforced `[G-so]`.

### 3.4 Context caching

- Implicit caching: "enabled by default for all Gemini 2.5 and newer models"; savings are passed on automatically, with "no cost saving guarantee" `[G-cache]`.
  - Minimum input tokens: 4,096 for Gemini 3.8, 3.7, 3.6, 3.5 Flash and 3.1 Pro Preview; 2,048 for 2.5 Flash and 2.5 Pro `[G-cache]`. Flash-Lite models are not in the table (could not verify).
  - To raise the hit chance: "putting large and common contents at the beginning of your prompt" and sending "requests with similar prefix in a short amount of time" `[G-cache]`. No TTL is stated (could not verify).
  - Hits are reported in `usage_metadata` (cached token count) `[G-cache]`.
- Explicit caching (generateContent only, Beta under `v1beta`) `[G-cache]` `[G-cache-int]`:
  - Create a cache object holding system instruction and content, then reference it per request (`cachedContent`).
  - "If not set, the TTL defaults to 1 hour"; "There are no minimum or maximum bounds on the TTL". Only `ttl` or `expire_time` can be changed afterwards.
  - "Cached content is a prefix to the prompt." Minimum token count "varies by model".
  - "There are no special rate or usage limits on context caching; the standard rate limits ... apply, and token limits include cached tokens."
  - Also usable through the OpenAI-compatibility layer via `cached_content` in `extra_body`, and in batch requests `[G-batch]`.
- Price `[G-pricing]`: cached tokens at the "Context caching price" (about 10% of input, for example $0.075 vs $0.75 on 3.8 Flash), plus storage for explicit caches: $0.50 per 1M tokens per hour on 3.8 Flash until 2026-12-31, then $1.00; $1.00 on the Flash-Lite models; $4.50 on 3.1 Pro Preview. No write surcharge is listed.

### 3.5 Rate limits and errors

- Dimensions: RPM, TPM (input), RPD; "applied per project, not per API key" `[G-limits]`.
- Per-model RPM and TPM numbers are not published in the docs: "Rate limits depend on a variety of factors ... and can be viewed in Google AI Studio" `[G-limits]`. Entry-tier numbers: could not verify.
- Tiers `[G-limits]`: Free; Tier 1 (billing account linked, billing cap $250); Tier 2 (paid $100 and 3 days, cap $2,000); Tier 3 (paid $1,000 and 30 days, cap $20,000 to $100,000+).
- Spend-based limits on a rolling 10-minute window `[G-limits]`: Tier 1 $10, Tier 2 $50, Tier 3 $200. Exceeding returns `429 RESOURCE_EXHAUSTED`.
- Headers reporting remaining quota: none documented (could not verify).
- Errors `[G-trouble]` `[G-errors]`: `429 RESOURCE_EXHAUSTED` (Interactions API codes `rate_limit_exceeded`, `quota_exceeded`, `too_many_requests`); `503 UNAVAILABLE` / `service_unavailable` ("temporarily overloaded or down"); 500 `api_error`; 504 `deadline_exceeded`; 402 `payment_required` (do not retry).
- Retry guidance `[G-trouble]`: exponential backoff with jitter; "Only retry on transient errors (like `429`, `408`, or `5xx`). Do not retry on client errors (like `400`, `402`, or `403`)"; set a maximum. The Python SDK "automatically retries transient errors up to four times with an initial delay of approximately 1 second and a maximum delay of 60 seconds".

### 3.6 Batch API and Flex

- Batch `[G-batch]`: "50% of the standard cost"; "target turnaround time is 24 hours, but in majority of cases, it is much quicker"; a job expires after "more than 48 hours" pending or running and then has no results. generateContent only.
- Input: inline requests (total under 20 MB) or a JSONL file (up to 2 GB) with a user-defined key per line `[G-batch]`.
- Limits `[G-limits]`: 100 concurrent batch requests; 20 GB file storage; enqueued tokens per model at Tier 1: 3,000,000 (3.8, 3.7, 3.6, 3.5 Flash), 10,000,000 (3.5 and 3.1 Flash-Lite), 5,000,000 (3.1 Pro Preview). Tier 2: 400,000,000 and 500,000,000.
- "The creation of a batch job is not idempotent." Results are stored 6 weeks. Structured output and explicit context caching work in batch `[G-batch]`.
- Flex `[G-flex]`: `service_tier: "flex"` on a synchronous request, 50% off, best-effort availability.

### 3.7 Thinking controls

- `thinkingLevel` (in `thinkingConfig`) is the control for Gemini 3: `minimal`, `low`, `medium`, `high` `[G-think]`.

| Model group | `minimal` | `low` | `medium` | `high` |
|---|---|---|---|---|
| 3.8 and 3.7 Flash | Not supported (error) | Supported | Default | Supported |
| 3.6 and 3.5 Flash | Supported | Supported | Default | Supported |
| 3.5 and 3.1 Flash-Lite | Default | Supported | Supported | Supported |
| 3.1 Pro | Not supported | Supported | Supported | Default |

- Thinking cannot be fully disabled: "`minimal` does not guarantee that thinking is off"; "You cannot disable thinking for Gemini 3.1 Pro. Gemini 3 Flash and Flash-Lite also do not support full thinking-off." `[G-think]`
- `thinkingBudget` is the Gemini 2.5 control; for 3.8 Flash Google says "Replace `thinking_budget` with the string enum `thinking_level`" `[G-latest]`.
- Thinking tokens are billed as output and count against `max_output_tokens`: "To reduce cost or latency without truncating responses, lower `thinking_level` ... instead of setting a small `max_output_tokens`." `[G-think]`
- Sampling: for 3.8 Flash "Strip `temperature`, `top_p`, and `top_k` from generation configs" and "Remove `candidate_count`"; prefilled model turns must be removed `[G-latest]`. For all Gemini 3: "we strongly recommend keeping the temperature parameter at its default value of `1.0`" `[G-g3]`.

### 3.8 Translation and localisation guidance

- Gemini 3.1 Flash-Lite page lists translation first among best-suited use cases: "Fast, cheap, high-volume translation ... You can use system instructions to constrain output to only the translated text with no extra commentary", with the system instruction "Only output the translated text" `[G-31lite]`.
- Gemini 3 prompting `[G-prompt]`: "Be precise and direct"; use "XML-style tags ... or Markdown headings" consistently; put role and output-format requirements "in the System Instruction or at the very beginning of the user prompt"; for long contexts "supply all the context first. Place your specific instructions or questions at the very end of the prompt"; "We recommend to always include few-shot examples".
- HTML or markup handling and glossaries with Gemini models: no guidance found in the Gemini API docs (could not verify). Google Cloud's translation documentation recommends its separate Translation LLM and NMT products (which have glossary support), not general Gemini models `[G-cloud]`.

### 3.9 Data handling

- Paid services: "Google doesn't use your prompts (including associated system instructions, cached content, and files ...) or responses to improve our products." `[G-terms]`
- Free (unpaid) quota: content is used "to provide, improve, and develop Google products and services and machine learning technologies"; "human reviewers may read, annotate, and process your API input and output"; "Do not submit sensitive, confidential, or personal information to the Unpaid Services." `[G-terms]`
- EEA, Switzerland, UK: the paid-services data terms "apply to all Services", and "You may use only Paid Services when making API Clients available to users in the European Economic Area, Switzerland, or the United Kingdom." `[G-terms]`
- Abuse monitoring: prompts, context and outputs retained "for fifty-five (55) days"; not used for training other than policy-enforcement models `[G-abuse]`.
- Zero data retention is not offered on the Gemini Developer API for abuse logging: "If your workload requires guaranteed zero data retention ... use Vertex AI" `[G-zdr]`.
- State: the Interactions API "enables state storage" by default (`store: false` to opt out) `[G-zdr]`. Batch results are stored for 6 weeks `[G-batch]`.

---

## 4. Cross-provider recommendations

These are recommendations. The inputs are the verified facts above; the arithmetic and judgement are mine.

### 4a. Default models

| Provider | Fast | Quality | Request settings for lowest latency |
|---|---|---|---|
| Anthropic | `claude-haiku-4-5` | `claude-sonnet-5-5` | Haiku: no `thinking`, no `effort`. Sonnet 5.5: `thinking: {"type":"between_tools"}`, `output_config.effort: "low"` |
| OpenAI | `gpt-6-luna` | `gpt-6.1-sol` | Luna: `reasoning.effort: "none"`. Sol: `reasoning.effort: "low"`. Both: `store: false` on the Responses API |
| Gemini | `gemini-3.5-flash-lite` | `gemini-3.8-flash` | Flash-Lite: leave default (`minimal`). 3.8 Flash: `thinkingLevel: "low"` |

For the review pass, reuse the quality model at one effort step higher, or the reviewer models in section 0. Send no sampling parameters to any of them.

### 4b. Safe default concurrency on an entry-tier key

Suggested default: 4 concurrent requests per provider, configurable, with the client slowing down on 429 and on low `remaining` headers.

Reasoning, assuming chunks of roughly 3K output tokens:

- Anthropic Start tier: the binding limit is 400,000 OTPM, which allows about 130 such chunks per minute; RPM is 1,000 `[A-limits]`. The published limits would tolerate 16 or more parallel requests. The reasons to start lower are the unpublished Evaluation tier for new organisations and the acceleration limits.
- OpenAI Tier 1: 500,000 TPM, counted as the larger of `max_output_tokens` and the estimate, with cached tokens included `[O-limits]` `[O-cache]`. A request with a 3K-token prefix, 3K of content and an 8K output cap costs about 14K against TPM, so roughly 35 requests per minute. A ceiling of about 8 parallel requests is reasonable at Tier 1. Keep the output cap tight.
- Gemini Tier 1: RPM and TPM are unpublished. The $10 per 10 minutes spend limit `[G-limits]` allows roughly 60 chunks per minute on 3.8 Flash at current prices. Start at 4 and treat 429 as the signal.

Retry policy that satisfies all three vendors: honour `retry-after` / `Retry-After` when present; otherwise exponential backoff with jitter starting near 1 second; cap attempts and total time; retry 429 (rate limit only), 500, 503, 529 and 504; never retry spend-cap, quota, billing or 400-class errors. Disable SDK-level retries if the addon retries itself, to avoid nested loops `[O-limits]`.

### 4c. Chunk size so structured output does not hit the output cap

- Hard caps: 64K (Haiku 4.5), 65,536 (all Gemini 3), 128K (Sonnet 5.5, Opus 5.5, GPT-6). Thinking and reasoning tokens count against the cap on all three.
- Use one fixed schema for every request, for example `{"translations": [{"id": string, "text": string}]}` with everything required. Reasons: Anthropic and OpenAI cannot express dynamic keys; a per-entry schema would trigger grammar compilation on first use and would change the cache prefix on Anthropic and OpenAI (`output_config.format`, `text.format`). Validate that the returned ID set equals the requested set.
- Target about 8,000 to 12,000 characters of source text per request (roughly 2K to 4K tokens), split on field boundaries, and split a single oversized HTML field at block-level elements. This keeps each response well under 10K output tokens, so latency per request stays bounded and work is spread across parallel requests. The character figure is a rule of thumb: Anthropic states 1M tokens is "roughly ... 2.5M Unicode characters" on its current tokenizer `[A-models]`; no per-language figures are published.
- Set the output cap to about 3x the estimated source tokens with a floor of 2,048, plus headroom when thinking is on. Do not set it to the model maximum on OpenAI (it counts against TPM). On Anthropic a high cap has no rate-limit cost, but SDKs want streaming above 21,333.
- Treat these as failure and retry with the chunk split in half: Anthropic `stop_reason: "max_tokens"`; OpenAI `status: "incomplete"` with reason `max_output_tokens` (or `finish_reason: "length"`); Gemini `finish_reason: MAX_TOKENS`. Also handle refusals (`stop_reason: "refusal"`, OpenAI `refusal` item, Gemini blocked codes).
- When embedding field content in the prompt, encode it as UTF-8 without `\u` escapes (in PHP: `JSON_UNESCAPED_UNICODE`), because of the Gemini repeated-newline issue `[G-trouble]`.

### 4d. Prompt layout that caches on all three

1. Order every request the same way: tools (none, or always the same) → static system/developer text (instructions, site context, glossary) → per-request content in the user message. Put the short "translate to X, return JSON" instruction at the end of the user message; that also matches the "context first, query last" advice from Anthropic and Google.
2. Keep the static block byte-identical: deterministic glossary ordering, no timestamps, no entry IDs, no per-request locale text before the breakpoint. If the glossary is per target locale, use two static blocks (global, then locale) with a breakpoint after each; both Anthropic and OpenAI allow up to four.
3. Put an explicit breakpoint at the end of the static part:
   - Anthropic: `system` as an array of text blocks, `cache_control: {"type": "ephemeral"}` on the last static block.
   - OpenAI: `prompt_cache_options: {"mode": "explicit"}` and the static text as an `input_text` block in a developer message carrying `prompt_cache_breakpoint: {"mode": "explicit"}`. Not in top-level `instructions`.
   - Gemini: nothing to mark for implicit caching. For guaranteed hits create an explicit cache (system instruction plus glossary) and pass `cachedContent`.
4. Keep everything that is rendered into the prefix constant for a run: model, schema, thinking mode, effort, verbosity.
5. Size matters. The static prefix must reach 512 tokens (Sonnet 5.5, Opus 5.5), 1,024 (OpenAI), 4,096 (Haiku 4.5, Gemini 3 Flash). A prefix of 1,500 tokens caches on Sonnet 5.5 and OpenAI but silently not on Haiku 4.5 or Gemini.
6. Warm before fanning out. On Anthropic the entry is usable only "after the first response begins", so send one small real request (same system prompt and schema, for example the entry title) and start the parallel requests once it responds. On OpenAI use `prewarm: true` (Responses API) or the same first-request pattern. On Gemini create the explicit cache first.
7. Mind the TTLs for long CLI runs: 5 minutes on Anthropic (refreshed by each hit, or pay 2x for 1 hour), 30 minutes on OpenAI, 1 hour default on Gemini explicit caches (billed per hour of storage).
8. The review pass uses a different model and prompt, so it has its own cache. Lay it out the same way.

---

## 5. Uncertain or unverified items

- Anthropic Evaluation-tier limits for new organisations: not published.
- Whether an Anthropic `max_tokens: 0` pre-warm (which cannot include `output_config.format`) shares a cache entry with structured-output requests: not documented.
- Claude Haiku 4.5: retirement commitment ends 2026-10-15; no deprecation announced, no successor listed.
- Anthropic standard retention: the privacy centre says deletion "within 30 days"; the platform docs say conversation content "is not retained by default". Both are quoted in 1.9.
- OpenAI: default reasoning effort of GPT-6 Astra; Tier 1 batch queue limit for GPT-6.1 Sol; when a new cache entry becomes visible to concurrent requests; cache-hit behaviour inside batches; whether `minLength` / `maxLength` are enforced.
- Gemini: per-tier RPM and TPM numbers; rate-limit response headers; implicit-cache TTL; cache minimum for Flash-Lite models; numeric schema limits; existence of `gemini-3.8-pro`.
- No vendor publishes tokens-per-second for these models in the pages consulted. Chunk and concurrency numbers in section 4 are therefore estimates and should be tuned with a benchmark.
- Translation-specific vendor guidance is thin everywhere. Nothing official was found on HTML preservation or glossary enforcement for any of the three.

## 6. Watchlist for the SDK layer (to check against the Laravel AI SDK)

These are provider-side constraints. Whether the SDK already handles them was not part of this research.

- Anthropic structured output must go through `output_config.format`; forced `tool_choice` is rejected on Sonnet 5.5, Opus 5.5 and Fable 5.1.
- Anthropic `thinking: {"type": "disabled"}` is rejected on Sonnet 5.5 (use `between_tools`) and on Opus 5.5 / Fable 5.1 (no off switch). Among the four current models, `thinking: {"type": "enabled"}` is accepted only by Haiku 4.5, and `output_config.effort` is not supported on Haiku 4.5.
- Sampling parameters must be omitted on all three providers' current models.
- OpenAI GPT-6 Astra and 6.1 Sol: tool calling only on the Responses API; `reasoning.effort: "none"` is rejected.
- OpenAI caching needs `prompt_cache_options` and a content-block `prompt_cache_breakpoint`; a system prompt sent as top-level `instructions` cannot carry a breakpoint.
- OpenAI Responses API stores responses for 30 days unless `store: false`.
- Anthropic caching needs `system` as a block array with `cache_control`; a plain string system prompt plus top-level automatic caching will not hit for this workload.
- Gemini batch and explicit caching require the generateContent API.
