# 02 — Laravel AI SDK (`laravel/ai`): verified API research

Research date: 2026-10-01. Read-only research for the Statamic 6 auto-translation addon plan.
All API facts are for **`laravel/ai` v1.0.1** unless a line says otherwise.

---

## 0. Sources, citation conventions, method

| Tag | What it is | How to open it |
|---|---|---|
| **[src]** | `laravel/ai` tag `v1.0.1`, commit `127fce89bc620fcf942252837669c2fc0895f4b3` (2026-09-29). Cited as `path:line`. | `https://github.com/laravel/ai/blob/v1.0.1/<path>#L<line>` |
| **[tests]** | `tests/` in the same tag. GitHub only: `/tests`, `CHANGELOG.md`, `UPGRADE.md`, `testbench.yaml`, `phpunit.xml.dist` are `export-ignore`d, so they are not in `vendor/` (`.gitattributes:9-21`). | same base URL |
| **[upgrade]** | `UPGRADE.md` at v1.0.1 | `https://github.com/laravel/ai/blob/v1.0.1/UPGRADE.md` |
| **[changelog]** | `CHANGELOG.md` on branch `1.x` | `https://github.com/laravel/ai/blob/1.x/CHANGELOG.md` |
| **[docs]** | `laravel/docs` branch `13.x`, `ai-sdk.md` at commit `bfee56a92f` (2026-09-29). Line numbers are of the raw markdown. | Rendered: https://laravel.com/framework/docs/13.x/ai-sdk (https://laravel.com/docs/13.x/ai-sdk 301-redirects there). Raw: https://github.com/laravel/docs/blob/13.x/ai-sdk.md |
| **[fw]** | `laravel/framework` v13.17.0, local copy | `/Volumes/1TB/Sites/istionyachting/vendor/laravel/framework/`. Paths are relative to `src/Illuminate/`, except `composer.json` and `config/...`, which are at the package root. |
| **[local]** | Other files on this machine | absolute paths given inline |
| **[anthropic-ref]** | Anthropic API reference bundled with Claude Code's `claude-api` skill (cached 2026-09-25). **Not** part of the Laravel SDK. Used only for cross-checks, always labelled. | n/a |

Markers used below: **Inference** = my reading of code/semantics that I did not execute. **Could not verify** = no source found or not testable without live API calls. No live provider API call was made during this research.

### Installed copies on this machine

| Project | `laravel/ai` | Released | `laravel/framework` | Notes |
|---|---|---|---|---|
| `/Volumes/1TB/Sites/backend` | v0.6.3 | 2026-04-22 | v13.6.0 | |
| `/Volumes/1TB/Sites/booknowdirect` | v0.6.0 | 2026-04-16 | v13.5.0 | still requires `prism-php/prism ^0.100.0` (v0.100.1 installed) |
| `/Volumes/1TB/Sites/istionyachting` | **v0.9.1** | 2026-07-14 | v13.17.0 | newest local copy; also has `statamic/cms` v6.23.0 |
| `/Volumes/1TB/Sites/socialschedule` | v0.8.1 | 2026-06-10 | v13.16.1 | |

