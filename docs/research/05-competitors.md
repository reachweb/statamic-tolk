# 05 - Competitors and prior art: AI / machine translation for Statamic multi-site

Research date: 2026-10-01 (all "as of" statements refer to this date). Read-only web research; nothing was installed or run against a Statamic site, so behaviour is taken from listings, READMEs, source code and issue trackers, not from hands-on testing.

## 0. Method and verification labels

- **Statamic Marketplace**: crawled the public Marketplace JSON API, all 42 pages / 621 addons (`https://statamic.com/api/v1/marketplace/addons?page=N`), then keyword-filtered name + summary + description for `translat|deepl|multiling|locali[sz]|multi-?site|i18n|language`. Also used the API `search=` parameter for translate, translation, translator, deepl, ai, localization, multisite, autotranslat, "auto translat", infofactory, kreatif, "entry translator", and read the AI tag page (https://statamic.com/addons/tags/ai). Note: the API is behind Cloudflare and returned HTTP 429 after ~16 rapid requests (retry-after about 10 minutes); spacing requests 7-9 s apart worked.
- **Packagist**: search API (https://packagist.org/search.json?q=...), the full `statamic-addon` type list (https://packagist.org/packages/list.json?type=statamic-addon, 442 packages) and per-package JSON (https://packagist.org/packages/VENDOR/NAME.json).
- **GitHub**: repository search plus direct reads of README, composer.json, source files, releases, issues and PRs.
- Labels used below: **[source]** = read in the repository source code; **[docs]** = stated in README / listing / changelog; **[issue]** = reported in an issue thread; **could not verify** = looked for it and could not confirm.

---

## 1. Headline findings

1. **There is exactly one serious LLM-based competitor: Magic Translator** (el-schneider / Jonas List). It is free and MIT-licensed, supports Statamic 5 and 6, uses Prism (OpenAI, Anthropic, Gemini, Mistral, Ollama...) or DeepL, and already ships a sidebar dialog, a bulk action, a CLI command, queue jobs and hash-based staleness badges. First release 2026-04-05, latest v0.3.2 on 2026-08-27, 1,175 Packagist installs (419/month). It is explicitly pre-v1 ("breaking changes are acceptable and expected"). Sources: https://github.com/el-schneider/statamic-magic-translator , https://statamic.com/addons/el-schneider/statamic-magic-translator , https://packagist.org/packages/el-schneider/statamic-magic-translator , https://github.com/el-schneider/statamic-magic-translator/blob/main/AGENTS.md
2. **Every paid translation addon on the Marketplace is DeepL/Google only**: One-click Content Translation ($39, 66.5k installs, per-field buttons) and AI Translator by Option5 ($49, DeepL only despite the name). Sources: https://statamic.com/addons/awl/deepl , https://statamic.com/addons/option5/ai-translator
3. **Nobody ships the differentiators planned for the new addon as a packaged product**: CP-editable site-aware context + glossary for LLMs, multi-model (LLM) review, field-level origin-change tracking, draft/review workflow, or coverage of entries + terms + globals + navs + assets in one tool. The closest thing is an agency's own layer on top of Magic Translator (kreatifIT/statamic-translator, published on GitHub only, created 2026-09-16) that adds CP settings for company context, tone, per-language formality, glossary, do-not-translate terms, example translations and a human review flag. Source: https://github.com/kreatifIT/statamic-translator
4. **No translation addon for Statamic uses the Laravel AI SDK (`laravel/ai`)**. The LLM ones (Magic Translator, infoFactory) use pre-1.0 `prism-php/prism` (0.98 to 0.100). `laravel/ai` reached v1.0.0 on 2026-09-23 (v1.0.1 on 2026-09-29). The Statamic addons that do depend on `laravel/ai` are not translators (Advanced SEO, two byte5 addons, an AI chatbot). Sources: https://packagist.org/packages/prism-php/prism/dependents.json , https://packagist.org/packages/laravel/ai/dependents.json , https://repo.packagist.org/p2/laravel/ai.json
5. **Statamic core has no built-in AI or translation feature**, shipped or announced. Latest stable is v6.34.1 (2026-09-23). The public roadmap lists "Multi-Site Improvements" (in progress), "Workflows" (planned) and "Localizable Asset Meta Data" (planned); none mention AI or machine translation. Sources: https://repo.packagist.org/p2/statamic/cms.json , https://github.com/statamic/cms/blob/6.x/CHANGELOG.md , https://statamic.com/roadmap
6. **Naming**: no Statamic addon is called Autotranslator / Auto Translator / Auto Translate on the Marketplace, Packagist or GitHub. The name is used elsewhere (Laravel `ibrahimamasha/autotranslator`, Craft "Auto Translator", TYPO3 "Auto translator"), and the Statamic space is already crowded with "... Translator" names.

---

## 2. Task 1 - What actually exists

### 2.1 Resolving the names you had heard of

| Heard-of name | Reality |
|---|---|
| el-schneider "Auto Translate" | **Does not exist.** el-schneider's repositories include `statamic-auto-alt-text` ("Auto Alt Text"), `statamic-magic-actions` and `statamic-magic-translator`; there is no auto-translate repo (https://github.com/el-schneider?tab=repositories). The Marketplace has "Auto Alt Text" by Jonas List (https://statamic.com/addons/el-schneider/auto-alt-text). Likely a conflation of "Auto Alt Text" and "Magic Translator". |
| el-schneider "Magic Translator" | **Real**, and the main competitor (section 3.1). |
| "DeepL-based translators" | **Real, several**: One-click Content Translation (DeepL or Google), AI Translator by Option5 (DeepL only), JustBetter Entry Translator (DeepL), Bold AI Assistant (DeepL for translation). Magic Translator also has a DeepL backend. |
| "Content Translator" | **No addon by that name** on the Marketplace (API search and full crawl) or Packagist. `CONTENT_TRANSLATOR_*` was Magic Translator's original env-var prefix, renamed to `MAGIC_TRANSLATOR_*` in v0.1.1 (https://github.com/el-schneider/statamic-magic-translator/blob/main/CHANGELOG.md). |
| "Translation Manager" | **Two listings, neither does machine translation.** (a) Thought Collective / `ryanmitchell/statamic-translation-manager`: manages Laravel language files (UI strings) in the CP; free, Statamic 6.5+, v3.1.1 (2026-04-20), 80k installs (https://statamic.com/addons/thoughtco/translation-manager , https://packagist.org/packages/ryanmitchell/statamic-translation-manager). (b) Happy Pixels "Translation Manager": XLIFF export/import of pages, globals, collections and taxonomies for agencies; legacy listing with no Composer package; its public page returns HTTP 404 (https://statamic.com/addons/happy-pixels/translation-manager ; description from the Marketplace API). |

### 2.2 Inventory

Content machine-translation addons for Statamic (everything found):

| # | Addon | Vendor | Where published | Engine | Status |
|---|---|---|---|---|---|
| 1 | Magic Translator | Jonas List (el-schneider) | Marketplace (free), Packagist, GitHub | LLM via Prism, or DeepL | Active, pre-1.0 |
| 2 | One-click Content Translation with DeepL or Google | Apps with love AG | Marketplace ($39), Packagist, GitHub | DeepL, Google Cloud Translation | Active, incumbent |
| 3 | AI Translator | Option5 | Marketplace ($49), Packagist, GitHub | DeepL only | Active |
| 4 | Statamic AI Translations | infoFactory | Packagist, GitHub (README says Marketplace, but not listed) | LLM via Prism | Statamic 5 only |
| 5 | Statamic Entry Translator | JustBetter | Packagist, GitHub (not on Marketplace) | DeepL | Active, Statamic 6.3+ only |
| 6 | Statamic AI Assistant | Bold Web (boldwebdev) | Packagist, GitHub (not on Marketplace) | DeepL for translation (LLM for writing) | Active, Statamic 6.5+ |
| 7 | Kreatif Translator | Kreatif (kreatifIT) | GitHub only | Layer on top of Magic Translator | New (2026-09-16) |
| 8 | Statamic Translator | Klickmanufaktur | Packagist, GitHub | DeepL, runtime HTML translation **without** multi-site | New, 0 installs |
| 9 | Translator | Michael Aerni | Legacy Marketplace listing (v2, $39, page 404), GitHub | Google Cloud Translation | Abandoned (last push 2023-01-06) |

Adjacent, not direct competitors:

| Addon | What it is | Source |
|---|---|---|
| Translation Manager (Thought Collective) | Language-file (UI string) editor | https://statamic.com/addons/thoughtco/translation-manager |
| Translation Manager (Happy Pixels) | Legacy XLIFF export/import of content | Marketplace API record; page 404 |
| Localize (novu / `teamnovu/localize`) | i18n file editor | https://statamic.com/addons/novu/localize |
| `agency-orgo/string-translations`, `bit-mx/statamic-translate-lang-files`, `kreatifIT/translum` | String / language-file managers | https://packagist.org/packages/agency-orgo/string-translations , https://github.com/kreatifIT/translum |
| Alt AI (Alt Design) | In-Bard OpenAI assistant that lists "Translation capabilities for multiple languages" (inline text transform, not localization); requires Statamic 4/5 | https://statamic.com/addons/alt-design/alt-ai , https://packagist.org/packages/alt-design/alt-ai |
| MCP Server for Statamic (Cbox), Statamic MCP (Daniel) | MCP servers exposing entry `localize` operations to external AI agents: an indirect substitute (an agent can translate and write localizations) | https://github.com/cboxdk/statamic-mcp , https://github.com/danielgnh/statamic-mcp |
| Multisite Any Field (daun), Multisite for assets (WADM), Locale Lander (Reach) | Multi-site helpers, no translation | https://statamic.com/addons/daun/multisite-field , https://statamic.com/addons/wadm/multisite-for-assets , https://statamic.com/addons/reach/locale-lander |

Checked and found to have **no** translation feature (Marketplace descriptions grepped for "translat"): Scout (mlaroy, $39), Statamic AI Assistant (Stopa Development, $15), Magic Actions (el-schneider), AI Writer for Bard, 1st ChatGPT. No Statamic integrations for Lokalise, Crowdin, Weglot, Phrase or XLIFF were found on GitHub (repository searches returned 0 results).

---

## 3. Task 2 - Profiles

### 3.1 Magic Translator (primary competitor)

| Item | Finding |
|---|---|
| Vendor | Jonas List ("el-schneider"), solo developer who also ships Auto Alt Text (10.5k installs) and Magic Actions |
| URLs | Repo https://github.com/el-schneider/statamic-magic-translator ; Marketplace https://statamic.com/addons/el-schneider/statamic-magic-translator ; Packagist https://packagist.org/packages/el-schneider/statamic-magic-translator |
| Price / licence | Free, MIT [docs: LICENSE, Marketplace "No purchase required"] |
| Statamic versions | `statamic/cms` `^5.0` or `^6.0`; separate v5 and v6 front-end bundles; CI tests both since v0.2.1 [source: composer.json, ServiceProvider.php; docs: CHANGELOG] |
| Releases | v0.1.0 2026-04-05 ... v0.3.2 2026-08-27 (10 releases). Only dependency bumps since. https://github.com/el-schneider/statamic-magic-translator/releases |
| Traction | 4 stars, 4 forks, 1,175 installs total / 419 per month (Packagist, 2026-10-01) |
| Backends | `prism-php/prism` `^0.99` or `^0.100` (any Prism provider) with structured output, or `deeplcom/deepl-php`. Custom backend via a `TranslationService` contract. Default model `gpt-5-mini`. [source: composer.json, config/magic-translator.php, src/Services/PrismTranslationService.php] |
| CP integration | Auto-injected hidden fieldtype (`ensureField` into the `sidebar` tab on `EntryBlueprintFound`) that renders a **Translate** button and dialog; per-locale progress with inline retry; a bulk **Translate** action on collection listings; status badges DOM-injected into Statamic's native Sites panel. [source: src/ServiceProvider.php; docs: README, PR #2] |
| CLI | `php please statamic:magic-translator:translate` with `--to`, `--from`, `--collection`, `--entry`, `--blueprint`, `--include-stale`, `--overwrite`, `--generate-slug`, `--dispatch-jobs`, `--dry-run`, `-n`; exit codes 0/1/2. **Requires at least one filter**, so there is no "whole site" switch. [docs: README] |
| Content types | **Entries only.** Only `TranslateEntryAction` is registered and blueprint injection listens only to `EntryBlueprintFound`. No terms, globals, navs or assets. [source: src/ServiceProvider.php, src/Actions/TranslateEntry.php] |
| Nested content | Text, Textarea, Markdown, Link (`text` only), Replicator, Grid, Table, Bard (sets extracted recursively; ProseMirror never round-tripped through HTML; custom marks preserved via placeholders). Everything else is skipped, **including `group`, `list`, `array`, `taggable`, `slug` and select labels**. Third-party fieldtypes can be opted in only as `plain` or `markdown`. Top-level fields must be `localizable: true`. [source: src/Extraction/FieldClassifier.php; docs: README] |
| Context / glossary / prompts | Prompts are Blade views published to `resources/views/vendor/...`, with per-language overrides in config. Prompt variables are only locale codes/names and two format flags. **No entry, collection or site context, no LLM glossary, no CP settings UI.** Glossary exists only for DeepL (glossary ID per language, since v0.2.0). [docs: README; source: resources/views/prompts/system.blade.php] |
| Review / multi-model | None. Dialog options are source locale, generate slugs, overwrite existing. [docs: README] |
| Origin-change tracking | Yes, entry-level. Stores `magic_translator.last_translated_at` and `source_content_hash` (sha256 over the origin's translatable units) **inside the localization's own data**. Sidebar badges: time-ago, "outdated", or "-" (never machine translated). "Mark current" button to dismiss staleness without re-translating. A manual edit to a localization's translatable content also refreshes the stored hash. CLI `--include-stale`. [source: src/Support/ContentFingerprint.php, src/Listeners/RefreshLocaleHashOnSave.php, src/Fieldtypes/MagicTranslatorFieldtype.php; docs: PR #2 https://github.com/el-schneider/statamic-magic-translator/pull/2] |
| Queue / bulk | CP translations always run as queued jobs (3 tries, backoff 30/60/120 s); job status is kept in the cache for 60 minutes and polled by the UI. `sync` queue driver is not supported for CP use. [source: src/Jobs/TranslateEntryJob.php, src/Http/Controllers/TranslationController.php; docs: README "Requirements"] |
| Permissions | No dedicated permission; requires `edit {collection} entries` plus access to the target sites. [source: src/Support/AccessibleSites.php] |

Limitations and complaints:

- **Whole-entry overwrite.** Re-translation rebuilds the target from a deep clone of the source data and sets it as the localization's data, so manual edits and deliberately different values in the target are replaced. There is no field-level delta and no diff view. [source: https://github.com/el-schneider/statamic-magic-translator/blob/main/src/Actions/TranslateEntry.php , https://github.com/el-schneider/statamic-magic-translator/blob/main/src/Reassembly/ContentReassembler.php]
- **Raw machine translation can go live immediately.** `TranslateEntry` saves the localization directly and never sets the published state; Statamic's `makeLocalization()` copies the origin's published flag. No draft, working-copy or revision handling in the action. [source: TranslateEntry.php; https://github.com/statamic/cms/blob/v6.34.1/src/Entries/Entry.php#L910-L924]. Behaviour on collections with revisions enabled: could not verify (not tested).
- **Install failures**: "Vite manifest not found" after `composer require`, reported in two issues by six users between April and June 2026 before being fixed (https://github.com/el-schneider/statamic-magic-translator/issues/4 , https://github.com/el-schneider/statamic-magic-translator/issues/15).
- **Queue/cache coupling**: a Statamic 5 user got a hanging spinner and silent non-translation until they set `CACHE_STORE=redis`; the same thread reported `Call to undefined function data_has()` on Laravel 11 (https://github.com/el-schneider/statamic-magic-translator/issues/38).
- **All units of an entry go in one LLM request by default** (`max_units_per_request` is null), with a 120 s request timeout; a fork adds a configurable job timeout, list-item translation and "structure-preserving" changes, which signals unmet needs (https://github.com/el-schneider/statamic-magic-translator/blob/main/config/magic-translator.php , https://github.com/christianhanne/statamic-magic-translator/tree/codex/configurable-job-timeout).
- **Bard paragraphs are mapped back by position** (translated text split on blank lines), which depends on the model returning the same number of blocks. [source: ContentReassembler.php]
- **DeepL edge cases fixed over time**: regional target codes, XML escaping of `&` and `<`, control characters, arrays flattened to the string "Array", fieldset imports inside sets, Eloquent-driver integer IDs (CHANGELOG v0.1.1 to v0.3.0).
- **Metadata lives in content.** The `magic_translator` key is written into each localization and needs an `EntrySaving` listener to survive CP saves. [source: src/ServiceProvider.php]
- **Staleness is only visible per entry** (sidebar) or via CLI; there is no listing column, dashboard or report. [docs: README]

### 3.2 One-click Content Translation with DeepL or Google (incumbent)

| Item | Finding |
|---|---|
| Vendor | Apps with love AG (Statamic Certified Partner) |
| URLs | Repo https://github.com/appswithlove/statamic-one-click-content-translation ; Marketplace https://statamic.com/addons/awl/deepl ; Packagist https://packagist.org/packages/appswithlove/statamic-one-click-content-translation |
| Price / licence | $39 one-time per site; free in development. No licence file (GitHub reports none). |
| Statamic versions | Addon 6.x for Statamic 6 (`^6.0`); addon 5.x for Statamic 3-5 [docs: README] |
| Releases | 6.0.0 on 2026-03-20 (v6 support), latest 6.0.7 on 2026-09-28 |
| Traction | 66,542 installs total, 2,774 per month; 5 stars; 20 open issues/PRs |
| Backends | DeepL or Google Cloud Translation, direct SDKs. **No LLM.** A request for ChatGPT support has been open since 2024-05-31; the maintainer asked "Why do you think ChatGPT will be better?" (https://github.com/appswithlove/statamic-one-click-content-translation/issues/45) |
| CP integration | A translate button **per field** in the publish form of non-default-site entries; the browser posts the default-site text to `/cp/one-click-content-translation` and writes the result into the open form; the editor still has to save. Always translates from the default site. No bulk action, no CLI, no queue. [source: routes/cp.php, src/Http/Controllers/TranslateMeController.php; docs: README] |
| Content types | Entries, global variables and asset containers: its hidden fieldtype is auto-injected on `EntryBlueprintFound`, `GlobalVariablesBlueprintFound` and `AssetContainerBlueprintFound` (globals support re-added in 6.0.6, asset buttons in 6.0.4/6.0.5) [source: src/ServiceProvider.php, src/BlueprintListener.php]. Taxonomy terms: "the plugin wasn't planned for taxonomies / terms. But you can use it if you manually add [the] field to your taxonomy blueprint" (maintainer, 2023, https://github.com/appswithlove/statamic-one-click-content-translation/issues/8); whether that still works on the 6.x line: could not verify. Navs: no. |
| Nested content | Bard supported; text/textarea inside Grid table mode has no button (https://github.com/appswithlove/statamic-one-click-content-translation/issues/57); Bard tables and Table fieldtype only partly translated (issue #59); buttons work only on the first blueprint tab (issue #80, open, fix PR #79 unmerged); fields outside the main section not detected (issue #55). |
| Glossary / context | DeepL glossary ID per Statamic site (PR #47). `formality` config reported as ignored (issue #83, open). DeepL style rules requested (issue #82, open). |
| Review, staleness | None. [source: controller holds no state] |
| Other complaints | "Translate all button would be awesome... or a job [to] translate an entire site" (issue #71, open since 2025-01); translate whole entry automatically on save (issue #44, declined for the time being); Vue 2 bundle crashed the Statamic 6 CP and wrong DeepL locale codes after the v6 port (issue #84); buttons randomly disappearing (issues #65, #77); request flood after save (issue #91); globals not working (issue #90). |

### 3.3 AI Translator (Option5)

| Item | Finding |
|---|---|
| URLs | Repo https://github.com/option5studio/ai-translator ; Marketplace https://statamic.com/addons/option5/ai-translator ; Packagist https://packagist.org/packages/option5studio/ai-translator |
| Price / licence | $49 one-time per site; "paid software with an open-source codebase"; no licence file |
| Statamic versions | `^5.0` or `^6.0`; v6 support shipped in v2.0.0 on 2026-02-09 |
| Releases / traction | Latest v2.0.7 on 2026-08-11; 1,374 installs total, 120 per month; 0 stars |
| Backends | **DeepL only**, called over HTTP one string at a time; listing says Google and OpenAI are "coming soon" [source: src/Jobs/TranslateContent.php, config/ai-translation.php] |
| CP integration | A "Translate" Statamic action on entries and terms (single and bulk) with **one target language per run**, plus a CP settings page for the API key. The action dialog warns "The page will refresh after translating, unsaved changes will be lost." No CLI. [source: src/Actions/SelectEntriesToTranslate.php] |
| Content types | Entries and taxonomy terms [source] |
| Nested content | array, bard, grid, list, markdown, redactor, replicator, table, tags, text, textarea. Bard is rendered to HTML with Statamic's Augmentor and parsed back. [source] |
| Glossary / review / staleness | None in the translation job (no glossary or formality parameter found). No review, no staleness tracking. |
| Complaints | Pages with Bard could not be translated at all until two fixes (TypeErrors from the HTML round trip) (https://github.com/option5studio/ai-translator/issues/8); content silently not translated, DeepL free endpoint not supported (issue #2, 18 comments); entry page does not show the new localization until manual refresh (issue #7, open); slug not translated (issue #10, open); DeepL glossary not applied (issue #11, open); Marketplace page out of date (issue #9). |

### 3.4 Statamic AI Translations (infoFactory)

| Item | Finding |
|---|---|
| URLs | Repo https://github.com/infofactory/statamic-ai-translations ; Packagist https://packagist.org/packages/infofactory/statamic-ai-translations |
| Price / licence | README: "paid software with a source-available codebase... buy a license from the Statamic Marketplace"; composer licence `proprietary`. **Not present in the Marketplace API listing and the guessed listing URL returns 404**, so price: could not verify. |
| Statamic versions | **`^5.0` only** (both branches); no Statamic 6 support |
| Releases / traction | 5.0.0 on 2026-04-30, latest 5.1.0 on 2026-07-23; 45 installs total |
| Backends | LLM via `prism-php/prism ^0.98`; provider and model chosen in a CP config page |
| CP integration | Statamic action on entries; inline (synchronous) by default, optional queued job with progress polling (`STATAMIC_AI_TRANSLATIONS_USE_QUEUE`) |
| Nested content | text, code, markdown, textarea, slug, array, list, table, taggable, bard, grid, group, replicator; **one LLM call per field/segment**; only fields that are not yet set in the target are translated [source: src/Actions/Translate.php] |
| Context / glossary | A single free-text `instructions` setting ("custom style and guidelines") [source: resources/blueprint-templates/config.yaml] |
| Review, staleness, CLI | None |
| Notable config lessons | Raises output-token cap ("Prism defaults to 2048, which is too low for long HTML (Bard) fields"), 600 s request timeout, reasoning-effort setting for reasoning models, per entry+locale lock [source: config/statamic-ai-translations.php] |

### 3.5 Statamic Entry Translator (JustBetter)

| Item | Finding |
|---|---|
| URLs | Repo https://github.com/justbetter/statamic-entry-translator ; Packagist https://packagist.org/packages/justbetter/statamic-entry-translator (not on the Marketplace) |
| Price / licence | Free, MIT (GitHub licence) |
| Statamic versions | `statamic/cms ^6.3`, PHP ^8.4, `laravel/framework ^13.0` |
| Releases / traction | 0.0.1 on 2026-01-28, latest 0.3.0 on 2026-06-18; 1,945 installs total, 212 per month |
| Backends | DeepL; custom translators via `BaseTranslator` |
| CP integration | "Translate Content" action on entries (bulk) and global sets; queued jobs; facades for programmatic use. No CLI. |
| Content types | Entries and global sets |
| Nested content | Flattens the data with `Arr::dot` and translates every leaf whose key matches a localizable field handle; exclusions by handle or fieldtype. Target data is rebuilt from the origin plus translations and saved with `saveQuietly()`. [source: src/Translators/DeeplTranslator.php, src/Actions/TranslateEntry.php] |
| Glossary, review, staleness | None |

### 3.6 Statamic AI Assistant (Bold Web)

| Item | Finding |
|---|---|
| URLs | Repo https://github.com/boldwebdev/statamic-ai-assistant ; Packagist https://packagist.org/packages/bold-web/statamic-ai-assistant (not on the Marketplace) |
| Price / licence | No licence declared; price: could not verify (installable from Packagist) |
| Statamic versions | `^6.5` on the 3.x line; 1.x for Statamic 5 |
| Releases / traction | v3.0 on 2026-07-06, latest v3.1.0 on 2026-07-14; 514 installs total |
| Backends | **DeepL** for all translation features; Infomaniak or Groq LLMs for text generation |
| CP integration | "Translate (DeepL)" entry action with target-language picker, overwrite toggle and a **preflight/conflict check**; a bulk-translation CP page (super admins only); Bard translation button; a **glossary and style-rules CP page** synced to DeepL; batch progress endpoint; **DeepL usage** endpoint; **navigation sync** endpoint. [source: src/Actions/TranslateEntry.php, routes/cp.php; docs: CHANGELOG 3.0.0] |
| Distinctive feature | **Linked-entries depth**: recursively creates or updates localizations of entries referenced by entries/link fields and Bard links so relationship IDs point to the right language (default depth 1, max 5). [docs: README] |
| Review, staleness | None found; published origin leads to published target [source: src/Services/EntryTranslator.php] |
| Caveat | "this package is maintained as a side project" [docs: README] |

### 3.7 Kreatif Translator (context + review layer on Magic Translator)

| Item | Finding |
|---|---|
| URLs | Repo https://github.com/kreatifIT/statamic-translator (composer name `kreatif/statamic-translator`; **not on Packagist**, not on the Marketplace) |
| Price / licence | Free, MIT |
| Statamic versions | `^6.0`, PHP ^8.3; requires `el-schneider/statamic-magic-translator ^0.3.2` |
| Release | v1.0.0 on 2026-09-16 ("previously the code was copied into each project") |
| What it adds | **Addon settings in the CP** (Tools > Addons): company description, tone of voice, target audience, formality per language (formal / informal / neutral), glossary grid with one column per target site plus notes, "never translate" list, style rules, example translations. **Automatic prompt context**: site name from a global set, collection and blueprint title, entry title, parent page title, up to 12 already-translated sibling titles (source to target) for terminology consistency, an 800-character excerpt of the existing translation, and whether this is a first translation or an update. **Human review workflow**: new localizations are saved as drafts with `needs_review: true`, `machine_translated_at`, `machine_translated_from`; existing live pages stay online and only get the flag; the flag clears on publish. "Approve translation" bulk action; "Translations to review" dashboard widget; `kreatif:translator:audit` command that finds fields that should not be translated (URLs, phone numbers, codes). [docs: README; source: src/Context/ContextBuilder.php, src/Settings/SettingsBlueprint.php, config/statamic-translator.php, resources/views/prompts/system.blade.php] |
| Why it matters | It is an agency building, on its own, almost the exact context/glossary/review layer planned for the new addon, entirely through Magic Translator's prompt views and events. That validates demand and shows Magic Translator could absorb these features quickly. Review here is human-only; there is no LLM reviewer. |

### 3.8 Statamic Translator (Klickmanufaktur)

Repo https://github.com/klickmanufaktur/statamic-translator ; Packagist https://packagist.org/packages/klickmanufaktur/statamic-translator (0 installs, v1.0.4 on 2026-05-29, proprietary licence, `statamic/cms ^6.0`). A different architecture: "Provider-independent automatic translations for Statamic **without Multisite**". It translates rendered HTML at runtime through middleware, stores segments and translated URLs in database tables, rewrites internal links, and ships Antlers tags for language switchers, hreflang and a legal "automatically translated" notice. DeepL only. Worth noting for ideas: `translator:estimate` cost estimation, `translator:warm --max-chars` prewarming with a character budget, protected terms swapped for placeholders before the provider call, regex-based exclusion of addresses, slug overrides. Not a competitor for multi-site localization, but it occupies the names "Statamic Translator", `config/statamic-translator.php`, the `translator:` artisan namespace and the `{{ translator:* }}` tag. [docs: README]

### 3.9 Translator (Michael Aerni) - legacy

Repo https://github.com/aerni/statamic-translator (Statamic `^3.0`, Google Cloud Translation, last push 2023-01-06, 25 open issues/PRs). Marketplace record "Translator (Statamic v2)" at $39 exists in the API but its page returns 404 (https://statamic.com/addons/aerni/translator-v1). Packagist `aerni/translator` has 8 installs and no stable release. Effectively abandoned.

### 3.10 Feature matrix

| Capability | Magic Translator | One-click | AI Translator (Option5) | infoFactory | JustBetter | Bold AI Assistant | Kreatif layer |
|---|---|---|---|---|---|---|---|
| Price | Free (MIT) | $39 | $49 | could not verify | Free (MIT) | could not verify | Free (MIT) |
| Statamic 6 | Yes (and 5) | Yes (6.x line) | Yes (and 5) | **No** (5 only) | Yes (6.3+) | Yes (6.5+) | Yes |
| LLM translation | Yes (Prism) | No | No | Yes (Prism) | No | No | via Magic Translator |
| DeepL | Yes | Yes (+Google) | Yes | No | Yes | Yes | via Magic Translator |
| Laravel AI SDK | No | No | No | No | No | No | No |
| Whole-entry translate | Yes | No (per field) | Yes | Yes | Yes | Yes | Yes |
| Bulk action | Yes | No | Yes | Action | Yes | Yes + bulk page | + approve action |
| CLI | Yes (filters required) | No | No | No | No | No | audit command only |
| Queue | Required in CP | No | Yes | Optional | Yes | Yes | inherited |
| Entries | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Terms | No | Manual field only (v6: could not verify) | Yes | No | No | No | No |
| Globals | No | Yes (per-field buttons) | No | No | Yes | No | No |
| Navs | No | No | No | No | No | Nav sync | No |
| Assets | No | Yes (per-field buttons) | No | No | No | No | No |
| CP-editable context / instructions | No (Blade files) | No | No | One textarea | No | DeepL style rules | **Yes** |
| Glossary for LLMs | No | n/a | n/a | No | n/a | n/a | **Yes** (prompt) |
| DeepL glossary | Yes | Yes (per site) | Reported broken | n/a | No | Yes (CP page) | inherited |
| Human review / draft | No | Editor saves manually | No | No | No | No | **Yes** (flag + draft) |
| LLM / multi-model review | No | No | No | No | No | No | No |
| Origin-change tracking | Yes (entry-level hash) | No | No | No | No | No | inherited |
| Field-level delta retranslation | No | n/a | No | Only never-translated fields | No | No | No |
| Cost estimate / budget | No | No | No | No | No | DeepL usage display | No |

---

## 4. Task 3 - Statamic core status

### 4.1 Latest stable

- **`statamic/cms` v6.34.1, released 2026-09-23T14:56:29Z** (Packagist), matching the GitHub release published 2026-09-23T14:56:33Z. v6.34.0 was tagged 2026-09-23T00:54:17Z (the changelog heading says 2026-09-22, a timezone difference). Latest 5.x is v5.74.4 (2026-08-12). Sources: https://repo.packagist.org/p2/statamic/cms.json , https://github.com/statamic/cms/releases/tag/v6.34.1 , https://github.com/statamic/cms/blob/6.x/CHANGELOG.md
- There are unreleased commits on `6.x` dated up to 2026-10-01 (video embed and Stache changes); no newer tag exists yet: https://github.com/statamic/cms/commits/6.x

### 4.2 Built-in AI or translation features

- **Changelog**: no AI or translation feature anywhere in 6.0.0 to 6.34.1. The only AI-adjacent entries in the whole 6.x changelog are "Laravel Boost Guidelines" (#13587, 6.0.0-beta.4) and "Improve Storybook agent readiness" (#15448, 6.34.0). Entries named "... translations" are CP language files. https://github.com/statamic/cms/blob/6.x/CHANGELOG.md
- **Roadmap** (https://statamic.com/roadmap): In progress: "Multi-Site Improvements" ("from grouping and a better UI for longer site lists to translatable assets, and more"). Planned: "Workflows" ("manage approvals and standardized editorial flows with notifications and dashboards"), "Localizable Asset Meta Data", "Better Dashboard & Widgets", "Collaboration 3.0". Shipped: "AI optimized documentation" (August 2026). Nothing about AI in the control panel, machine translation or a first-party AI addon.
- **Blog** (https://statamic.com/blog): "Making the Statamic Docs Agent-Ready" (2026-08-21, https://statamic.com/blog/ai-ready-docs) covers markdown docs and llms.txt only. No post announces AI content or translation features.
- **GitHub**: statamic/ideas #1366 "Statamic's Vision and Roadmap Regarding AI" (open since 2025-09-09) has a single comment, from GitHub user `andjsch`, preferring "such features as an addon and not in the core"; there is no statement from Statamic in the thread (https://github.com/statamic/ideas/issues/1366). No AI or translation repository exists in the statamic organisation (https://github.com/orgs/statamic/repositories). Searches of statamic/cms issues and PRs for AI, MCP and "machine translation" in titles returned nothing for 2026.
- **Marketplace policy changes that affect a new commercial addon**: formal submission review with 11 rules, including Rule 01 "Offer substantial original value... must contribute a distinct design, capability, or workflow" and Rule 11 "Respect Statamic's Core and Pro boundaries" (https://statamic.com/marketplace/submission-guidelines , https://statamic.com/blog/stewarding-a-better-marketplace , 2026-09-14). Opt-in automatic annual renewals for Pro and eligible Marketplace products (https://statamic.com/blog/automatic-renewals , 2026-09-17). Creators keep 75% of sales (https://statamic.com/creator/begin).

Implication: the two roadmap items "Workflows" and "Multi-Site Improvements / translatable assets" are the core changes most likely to overlap with a review workflow and asset translation. Timing: could not verify (no dates given).

### 4.3 Changelog entries since 6.19.0 touching multisite, localization, the publish form, field actions or addon settings

Source for all rows: https://github.com/statamic/cms/blob/6.x/CHANGELOG.md (dates are changelog headings).

| Version (date) | Entry | Link |
|---|---|---|
| 6.19.0 (2026-05-13) | Fix `nav:breadcrumbs` for multi-site | https://github.com/statamic/cms/issues/13789 |
| 6.20.0 (2026-05-22) | Don't show unsaved changes warning when switching tabs | https://github.com/statamic/cms/issues/14678 |
| 6.20.1 (2026-06-02) | Fix entry revision localizations to filter unauthorized sites | https://github.com/statamic/cms/issues/14714 |
| 6.20.1 | Fix `Cached Pages` count for multi-site setups; scope shared static cache errors to sites | https://github.com/statamic/cms/issues/14726 , https://github.com/statamic/cms/issues/14763 |
| 6.22.0 (2026-06-19) | Retain current tab hash in URL when switching localization | https://github.com/statamic/cms/issues/14846 |
| 6.22.0 | Avoid loading the revisions store when finding a working copy | https://github.com/statamic/cms/issues/14847 |
| 6.22.0 | Don't show selection checkboxes when bulk actions are disabled | https://github.com/statamic/cms/issues/14850 |
| 6.22.0 | Fix nested Bard data loss when editing a set | https://github.com/statamic/cms/issues/14808 |
| 6.24.0 (2026-07-01) | Define emits in `PublishActions` component | https://github.com/statamic/cms/issues/14872 |
| 6.24.2 (2026-07-08) | Fix global variable references not resolving through the data repository | https://github.com/statamic/cms/issues/14938 |
| 6.25.0 (2026-07-17) | Hide permissions covered by a broader permission; preserve unregistered permissions when saving a role | https://github.com/statamic/cms/issues/14970 , https://github.com/statamic/cms/issues/14975 |
| 6.25.0 | Fix global variables being lost when migrated alongside addon update scripts | https://github.com/statamic/cms/issues/14963 |
| 6.25.0 | Fix filter badge showing the term title in the wrong site | https://github.com/statamic/cms/issues/14973 |
| 6.25.0 | Fix nested Bard data loss when reordering sets; fix control panel text direction on RTL sites | https://github.com/statamic/cms/issues/15013 , https://github.com/statamic/cms/issues/15023 |
| 6.26.0 (2026-07-21) | Fix origin() on null when fetching nav tree with origin_id field | https://github.com/statamic/cms/issues/15042 |
| 6.26.0 | Add support for custom link types | https://github.com/statamic/cms/issues/15004 |
| 6.27.0 (2026-08-05) | **Ensure fields are added to the first section of a tab instead of the last** (changes where `ensureField()` injections land) | https://github.com/statamic/cms/pull/15091 |
| 6.27.0 | Export Inertia's `usePage` composable via `@statamic/cms/inertia` | https://github.com/statamic/cms/issues/15123 |
| 6.27.0 | Move testing traits into `Statamic\Testing\Concerns`; fall back to default site on password-protected sites | https://github.com/statamic/cms/issues/15115 , https://github.com/statamic/cms/issues/15086 |
| 6.27.1 (2026-08-07) | **Reset memoized Stache state between jobs instead of disabling it process-wide** (queue-worker behaviour) | https://github.com/statamic/cms/pull/15126 |
| 6.27.1 | Fall back to the default site when `sites.yaml` is empty; fix hidden fieldtypes ignoring their field conditions | https://github.com/statamic/cms/issues/15135 , https://github.com/statamic/cms/issues/15132 |
| 6.27.2 (2026-08-12) | **Fix non-localizable button group fields being editable in localizations** | https://github.com/statamic/cms/issues/15151 |
| 6.27.2 | Update `extraValues` state in `PublishContainer` when prop is updated | https://github.com/statamic/cms/issues/15159 |
| 6.27.2 | Optimize site loading with hundreds of sites; resolve `@statamic/cms` types under legacy module resolution | https://github.com/statamic/cms/issues/14988 , https://github.com/statamic/cms/issues/15160 |
| 6.28.0 (2026-08-19) | **Multisite improvements - entry vs global** (entry localization panel relabelled "Current Localization" to "Working In", new icon, "Site" added to the global selector) | https://github.com/statamic/cms/pull/15191 |
| 6.28.0 | Multisite - turn stacked sites into a table view for larger viewports (`/cp/sites`) | https://github.com/statamic/cms/pull/15202 |
| 6.28.0 | Fix save button hanging forever when a save hook fails | https://github.com/statamic/cms/issues/15194 |
| 6.28.0 | Fix ensuring config on fields from imported fieldsets; give nested sets and rows new ids when duplicating | https://github.com/statamic/cms/issues/15187 , https://github.com/statamic/cms/issues/15190 |
| 6.28.0 | Allow Inertia v3 alongside v2 | https://github.com/statamic/cms/issues/15065 |
| 6.29.0 (2026-08-25) | Reduce publish form and Live Preview overhead; Bard server-side performance improvements | https://github.com/statamic/cms/issues/15251 , https://github.com/statamic/cms/issues/15258 |
| 6.29.0 | Fix localized dates leaking into later template usages; fix nav entry links across sites losing their domain | https://github.com/statamic/cms/issues/15224 , https://github.com/statamic/cms/issues/15239 |
| 6.30.0 (2026-08-28) | **Allow addon settings blueprints to be registered lazily** (`registerSettingsBlueprint` accepts a closure) | https://github.com/statamic/cms/pull/15286 |
| 6.30.0 | **Multisite improve synced icons** (new synced/unsynced icons) | https://github.com/statamic/cms/pull/15275 |
| 6.30.0 | Multisite - fix localization switch sending the browser to /entries/null | https://github.com/statamic/cms/issues/15230 |
| 6.30.0 | Multisite - fix unsynced fields after switching sites | https://github.com/statamic/cms/issues/15267 |
| 6.30.0 | Fix item actions on the term edit page | https://github.com/statamic/cms/issues/15290 |
| 6.31.0 (2026-09-01) | REST API: ping and sites endpoints; site query param | https://github.com/statamic/cms/issues/15317 , https://github.com/statamic/cms/issues/15320 |
| 6.31.0 | Add extra sections with fields to existing fieldtypes (`appendConfigFields` sections now render) | https://github.com/statamic/cms/pull/13796 |
| 6.31.0 | Added saveQuietly, deleteQuietly to CollectionTree | https://github.com/statamic/cms/issues/14879 |
| 6.31.0 | Fix global set sites array being ordered by file modification time | https://github.com/statamic/cms/issues/15298 |
| 6.33.0 (2026-09-16) | Add Info fieldtype | https://github.com/statamic/cms/issues/15368 |
| 6.33.0 | Give reference updater fields the item being updated as their parent | https://github.com/statamic/cms/issues/15443 |
| 6.33.0 | Fix default values and fieldtype meta after saving entries and terms; fix Bard sets showing the wrong values after saving | https://github.com/statamic/cms/issues/15427 , https://github.com/statamic/cms/issues/15445 |
| 6.33.0 | Show and keep a localized date in the control panel | https://github.com/statamic/cms/issues/15453 |
| 6.34.0 (2026-09-22) | **Asset container actions** | https://github.com/statamic/cms/issues/15461 |
| 6.34.0 | **Re-render publish tabs and sections when the blueprint changes** | https://github.com/statamic/cms/pull/15474 |
| 6.34.0 | **Fix addon settings not being found when the addon has a custom slug** | https://github.com/statamic/cms/pull/15495 |
| 6.34.0 | **Fix field alignment when field actions are visible** | https://github.com/statamic/cms/issues/15487 |
| 6.34.0 | Fix static cache invalidation rules for multisite with relative site URLs | https://github.com/statamic/cms/issues/15493 |
| 6.34.1 (2026-09-23) | No relevant entries | |

Versions 6.20.2, 6.20.3, 6.21.0, 6.23.0, 6.24.1 and 6.32.0 have no entries in these areas.

Design relevance: the entry localization panel markup and icons changed three times in 6.28 to 6.30, which is a direct risk for addons that DOM-inject into it, as Magic Translator does for its badges (https://github.com/el-schneider/statamic-magic-translator/pull/2).

---

## 5. Task 4 - UX patterns outside Statamic

### 5.1 "Translation outdated" states

- **Per-field source hash, re-translate only what changed** (Craft, `recranet/craft-ai-translator`): "every run stores a per-field hash of the source; later runs only re-translate fields whose source changed, and outdated translations are surfaced in the utility". A utility page shows "translation status per section/site (translated, outdated, missing)" with bulk translate forms. https://github.com/recranet/craft-ai-translator
- **Explicit editor intent** (Drupal core): "you may want to set the status of the other translations as out-of-date... by selecting the *Flag other translations as outdated* checkbox... The status will be visible on the Translations page." So typo fixes do not flood translators. https://www.drupal.org/docs/8/core/modules/content-translation/overview
- **Status icon vocabulary** (WPML): plus = not translated, pencil = translated, circular arrows = "translation needs update", gear = in progress, eye = awaiting review; plus a "Minor edit - don't update translation" option. Source: search snippets of wpml.org support threads (https://wpml.org/forums/topic/wpml-translation-status-meaning/ , https://wpml.org/forums/topic/disable-re-translation/); the pages returned 404 to my fetcher, so exact wording: could not verify on-page.
- **Content fingerprint instead of timestamps, plus "Mark current"** (Magic Translator): hashing only translatable units means toggling published, changing dates or non-localizable fields does not mark locales stale; a one-click escape hatch dismisses a false positive. https://github.com/el-schneider/statamic-magic-translator/pull/2
- Counter-example: Storyblok's and Strapi's docs describe no outdated indicator at all. https://www.storyblok.com/docs/manuals/translations , https://docs.strapi.io/cms/features/internationalization

### 5.2 Review workflows

- **Review queue with a "publish without review" switch** (WPML): translations go to a review queue unless the administrator enabled "Publish without review"; "every page that's ready for your review or edit appears" in a Translation Tasks tab; the editor shows "the source... on the left and the translation on the right, segment by segment"; the reviewer can "either publish the translation or edit it". https://wpml.org/documentation/automatic-translation/review-automatic-translations/ , https://wpml.org/changelog/2021/07/wpml-4-5-beta-translate-everything-workflow/ . A web-search summary also named two modes, "Wait for review" (publish only after review) and "Publish and mark for review" (publish now, review later); I could not confirm those exact labels on any page I could fetch.
- **Draft or direct save, chosen per action** (Craft AI Translator): "editors choose whether translations land as a draft (review first) or are saved directly; repeated draft runs reuse the same draft". Draft and direct-save translations are tracked separately. https://github.com/recranet/craft-ai-translator
- **Order-based workflow** (Acclaro Translations for Craft, $99 + $49/yr): submit, track, review, approve and publish; translations arrive as drafts, with a bulk "Apply Translations Drafts". https://plugins.craftcms.com/translations , https://github.com/AcclaroInc/craft-translations/blob/master/docs/user-guide.md
- **Machine review before human review** (localization platforms): Smartling's LQA Agent applies "Industry-standard MQM scoring, applied automatically" and can "Route strings based on error severity"; Lokalise can "auto-approve high-scoring strings while routing only low-scoring ones to human reviewers"; Phrase gives a "0-100 score per segment". This is the established shape for the planned multi-model review: score, then route. https://www.smartling.com/software/lqaagent , https://lokalise.com/blog/top-mtpe-tools/
- **Honest labelling**: Sanity warns "AI Assist should never be relied on for critical content without a human being reviewing the results". https://www.sanity.io/docs/studio/ai-assist-content-translation . Weglot labels each translation's quality as automatic, manual or professional and lets you filter by it. https://support.weglot.com/article/128-how-to-edit-my-translations

### 5.3 Glossaries, instructions and brand voice

- **Two rule types** (Weglot): "Never translate" and "Always translate", applied to all language pairs or to specific pairs, with a case-sensitivity toggle, CSV import/export (max 2,000 rules) and a 5-word limit per rule. Rules apply "only... to your automatic translations, not manually-edited ones". https://support.weglot.com/article/130-translations-exceptions
- **Brand profile as structured settings** (Weglot AI Language Model): learns from the glossary, manual translation history and custom instructions such as "Keep headlines short" or "Keep proper nouns and brand terms in English"; can be applied to single strings or in bulk "up to 10,000 translations at a time". https://www.weglot.com/blog/ai-language-model
- **Style guide with a size limit and dynamic source** (Sanity): a style guide of max 2,000 characters, or an async function so editors can maintain it in a document. https://www.sanity.io/docs/studio/ai-assist-content-translation
- **Glossary honesty per backend** (Craft AI Translator): "DeepL ignores the glossary and tone-of-voice settings (no prompt support)". https://github.com/recranet/craft-ai-translator
- **Glossary grid with one column per target site, per-language formality, example pairs, sibling titles as terminology memory** (Kreatif layer, section 3.7). https://github.com/kreatifIT/statamic-translator
- Contentful: AI Actions "Translate" template with variables for terms to exclude and brand voice, combined with Workflows. Source: search snippets of https://www.contentful.com/products/ai-actions/ and https://www.contentful.com/help/ai-automations/ai-actions/ai-actions-templates/ ; both returned HTTP 429, so details: could not verify on-page.

### 5.4 Bulk runs and entry points

- **Three entry points that agree with each other** (Craft AI Translator): bulk element action with target-site picker running as queue jobs, a sidebar button on the element, and small per-field buttons on non-primary sites. https://github.com/recranet/craft-ai-translator
- **Language menu as the trigger** (Storyblok): open the Languages menu, hover the target language, choose "AI Translate" for all text fields of a story, with an explicit "Overwrite fields that have already been translated" option; per-field "Translate using AI". https://www.storyblok.com/docs/manuals/translations
- **Whole-site background mode with priorities** (WPML Translate Everything): "Most visible content first... the homepage, menu pages, and pages your homepage links to before working outward". https://wpml.org/documentation/automatic-translation/translate-everything-automatically/
- **Preflight / conflict check before a bulk run** and a live provider-usage figure (Bold AI Assistant). https://github.com/boldwebdev/statamic-ai-assistant/blob/main/routes/cp.php
- **Dry-run plan, then execute** (Magic Translator CLI `--dry-run`; Klickmanufaktur `translator:warm` is a dry run unless `--execute`, with `--max-chars`, and `translator:estimate` for cost). https://github.com/el-schneider/statamic-magic-translator/blob/main/README.md , https://github.com/klickmanufaktur/statamic-translator
- **Queued jobs re-check permissions at execution time** (Craft AI Translator): "revoking access also stops jobs that are already queued". https://github.com/recranet/craft-ai-translator
- **Follow relationships** (Bold): translate linked entries to a configurable depth so references point at the right language. https://github.com/boldwebdev/statamic-ai-assistant

### 5.5 Protecting manual edits (the most common anti-pattern)

- Strapi AI: "manual modifications made to other locales will be overwritten" on every save of the default locale. https://docs.strapi.io/cms/features/internationalization
- Sanity: "The assistant will replace the current field values with the translated values". https://www.sanity.io/docs/studio/ai-assist-content-translation
- Better: Craft AI Translator's skip rules - fields never translated by the plugin are translated only "when the target is empty or still an untranslated copy of the source; a deviating target is assumed manually translated and skipped"; overwrite is an explicit mode. It also documents the remaining trap: re-translating a tracked field "replaces any manual edits made to the AI translation of that field". https://github.com/recranet/craft-ai-translator

### 5.6 Pricing reference points

- Statamic Marketplace: 170 of 621 addons are paid; median top price $20, 75th percentile $49, maximum $295 (computed from the Marketplace API). Translation addons: $39 and $49.
- Craft: Multi Translator $59 + $19/yr (https://plugins.craftcms.com/multi-translator); Acclaro Translations $99 + $49/yr (https://plugins.craftcms.com/translations).

---

## 6. Task 5 - Synthesis

### 6.1 Gaps in the Statamic market

1. **No paid LLM translator.** The only LLM options are free and pre-1.0 (Magic Translator) or Statamic 5 only (infoFactory). Paid options are DeepL/Google.
2. **Context and glossary for LLMs are developer-only or absent.** Magic Translator's prompts are Blade files with locale names as the only variables. No Marketplace addon gives editors a CP screen for brand context, per-site instructions, glossary and do-not-translate terms. The Kreatif layer proves the need but is not distributed.
3. **No machine review.** No Statamic addon scores, critiques or cross-checks a translation with a second model. Human review exists only in the Kreatif layer.
4. **Staleness is shallow.** Only Magic Translator tracks origin changes, at entry level, with whole-entry re-translation, no field-level delta, no diff, no protection for manual edits and no site-wide overview.
5. **Content-type coverage is fragmented.** Entries everywhere; terms only in Option5 (DeepL); globals in JustBetter (DeepL) and One-click (per field); nav trees only as "sync" in Bold; assets only as per-field buttons in One-click. Nobody covers all five, and nobody covers them with LLMs.
6. **No true whole-site command.** Magic Translator's CLI needs a filter and handles entries only. One-click users have asked for "a job [to] translate an entire site" since January 2025.
7. **No review-safe publishing.** New localizations inherit the origin's published state in Magic Translator, Bold and JustBetter.
8. **No cost visibility for LLM runs** (token estimates, budgets, per-run reports).
9. **Fieldtype gaps** in the leader: group, list, array, taggable, slug and structured third-party fieldtypes are skipped.
10. **No Laravel AI SDK integration** for translation in the Statamic ecosystem.

Caveat on differentiation: because the strongest competitor is free and MIT, the Marketplace's Rule 01 ("substantial original value") and plain buyer logic both require the new addon to be visibly more than "Magic Translator, but paid". Gaps 2 to 7 are where that case is strongest.

### 6.2 UX patterns worth copying

1. Hash-based staleness on **translatable content only**, stored **per field**, so re-runs touch only changed fields (Craft AI Translator; Magic Translator's fingerprint idea).
2. A "Mark as current" escape hatch and a "minor edit, don't flag translations" option at save time (Magic Translator, WPML, Drupal).
3. A small, fixed status vocabulary shown everywhere: missing, translated, outdated, needs review, manually edited (WPML icons, Weglot quality labels).
4. A site-wide status overview by collection and site with bulk actions (Craft AI Translator utility; Kreatif review widget).
5. Per-run choice of draft versus direct save, with a global default (Craft AI Translator; WPML's three review modes).
6. Machine quality scoring that routes only low-scoring items to humans (Smartling, Lokalise, Phrase) as the model for multi-model review.
7. Glossary with "never translate" and "always translate as", scoped per language pair, importable as CSV, plus structured brand fields (description, tone, audience, per-language formality, examples) rather than one prompt box (Weglot, Kreatif, Sanity).
8. Never overwrite a target that differs from the source unless it was produced by the addon or the user chose overwrite; say so in the dialog (Craft AI Translator, Storyblok's explicit overwrite option).
9. Dry-run plan and cost estimate before bulk and CLI runs; preflight conflict check in the CP (Magic Translator, Klickmanufaktur, Bold).
10. Per-locale progress with inline retry in the dialog (Magic Translator), and refresh the publish form when done (Option5 issue #7 is the complaint when this is missing).
11. Sibling titles and the existing translation as prompt context for terminology consistency (Kreatif).
12. Optional translation of linked entries so relationships resolve in the target site (Bold).
13. An audit command that lists fields that are localizable but should not be translated (Kreatif).

### 6.3 Pitfalls competitors hit

| Pitfall | Evidence |
|---|---|
| CP assets not published after install ("Vite manifest not found") | https://github.com/el-schneider/statamic-magic-translator/issues/4 , https://github.com/el-schneider/statamic-magic-translator/issues/15 |
| Hard dependency on a queue worker and shared cache; spinner hangs when misconfigured | https://github.com/el-schneider/statamic-magic-translator/issues/38 |
| Framework-version helpers missing on older Laravel (`data_has`) | same issue #38; fixed in v0.3.2 |
| Bard round-tripped through HTML breaks on sets and custom nodes | https://github.com/option5studio/ai-translator/issues/8 |
| Bard tables, Table fieldtype and Grid cells missed | https://github.com/appswithlove/statamic-one-click-content-translation/issues/59 , https://github.com/appswithlove/statamic-one-click-content-translation/issues/57 |
| Fieldset imports inside Replicator/Bard sets not resolved | Magic Translator CHANGELOG v0.1.4 (https://github.com/el-schneider/statamic-magic-translator/blob/main/CHANGELOG.md) |
| Arrays flattened to the literal string "Array" | Magic Translator CHANGELOG v0.3.0 |
| DeepL language codes: only EN and PT need a region; others must not have one | Magic Translator CHANGELOG v0.1.1; https://github.com/appswithlove/statamic-one-click-content-translation/issues/84 |
| DeepL free versus pro endpoint, header-based auth | https://github.com/option5studio/ai-translator/issues/2 , https://github.com/option5studio/ai-translator/issues/5 |
| DeepL XML payload broken by `&`, `<` and control characters | Magic Translator CHANGELOG v0.3.0 |
| Glossary or formality configured but silently not applied | https://github.com/option5studio/ai-translator/issues/11 , https://github.com/appswithlove/statamic-one-click-content-translation/issues/83 |
| Per-field buttons injected into the publish form break on multiple tabs, sidebar sections, Grid table mode and after CP upgrades (Vue 2 to 3) | One-click issues #80, #55, #57, #77, #65, #84 |
| Publish form not refreshed after translation | https://github.com/option5studio/ai-translator/issues/7 |
| Slug expectations unmet | https://github.com/option5studio/ai-translator/issues/10 , https://github.com/appswithlove/statamic-one-click-content-translation/issues/55 |
| Eloquent driver integer IDs | Magic Translator CHANGELOG v0.2.0 |
| Long Bard fields exceed default output-token limits and HTTP timeouts; reasoning models add latency | https://github.com/infofactory/statamic-ai-translations/blob/5.X/config/statamic-ai-translations.php |
| Machine translation published immediately | https://github.com/statamic/cms/blob/v6.34.1/src/Entries/Entry.php#L910-L924 ; Kreatif's `unpublish_new` workaround |
| Manual edits overwritten on re-translation | Magic Translator source (section 3.1); Strapi and Sanity docs (section 5.5) |
| Metadata stored inside entry content, needing save listeners to survive | https://github.com/el-schneider/statamic-magic-translator/blob/main/src/ServiceProvider.php |
| DOM injection into the native Sites panel while core keeps changing it | https://github.com/el-schneider/statamic-magic-translator/pull/2 ; core PRs #15191, #15275 |
| Marketplace listing out of date with the release | https://github.com/option5studio/ai-translator/issues/9 |

### 6.4 Naming collisions for "Autotranslator"

Clear:

- Marketplace: no addon named Autotranslator, Auto Translator or Auto Translate among 621 addons; API searches for `autotranslat` and `auto translat` return 0.
- Packagist: no `statamic-addon` package with that name; `statamic/autotranslator` returns 404; search "statamic auto translate" finds nothing relevant.
- GitHub: 0 repositories for `statamic-autotranslator`, `statamic-auto-translator`, `statamic-auto-translate`, `statamic-autotranslate`.

Same or near-same names elsewhere (confusion in search, not legal findings; trademark status: could not verify, not researched):

- `ibrahimamasha/autotranslator` - Laravel package for language JSON files, 2,259 installs (https://packagist.org/packages/ibrahimamasha/autotranslator)
- `stronger21012/autotranslator` (https://packagist.org/packages/stronger21012/autotranslator)
- Craft CMS "Auto Translator": `miranj/craft-auto-translator` (https://github.com/miranj/craft-auto-translator/) and `cstudios-slovakia/craft-auto-translator` (https://packagist.org/packages/cstudios-slovakia/craft-auto-translator)
- TYPO3 "Auto translator" (`hn/auto-translator`, https://extensions.typo3.org/extension/auto_translator) and `thieleundklose/autotranslate`
- WordPress "Auto Translator for Polylang" (https://wordpress.org/plugins/auto-translator-polylang/)
- `reliqarts/laravel-auto-translator`, `syriable/filament-auto-translator`, several `*/laravel-auto-translate`

Crowded neighbours inside Statamic (easy to confuse in the Marketplace search and in conversation): Magic Translator, AI Translator, Translator (Aerni), Statamic Translator (Klickmanufaktur), Kreatif Translator, Statamic Entry Translator, Statamic AI Translations, One-click Content Translation, Translation Manager (two listings). "Auto"-prefixed addons already listed: Auto Alt Text (same author as Magic Translator), Auto Open Graph Images, Automagic, Autograph, Autoreply.

Identifiers already taken that a new addon should avoid:

- Config file `config/statamic-translator.php` (used by both Klickmanufaktur and Kreatif)
- Artisan namespace `translator:` and Antlers tag `{{ translator:* }}` (Klickmanufaktur)
- Env prefix `TRANSLATION_*` (One-click: `TRANSLATION_SERVICE`, `TRANSLATION_DEEPL_AUTH_KEY`) and `MAGIC_TRANSLATOR_*`
- Entry data keys `magic_translator`, `needs_review`, `machine_translated_at`, `machine_translated_from`
- Fieldtype handles `magic_translator`, `one_click_content_translation_inputs`

Assessment: "Autotranslator" is available in the Statamic namespace but generic. It is hard to distinguish from "AI Translator" and "Magic Translator" in a listing, and it undersells the review, context and tracking features that are the actual differentiators.

---

## 7. Could not verify

- Install or sales numbers on Marketplace pages (not shown); Packagist installs are used instead and include CI and dev installs.
- Whether infoFactory's addon is purchasable on the Marketplace, and its price (not in the API listing; guessed URL 404).
- Price or licence terms for Bold's Statamic AI Assistant (no licence file; not on the Marketplace).
- Whether One-click Content Translation's manual-field workaround for taxonomy terms still works on its 6.x line.
- WPML's exact review-mode labels ("Wait for review", "Publish and mark for review"); only the "Publish without review" setting and the review queue were confirmed on-page.
- Magic Translator's behaviour on collections with revisions enabled (source shows a direct `save()`; not tested).
- Translation quality of any addon (no hands-on testing).
- WPML's exact wording for status icons and the "minor edit" option (forum pages not fetchable; search snippets only).
- Contentful AI Actions details (HTTP 429; search snippets only).
- Any private statement by the Statamic team about built-in AI translation; none was found publicly.
- Timing of the roadmap items "Workflows" and "Multi-Site Improvements".
- Trademark status of "Autotranslator" in any jurisdiction (not researched).

## 8. Source index

Statamic addons
- https://github.com/el-schneider/statamic-magic-translator (README, CHANGELOG, AGENTS.md, composer.json, config, src, issues #4 #15 #38, PR #2 #33)
- https://statamic.com/addons/el-schneider/statamic-magic-translator
- https://packagist.org/packages/el-schneider/statamic-magic-translator
- https://github.com/christianhanne/statamic-magic-translator (fork branches)
- https://github.com/appswithlove/statamic-one-click-content-translation (README, config, routes, controller, releases, issues #44 #45 #55 #57 #59 #65 #71 #77 #80 #82 #83 #84 #90 #91, PRs #47 #79 #93 #95)
- https://statamic.com/addons/awl/deepl
- https://packagist.org/packages/appswithlove/statamic-one-click-content-translation
- https://github.com/option5studio/ai-translator (README, config, action, job, issues #2 #5 #7 #8 #9 #10 #11)
- https://statamic.com/addons/option5/ai-translator
- https://packagist.org/packages/option5studio/ai-translator
- https://github.com/infofactory/statamic-ai-translations ; https://packagist.org/packages/infofactory/statamic-ai-translations
- https://github.com/justbetter/statamic-entry-translator ; https://packagist.org/packages/justbetter/statamic-entry-translator
- https://github.com/boldwebdev/statamic-ai-assistant ; https://packagist.org/packages/bold-web/statamic-ai-assistant
- https://github.com/kreatifIT/statamic-translator
- https://github.com/klickmanufaktur/statamic-translator ; https://packagist.org/packages/klickmanufaktur/statamic-translator
- https://github.com/aerni/statamic-translator ; https://packagist.org/packages/aerni/translator
- https://statamic.com/addons/thoughtco/translation-manager ; https://packagist.org/packages/ryanmitchell/statamic-translation-manager
- https://statamic.com/addons/alt-design/alt-ai ; https://statamic.com/addons/novu/localize
- https://github.com/cboxdk/statamic-mcp ; https://github.com/danielgnh/statamic-mcp
- https://statamic.com/addons/tags/ai ; https://statamic.com/api/v1/marketplace/addons
- https://packagist.org/packages/list.json?type=statamic-addon

Statamic core and marketplace
- https://repo.packagist.org/p2/statamic/cms.json
- https://github.com/statamic/cms/blob/6.x/CHANGELOG.md
- https://github.com/statamic/cms/releases/tag/v6.34.1
- https://github.com/statamic/cms/blob/v6.34.1/src/Entries/Entry.php#L910-L924
- https://statamic.com/roadmap ; https://statamic.com/blog
- https://statamic.com/blog/ai-ready-docs ; https://statamic.com/blog/stewarding-a-better-marketplace ; https://statamic.com/blog/automatic-renewals
- https://statamic.com/marketplace/submission-guidelines ; https://statamic.com/creator/begin
- https://github.com/statamic/ideas/issues/1366 ; https://github.com/statamic/ideas/issues/291
- https://repo.packagist.org/p2/laravel/ai.json ; https://packagist.org/packages/laravel/ai/dependents.json ; https://packagist.org/packages/prism-php/prism/dependents.json

Other CMS and localization tools
- https://github.com/recranet/craft-ai-translator
- https://plugins.craftcms.com/multi-translator ; https://plugins.craftcms.com/translations ; https://github.com/AcclaroInc/craft-translations/blob/master/docs/user-guide.md
- https://wpml.org/documentation/automatic-translation/ ; https://wpml.org/documentation/automatic-translation/translate-everything-automatically/ ; https://wpml.org/documentation/automatic-translation/review-automatic-translations/ ; https://wpml.org/changelog/2023/03/wpml-4-6-bulk-auto-translation-language-switcher-block-translation-mode-improvements/
- https://support.weglot.com/article/130-translations-exceptions ; https://support.weglot.com/article/128-how-to-edit-my-translations ; https://www.weglot.com/blog/ai-language-model
- https://www.storyblok.com/docs/manuals/translations ; https://www.storyblok.com/docs/concepts/internationalization
- https://docs.strapi.io/cms/features/internationalization
- https://www.sanity.io/docs/studio/ai-assist-content-translation
- https://www.drupal.org/docs/8/core/modules/content-translation/overview
- https://www.smartling.com/software/lqaagent ; https://lokalise.com/blog/top-mtpe-tools/
- https://www.contentful.com/products/ai-actions/ (not fetchable, HTTP 429)
