# Configuration

Tolk has two kinds of configuration:

- **Settings**, for editors, on the settings page in the Control Panel: models, review, the context sent with every translation, the glossary and what is left out. They are stored in `resources/addons/statamic-tolk.yaml`.
- **The developer config**, `config/tolk.php`: models to add to Tolk's catalog or to change in it, timeouts, batch sizes, concurrency, drivers and paths. Publish it with `php artisan vendor:publish --tag=tolk-config` when you need to change a value.

API keys belong to neither: they come from `.env` only (see [Installation](installation.md#api-keys)).

## Settings

Open the settings page under **Tools › Addons › Tolk** or **Tools › Translations › Settings** (`cp/addons/statamic-tolk/settings`). It needs the **Tolk settings** permission or **Configure addons**. Every setting is read fresh on each request, so queue workers and the command's worker processes pick up a change without a restart.

### General

| Key | Field | Default | Meaning |
| --- | --- | --- | --- |
| `translator_model` | Translator model | Automatic | The model that translates. Automatic is the quality model of the first provider with an API key, in the order Anthropic, OpenAI, Gemini. |
| `review_enabled` | Review translations | off | A second model checks every translation and applies its corrections (see [Review](review.md)). Roughly doubles the time and the cost of a translation. Every translation can still turn review on or off for itself. |
| `reviewer_model` | Reviewer model | Automatic | The model that reviews. Automatic is the quality model of another provider with an API key, or the translator when there is no other. Shown only while review is on; its value is kept while it is hidden, because a single translation can still ask for a review. |
| `fallback_model` | Fallback model | none | Takes over a request after the translator failed it `retry.max_attempts` times in a row with a temporary error (a rate limit, an overloaded or unreachable provider). Ignored when its provider has no key, when it is the translator, or when its provider is the one that is paused. |
| `new_localization_status` | New localizations | Draft | Whether the localizations Tolk creates server-side (runs, the origin view's Translate into…, the command line) are drafts (`draft`) or published when their origin is (`mirror`, "Same as origin"; only when the user may publish and every field was translated completely). It is the default of the **New localizations** choice where there is one. Existing localizations keep their status. A localization's own form saves nothing by itself, so this does not apply there. |
| `offer_on_create` | Offer to translate new localizations | on | When an editor creates a localization in the entry form, Tolk offers to fill it with a translation (see [The editor](editor.md#the-offer)). |
| `strict_fields` | Strict fields | off | When part of a field cannot be translated (the model refuses it, or keeps returning invalid text), the field is normally saved with that part in the source language, flagged, and tried again on the next update. With this on, the whole field is left untranslated instead. See [Statuses](statuses.md#fields-left-partly-untranslated). |

The three model fields list the catalog grouped by provider, with each model marked **Quality** or **Fast**. Models of a provider without a key are disabled. To use a model that is not listed, type it as `provider:model`, for example `anthropic:claude-opus-5-5`; the provider must be `anthropic`, `openai` or `gemini`.

### Context

| Key | Field | Default | Meaning |
| --- | --- | --- | --- |
| `site_profile` | Site profile | empty | A short description of the organisation, what it offers, its audience and its tone, sent with every translation. **Generate** writes one from the default site's content with the translator model. |
| `instructions` | Instructions | empty | Instructions for every translation (tone, spelling, units). Any language. |
| `language_instructions` | Language instructions | none | One row per language: `site` (the site whose language it is), `formality` (`default`, `formal` or `informal`) and `instructions` for that language only. |

How the context reaches the model, and how to write it, is in [Context and glossary](context-and-glossary.md).

### Glossary

| Key | Field | Default | Meaning |
| --- | --- | --- | --- |
| `glossary` | Glossary | none | Terms translated the same way every time, or not at all. Each row has `term`, `site` (empty for every language), `translation`, `do_not_translate` and `note`. |

See [Context and glossary](context-and-glossary.md#the-glossary).

### Content

| Key | Field | Default | Meaning |
| --- | --- | --- | --- |
| `excluded_collections` | Excluded collections | none | Entries of these collections are never translated: the banner, the offer and the field actions do not appear in their forms, the listing action is hidden for them, runs, the origin view's Translate into… and the commands leave them out with a warning, and the Overview and `tolk:status` do not list them. |
| `excluded_taxonomies` | Excluded taxonomies | none | The same, for the terms of these taxonomies. |
| `excluded_fields` | Excluded fields | none | Field handles that are never translated, in every blueprint of entries, terms and global sets. This applies to top-level fields; for a field inside a Replicator, Grid or Bard set, switch off its **Translate automatically** option instead (below). |
| `translated_slugs` | Translated slugs | none | New localizations of these collections get a slug made from their translated title, the way Statamic makes slugs in the target site's language ("Hallo Welt" becomes `hallo-welt`). In every other collection a new localization keeps its origin's slug. An existing slug is never changed. See [Entries](content-types.md#entries). |

### Automation

| Key | Field | Default | Meaning |
| --- | --- | --- | --- |
| `auto_update` | Update translations automatically | off | Each time an entry is saved published in its origin, its localizations are updated in the background: the fields whose origin changed and the fields not translated yet, as **Update translations** does. Fields edited by hand are never overwritten. Every save that changes text spends API credit. See [Automatic updates](editor.md#automatic-updates); without a queue worker, the web server must send a save's response before the update runs ([Automatic updates](bulk-and-runs.md#automatic-updates)). |
| `auto_update_create` | Create missing localizations | off | Also translates the entry into the sites of its collection that have no localization of it yet, as **Translate into…** does. New localizations follow `new_localization_status`. Shown only while `auto_update` is on, like the two below; their values are kept while they are hidden. |
| `auto_update_collections` | Collections | none | Only the entries of these collections are updated automatically. Empty: every collection that is not excluded. |
| `auto_update_publish` | Publish updates | off | In collections with revisions, an update of a published localization goes into its working copy and waits until someone publishes it. On: it is published at once, unless the localization already had unpublished changes. |

Entries only: terms, global sets and the navigation are not updated automatically.

### Providers

`providers` (Providers) shows, for Anthropic, OpenAI and Gemini, whether a key is set, whether it is Tolk's own, from the addon's config (`TOLK_*_API_KEY` by default), or comes from `config/ai.php`, which Tolk shares with the rest of the site, and, without a key, which variables to set, with **Test connection**: one small request to the provider's fast model, with a 20-second timeout, answered with the time it took or a readable error. The field stores nothing.

### What the settings page refuses

- A glossary row needs a term, and a translation or **Do not translate**. A term or translation of nothing but the noparse tags (below) counts as empty.
- The same term twice for one language, or twice for every language, is refused on the later row. "The same" means equal after trimming spaces and leaving out the noparse tags: terms that differ only in case are separate rows. A row for one language may override the row for every language with the same term, ignoring case and accents.
- Each language has at most one language instruction row, and the row needs a site.
- Formality is `default`, `formal` or `informal`; New localizations is `draft` or `mirror`; a model is a `provider:model` reference.
- Statamic reads `{{` and `}}` in every addon setting as Antlers, so a text it cannot read as Antlers, such as `Keep {{ and }} unchanged.`, is refused. To keep the braces as written, put the whole text between `{{ noparse }}` and `{{ /noparse }}`: Tolk leaves those two tags out of what it sends to the model. A text whose braces are valid Antlers, such as `{{ site:name }}`, is saved and sent as you wrote it. `tolk:profile --save` puts a generated profile that holds `{{` between the noparse tags itself, so Statamic neither refuses it nor reads it as Antlers. Where even the tags would not keep it as written, such as a profile that holds `{{ /NOPARSE }}` or ends in `{`, it saves the profile with each `{{` written as `{ {`.

## Per-field options

Tolk adds two options to the settings of every field whose fieldtype it can translate (Text, Textarea, Markdown, Bard, Replicator, Grid, Group, Table, List, Array, and any fieldtype an addon registers, see [Extending](extending.md)). They are in the blueprint editor, in the field's settings:

| Option | Label | Default | Meaning |
| --- | --- | --- | --- |
| `tolk_translate` | Translate automatically | on | Off: Tolk never translates this field. On a field inside a Replicator, Grid, Group or Bard set this is the way to leave it out, since nested fields have no `localizable` flag. |
| `tolk_hint` | Translation hint | empty | Guidance sent to the model with this field's text, for example "product name, keep in English" or "button label, at most three words". |

A top-level field is translated only when it is **localizable**. A field that is not localizable is shared by every site, so Tolk leaves it alone. `tolk:doctor` and the Audit page list the text fields that are not localizable and can make them localizable for you. The other rules (which fieldtypes, `slug`, generated titles, hidden and computed fields) are in [Content types](content-types.md#which-fields-are-translated).

In YAML the options look like this:

```yaml
-
  handle: product_code
  field:
    type: text
    localizable: true
    tolk_translate: false
-
  handle: tagline
  field:
    type: text
    localizable: true
    tolk_hint: 'Short marketing line, at most eight words'
```

## Environment variables

| Variable | Meaning |
| --- | --- |
| `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY` | The Laravel AI SDK's keys, which Tolk uses. |
| `TOLK_ANTHROPIC_API_KEY`, `TOLK_OPENAI_API_KEY`, `TOLK_GEMINI_API_KEY` | Keys for Tolk only, used before the SDK's (`providers.*.key`). |
| `TOLK_FAKE_ENGINE` | `true` turns on the fake engine (`fake_engine`), in the `local` and `testing` environments only. |

## The developer config

`config/tolk.php`, read with `config('tolk.…')`. The defaults suit a small to medium site on shared hosting; most sites change nothing.

### Providers

| Key | Default | Meaning |
| --- | --- | --- |
| `providers.anthropic.key` | `env('TOLK_ANTHROPIC_API_KEY')` | A key for Tolk only. Empty: `config('ai.providers.anthropic.key')` (`ANTHROPIC_API_KEY`). |
| `providers.anthropic.url` | `null` | Another API address, for example a proxy. Empty: `config('ai.providers.anthropic.url')`. |
| `providers.openai.key` | `env('TOLK_OPENAI_API_KEY')` | As above, for OpenAI (`OPENAI_API_KEY`). |
| `providers.openai.url` | `null` | As above, for OpenAI. |
| `providers.gemini.key` | `env('TOLK_GEMINI_API_KEY')` | As above, for Gemini (`GEMINI_API_KEY`). |
| `providers.gemini.url` | `null` | As above, for Gemini. |

Tolk never changes the app's `ai.*` config. It builds its own provider connections, named `tolk_anthropic`, `tolk_openai` and `tolk_gemini`, so do not define connections with those names in `config/ai.php`. OpenAI requests are sent with `store: false`.

Tolk sends each request to a provider once and never waits inside it. From version 1.2.1 the Laravel AI SDK itself waits up to 10 seconds and sends a request again when the provider answers a rate limit or an overload with a short `Retry-After` header. So Tolk adds one middleware to Laravel's HTTP client: while one of Tolk's own provider requests runs, it renames that header to `Tolk-Retry-After`, and Tolk pauses the provider instead ([Retries and concurrency](#retries-and-concurrency)). The app's other HTTP requests pass through it unchanged.

### The model catalog

Tolk comes with a catalog of models per provider, and each release of Tolk keeps it up to date, with new models, prices and request options. Its defaults:

| Provider | Quality (the default) | Fast |
| --- | --- | --- |
| Anthropic | `claude-sonnet-5-5` (Claude Sonnet 5.5) | `claude-haiku-5-5` (Claude Haiku 5.5) |
| OpenAI | `gpt-6.1-sol` (GPT-6.1 Sol) | `gpt-6-luna` (GPT-6 Luna) |
| Gemini | `gemini-3.8-flash` (Gemini 3.8 Flash) | `gemini-3.5-flash-lite` (Gemini 3.5 Flash-Lite) |

`models.anthropic`, `models.openai` and `models.gemini` are empty in the config. List models there only to add one to Tolk's catalog or to change one of Tolk's:

- An entry with the id of one of Tolk's models replaces the keys it names and keeps Tolk's other keys. The keys it does not name still follow Tolk's updates.
- An entry with any other id adds a model, offered after Tolk's. So Tolk's first model of each tier stays the provider's default.

Each entry has these keys:

| Key | Meaning |
| --- | --- |
| `id` | The provider's model id. The only key that a change to one of Tolk's models needs. |
| `label` | The name shown in the CP and the commands. |
| `tier` | `quality` or `fast`. The first model of a tier is the provider's default for it; Automatic uses the quality one. To make a model you add the default, choose it in the settings, or give Tolk's model another tier. |
| `options` | Provider options merged into every request, such as reasoning settings. Never put `output_config` (Anthropic), `text` (OpenAI) or `response_format` (Gemini) here: each would replace the structured output format Tolk relies on. Never add `temperature`, `top_p` or `top_k`. OpenAI's models from GPT-5.6 on whose id starts with `gpt-` also get `prompt_cache_options: {mode: explicit}` (see [How much context costs](context-and-glossary.md#how-much-context-costs)), unless their options hold a `prompt_cache_options` of their own. A fine-tuned model gets it only from its own options (see the note after the example below). |
| `max_output_tokens` | The model's output limit. |
| `reasoning_headroom` | Output tokens reserved for the model's thinking with these options; `0` when it does not think. |
| `price.input`, `price.output` | USD per million tokens, for the estimates and the usage figures. Without a price the cost reads "unknown". |
| `price.cache_read`, `price.cache_write` | Optional: USD per million tokens read from or written to the provider's prompt cache. Without them, reads cost 10% of the input price and writes 125%, the usual rates; GPT-6.1 Sol sets `cache_read`, because OpenAI bills its reads at 5%. |

A key you name replaces Tolk's whole value: your `options` replace Tolk's options, and your `price` needs both `input` and `output`.

Claude Haiku 5.5 is priced by prompt length; the catalog holds its rate for prompts up to 100,000 tokens, which Tolk's requests stay well below. Gemini 3.8 Flash is priced at its rate from January 2027, so until then the estimates for it are about twice the cost.

For example, to pay a negotiated price for Claude Sonnet 5.5 and offer a fine-tuned OpenAI model:

```php
'models' => [
    'anthropic' => [
        ['id' => 'claude-sonnet-5-5', 'price' => ['input' => 1.8, 'output' => 9.0]],
    ],
    'openai' => [
        [
            'id' => 'ft:gpt-6-luna:acme::abc123',
            'label' => 'Acme Luna',
            'tier' => 'fast',
            'options' => ['reasoning' => ['effort' => 'none']],
            'max_output_tokens' => 128000,
            'reasoning_headroom' => 0,
            'price' => ['input' => 0.2, 'output' => 0.8],
        ],
    ],
    'gemini' => [],
],
```

Tolk adds `prompt_cache_options` on its own only to ids that start with `gpt-`, not to a fine-tuned model's `ft:` id, because it has not been checked whether OpenAI accepts the option for fine-tuned models. Without it, OpenAI may write every request to a fine-tune of a model from GPT-5.6 on to the cache at 1.25 times the input price. To switch that off, add `'prompt_cache_options' => ['mode' => 'explicit']` to the fine-tune's `options`, but try it before a run uses the model. OpenAI refuses an option a model does not accept with HTTP 400 (models before GPT-5.6 refuse this one), and Tolk reads a 400 as a refusal of that request, not of the model: a run does not stop, and each field is saved with its text in the source language, over the translation it had, and flagged as [partly untranslated](statuses.md#fields-left-partly-untranslated). So choose the fine-tune under **Options** in the entry editor and translate one entry without saving it. If the fields fill with the source text and the banner says they are partly untranslated, press **Undo** and remove the option: the app's log has the entry "Tolk: the AI provider rejected a request." with OpenAI's message, such as "prompt_cache_options is not supported on this model". Runs and the command line warn that such a field's text "was left untranslated (the provider rejected the request)".

A config published from Tolk 1.0.0-beta.1 holds a copy of that version's whole catalog, and each entry of the copy keeps replacing Tolk's model with the same id. Delete the entries, or keep only the keys you changed. The copy's Claude Haiku 5.5 sends a thinking option that Anthropic refuses.

A model may also be used without a catalog entry, typed as `provider:model` in the settings (or in `--translator`); it then gets no options, no known output limit and no price. Only users who may edit the settings may choose a model outside the catalog for a single translation.

### Requests and batches

| Key | Default | Meaning |
| --- | --- | --- |
| `http.timeout.web` | `45` | Seconds a provider call may take for the editor and for runs driven by the browser or by Tolk's queue lanes, including a command-line run resumed or retried on its run page. Keep it below your web server's and proxy's timeouts (often 60 seconds). |
| `http.timeout.background` | `180` | Seconds a provider call may take for the command line, and for an automatic update that Tolk's queue lanes do not drive: the process that saved the entry runs it after its response or, for a save inside a queue job, a job of its own on that job's queue ([Automatic updates](bulk-and-runs.md#automatic-updates)). |
| `batch.max_chars.web` | `4000` | Source characters per request for the editor, browser runs and Tolk's queue lanes. A command-line run resumed or retried on its run page first splits the larger requests the command planned, without a call. |
| `batch.max_chars.background` | `10000` | Source characters per request for the command line, and for an automatic update that Tolk's queue lanes do not drive (run by the process that saved the entry or, for a save inside a queue job, by a job on that job's queue). |
| `batch.min_chars` | `1200` | The editor splits an entry into parallel requests of at least this many characters, so a short entry is one request and a long one fills in faster. |
| `batch.max_segments` | `80` | Pieces of text per request. |

A request that times out with several pieces of text is split in two and sent again, so a timeout costs time but loses nothing. Lower the batch sizes when requests keep timing out (see [Troubleshooting](troubleshooting.md#requests-time-out)).

A config published from Tolk 1.0.0-beta.1 still sends 6,000 characters per web request, the default then: with real providers, 6,000 characters into Greek took Claude Sonnet 5.5 43 seconds against the 45-second `http.timeout.web`, so one slow answer timed out and was sent again in two halves. Set `batch.max_chars.web` to `4000`, or remove the keys you did not change on purpose. Remove a whole top-level key, such as `batch`: Laravel fills in Tolk's defaults only for the top-level keys a published config leaves out, not for a key inside one.

### Retries and concurrency

| Key | Default | Meaning |
| --- | --- | --- |
| `retry.max_attempts` | `3` | Temporary failures of one request before the fallback model takes it over. |
| `retry.base_delay_ms` | `1000` | The first wait after a temporary failure, when the provider names none. |
| `retry.max_delay_ms` | `20000` | The longest wait; the waits grow exponentially in between. |
| `concurrency.editor` | `4` | Parallel requests from one open entry form. |
| `concurrency.browser` | `2` | Lanes of a run driven by its run page. Each lane holds a PHP worker for as long as the page is open, plus one for saving, so keep this low on shared hosting. |
| `concurrency.queue` | `4` | Lane jobs of a run on the queue. |
| `concurrency.cli` | `4` | Worker processes of `tolk:translate` and `tolk:site` (`--concurrency` overrides it). |

A rate limit or an overloaded provider pauses every request to that provider for the time it asks for, so higher concurrency does not make Tolk hammer a provider that pushes back.

### Translation state

| Key | Default | Meaning |
| --- | --- | --- |
| `state.driver` | `file` | Where the state is stored. `file` is the only driver in this version. |
| `state.path` | `base_path('content/tolk')` | The state's folder. With content in files, keep it under `content/`, so it is committed with the content. With content in a database (Statamic's Eloquent driver), set it to a folder that every deploy keeps, such as `storage_path('statamic/tolk/state')`, and back it up with the database: a deploy that replaces the site's folder would take `content/tolk/` with it, and every translation would then read as edited by hand. On several servers that do not share the content folder, a folder they all share ([Several servers](installation.md#several-servers)). `tolk:doctor` warns about it. |

### Runs and usage

| Key | Default | Meaning |
| --- | --- | --- |
| `runs.driver` | `file` | Where runs are stored. `file` is the only driver in this version; it rewrites a run's index on every step, so it suits runs of up to a few thousand items. |
| `runs.path` | `storage_path('statamic/tolk/runs')` | The runs' folder. On several servers, a folder every server and queue worker shares ([Several servers](installation.md#several-servers)). |
| `runs.retention_days` | `30` | `tolk:prune` deletes runs without activity for this many days. |
| `runs.tick_seconds` | `5` | How long one request of a browser-driven run may keep working on items. It starts at most one provider call, so a request lasts at most about this plus `http.timeout.web`. |
| `runs.claim_ttl` | `300` | Seconds a queue or command-line worker holds an item before another may take it over. `tolk:prune` stops runs that made no progress for this long. |
| `runs.execution` | `auto` | How runs started in the CP are driven: `auto` (on the queue when the queue connection has a worker, so not `sync`, `deferred`, `background` or `null`; otherwise by the run page), `queue` or `browser`. Automatic updates go on that queue only when runs do; otherwise the process that saved the entry drives them after its response, or, for a save inside a queue job, a job of their own on that job's queue ([Automatic updates](bulk-and-runs.md#automatic-updates)). |
| `runs.queue.connection` | `null` | The queue connection for runs; `null` is the app's default. |
| `runs.queue.name` | `null` | The queue name for runs; `null` is the connection's default. |
| `usage.path` | `storage_path('statamic/tolk/usage')` | The monthly usage files the Overview reads. On several servers, a folder they all share, like `runs.path`. |

How runs work, and what the queue connection's `retry_after` must be, is in [Bulk translations and runs](bulk-and-runs.md).

### Saving, cache and the rest

| Key | Default | Meaning |
| --- | --- | --- |
| `write.lock_seconds` | `120` | Content is saved by one process at a time, under a global lock held for at most this long. |
| `write.wait_seconds` | `60` | How long a save waits for that lock. |
| `cache_store` | `null` | The cache store for plans, locks, pauses and the Overview's counts; `null` is the app's default store. It must support locks. On several servers use one they share (`redis`, `database`): a `file` cache is local to one server ([Several servers](installation.md#several-servers)). Clearing it loses the plans of the translations in progress: a run pays again for the items it had translated, wholly or in part, but not saved yet, and an editor starts a half-done translation again. `php artisan cache:clear`, which many deploy scripts run, and the application cache of Statamic's **Cache Manager** clear the default store. Where a deploy may come while a run is in progress, give Tolk a store of its own that they do not clear (a `file` store with its own `path`, or a Redis store whose connection uses a database of its own), or deploy between runs. |
| `plan_ttl` | `7200` | Seconds a translation plan and its results stay in the cache, unless the translation is saved first, which deletes them. An editor who leaves a translation half done for longer starts it again, and a run resumed later pays again for the items it had translated but not saved. Clearing the cache store loses them at once (`cache_store`). A `file` or `database` cache keeps an expired plan's text until the cache is cleared ([Privacy and data](privacy.md#what-tolk-keeps-on-your-server)). |
| `fieldtypes` | `[]` | Handlers for more fieldtypes, as `'fieldtype handle' => HandlerClass::class` (see [Extending](extending.md)). |
| `tokens.chars_per_token` | `3` | Characters per token, for the estimates. |
| `fake_engine` | `env('TOLK_FAKE_ENGINE', false)` | A deterministic engine for development and tests that calls no provider and returns `[lang] text`, for example `[de] Welcome`. It works only in the `local` and `testing` environments and is ignored everywhere else. Source text containing `[[review]]`, `[[invalid]]` or `[[refuse]]` makes it return a review correction, invalid text or a refusal. |
| `fake_delay_ms` | `300` | How long the fake engine takes per request. |