(Source: each project's `vendor/composer/installed.json`.)

The newest local copy (0.9.1) is **nine releases behind** the latest (0.10.0, 0.10.1, 0.10.2, 0.10.3, 0.11.0, 0.11.1, 0.11.2, 1.0.0, 1.0.1) and differs in ways that matter (section 19). I therefore read v1.0.1 from GitHub and used the local copy only for the differences table.

### Method note

Nothing in any project was modified. One deviation from the read-only instruction: at the very start I saved the Packagist metadata JSON into the session scratchpad root (`.../scratchpad/laravel-ai-packagist.json`) and deleted it a few minutes later when I noticed it fell outside the single allowed write. All later remote reads were streamed without writing files. Two oversized command outputs were persisted automatically by the harness in its own `~/.claude/projects/.../tool-results/` directory. This report is the only file I created that remains.

---

## 1. Decision-relevant findings in one page

1. **Latest stable is v1.0.1 (2026-09-29)**; 1.0.0 shipped 2026-09-23. The API changed a lot between the newest local copy (0.9.1) and 1.0.x. Plan against `^1.0.1`.
2. **`Ai::build([...])` (on-demand providers) exists only from v1.0.1.** It is the supported way to use API keys from a settings store. `config()->set('ai.providers...')` alone is unreliable because resolved providers are cached in a singleton manager.
3. **No built-in concurrency, no retries/backoff, no provider batch APIs.** All three verified by full-source search. Only provider failover exists.
4. **Structured output cannot be streamed** (`InvalidArgumentException`), and **invalid or truncated JSON does not throw**: the response comes back with `structured === []`. Truncation is visible only as `FinishReason::Length` on the last step.
5. **Default HTTP timeout is 60 s** for text. A timeout surfaces as `ProviderConnectionException` (failoverable).
6. **Anthropic requests always send `max_tokens` (default 64 000)**; OpenAI and Gemini send no output cap unless `MaxTokens` is set.
7. **Provider options are shallow-merged into the request body.** For Anthropic, an `output_config` provider option (where `effort` lives) replaces the SDK's `output_config.format`, i.e. breaks native structured output unless you include `format` yourself. (Inference from source, see 10.4.)
8. **Default model IDs changed four times between 0.9.1 and 1.0.1** (five different sets, table in 3.5). Never rely on SDK defaults or `UseCheapestModel`/`UseSmartestModel`; store explicit model IDs in addon settings.
9. `laravel/ai` 1.0.1 effectively requires **Laravel >= 12.62 or >= 13.15** and **PHP >= 8.3**; Statamic 6.23 itself only needs `^12.40 || ^13.0`.
10. Several **docs-vs-source discrepancies** exist (section 20), including the fake-closure signature used in tests.

---

## 2. Q1 — Version, constraints, dependencies, Prism

**Latest stable: `v1.0.1`.** Packagist `time` 2026-09-29T18:50:17+00:00 (https://repo.packagist.org/p2/laravel/ai.json); GitHub release published 2026-09-29T19:09:43Z. `v1.0.0`: Packagist 2026-09-23T06:31:17+00:00, GitHub release 2026-09-23T12:46:22Z. First release `v0.1.0` 2026-02-05. Packagist lists 51 versions. Default branch is `1.x`; `branch-alias dev-master => 1.x-dev` ([src] `composer.json:100-102`).

Constraints ([src] `composer.json:14-45`):

```json
"require": {
    "php": "^8.3",
    "illuminate/console": "^12.0|^13.0",
    "illuminate/container": "^12.0|^13.0",
    "illuminate/contracts": "^12.0|^13.0",
    "illuminate/database": "^12.0|^13.0",
    "illuminate/filesystem": "^12.0|^13.0",
    "illuminate/json-schema": "^12.62|^13.15",
    "illuminate/support": "^12.0|^13.0",
    "laravel/prompts": "^0.3.6",
    "laravel/serializable-closure": "^2.0"
},
"conflict": { "aws/aws-sdk-php": "<3.369.1", "laravel/mcp": "<1.0" },
"suggest": {
    "aws/aws-sdk-php": "Required to use the Bedrock provider.",
    "laravel/mcp": "Required to use MCP client tools or MCP server tools with Laravel AI agents."
}
```

- `require-dev` includes `orchestra/testbench ^10.6|^11.0`, `pestphp/pest ^3.0|^4.0`, `laravel/mcp ^1.0`, `aws/aws-sdk-php ^3.369.1` (`composer.json:26-37`).
- **Effective Laravel floor:** `laravel/framework` replaces `illuminate/json-schema` with `self.version` ([fw] `composer.json:115`), so `^12.62|^13.15` means framework >= 12.62 or >= 13.15. Statamic 6.23.0 requires `laravel/framework: ^12.40.0 || ^13.0` ([local] `/Volumes/1TB/Sites/istionyachting/vendor/statamic/cms/composer.json`). An addon requiring `laravel/ai ^1.0` therefore raises the host's minimum Laravel version.
- PHP 8.3 is genuinely needed: the source uses typed class constants (`public const string auto = 'auto';`, [src] `src/ToolChoice.php:14-20`).
- The service provider is auto-discovered: `extra.laravel.providers = ["Laravel\\Ai\\AiServiceProvider"]` (`composer.json:103-107`). `functions.php` is autoloaded and defines namespaced functions `Laravel\Ai\agent()`, `pipeline()`, `ulid()`, `generate_fake_data_for_json_schema_type()` ([src] `functions.php:21-51`). No facade alias is registered; use `Laravel\Ai\Ai`.

**Prism: not used.** v1.0.1 has no `prism-php/prism` requirement. It was removed in **v0.6.1** (2026-04-21): "Remove unused prism-php/prism dependency" (PR #414, [changelog] line 624 under the v0.6.1 heading at line 614), after v0.6.0 migrated the remaining providers to native gateways ([changelog] lines 640-647). Each provider now has its own gateway under `src/Gateway/<Provider>/` built on Laravel's HTTP client:

```php
// [src] src/Gateway/Concerns/CreatesClient.php:28-31
return Http::baseUrl($baseUrl)
    ->withHeaders($headers)
    ->when($timeout !== null, fn (PendingRequest $client) => $client->timeout($timeout))
    ->when($throw, fn (PendingRequest $client) => $client->throw());
```

Bedrock is the exception (AWS SDK, optional since 1.0: [upgrade] lines 273-283). Consequence: `Http::fake()`, `Http::assertSent()`, `Http::preventStrayRequests()` all work against the SDK.

---

## 3. Q2 — Providers, identifiers, config, env vars, default models

### 3.1 Text providers

Twelve providers implement `Laravel\Ai\Contracts\Providers\TextProvider` (verified by scanning every `src/Providers/*Provider.php` class declaration): Anthropic, Azure OpenAI, Bedrock, DeepSeek, Gemini, Groq, Mistral, Ollama, OpenAI, OpenAI-compatible, OpenRouter, xAI. This matches [docs] line 285.

There is no Vertex AI provider. Gemini is reached with an API key (`x-goog-api-key` header, [src] `src/Gateway/Gemini/Concerns/CreatesGeminiClient.php:20`).

### 3.2 Identifiers

`Laravel\Ai\Enums\Lab` ([src] `src/Enums/Lab.php:5-24`):

| Provider | Enum case | String value (config key and driver) |
|---|---|---|
| Anthropic | `Lab::Anthropic` | `'anthropic'` |
| OpenAI | `Lab::OpenAI` | `'openai'` |
| Google Gemini | `Lab::Gemini` | `'gemini'` |

Other cases: `Azure='azure'`, `Bedrock='bedrock'`, `Cohere='cohere'`, `DeepSeek='deepseek'`, `ElevenLabs='eleven'`, `Groq='groq'`, `Jina='jina'`, `Mistral='mistral'`, `Ollama='ollama'`, `OpenAICompatible='openai-compatible'`, `OpenRouter='openrouter'`, `TypeSafe='typesafe'`, `VoyageAI='voyageai'`, `xAI='xai'`. Case names are exact; the docs and Boost skill misspell one (section 20).

Driver factory methods on the manager: `createAnthropicDriver` ([src] `src/AiManager.php:334-341`), `createGeminiDriver` (401-408), `createOpenaiDriver` (457-464). Custom drivers can be added with `Ai::extend('name', fn ($app, array $config) => ...)` ([tests] `tests/Feature/AiManagerTest.php:76-83`).

### 3.3 Published config (`config/ai.php`)

[src] `config/ai.php`, relevant parts verbatim:

```php
'default' => 'openai',                       // line 16, literal, NOT env-driven
'default_for_images' => 'gemini',
'default_for_audio' => 'openai',
'default_for_transcription' => 'openai',
'default_for_embeddings' => 'openai',
'default_for_reranking' => 'cohere',
'default_for_classification' => 'typesafe',  // lines 17-22

'caching' => [
    'embeddings' => [
        'cache' => false,
        'store' => env('CACHE_STORE', 'database'),
        'individually' => true,
    ],
],                                            // lines 35-41

'providers' => [
    'anthropic' => [
        'driver' => 'anthropic',
        'key' => env('ANTHROPIC_API_KEY'),
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
    ],                                        // lines 55-59
    'gemini' => [
        'driver' => 'gemini',
        'key' => env('GEMINI_API_KEY'),
        'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
    ],                                        // lines 103-107
    'openai' => [
        'driver' => 'openai',
        'key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        'store' => env('OPENAI_STORE', true),
    ],                                        // lines 130-135
    // ... azure, bedrock, cohere, deepseek, eleven, groq, jina, mistral, ollama,
    //     openai-compatible, openrouter, typesafe, voyageai, xai
],
```

- The config is merged in the service provider (`mergeConfigFrom`, [src] `src/AiServiceProvider.php:39`), so it works without publishing. Publish tags: `ai`, `ai-config` (config), `ai-stubs` (stubs); migrations via `publishesMigrations` (`src/AiServiceProvider.php:227-243`). Migrations are **not** auto-loaded.
- `ai.default` must be a string or a `Lab` enum, not an array (`src/AiManager.php:524-537`).
- Config keys read by the source but absent from the published file: `ai.conversations.connection`, `ai.conversations.tables.conversations`, `ai.conversations.tables.messages`, `ai.conversations.generate_title`, `ai.remote_files.allowed_hosts`, `ai.caching.embeddings.seconds` (full-source search of `config('ai.` calls).

Per-provider keys read by the three target gateways:

| Provider | Keys (beyond `driver`, `key`) | Source |
|---|---|---|
| anthropic | `url`, `version` (header `anthropic-version`, default `2023-06-01`), `anthropic_beta` (header `anthropic-beta`, default `web-fetch-2025-09-10`), `headers` (array), `use_native_structured_output` (bool, default true), `models.text.{default,cheapest,smartest}` | `src/Gateway/Anthropic/Concerns/CreatesAnthropicClient.php:20-40`, `.../BuildsTextRequests.php:166-175`, `src/Providers/AnthropicProvider.php:71-90` |
| openai | `url`, `store` (default true), `headers`, `models.text.{...}` | `src/Gateway/OpenAi/Concerns/CreatesOpenAiClient.php:16-32`, `.../BuildsTextRequests.php:149-156`, `src/Providers/OpenAiProvider.php:102-121` |
| gemini | `url`, `headers`, `models.text.{...}` | `src/Gateway/Gemini/Concerns/CreatesGeminiClient.php:16-32`, `src/Providers/GeminiProvider.php:105-124` |

### 3.4 Env var names

From the shipped config: `ANTHROPIC_API_KEY`, `ANTHROPIC_URL`, `OPENAI_API_KEY`, `OPENAI_URL`, `OPENAI_STORE`, `GEMINI_API_KEY`, `GEMINI_URL`. The docs list the key names at [docs] lines 91-109. There is no env var for the default provider.

### 3.5 Default models and how they are chosen

Hard-coded fallbacks, overridable per provider via `models.text.default|cheapest|smartest`:

| Provider | default | cheapest | smartest | Source |
|---|---|---|---|---|
| Anthropic | `claude-sonnet-5` | `claude-haiku-4-5-20251001` | `claude-opus-5-5` | `src/Providers/AnthropicProvider.php:73,81,89` |
| OpenAI | `gpt-6-sol` | `gpt-6-luna` | `gpt-6-astra` | `src/Providers/OpenAiProvider.php:104,112,120` |
| Gemini | `gemini-3.6-flash` | `gemini-3.1-flash-lite` | `gemini-3.6-flash` | `src/Providers/GeminiProvider.php:107,115,123` |

Override example ([upgrade] lines 735-744, same shape for every provider):

```php
'gemini' => [
    'driver' => 'gemini',
    'key' => env('GEMINI_API_KEY'),
    'models' => ['text' => ['default' => 'gemini-3.6-flash', 'smartest' => 'gemini-3.6-flash']],
],
```

**Shortcuts exist:** class attributes `#[UseCheapestModel]` and `#[UseSmartestModel]` ([src] `src/Attributes/UseCheapestModel.php`, `UseSmartestModel.php`). Resolution ([src] `src/Promptable.php:510-523`): `UseSmartestModel` -> `$provider->smartestTextModel()`, else `UseCheapestModel` -> `cheapestTextModel()`, else `defaultTextModel()`. The docs warn that the selected model "may change between releases of the Laravel AI SDK" ([docs] line 1835).

**The defaults really do churn** (same three lines read at each tag):

| Tag | Anthropic default / cheapest / smartest | OpenAI default / cheapest / smartest | Gemini default / cheapest / smartest |
|---|---|---|---|
| v0.9.1, v0.10.0 | sonnet-5 / haiku-4-5-20251001 / opus-4-8 | gpt-5.4 / gpt-5.4-nano / gpt-5.4-pro | 3.5-flash / 3.1-flash-lite / 3.5-flash |
| v0.10.1 - v0.10.3 | same | same | 3.6-flash / 3.5-flash-lite / 3.6-flash |
| v0.11.0 | same | same | 3.7-flash / 3.5-flash-lite / 3.7-flash |
| v0.11.1, v0.11.2 | sonnet-5 / haiku-4-5-20251001 / opus-5 | gpt-5.6-terra / gpt-5.6-luna / gpt-5.6-sol | 3.6-flash / 3.1-flash-lite / 3.6-flash |
| v1.0.0, v1.0.1, `1.x` HEAD | sonnet-5 / haiku-4-5-20251001 / opus-5-5 | gpt-6-sol / gpt-6-luna / gpt-6-astra | 3.6-flash / 3.1-flash-lite / 3.6-flash |

Precedence for the **model** ([src] `src/Promptable.php:476-505`, `452-461`): `model:` argument -> agent `model()` method -> `#[Model]` attribute -> provider default (cheapest/smartest attribute applies here). Precedence for the **provider**: `provider:` argument -> agent `provider()` method -> `#[Provider]` attribute -> `config('ai.default')`.

---

## 4. Q3 — Choosing provider/model per call, runtime API keys, base URL

### 4.1 Per-call selection

```php
// [src] src/Promptable.php:81-86 (same parameters on stream(), lines 132-137)
public function prompt(
    AgentInput|UserMessage|Decisions|string $prompt,
    array $attachments = [],
    Lab|array|string|null $provider = null,
    ?string $model = null,
    ?int $timeout = null): AgentResponse
```

```php
$response = (new SalesCoach)->prompt('...', provider: Lab::Anthropic, model: 'claude-sonnet-5', timeout: 120); // [docs] 415-420
```

Method-based configuration on the agent is honoured too (checked with `method_exists`): `provider()`, `model()`, `timeout()` ([src] `src/Promptable.php:479-495`, `534-536`), and `maxSteps()`, `maxTokens()`, `temperature()`, `topP()`, `toolChoice()` ([src] `src/Gateway/TextGenerationOptions.php:108-168`). Methods win over attributes ([tests] `tests/Fixtures/Agents/MethodOverridesAttributeAgent.php`). A method may return `null` to fall through to the attribute (`TextGenerationOptions.php:160-167`).

**Gotcha, verified in source:** when `provider` is an **array**, the `model:` argument, `model()` method and `#[Model]` attribute are all ignored. `getProvidersAndModels()` only resolves a model when `! is_array($provider)` (`src/Promptable.php:488`), and `Provider::formatProviderAndModelList()` maps numeric-keyed entries to `[name => null]` ([src] `src/Providers/Provider.php:76-85`). With an array, specify models as keys (`['anthropic' => 'model-a', 'openai' => 'model-b']`, [docs] lines 3084-3090) or via the provider's `models.text.default`. The changelog shows this was tried and reverted (PR #633, reverted by #667; [changelog] lines 389, 401).

### 4.2 Are provider instances cached? Yes.

- `AiManager` is a container **singleton** ([src] `src/AiServiceProvider.php:33`) and extends `Illuminate\Support\MultipleInstanceManager` ([src] `src/AiManager.php:42`).
- `MultipleInstanceManager::instance($name)` stores resolved instances in `$this->instances[$name]` and returns the cached one afterwards ([fw] `Support/MultipleInstanceManager.php:89-105`).
- The provider object keeps its own copy of the config array ([src] `src/Providers/Provider.php:16-19`), and config is read only at first resolution ([src] `src/AiManager.php:554-571`).

So `config()->set('ai.providers.anthropic.key', ...)` works **only if that provider has not been resolved yet in the current process**. In queue workers and Octane the instance persists across jobs/requests. After changing config you would have to call `Ai::forgetInstance('anthropic')` or `Ai::purge('anthropic')` ([fw] `MultipleInstanceManager.php:166-190`). It also mutates the host application's own `ai.*` config, which an addon should not do.

### 4.3 The supported way: on-demand providers (`Ai::build`, v1.0.1+)

Added in v1.0.1: "Add Ai::build() for on-demand provider configuration" (PR #1071, [changelog] line 12). Documented at [docs] lines 231-274.

```php
// [src] src/AiManager.php:66-77
public function build(array $config): Provider
{
    $name = $config['name'] ?? 'ondemand_'.md5(json_encode($config, JSON_THROW_ON_ERROR));

    if ($this->app['config']->has("ai.providers.{$name}") || Lab::tryFrom($name) !== null) {
        throw new InvalidArgumentException("The provider name [{$name}] is already taken.");
    }

    $this->onDemandProviders[$name] = [...$config, 'ondemand' => true];

    return $this->forgetInstance($name)->instance($name);
}
```

The array has the same shape as a `config/ai.php` provider entry (`driver`, `key`, optional `url`, `headers`, `store`, `models`, `name`...).

Usage patterns, all covered by the package's own tests ([tests] `tests/Feature/Providers/Anthropic/OnDemandProviderTest.php`):

```php
// (a) inside an array: safe everywhere, including queues  [docs] 240-245, test lines 70-86
$agent->prompt('...', provider: [Ai::build(['driver' => 'anthropic', 'key' => $key])]);

// (b) on its own, with a model: works in the test (line 17-25), see caveat below
$agent->prompt('Hi', provider: Ai::build([...]), model: 'claude-opus-5-5');

// (c) from the agent, rebuilt wherever the agent runs: best for queued agents  [docs] 261-271, test lines 45-56
public function provider(): Provider
{
    return Ai::build($this->user->aiConfiguration());
}
```

Details that matter:

- **Model with (a):** because `provider` is an array, `model:` is ignored (4.1). Put the model in the build config: `'models' => ['text' => ['default' => 'gpt-6']]` ([docs] lines 251-258).
- **Caveat for (b):** `prompt()`'s parameter type is `Lab|array|string|null`. A `Provider` object satisfies it only through `Stringable` coercion (`Provider implements \Stringable`, `__toString()` returns the on-demand name; [src] `src/Providers/Provider.php:14,128-132`). **Inference:** in a file with `declare(strict_types=1)` this is a `TypeError`. It is also not queue-safe: the job then carries only the name and fails with "On-demand provider [...] was not built in this process" (test lines 58-68; message from `src/AiManager.php:558-560`). The docs say "You should pass on-demand providers within an array" ([docs] line 274).
- **Pattern (c) resolves the model normally:** `provider()` returns a non-array, so `model()` / `#[Model]` / `model:` apply.
- **Serialization:** an on-demand `Provider` serializes its config **encrypted** with the app `Encrypter`, a configured provider serializes only its name ([src] `src/Providers/Provider.php:102-123`; tests lines 88-100). Requires `APP_KEY` (the test sets `app.key` in `beforeEach`, lines 10-12).
- **Lifetime:** on-demand providers are flushed on `Illuminate\Queue\Events\Looping` and on Octane `OperationTerminated` ([src] `src/AiServiceProvider.php:248-258`, `src/AiManager.php:576-581`; [tests] `tests/Feature/AiManagerTest.php:48-74`).
- **Naming:** a custom `name` must not equal a configured provider key or any `Lab` value (tests `AiManagerTest.php:26-34`). Without `name` the provider is called `ondemand_<md5>`, and that string is what appears in `$response->meta->provider` and in events (gateways build `new Meta($provider->name(), ...)`, e.g. [src] `src/Gateway/Anthropic/Concerns/ParsesTextResponses.php:94`). `providerOptions()` still receives the driver as a `Lab` enum, because gateways call `providerOptions($provider->driver())` (`src/Gateway/Anthropic/Concerns/BuildsTextRequests.php:35`, `src/Gateway/TextGenerationOptions.php:38-44`).

### 4.4 Custom base URL: yes

`url` key per provider, in config or in `Ai::build()`. Defaults: Anthropic `https://api.anthropic.com/v1`, OpenAI `https://api.openai.com/v1`, Gemini `https://generativelanguage.googleapis.com/v1beta/` (client traits cited in 3.3). Docs: "Custom base URLs are supported for the following providers: OpenAI, Anthropic, Gemini, Groq, Cohere, DeepSeek, xAI, and OpenRouter." ([docs] line 138). Extra HTTP headers per provider via a `headers` array ([docs] lines 176-187; client traits). Test: `Http::assertSent(... $request->url() === 'https://tenant.example.com/v1/messages')` (OnDemandProviderTest lines 15-26).

Endpoints actually called: Anthropic `POST {url}/messages` ([src] `src/Gateway/Anthropic/AnthropicGateway.php:66`), OpenAI `POST {url}/responses` (Responses API, `src/Gateway/OpenAi/Concerns/HandlesTextSteps.php:31`), Gemini `POST {url}/interactions` (Interactions API, `src/Gateway/Gemini/GeminiGateway.php:72`; streaming `interactions?alt=sse`, line 103).

---

## 5. Q4 — Agents API

### 5.1 Class-based agents

Minimum: implement `Laravel\Ai\Contracts\Agent` and `use Laravel\Ai\Promptable`. The only method you must write is `instructions(): Stringable|string` ([src] `src/Contracts/Agent.php:14-86`); the trait supplies `prompt`, `stream`, `queue`, `broadcast`, `broadcastNow`, `broadcastOnQueue`. Agent classes can live in any namespace (the package's fixtures live in `Tests\Fixtures\Agents`); `make:agent` targets `app/Ai/Agents` but is not required.

Optional interfaces (all in `Laravel\Ai\Contracts`):

| Interface | Method | Source |
|---|---|---|
| `Conversational` | `messages(): iterable` (returns `Laravel\Ai\Messages\Message[]`) | `src/Contracts/Conversational.php:7-15` |
| `HasTools` | `tools(): iterable` (`list<Agent|Tool|ProviderTool>`) | `src/Contracts/HasTools.php:7-15` |
| `HasStructuredOutput` | `schema(JsonSchema $schema): array` | `src/Contracts/HasStructuredOutput.php:8-16` |
| `HasMiddleware` | `middleware(): array` | `src/Contracts/HasMiddleware.php:5-11` |
| `HasProviderOptions` | `providerOptions(Lab|string $provider): array` | `src/Contracts/HasProviderOptions.php:7-15` |
| `CanActAsTool` | `name()`, `description()` (sub-agent exposure) | [docs] 1557-1585 |

Traits: `Laravel\Ai\Promptable`; `Laravel\Ai\Concerns\RemembersConversations` (DB-backed history; needs the published migrations).

Class attributes (`Laravel\Ai\Attributes\*`, all `Attribute::TARGET_CLASS`):

| Attribute | Argument | Method equivalent | Notes |
|---|---|---|---|
| `Provider` | `Lab|array|string $value` | `provider()` | array = failover list |
| `Model` | `string $value` | `model()` | |
| `MaxTokens` | `int $value` | `maxTokens()` | |
| `Temperature` | `float $value` | `temperature()` | |
| `TopP` | `float $value` | `topP()` | |
| `Timeout` | `int $value` | `timeout()` | HTTP timeout, seconds; default 60 |
| `MaxSteps` | `int $value` | `maxSteps()` | tool-loop budget |
| `UseCheapestModel`, `UseSmartestModel` | none | none | see 3.5 |
| `Strict` | none | none | strict JSON schema (OpenAI and others, see 6.2) |
| `CacheInstructions`, `CacheToolDefinitions` | `?string $ttl = null` | none | Anthropic/Bedrock prompt caching |
| `RepairToolCalls` | none | none | |
| `WithoutBroadcasting` | event class names | none | |
| `Laravel\Ai\ToolChoice` (also an attribute) | `string $mode, ?string $toolName` | `toolChoice()` | modes `auto`, `none`, `required`, `tool` |

Sources: `src/Attributes/*.php`, `src/ToolChoice.php:11-70`; docs list at [docs] lines 1766-1807.

Default step budget when `MaxSteps` is unset: `round(tools * 1.5)` capped at 25, or 5 when there are no tools ([src] `src/Gateway/TextGenerationLoop.php:545-555`, const at line 70). Without tool calls the loop stops after one step (lines 194-196).

Instantiation helper: `SalesCoach::make(user: $user)` resolves through the container ([src] `src/Promptable.php:69-76`).

### 5.2 Anonymous agents

```php
// [src] functions.php:21-30
function agent(
    string $instructions = '',
    iterable $messages = [],
    iterable $tools = [],
    ?Closure $schema = null,
): Agent
```

Returns `Laravel\Ai\AnonymousAgent` (implements `Agent, Conversational, HasTools`; [src] `src/AnonymousAgent.php:12-32`), or `Laravel\Ai\StructuredAnonymousAgent` when `$schema` is given (`src/StructuredAnonymousAgent.php:10-30`; the closure is wrapped in `SerializableClosure`).

**Limitation, from source:** anonymous agents carry no attributes and implement neither `HasProviderOptions` nor `HasMiddleware`. With `agent()` you can only set `provider`, `model`, `timeout` per call. Max tokens, temperature, provider options, prompt caching and `Strict` need a real class (or a subclass of `AnonymousAgent`).

### 5.3 Instructions and prior messages

- System prompt: `instructions(): Stringable|string`; cast to string before sending ([src] `src/Providers/Concerns/GeneratesText.php:74`). Sent as `system` (Anthropic), a system message inside `input` (OpenAI), `system_instruction` (Gemini).
- History, two mutually exclusive ways:
  1. `Conversational::messages()` returning `new Message($role, $content)`; roles `'user'`, `'assistant'`, `'tool_result'` ([src] `src/Messages/MessageRole.php:5-10`).
  2. `->withMessages([...])` for one run ([src] `src/Promptable.php:337-350`). Calling it on a `Conversational` agent throws `LogicException('Ad-hoc message history may not be combined with a conversational agent.')` (lines 339-341). Items may be `Message` instances, `['role' => ..., 'content' => ...]` arrays, or objects ([src] `src/Messages/Message.php:34-42`).
- Message order sent: ad-hoc messages, then `messages()`, then a new `UserMessage($prompt, $attachments)` (`src/Providers/Concerns/GeneratesText.php:60-67`).
- `->withTools([...])` or a closure replaces tools per instance (`src/Promptable.php:357-368`).

### 5.4 Attachments

Second argument of `prompt()`/`stream()`/`queue()`. Factories ([src] `src/Files/Document.php:17-65`, `src/Files/Image.php:12-52`): `Document::fromString|fromBase64|fromId|fromPath|fromUrl|fromStorage|fromUpload`, `Image::fromBase64|fromId|fromPath|fromUrl|fromStorage|fromUpload`; `Audio` and `Video` have similar factories. An `UploadedFile` can be passed directly ([docs] lines 797-825). Not needed for text translation; noted for completeness.

---

## 6. Q5 — Structured output

### 6.1 Schema definition API

Implement `HasStructuredOutput::schema(Illuminate\Contracts\JsonSchema\JsonSchema $schema): array` returning `array<string, Illuminate\JsonSchema\Types\Type>`. The factory is part of the framework (`illuminate/json-schema`).

Factory methods ([fw] `Contracts/JsonSchema/JsonSchema.php:7-67`): `object(Closure|array $properties = [])`, `array()`, `string()`, `integer()`, `number()`, `boolean()`, `union(array $types)`, `anyOf(Closure|array $schemas)`.

Type methods ([fw] `JsonSchema/Types/*.php`): on every type `required()`, `nullable()`, `title()`, `description()`, `enum()`; `StringType`: `min()`, `max()`, `pattern()`, `format()`, `default()`; `IntegerType`/`NumberType`: `min()`, `max()`, `multipleOf()`, `default()`; `ArrayType`: `min()`, `max()`, `items(Type)`, `unique()`, `default()`; `ObjectType`: `withoutAdditionalProperties()`, `default()`.

```php
// [docs] 738-747 and 757-769
public function schema(JsonSchema $schema): array
{
    return [
        'score' => $schema->integer()->required(),
        'metadata' => $schema->object(fn ($schema) => [
            'confidence' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
        ])->required(),
        'feedback' => $schema->array()->items(
            $schema->object(fn ($schema) => ['comment' => $schema->string()->required()])
        )->required(),
    ];
}
```

The SDK wraps the array in `Laravel\Ai\ObjectSchema`, which forces `additionalProperties: false` on **every** object node, recursively ([src] `src/ObjectSchema.php:12-22, 41-66`). Free-form maps (arbitrary keys) are therefore not expressible. Because `schema()` is an ordinary method, properties can be generated at runtime (for example one property per field handle). If `schema()` returns `[]` the agent behaves as a plain text agent ([tests] `tests/Feature/AgentFakeTest.php:148-158`).

### 6.2 Native versus emulated

| Provider | Mechanism in v1.0.1 | Source |
|---|---|---|
| **Anthropic** | Native by default: `output_config.format = {type: 'json_schema', schema}`. Schema is passed through `AnthropicSchemaSanitizer`, which strips keywords Anthropic rejects (`minimum`, `exclusiveMinimum`, `maximum`, `exclusiveMaximum`, `multipleOf`, `minLength`, `maxLength`, `pattern`, `maxItems`, `uniqueItems` and others) and folds each into the node's `description`, so those constraints become hints rather than guarantees. Setting provider config `use_native_structured_output => false` falls back to a synthetic tool named `output_structured_data`. | `src/Gateway/Anthropic/Concerns/BuildsTextRequests.php:43-66, 166-193`; `src/Gateway/Anthropic/AnthropicSchemaSanitizer.php:11-73`; [upgrade] lines 1004-1016 |
| **OpenAI** | Native: `text.format = {type: 'json_schema', name, schema, strict}`. `strict` is `true` only when the agent class has `#[Strict]`; otherwise `false`. | `src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:97-99, 173-185`; [tests] `tests/Feature/Providers/OpenAi/RequestMappingTest.php:106-133` |
| **Gemini** | Native: `response_format = {type: 'text', mime_type: 'application/json', schema}`. | `src/Gateway/Gemini/Concerns/BuildsTextRequests.php:87-93` |
| xAI, Groq, Mistral, OpenRouter, OpenAI-compatible | native `json_schema` formats | respective `BuildsTextRequests.php` |
| DeepSeek, Ollama | **Emulated**: the schema is appended to the instructions ("You MUST respond EXCLUSIVELY with a JSON object...") plus `json_object`/`format`. Groq falls back to the same inlining only when the request has both tools and a schema (`src/Gateway/Groq/Concerns/BuildsTextRequests.php:62-71`). | `src/Gateway/Concerns/ComposesSchemaInstructions.php:9-25`; DeepSeek/Ollama/Groq `BuildsTextRequests.php` |

All three target providers use native structured output.

### 6.3 Accessing results

`prompt()` returns `Laravel\Ai\Responses\StructuredAgentResponse` (extends `AgentResponse`; implements `Arrayable`, `ArrayAccess`, `Jsonable`, `JsonSerializable`; [src] `src/Responses/StructuredAgentResponse.php:13-60`):

```php
$response['score'];        // ArrayAccess          [docs] 709-711
$response->structured;     // public array         src/Responses/ProvidesStructuredResponse.php:7
$response->toArray();      // same array
$response->text;           // raw JSON text from the model
(string) $response;        // json_encode($structured)
```

### 6.4 Failure modes (verified in source)

1. **Invalid JSON does not throw.** `decodeStructuredOutput()` returns `[]` when the text is empty or not decodable; it only additionally tries to strip a Markdown code fence ([src] `src/Gateway/Concerns/DecodesStructuredOutput.php:10-37`). Used by Anthropic (`ParsesTextResponses.php:76-82`), OpenAI (`ParsesTextResponses.php:66`) and Gemini (`ParsesTextResponses.php:76`). Result: a `StructuredAgentResponse` whose `structured` is `[]`.
2. **Reading a missing key raises a PHP warning**, not a domain exception: `offsetGet` returns `$this->structured[$offset]` without a guard (`src/Responses/ProvidesStructuredResponse.php:20-23`). Laravel normally converts that warning to an `ErrorException`.
3. **Truncation is not an error.** The finish reason is on the step, not on the response object: `$response->steps->last()?->finishReason === FinishReason::Length`. Mappings: Anthropic `max_tokens` and `model_context_window_exceeded` -> `Length`, `refusal` -> `ContentFilter` (`src/Gateway/Anthropic/Concerns/ParsesTextResponses.php:219-229`); OpenAI status `incomplete` -> `Length` (`src/Gateway/OpenAi/Concerns/ParsesTextResponses.php:190-206`); Gemini status `incomplete` -> `Length`, blocked content -> `ContentFilter` (`src/Gateway/Gemini/Concerns/ParsesTextResponses.php:305-321`). `FinishReason` cases: `Stop`, `ToolCalls`, `Continue`, `Length`, `ContentFilter`, `Error`, `Unknown` (`src/Responses/Data/FinishReason.php:5-14`). The loop simply ends on `Length` (`src/Gateway/TextGenerationLoop.php:194-196`).
4. **No schema validation of the decoded data** by the SDK. I found no validation step between decode and response (`buildFinalResponse`, `TextGenerationLoop.php:1023-1058`). Required keys can be missing when the provider is not in strict mode.
5. API-level errors inside a 200 body throw `Laravel\Ai\Exceptions\AiException` (for example `'Anthropic Error: [%s] %s'`, `src/Gateway/Anthropic/Concerns/ParsesTextResponses.php:27-36`).

Addon implication: after every structured call check (a) last step finish reason, (b) `structured !== []`, (c) your own key/shape validation.

---

## 7. Q6 — Streaming

### 7.1 API

```php
$stream = (new SalesCoach)->stream('...');   // Laravel\Ai\Responses\StreamableAgentResponse, lazy

foreach ($stream as $event) { /* Laravel\Ai\Streaming\Events\StreamEvent subclasses */ }

$stream->text; $stream->usage; $stream->events; $stream->reasoning; $stream->citations; // filled after iteration
```

- Nothing is sent to the provider until the object is iterated ([src] `src/Responses/StreamableAgentResponse.php:199-271`). After iteration the properties above are populated (lines 236-240) and `then()` callbacks receive a `StreamedAgentResponse` (an `AgentResponse` with `events`, `steps`, `usage`, `text`; `src/Responses/StreamedAgentResponse.php:16-57`).
- `->then(callable)`, `->catch(callable)` (receives the exception before it is rethrown), `->each(callable)` (`StreamableAgentResponse.php:73-111`).
- Re-iterating replays the recorded events instead of calling the provider again (lines 201-210).

### 7.2 Returning a stream from a route

`StreamableAgentResponse` implements `Responsable`:

```php
Route::get('/coach', fn () => (new SalesCoach)->stream('...'));   // [docs] 835-837
```

Default wire format ([src] `StreamableAgentResponse.php:176-194`): Server-Sent Events, one `data: {json}\n\n` per event where the JSON is the event's `toArray()`, then `data: [DONE]\n\n`. Headers: `Cache-Control: no-cache, no-transform`, `Content-Type: text/event-stream`, `X-Accel-Buffering: no`.

Protocol adapters:

- `->usingVercelDataProtocol(?string $messageId = null)`: Vercel AI SDK UI message stream; adds header `x-vercel-ai-ui-message-stream: v1` ([src] `src/Streaming/Protocols/VercelDataProtocol.php:125-128`; [docs] 886-902). The boolean first argument of 0.x was removed ([upgrade] 439-455).
- `->usingAgentUserInteractionProtocol(?string $threadId = null, ?string $runId = null)`: AG-UI ([docs] 904-922).
- `->usingProtocol(StreamProtocol $protocol)`: custom, extend `Laravel\Ai\Streaming\Protocols\StreamProtocol` (`src/Streaming/Protocols/StreamProtocol.php:11-99`). Exceptions during a protocol stream are reported and replaced by a masked error part (lines 47-66).
- Request helpers for chat front ends: `Laravel\Ai\Vercel\Vercel::chat($request)`, `Laravel\Ai\AgentUserInteraction\AgentUserInteraction::chat($request)` ([docs] 938-983).

### 7.3 Events (chunks)

All in `Laravel\Ai\Streaming\Events`, each with `id`, `invocationId`, `timestamp` and `toArray()`:

| Class | `type` | Key properties |
|---|---|---|
| `StreamStart` | `stream_start` | `provider`, `model`, `metadata` |
| `TextStart` / `TextEnd` | `text_start` / `text_end` | `messageId` |
| `TextDelta` | `text_delta` | `messageId`, `delta` |
| `ReasoningStart` / `ReasoningDelta` / `ReasoningEnd` | `reasoning_start` / `reasoning_delta` / `reasoning_end` | `reasoningId`, `delta`, `summary` |
| `ToolCall` | `tool_call` | `toolCall` |
| `ToolResult` | `tool_result` | `toolResult`, `successful`, `error`, `denied`, `preliminary` |
| `ToolApprovalRequest` | `tool_approval_request` | `pendingApprovals` |
| `Citation` | `citation` | `citation` |
| `ProviderToolEvent` | dynamic | `itemId`, `data`, `status`, `provider` |
| `Error` | dynamic | `message`, `recoverable`, `metadata` |
| `StreamEnd` | `stream_end` | `reason` (FinishReason value), `usage` (`TextUsage`), `steps` |

(Source: constructors and `toArray()` in `src/Streaming/Events/*.php`.) One `TextStart`/`TextEnd` pair per generation step since 1.0 ([upgrade] 475-481). `TextDelta::combine()` joins steps with a blank line (`src/Streaming/Events/TextDelta.php:27-37`).

A provider error reported inside the stream body throws `Laravel\Ai\Exceptions\StreamErrorException` with the `Error` event on `->error` ([src] `src/Gateway/TextGenerationLoop.php:340-346`, `src/Exceptions/StreamErrorException.php`; [upgrade] 700-718).

### 7.4 Broadcasting

- Per event: `$event->broadcast(Channel|array $channels, bool $now = false)` / `broadcastNow()`; event name is the `type`, payload is `toArray()` ([src] `src/Streaming/Events/StreamEvent.php:23-41`). `BroadcastException` is caught and `report()`ed.
- Agent level: `broadcast($prompt, $channels, ...)`, `broadcastNow(...)`, `broadcastOnQueue(...)` ([src] `src/Promptable.php:292-330`). `broadcastOnQueue` dispatches `Laravel\Ai\Jobs\BroadcastAgent` (`$tries = 1`; on failure broadcasts an `Error` event of type `stream_failed`; `src/Jobs/BroadcastAgent.php:24, 69-79`).
- `#[WithoutBroadcasting(ToolCall::class, ...)]` excludes event types ([docs] 1011-1036).

### 7.5 Can structured output be streamed? No.

```php
// [src] src/Providers/Concerns/StreamsText.php:47-49
if ($agent instanceof HasStructuredOutput) {
    throw new InvalidArgumentException('Streaming structured output is not currently supported.');
}
```

This also rules out `broadcast*()` for structured agents, because they call `stream()`.

---

## 8. Q7 — Queueing

```php
// [src] src/Promptable.php:242-255
public function queue(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [],
    Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
```

- Dispatches `Laravel\Ai\Jobs\InvokeAgent` (`ShouldQueue`, uses `Illuminate\Foundation\Queue\Queueable`), whose `handle()` calls `$this->agent->prompt($this->prompt, $this->attachments, $this->provider, $this->model)` ([src] `src/Jobs/InvokeAgent.php:12-44`).
- **No `timeout` parameter** on `queue()`. The HTTP timeout comes from the agent's `timeout()` method, `#[Timeout]`, or the 60 s default.
- **What is serialized:** the job's public properties: the whole agent object, the prompt (string or `Decisions`), the attachments array, `provider`, `model`. `Promptable` uses `SerializesModels` (`src/Promptable.php:52`), so Eloquent models held by the agent are serialized as identifiers. Anything else on the agent (including an API key stored in a property) goes into the queue payload as-is. On-demand `Provider` objects encrypt themselves (4.3).
- **Callbacks:** `->then(Closure)` and `->catch(Closure)` are stored on the job as `SerializableClosure` ([src] `src/Jobs/Concerns/InvokesQueuedResponseCallbacks.php:31-46`, `src/Responses/Concerns/HasQueuedResponseCallbacks.php:12-27`). `then` runs in the worker right after `prompt()` returns and receives the `AgentResponse` (lines 17-26). `catch` runs from the job's `failed()` hook (lines 51-56), i.e. once the job has finally failed, not on each attempt.
- **Queue/connection selection:** `QueuedAgentResponse` proxies unknown methods to the underlying `Illuminate\Foundation\Bus\PendingDispatch` ([src] `src/Responses/QueuedAgentResponse.php:10-23`), so `->onQueue()`, `->onConnection()`, `->delay()`, `->afterCommit()`, `->chain()` are available ([fw] `Foundation/Bus/PendingDispatch.php:52-196`). The docs do not show this; it follows from the `@mixin PendingDispatch` and `__call`.
- **Job settings:** `InvokeAgent` defines no `$tries`, `$backoff` or `$timeout`; worker defaults apply (`queue:work --timeout=60 --tries=1 --backoff=0`, [fw] `Queue/Console/WorkCommand.php:42-50`). Job display name is the agent class.
- The job is dispatched when the `PendingDispatch` is destroyed, so chain `then()`/`catch()` before the returned object goes out of scope (standard Laravel behaviour).

```php
(new SalesCoach)->queue($transcript)
    ->then(function (AgentResponse $response) { /* ... */ })
    ->catch(function (Throwable $e) { /* ... */ });          // [docs] 1048-1056
```

Addon implication: because `InvokeAgent` exposes no retry/backoff/timeout control, a custom job that calls `->prompt()` synchronously is the more controllable design for long translations.

---

## 9. Q8 — Concurrency

**Built-in: none.** A regex search of every PHP file in v1.0.1 for `pool`, `Http::pool`, `Concurrency`, `->async(`, `Promise`, `defer(` returned no matches; the docs contain no concurrency section. Tool calls inside a step are executed sequentially (`array_map`, [src] `src/Gateway/TextGenerationLoop.php:726-736`). Gateways call `->post()` synchronously.

Workable approaches:

1. **Queue fan-out (most robust).** One job per chunk/locale, several workers, optionally `Bus::batch()`. Each job calls `$agent->prompt()`. Works on any Laravel version the SDK supports. Needs worker `--timeout` and the connection's `retry_after` above the HTTP timeout.
2. **`Illuminate\Support\Facades\Concurrency`.** Drivers `process` (default), `fork` (needs `spatie/fork`), `sync` ([fw] `Concurrency/ConcurrencyManager.php:33-65`, `config/concurrency.php:18`).
   - The process driver runs `php artisan invoke-serialized-closure` per task, passing the closure base64-serialized in the env var `LARAVEL_INVOKABLE_CLOSURE`, and `unserialize`s the result ([fw] `Concurrency/ProcessDriver.php:33-74`).
   - **Version trap:** on Laravel 13 the signature is `run(Closure|array $tasks, CarbonInterval|int|null $timeout = null)` ([fw] `ProcessDriver.php:33`; also `13.x` branch HEAD). On the `12.x` branch HEAD it is `run(Closure|array $tasks): array` with no timeout, and child processes use the `Process` default of 60 s (`PendingProcess::$timeout = 60`, [fw] `Process/PendingProcess.php:46`; same on 12.x). So on Laravel 12 each concurrent task is capped at 60 s. Could not verify the exact 13.x minor that added the parameter; it is present in 13.17.0.
   - **Inference:** captured variables travel through an environment variable, so large payloads can hit OS limits on argument/environment size. Pass IDs and load content inside the closure. Return plain arrays rather than response objects (responses do drop the non-serializable `raw` property: [src] `src/Responses/Concerns/HasRawResponse.php:27-34`).
3. **`Http::pool()` below the SDK.** Possible only by bypassing the SDK and building provider requests yourself; the gateways expose no async hook. You lose mapping, usage parsing, failover and fakes.
4. **Global HTTP hooks.** `Http::globalMiddleware()` / `globalOptions()` exist ([fw] `Http/Client/Factory.php:111-150`) and would affect SDK requests, but they are application-global; not suitable for an addon.

---

## 10. Q9 — Failover, retries, timeouts, exceptions

### 10.1 Failover

Pass an array of providers; attempts run in order and move on only for `Laravel\Ai\Exceptions\FailoverableException` ([src] `src/Promptable.php:407-422`).

```php
$agent->prompt('...', provider: [Lab::OpenAI, Lab::Anthropic]);                      // default model each
$agent->prompt('...', provider: [Lab::Gemini->value => 'gemini-3-flash-preview',
                                 Lab::DeepSeek->value => 'deepseek-v4-pro']);        // [docs] 3068-3090
```

- "Failover only occurs when a `FailoverableException` is thrown ... Ordinary errors, like a validation or bad request error, will not trigger failover." ([docs] line 3077).
- Each failover dispatches `Laravel\Ai\Events\AgentFailedOver` (`src/Promptable.php:466-471`).
- Streaming fails over only if nothing has been yielded to the consumer yet (`src/Promptable.php:220-228`).
- `providerOptions()` is called per provider, so each fallback can get its own options ([docs] line 1879).
- This is failover across providers/models, not a retry of the same provider. Listing the same provider twice is not something I verified.

### 10.2 Retries and backoff: none

A full-source search for `retry`, `backoff`, `sleep(`, `usleep` finds only comments and the failover bookkeeping (`willRetry` means "another provider remains", [src] `src/Prompts/AgentPrompt.php:213-216`). The HTTP client is built without `->retry()` (2). A 429 or 529 on a single-provider call throws immediately.

### 10.3 Status mapping

[src] `src/Gateway/Concerns/HandlesFailoverErrors.php:23-88`, Anthropic overrides at `src/Gateway/Anthropic/AnthropicGateway.php:189-208`, confirmed by [tests] `tests/Feature/Providers/Anthropic/ErrorHandlingTest.php`:

| Condition | Exception thrown | Failoverable |
|---|---|---|
| HTTP 429 | `Laravel\Ai\Exceptions\RateLimitedException` | yes |
| HTTP 402 | `InsufficientCreditsException` | yes |
| HTTP 502, 503, 504, 520, 522, 524 (all providers); **529** additionally for Anthropic; Groq uses 498, 502, 503 | `ProviderOverloadedException` | yes |
| Anthropic only: any other error status whose `error.message` contains `credit balance`, `insufficient`, `quota exceeded`, `exceeded your current quota`, `billing`, `usage limit` | `InsufficientCreditsException` | yes |
| Connection failure **or timeout** (`Illuminate\Http\Client\ConnectionException`) | `ProviderConnectionException` ("Could not connect to AI provider [x].") | yes |
| Any other 4xx/5xx, **including 400, 401, 403, 404, 413, 500** | `Illuminate\Http\Client\RequestException` (framework class, not an `AiException`) | no |
| HTTP 200 with an error payload | `AiException` | no |
| Error event inside a stream | `StreamErrorException` | no |
| Model calls an unknown tool | `NoSuchToolException` | no |

All SDK exceptions live in `Laravel\Ai\Exceptions` and extend `AiException extends \Exception`; the failoverable ones also implement `FailoverableException` ([src] `src/Exceptions/*.php`). The original HTTP exception is the `previous` exception, so rate-limit headers are reachable:

```php
try {
    $response = $agent->prompt($text);
} catch (RateLimitedException|ProviderOverloadedException $e) {
    $retryAfter = $e->getPrevious()?->response?->header('retry-after');   // previous is the RequestException
}
```

(The chain is from `RateLimitedException::forProvider($providerName, $code, $requestException)`, `HandlesFailoverErrors.php:35-39`. Whether a given provider sends `retry-after` was not checked against live responses; [anthropic-ref] says Anthropic does on 429.)

Timeouts count as connection errors because Guzzle classifies `CURLE_OPERATION_TIMEOUTED` as a connect error ([local] `vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:711-717`, Guzzle 7.13.1) and Laravel rethrows it as `ConnectionException`. So a slow generation that exceeds the timeout looks like "could not connect" and triggers failover.

### 10.4 Timeouts

- Text generation: `timeout:` argument -> agent `timeout()` -> `#[Timeout]` -> **60 s** ([src] `src/Promptable.php:528-545`; docs line 1773). Applied as the HTTP client's `timeout` (`CreatesClient.php:30`). No connect timeout is set by the SDK; Laravel's default `connect_timeout` is 10 s ([fw] `Http/Client/PendingRequest.php:268-271`).
- The same timeout applies to every step of a tool loop and to every failover attempt.
- Other capabilities default to 30 s (embeddings, audio, transcription, reranking, classification) and 120 s for image generation (full-source search of timeout defaults).
- **Streaming and timeouts (environment-dependent):** with `allow_url_fopen` enabled Guzzle routes `stream => true` requests to its `StreamHandler` ([local] `guzzlehttp/guzzle/src/Utils.php:100-119, 171-184`, `Handler/Proxy.php:45-50`), where `timeout` becomes the PHP stream read timeout (`Handler/StreamHandler.php:849-852`), i.e. an idle timeout between chunks rather than a total limit. With the cURL handler it is a total transfer limit. **Could not verify** how a mid-stream read timeout surfaces in the SDK (the `withErrorHandling` wrapper only covers the initial `post()`).

---

## 11. Q10 — Provider-specific options

### 11.1 Generic knobs

| Knob | How | Sent as |
|---|---|---|
| Temperature | `#[Temperature(0.7)]` / `temperature(): float` | Anthropic `temperature`; OpenAI `temperature`; Gemini `generation_config.temperature` |
| Top-p | `#[TopP(0.9)]` / `topP()` | `top_p` |
| Max output tokens | `#[MaxTokens(4096)]` / `maxTokens(): int` | Anthropic `max_tokens` (**default 64 000 when unset**); OpenAI `max_output_tokens` (omitted when unset); Gemini `generation_config.max_output_tokens` (omitted when unset) |

Sources: Anthropic `src/Gateway/Anthropic/Concerns/BuildsTextRequests.php:29-33, 68-71`; OpenAI `src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:101-108`; Gemini `src/Gateway/Gemini/Concerns/BuildsTextRequests.php:95-99`. Tests: Anthropic `max_tokens === 64000` by default ([tests] `tests/Feature/Providers/Anthropic/RequestMappingTest.php:66`); OpenAI omits `max_output_tokens` when unset (`tests/Feature/Providers/OpenAi/RequestMappingTest.php:65-74`).

### 11.2 Generic passthrough: `HasProviderOptions`

```php
public function providerOptions(Lab|string $provider): array
{
    return match ($provider) {
        Lab::OpenAI => ['reasoning' => ['effort' => 'low']],
        Lab::Anthropic => ['thinking' => ['budget_tokens' => 1024], 'cache_control' => ['type' => 'ephemeral']],
        default => [],
    };
}                                                                      // [docs] 1861-1875
```

How the array is applied, per gateway:

- **Anthropic:** `array_merge($body, $providerOptions)` at the top level of the Messages request (`BuildsTextRequests.php:73`). Any top-level Messages API field can be set or **overridden**, including `system` and `tools` ([tests] `tests/Feature/Providers/Anthropic/PromptCacheTest.php:120-134`).
- **OpenAI:** `array_merge($body, $providerOptions)` at the top level of the Responses request (`BuildsTextRequests.php:110-114`). Example from tests: `reasoning.effort` ([tests] `tests/Feature/Providers/OpenAi/ProviderOptionsTest.php:17-27`).
- **Gemini:** merged into `generation_config`, except keys in a fixed top-level list (`agent`, `agent_config`, `background`, `environment`, `labels`, `previous_interaction_id`, `response_format`, `safety_settings`, `service_tier`, `store`, `user_metadata`, `webhook_config`) which are hoisted to the request root; a nested `generation_config`/`generationConfig` is flattened (`BuildsTextRequests.php:21-25, 105-129`). Example: `['thinking_level' => 'high']` ([tests] `tests/Feature/Providers/Gemini/ProviderOptionsTest.php:15-25`; [upgrade] 359-380).

Other entry points: agent middleware can add options for one step with `$step->withProviderOptions([...])` ([src] `src/PendingStep.php:126-131`). There is **no** `withProviderOptions()` on agents themselves (section 20).

**Shallow-merge hazard (Inference from `BuildsTextRequests.php:43-51` and `:73`):** for Anthropic, structured output is sent as `output_config.format`. A provider option named `output_config` replaces that whole key. Per [anthropic-ref] the reasoning `effort` setting lives at `output_config.effort`, so combining effort with native structured output requires returning the complete `output_config` (both `effort` and `format`) from `providerOptions()`, or switching to the synthetic-tool mode. The same reasoning applies to OpenAI's `text` key (it holds `text.format`). No test in the repository covers this combination; verify with an `Http::fake()` request-mapping test before relying on it.

### 11.3 Reasoning / thinking effort

No first-class API; use provider options:

- OpenAI: `['reasoning' => ['effort' => 'low']]` ([docs] 1864-1868). For models whose ID starts with `gpt-5` (except `gpt-5-chat`), `gpt-6`, `o4-mini`, `o3`, `o1` the SDK adds `include: ['reasoning.encrypted_content']` (`src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:120-125, 161-168`).
- Anthropic: `['thinking' => [...]]` is passed through unchanged (tests use `['type' => 'enabled', 'budget_tokens' => 10000]`, [tests] `tests/Feature/Providers/Anthropic/ProviderOptionsTest.php:8-25`). When `thinking` is present and the synthetic structured-output tool is in use, `tool_choice` becomes `auto` (`BuildsTextRequests.php:131-143`).
- Gemini: `['thinking_level' => 'high']`, `thinking_summaries` ([upgrade] 365-378).
- Reasoning text, when the provider returns it, is on `$response->reasoning` and per step ([upgrade] 533-539).

Cross-check from [anthropic-ref], not from the SDK, not verified by a live call: on the SDK's default Anthropic model `claude-sonnet-5`, `budget_tokens` is rejected and non-default sampling parameters (`temperature`, `top_p`) are rejected with HTTP 400; thinking is adaptive and on by default; depth is controlled with `output_config.effort`. On `claude-opus-5-5` thinking cannot be disabled and forced `tool_choice` returns 400 (relevant to `use_native_structured_output => false`, which forces `tool_choice: {type: 'tool'}` when no `thinking` option is set). `claude-haiku-4-5` still accepts sampling parameters and `budget_tokens`. The docs' own example pairs `#[Model('claude-sonnet-5')]` with `#[Temperature(0.7)]` and `#[TopP(0.9)]` ([docs] 1794-1800). Treat temperature on current Claude models as needing a live test.

### 11.4 Prompt caching

- **Anthropic and Bedrock cache only when asked** ([docs] line 1923). Two mechanisms:
  1. Attributes `#[CacheInstructions]` and `#[CacheToolDefinitions]`, optional TTL argument `'1h'` (default TTL is the provider's five minutes). They stamp `cache_control: {type: 'ephemeral'[, ttl]}` on the last `system` block and on the last tool (`BuildsTextRequests.php:79-126`; [tests] `PromptCacheTest.php:16-50, 95-104`). A one-hour instructions cache combined with a shorter tools cache throws `InvalidArgumentException` (lines 103-116).
  2. Provider option `['cache_control' => ['type' => 'ephemeral']]` at the top level enables Anthropic's automatic caching ([docs] line 1950).
  The attributes are class-level and static; there is no per-call switch other than using different classes.
- **OpenAI, Gemini, Groq, DeepSeek, xAI:** "require no configuration" ([docs] line 1914); the SDK only reports usage. Gemini's explicit `cachedContent` option "no longer exists" after the move to the Interactions API ([upgrade] line 377). OpenAI-specific cache parameters could be sent through provider options since they merge at top level (Inference; not tested).
- "Providers that do not support these attributes ignore them" ([docs] line 1941).
- Cross-check from [anthropic-ref]: prefixes shorter than a model-specific minimum silently do not cache (512 tokens on Opus 5.5 and Opus 5; 1024 on Sonnet 5; 4096 on Haiku 4.5). A short translation system prompt may never be cached; check `cacheReadInputTokens`.

### 11.5 Other provider behaviours worth knowing

- **OpenAI `store`:** with `store` not false (the default), the SDK does not send `store` and continues multi-step runs with `previous_response_id`; with `'store' => false` it sends `store: false` and replays history (`BuildsTextRequests.php:38-54, 116-118, 149-156`). For a content addon, set `'store' => false` in the provider config or `Ai::build()` array if responses should not be retained by OpenAI. (OpenAI's server-side default retention was not verified here.)
- **Gemini** always sends `store: false` unless overridden by a provider option (`BuildsTextRequests.php:72-77`).
- **Anthropic headers:** `anthropic-version: 2023-06-01` and `anthropic-beta: web-fetch-2025-09-10` by default, both overridable through provider config (`CreatesAnthropicClient.php:20-24`).

---

## 12. Q11 — Response metadata, events, middleware

### 12.1 Response object

`Laravel\Ai\Responses\AgentResponse extends TextResponse` ([src] `src/Responses/TextResponse.php:17-48`, `AgentResponse.php:10-27`):

| Property | Type | Notes |
|---|---|---|
| `text` | `string` | final step text |
| `usage` | `Laravel\Ai\Responses\Data\TextUsage` | summed over all steps |
| `meta` | `Laravel\Ai\Responses\Data\Meta` | `provider` (provider **name**), `model` (API-reported for Anthropic and OpenAI; the requested model for Gemini), `citations` |
| `steps` | `Collection<int, Step>` | each: `text`, `toolCalls`, `toolResults`, `finishReason`, `usage`, `meta`, `reasoning`, `raw` |
| `messages`, `toolCalls`, `toolResults` | `Collection` | |
| `reasoning` | `string` | `''` unless returned |
| `raw` | `?Illuminate\Http\Client\Response` | last HTTP response; `null` when streaming, on Bedrock, and on fakes ([docs] line 445) |
| `invocationId` | `string` | UUIDv7 per run |
| `conversationId`, `userMessageId`, `assistantMessageId` | `?string` | only with remembered conversations |

There is no top-level finish reason; use `$response->steps->last()?->finishReason`.

### 12.2 Token usage

```php
// [src] src/Responses/Data/Usage.php:14-17 and TextUsage.php:14-22
new TextUsage(
    int $inputTokens = 0,              // total input, INCLUDING cached and cache-written tokens
    int $outputTokens = 0,             // total output, INCLUDING reasoning tokens
    ?int $cacheReadInputTokens = null,
    ?int $cacheWriteInputTokens = null,
    ?int $reasoningTokens = null,
);
$usage->totalTokens();          // input + output
$usage->uncachedInputTokens();  // input - cacheRead - cacheWrite
$usage->toArray();              // input_tokens, output_tokens, cache_read_input_tokens, cache_write_input_tokens, reasoning_tokens
```

`null` means the provider did not report the value ([docs] lines 3021-3031). Per-provider mapping:

| Provider | input | output | cache read | cache write | reasoning | Source |
|---|---|---|---|---|---|---|
| Anthropic | `input_tokens + cache_read_input_tokens + cache_creation_input_tokens` | `output_tokens` | `cache_read_input_tokens` | `cache_creation_input_tokens` | `output_tokens_details.thinking_tokens` | `src/Gateway/Anthropic/Concerns/ParsesTextResponses.php:200-214` |
| OpenAI | `input_tokens` | `output_tokens` | `input_tokens_details.cached_tokens` | `input_tokens_details.cache_write_tokens` | `output_tokens_details.reasoning_tokens` | `src/Gateway/OpenAi/Concerns/ParsesTextResponses.php:158-169` |
| Gemini | `total_input_tokens` | `total_output_tokens + total_thought_tokens` | `total_cached_tokens` | never set (`null`) | `total_thought_tokens` | `src/Gateway/Gemini/Concerns/ParsesTextResponses.php:253-266` |

Pricing must treat uncached, cache-read and cache-write input separately ([upgrade] 303-311). In 0.x the fields were `promptTokens`/`completionTokens` and excluded cached/reasoning tokens (section 19).

### 12.3 Events

All in `Laravel\Ai\Events`, plain classes with public constructor properties:

| Event | Payload | Dispatched |
|---|---|---|
| `PromptingAgent` | `invocationId`, `prompt` (`AgentPrompt`) | before generation (`src/Providers/Concerns/GeneratesText.php:56`) |
| `AgentPrompted` | `invocationId`, `prompt`, `response` | after a successful `prompt()` (`GeneratesText.php:113-115`) |
| `StreamingAgent` (extends `PromptingAgent`) | same | when stream iteration starts (`StreamsText.php:77`) |
| `AgentStreamed` (extends `AgentPrompted`) | same, `response` is `StreamedAgentResponse` | after the stream completes (`StreamsText.php:129-132`) |
| `AgentFailed` | `invocationId`, `prompt`, `exception` | terminal failure only; skipped while another failover provider remains (`GeneratesText.php:203-213`) |
| `AgentFailedOver` (extends `ProviderFailedOver`) | `invocationId`, `agent`, `provider`, `model`, `exception` | on each failover (`src/Promptable.php:466-471`) |
| `StartingStep` | `invocationId`, `stepNumber`, `agent`, `provider`, `model`, `isFinalStep`, `messages`, `options` | per step (`src/Gateway/RunContext.php:78`) |
| `StepCompleted` | ..., `response` (`StepResponse`), `time` (ms in the provider call) | per step (`RunContext.php:93`) |
| `StepFailed` | ..., `exception`, `time` | per failed step (`RunContext.php:108`) |
| `InvokingTool`, `ToolInvoked`, `ToolFailed` | `invocationId`, `toolInvocationId`, `agent`, `tool`, `arguments`, then `result`/`exception`, `time` | around tool handlers (`RunContext.php:121-145`) |
| `ToolApprovalRequested`, `ToolApprovalResolved` | approval data | human tool approval |

The docs list 37 event names in total, covering images, audio, embeddings, files and stores as well ([docs] lines 3663-3699). `AgentPrompt` exposes `prompt`, `agent`, `attachments`, `provider`, `model`, `timeout`, `messages`, `tools`, `invocationId` ([src] `src/Prompts/AgentPrompt.php:17-78`).

Usage logging for the addon can hang off `AgentPrompted`/`AgentStreamed` (`$event->response->usage`, `$event->prompt->model`, `$event->prompt->provider->name()`), with `StepCompleted->time` for latency.

### 12.4 Agent middleware

- Declare with `HasMiddleware::middleware(): array`. Entries may be objects, class strings (container-resolved) or closures ([src] `src/Gateway/TextGenerationLoop.php:432-443`).
- **Since 1.0 middleware wraps each generation step, not the whole run** ([upgrade] 200-257): `handle(Laravel\Ai\PendingStep $step, Closure $next)` and must return `$next($step)` (a `StepResult`) or a `Laravel\Ai\Gateway\StepResponse` to short-circuit; anything else throws `LogicException('Agent middleware must return the next step result or a StepResponse.')` (`TextGenerationLoop.php:448-455`).
- `PendingStep` is immutable with copy methods `withModel`, `withInstructions`, `withMessages`, `withTools`, `onlyTools`, `withoutTools`, `withToolChoice`, `withMaxTokens`, `withProviderOptions`, plus `number`, `isFirstStep()`, `isFinalStep`, `steps`, `usage`, `provider`, `model`, `timeout` ([src] `src/PendingStep.php:25-158`).
- `$next($step)->then(fn (StepResponse $response) => ...)` runs after the model answered ([docs] 1719-1730).
- Returning a `StepResponse` from middleware is the hook for an addon-level response cache.

---

## 13. Q12 — Testing utilities

### 13.1 Faking agents

```php
SalesCoach::fake();                                   // auto responses
SalesCoach::fake(['First', 'Second']);                // sequential
SalesCoach::fake([['score' => 87]]);                  // structured agents: arrays
SalesCoach::fake(fn (string $prompt) => 'Echo: '.$prompt);
SalesCoach::fake()->preventStrayPrompts();
```

- `Promptable::fake(Closure|array $responses = []): FakeTextGateway` -> `Ai::fakeAgent(static::class, $responses)` ([src] `src/Promptable.php:550-553`, `src/Concerns/InteractsWithFakeAgents.php:35-41`). Fakes are keyed **by agent class name** on the `AiManager` singleton. Anonymous agents are faked through their classes: `AnonymousAgent::fake()` / `StructuredAnonymousAgent::fake()` (follows from the same code; not shown in docs).
- Accepted response items ([src] `src/Gateway/FakeTextGateway.php:201-236`): `string`; `array` (becomes a structured response); a `Closure`; a `TextResponse`/`StructuredTextResponse`; a `Laravel\Ai\Responses\Data\ToolCall` (simulated tool call); `AgentResponse::fakeWithReasoning($reasoning, $text)`; `AgentResponse::fakeWithPendingApprovals([...])`.
- **Closure signature (source and package tests):** `function (string $prompt, Collection $attachments, TextProvider $provider, string $model)` (`FakeTextGateway.php:137-144, 189-191`; [tests] `tests/Feature/AgentFakeTest.php:44, 95, 113`). The docs example type-hints `AgentPrompt $prompt` ([docs] 3117-3119), which does not match (section 20).
- With no response left: a plain agent returns `'Fake response for prompt: <first 10 words>'`; a structured agent gets generated data matching its schema (`FakeTextGateway.php:214-216`; `functions.php:51-140`). With `preventStrayPrompts()` a `RuntimeException('Attempted prompt [...] without a fake agent response.')` is thrown instead (`FakeTextGateway.php:209-212`).
- Fakes run through the real `TextGenerationLoop` ([upgrade] 986-1002), so streaming a faked agent emits real events (`StreamStart`, `TextStart`, word-by-word `TextDelta`, `TextEnd`, `StreamEnd`).
- `usage` on fakes is zero and `raw` is `null` unless you supply a response built with `->withRawResponse(...)` ([tests] `AgentFakeTest.php:71-82`).
- No API key is needed when faking: the real provider object is cloned and given the fake gateway (`src/AiManager.php:238-245`); no HTTP client is created.

### 13.2 Assertions

```php
SalesCoach::assertPrompted('Analyze this...');
SalesCoach::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Analyze') && $prompt->model === 'claude-sonnet-5');
SalesCoach::assertPromptedTimes(3);
SalesCoach::assertNotPrompted('Missing prompt');
SalesCoach::assertNeverPrompted();
SalesCoach::assertQueued(fn (QueuedAgentPrompt $prompt) => $prompt->contains('Analyze'));
SalesCoach::assertNotQueued('...');
SalesCoach::assertNeverQueued();
```

([src] `src/Promptable.php:558-609`; [docs] 3172-3222.) Assertion closures do receive `Laravel\Ai\Prompts\AgentPrompt` / `Laravel\Ai\QueuedAgentPrompt`. Prompts are recorded only for faked agents (`src/Providers/Concerns/GeneratesText.php:143-149`). Queued fakes dispatch the real job, so `then()` callbacks run unless you also call `Queue::fake()` ([upgrade] 720-726; [docs] line 3096).

### 13.3 Preventing stray requests

`preventStrayPrompts()` only protects the agent class it is called on. For a hard guarantee use the framework's `Http::preventStrayRequests()` ([fw] `Http/Client/Factory.php:406`), which covers every SDK request because the gateways use the `Http` facade.

For request-shape tests, fake at HTTP level exactly as the package does:

```php
Http::fake(['api.anthropic.com/*' => Http::response([
    'id' => 'msg_123', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
    'content' => [['type' => 'text', 'text' => 'Hello']],
    'stop_reason' => 'end_turn',
    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
])]);                                           // [tests] tests/Feature/Providers/Anthropic/AnthropicHelpers.php:11-22

Http::assertSent(fn ($request) => $request->data()['max_tokens'] === 64000);
```

Streaming can be faked with an SSE body and `['Content-Type' => 'text/event-stream']` ([tests] `PromptCacheTest.php:142-151`).

### 13.4 Inside a package with `orchestra/testbench`

1. **Register the provider explicitly.** Testbench ignores package auto-discovery by default (`ignorePackageDiscoveriesFrom()` returns `['*']`; `orchestral/testbench-core` 11.x `src/Concerns/CreatesApplication.php:62-65`). The SDK's own base test does:
   ```php
   // [tests] tests/TestCase.php:11-16
   protected function getPackageProviders($app): array
   {
       return [AiServiceProvider::class];
   }
   ```
   Statamic's `Statamic\Testing\AddonTestCase::getPackageProviders()` returns only Statamic's, Inertia's and the addon's providers ([local] `/Volumes/1TB/Sites/istionyachting/vendor/statamic/cms/src/Testing/AddonTestCase.php:52-65`), so override it and append `\Laravel\Ai\AiServiceProvider::class`.
2. **Set `app.key`** in tests that serialize on-demand providers (queued agents); the package tests do `config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)))` ([tests] `OnDemandProviderTest.php:10-12`).
3. **Migrations** are needed only for `RemembersConversations` (the middleware applies only to such agents: [src] `src/Middleware/RememberConversation.php:32-36`). The SDK's tests load `database/migrations` via `defineDatabaseMigrations()` (`tests/TestCase.php:25-28`).
4. Assertions use `PHPUnit\Framework\Assert` (`src/Concerns/InteractsWithFakeAgents.php:13`), fine under Pest or PHPUnit.
5. Each test gets a fresh application, hence a fresh `AiManager` and fresh fakes.
6. The SDK's dev constraints are `orchestra/testbench ^10.6|^11.0`, `pest ^3.0|^4.0`; Statamic 6.23 dev uses `orchestra/testbench ^10.8 || ^11.0`. Compatible.

---

## 14. Q13 — Tools and provider-native tools (brief)

- **Custom tools:** implement `Laravel\Ai\Contracts\Tool` with `description(): Stringable|string`, `handle(Laravel\Ai\Tools\Request $request): Stringable|string`, `schema(JsonSchema $schema): array` ([src] `src/Contracts/Tool.php:10-28`). `Request` is `ArrayAccess` and has `validate(array $rules, ...)`; validation errors are returned to the model ([docs] 1152-1168). Scaffold with `php artisan make:tool`. Return tools from `HasTools::tools()` or override per instance with `withTools()`.
- **Loop:** the SDK runs the tool loop itself, up to the step budget (5.1). Tools in one step run sequentially.
- **Sub-agents:** returning an `Agent` from `tools()` exposes it as a tool; optional `CanActAsTool` for name/description ([docs] 1515-1603).
- **Provider tools** (`Laravel\Ai\Providers\Tools\*`, executed by the provider): `WebSearch` (Anthropic, OpenAI, Azure, Gemini, xAI, OpenRouter), `WebFetch` (Anthropic, Gemini, OpenRouter), `FileSearch` (OpenAI, Gemini, xAI), `CodeExecution` (Anthropic, OpenAI, Azure, Gemini, xAI), `ToolSearch` for deferred tool loading (OpenAI, Anthropic) ([docs] 1253-1291, 1382-1513).
- **Also available:** MCP tools via `laravel/mcp` ([docs] 1323-1379), filesystem tools `FileStorage::all($disk)` / `readOnly()` ([docs] 1294-1320), `SimilaritySearch` for RAG ([docs] 1193-1250), `#[RepairToolCalls]`, human tool approval ([docs] 1956 onward), `ToolChoice`.
- None of this is required for translation or review.

---

## 15. Q14 — Laravel Boost guidelines/skills

- v1.0.1 ships exactly one Boost resource: **`resources/boost/skills/ai-sdk-development/SKILL.md`** (15.8 KB). There is no `resources/boost/guidelines/` directory in the tag (full tree listing). The local 0.9.1 copy has the same single file.
- The 1.0 upgrade guide mentions a Boost slash command `/upgrade-ai-sdk-v1` ([upgrade] line 7); it is not shipped inside `laravel/ai` (could not verify where it lives).

Conventions the skill prescribes ([src] `resources/boost/skills/ai-sdk-development/SKILL.md`):

1. "Always search the documentation before implementing any feature. Never guess at APIs" (line 15); query the docs with section headings, without package names (lines 17-19).
2. Decision workflow (lines 42-51): text or chat -> agent class with `Promptable`; history -> `Conversational` or `RemembersConversations`; structured JSON -> `HasStructuredOutput`; images/audio/transcription/embeddings/reranking/files/stores -> the matching entry-point class.
3. Key patterns (lines 438-445): namespace `Laravel\Ai\`; implement `Agent` + use `Promptable`; optional interfaces `HasTools`, `HasMiddleware`, `HasStructuredOutput`, `Conversational`; "Provider enum: `Laravel\Ai\Enums\Lab` (prefer over plain strings)"; Artisan `make:agent`, `make:tool`; helper `agent()`.
4. Configure agents with PHP attributes (lines 262-277).
5. Testing through `fake()` plus assertions for every capability (lines 389-434).
6. Pitfalls (lines 488-507): wrong namespace (`Illuminate\Ai`, `Laravel\AI` do not exist); unsupported capability throws `LogicException`.

Notes: the skill's provider table is older than the docs' (for example no Bedrock under Text, lines 511-519); it uses `Lab::OpenAiCompatible` (lines 467, 529), which does not match the enum case (section 20). Its only model ID is `claude-haiku-4-5-20251001` (lines 83, 267).

---

## 16. Q15 — Provider batch APIs

**None for text generation.** A search for `batch` across the v1.0.1 source matches only Gemini's embeddings endpoint `models/{model}:batchEmbedContents` (`src/Gateway/Gemini/GeminiGateway.php:198`), a Bedrock embeddings comment and an SSE-parsing comment. The three text gateways call only `messages`, `responses` and `interactions` (4.4). The docs do not mention batch processing. There is likewise no model-listing or token-counting call in the SDK.

Using Anthropic Message Batches, OpenAI Batch or Gemini batch would mean direct HTTP calls outside the SDK. (Per [anthropic-ref], Anthropic's Message Batches API exists and is asynchronous; not part of this SDK.)

---

## 17. Q16 — Limitations and gotchas

### Long outputs

- Anthropic: `max_tokens` defaults to **64 000** when no `MaxTokens` is set (11.1). Per [anthropic-ref], Haiku 4.5's output cap is 64K and current Sonnet/Opus models 128K, so the default fits those. **Could not verify** caps for older models; a model with a lower cap would reject the default, so set `maxTokens()` explicitly when the model is user-selectable.
- OpenAI and Gemini: no cap is sent unless set; the provider default applies.
- Reasoning tokens count as output tokens (12.2) and, on models that think by default, consume part of the output budget.
- The 60 s default timeout is too short for long generations; set `timeout()` explicitly and raise queue worker `--timeout` and `retry_after` above it.

### JSON truncation

- Truncated structured output yields `structured === []` without an exception, with `FinishReason::Length` on the last step (6.4). Build an explicit check and a split-and-retry path.
- Structured output cannot be streamed (7.5), so structured calls get no incremental progress and are fully exposed to the total HTTP timeout.

### Parallelism

- Nothing built in (9). Queue fan-out is version-independent. `Concurrency::run()` has no timeout parameter on Laravel 12 (60 s child process limit).
- No retry/backoff (10.2): parallel calls that hit 429 fail immediately unless the addon retries or lists a failover provider.

### HTTP timeouts

- Timeout -> `ProviderConnectionException` -> failover (10.3). With a failover list, a slow primary can silently shift work to the secondary provider. `AgentFailedOver` is the signal.
- Streaming timeout semantics depend on the Guzzle handler (10.4).

### Running inside a package

- `ai.default` is the literal `'openai'` and may be changed by the host app. Always pass a provider (argument or `provider()` method).
- Do not mutate the host's `ai.*` config; use `Ai::build()` with a unique `name` or the default hash name.
- Keys held in agent properties end up in queue payloads in plain form; resolve them inside `provider()` instead (8, 4.3).
- Host apps may already require `laravel/ai` 0.x; `^1.0.1` will conflict and force an upgrade with breaking changes (section 19).
- The package's migrations are publish-only and only needed for remembered conversations.
- `Ai` has no global alias; import `Laravel\Ai\Ai`.
- Octane and queue workers flush on-demand providers between operations (4.3); build providers where the work runs.

### API churn

- 51 releases in under eight months, default models changed four times since July, and the docs lag the code in places (section 20). Pin a minimum version and cover the request shapes you depend on with `Http::fake()` tests.

---

## 18. Q17 — Model IDs referenced by the SDK

Exactly as they appear; validity at the providers was **not** checked for OpenAI and Gemini.

| Where | IDs |
|---|---|
| Source defaults, Anthropic (`src/Providers/AnthropicProvider.php:73,81,89`) | `claude-sonnet-5` (default), `claude-haiku-4-5-20251001` (cheapest), `claude-opus-5-5` (smartest) |
| Source defaults, OpenAI (`src/Providers/OpenAiProvider.php:104,112,120`) | `gpt-6-sol` (default), `gpt-6-luna` (cheapest), `gpt-6-astra` (smartest) |
| Source defaults, Gemini (`src/Providers/GeminiProvider.php:107,115,123`) | `gemini-3.6-flash` (default and smartest), `gemini-3.1-flash-lite` (cheapest) |
| Reasoning-model prefixes, OpenAI (`src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:163-167`) | `gpt-5*` (not `gpt-5-chat*`), `gpt-6*`, `o4-mini*`, `o3*`, `o1*` |
| Docs examples | `claude-sonnet-5` ([docs] 418, 1795, 2374); `gpt-6` (256); `gemini-3-flash-preview`, `deepseek-v4-pro` (3087-3088); comments "(e.g., Haiku)", "(e.g., Opus)" (1822, 1830) |
| Boost skill | `claude-haiku-4-5-20251001` (SKILL.md 83, 267) |
| Upgrade guide | `gemini-3.7-flash`, `gemini-3.6-flash` ([upgrade] 732-741) |
| Config stub, Azure deployments (`config/ai.php:66-68`) | `gpt-4o`, `text-embedding-3-small`, `gpt-image-1` |
| Tests (fixtures only) | `claude-opus-5-5` (`OnDemandProviderTest.php:21`), `claude-sonnet-4-6` (fake payloads, `AnthropicHelpers.php:17`), `claude-3-5-sonnet`, `gpt-4` (`AiManagerDefaultConfigTest.php:30`) |
| Earlier defaults | see the table in 3.5 |

Cross-check for the Anthropic IDs only, from [anthropic-ref] (cached 2026-09-25): `claude-sonnet-5`, `claude-opus-5-5` and `claude-haiku-4-5` are listed model IDs; `claude-haiku-4-5-20251001` is listed as the full ID of the `claude-haiku-4-5` alias; `claude-sonnet-5` is described there as the previous Sonnet, with `claude-sonnet-5-5` the current one.

---

## 19. Differences: newest local copy (0.9.1) versus 1.0.1

| Area | 0.9.1 ([local] `/Volumes/1TB/Sites/istionyachting/vendor/laravel/ai`) | 1.0.1 |
|---|---|---|
| On-demand providers | no `Ai::build()` (absent from `src/AiManager.php`) | `Ai::build()`, `flushState()` |
| `prompt()` input | `string $prompt` (`src/Promptable.php:55-60`) | `AgentInput|UserMessage|Decisions|string`; `withMessages()`, `withTools()` added |
| Usage | `Usage` with `promptTokens`, `completionTokens`, `cacheWriteInputTokens`, `cacheReadInputTokens`, `reasoningTokens`, all `int` default 0 (`src/Responses/Data/Usage.php:10-15`) | `inputTokens`/`outputTokens` (inclusive totals) on `Usage`; nullable cache/reasoning counts on `TextUsage`; constructor order changed ([upgrade] 285-410) |
| Middleware | wraps the whole run, receives `AgentPrompt` (`src/Providers/Concerns/GeneratesText.php:113-114`) | wraps each step, receives `PendingStep` ([upgrade] 200-257) |
| Gemini API | `models/{model}:generateContent` (`src/Gateway/Gemini/GeminiGateway.php:69,100`) | Interactions API; provider option names changed ([upgrade] 359-380) |
| Failover statuses | 503 only, plus 529 for Anthropic (`HandlesFailoverErrors.php:71`, `AnthropicGateway.php:189`) | 502, 503, 504, 520, 522, 524 (+529); connection failures -> `ProviderConnectionException` ([upgrade] 668-698) |
| Exceptions | 6 classes | adds `ProviderConnectionException`, `StreamErrorException`, `EmbeddingsCountMismatchException`, approval exceptions |
| Attributes | 11 | adds `CacheInstructions`, `CacheToolDefinitions`, `RepairToolCalls` |
| Events | no `AgentFailed`, `StartingStep`, `StepCompleted`, `StepFailed`, `ToolFailed` | present; `AgentFailedOver` and `ToolInvoked` constructors changed ([upgrade] 747-758) |
| Vercel protocol | `usingVercelDataProtocol(bool $value = true)` (`src/Responses/StreamableAgentResponse.php:114`) | `usingVercelDataProtocol(?string $messageId = null)`; `StreamProtocol` classes |
| Stream errors | step ended quietly | throws `StreamErrorException`; always emits `StreamEnd` ([upgrade] 700-718) |
| AWS SDK | hard requirement `aws/aws-sdk-php ^3.339` | optional (`suggest`) |
| Conversations schema | `user_id`, `tool_calls`, `tool_results` | polymorphic participants (0.10), `steps` + `status` columns (1.0); manual migrations needed ([upgrade] 9-198, 762-877) |
| Default models | sonnet-5 / gpt-5.4 / gemini-3.5-flash | sonnet-5 / gpt-6-sol / gemini-3.6-flash |
| New capabilities since | n/a | human tool approval (0.10), `summarize` macro (0.10), tool search, raw HTTP response on responses (0.10.3), custom headers (0.10.3), classification, `decide` macros, AG-UI protocol, runtime tool overrides, code execution tool |

Unchanged between the two: Anthropic default `max_tokens` 64 000 and native `output_config` structured output (0.9.1 `BuildsTextRequests.php:29,41`), the fake closure call signature (0.9.1 `FakeTextGateway.php:151`), 60 s default timeout.

Also relevant: 0.6.0 (booknowdirect) still depended on Prism; 0.6.1 removed it.

---

## 20. Docs versus source discrepancies found

| # | Docs / guide say | Source says | Impact |
|---|---|---|---|
| 1 | Fake closure: `SalesCoach::fake(function (AgentPrompt $prompt) { return 'Response for: '.$prompt->prompt; });` ([docs] 3117-3119) | Closure is called with `(string $prompt, Collection $attachments, TextProvider $provider, string $model)` (`src/Gateway/FakeTextGateway.php:137-144, 189-191`); the package's tests use `fn (string $prompt)` | A closure type-hinted `AgentPrompt` would raise a `TypeError`. Use the source signature. |
| 2 | `Lab::OpenAiCompatible` ([docs] 303; SKILL.md 467, 529); `Lab::OpenAi` ([upgrade] 943) | Cases are `OpenAICompatible` and `OpenAI` (`src/Enums/Lab.php:18-19`) | Enum case names are case-sensitive. |
| 3 | Example uses `env('ANTHROPIC_BASE_URL')` ([docs] 131) | Shipped config reads `ANTHROPIC_URL` (`config/ai.php:58`) | Only matters when relying on the unpublished default config. |
| 4 | 0.11 notes: Gemini default and smartest are now `gemini-3.7-flash` ([upgrade] 728-745) | v0.11.1 onward and v1.0.1: `gemini-3.6-flash` (`src/Providers/GeminiProvider.php:107,123`) | Guide is stale; do not rely on defaults. |
| 5 | `$agent->withProviderOptions(['thinking_level' => 'high'])` ([upgrade] 369-372) | `Promptable` has no `withProviderOptions()`; the method exists only on `PendingStep`, `TextGenerationOptions`, `ProviderTool`, `Files\File` and the non-agent builders | For agents use `HasProviderOptions` or middleware. |
| 6 | Anthropic example `'thinking' => ['budget_tokens' => 1024]` and `#[Temperature(0.7)]`/`#[TopP(0.9)]` on `claude-sonnet-5` ([docs] 1869-1872, 1794-1800) | The SDK forwards these unchanged. Per [anthropic-ref] that model rejects `budget_tokens` and non-default sampling parameters | Not verified live. Test before exposing temperature for Claude models. |
| 7 | Boost skill provider table (SKILL.md 511-519) | Older than [docs] 283-292 | Prefer the docs table. |
| 8 | Docs do not mention `->onQueue()` etc. on queued responses | Available through `PendingDispatch` proxying (8) | Undocumented but source-backed. |

---

## 21. Could not verify

- Whether the OpenAI and Gemini model IDs in section 18 are currently valid at those providers.
- Any behaviour that needs a live API call: Anthropic's response to `temperature` on Sonnet 5, the `output_config` overwrite effect (11.2), actual `retry-after` headers, real cache hit behaviour.
- How a mid-stream read timeout surfaces (`ProviderConnectionException`, `StreamErrorException` or another exception).
- Output-token caps of legacy Anthropic models relative to the 64 000 default.
- Behaviour on Laravel 12.62 specifically. Local framework copies are 13.x only; the `Concurrency::run` signature for 12.x was read from the `12.x` branch HEAD on GitHub, not from a tagged 12.62 install.
- The exact 13.x release that added the `$timeout` parameter to `Concurrency::run`.
- Where the Boost `/upgrade-ai-sdk-v1` command is shipped.
- OpenAI's server-side retention default when `store` is not sent.
- Whether listing the same provider twice in a failover array acts as a retry.
- Whether Testbench sets a default `app.key` (the SDK's tests set one explicitly).

---

## 22. Implementation sketches for the task list

These are untested sketches assembled from the verified signatures above.

### 22.1 Agent with runtime provider, model, limits and options

```php
namespace Acme\AutoTranslator\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

class TranslatorAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /** @param list<string> $handles  Only scalars/IDs here: the object is serialized when queued. */
    public function __construct(
        protected string $role,            // 'translator' or 'reviewer'
        protected string $systemPrompt,
        protected array $handles,
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    // Resolved where the agent runs, so keys never enter a queue payload.
    public function provider(): Provider
    {
        return Ai::build(app(\Acme\AutoTranslator\Settings::class)->providerConfig($this->role));
        // e.g. ['driver' => 'anthropic', 'key' => '...', 'url' => null, 'store' => false]
    }

    public function model(): string
    {
        return app(\Acme\AutoTranslator\Settings::class)->model($this->role);
    }

    public function timeout(): int
    {
        return 300;
    }

    public function maxTokens(): ?int
    {
        return app(\Acme\AutoTranslator\Settings::class)->maxOutputTokens($this->role); // null = provider default
    }

    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenAI => ['reasoning' => ['effort' => 'low']],
            Lab::Gemini => ['thinking_level' => 'low'],
            default => [],   // Anthropic: see 11.2 before adding output_config
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return collect($this->handles)
            ->mapWithKeys(fn (string $handle) => [$handle => $schema->string()->required()])
            ->all();
    }
}
```

### 22.2 Calling it and guarding the result

```php
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\FinishReason;

try {
    $response = (new TranslatorAgent('translator', $system, $handles))->prompt($payloadJson);
} catch (RateLimitedException|ProviderOverloadedException|ProviderConnectionException $e) {
    $retryAfter = $e->getPrevious()?->response?->header('retry-after');
    // release the job with backoff: the SDK does not retry
    throw $e;
}

$finish = $response->steps->last()?->finishReason;

if ($finish === FinishReason::Length || $response->structured === []) {
    // truncated or undecodable JSON: split the payload and retry
}

$translations = $response->toArray();
$usage = $response->usage;   // inputTokens, outputTokens, cacheReadInputTokens, cacheWriteInputTokens, reasoningTokens
$model = $response->meta->model;
```

### 22.3 Streaming plain text with progress (structured agents cannot stream)

```php
use Laravel\Ai\Streaming\Events\TextDelta;

$stream = (new PlainTranslatorAgent(...))->stream($text);

foreach ($stream as $event) {
    if ($event instanceof TextDelta) {
        $buffer .= $event->delta;   // push progress to the Control Panel here
    }
}

$stream->text;    // full text
$stream->usage;   // TextUsage
```

### 22.4 Tests

```php
protected function getPackageProviders($app)
{
    return [...parent::getPackageProviders($app), \Laravel\Ai\AiServiceProvider::class];
}

protected function setUp(): void
{
    parent::setUp();
    \Illuminate\Support\Facades\Http::preventStrayRequests();
}

TranslatorAgent::fake([['title' => 'Bonjour']])->preventStrayPrompts();
// ... run the addon code ...
TranslatorAgent::assertPrompted(fn (\Laravel\Ai\Prompts\AgentPrompt $p) => $p->model === 'claude-haiku-4-5');
```

### 22.5 Parallelism options in order of robustness

1. One queued job per chunk or locale (`Bus::batch`), each calling `->prompt()` synchronously with its own `tries`/`backoff`/`timeout`.
2. `Concurrency::run([...], timeout: 300)` on Laravel 13 only.
3. Direct `Http::pool()` calls outside the SDK, only if the extra speed justifies re-implementing request mapping.
