# CLAUDE.md

Guidance for agents working on this repository. Keep it under 120 lines, and update it whenever a phase changes how the code is organised.

## Project overview

Tolk (`reachweb/statamic-tolk`, namespace `Reach\StatamicTolk\`) is a commercial Statamic 6 addon that translates multi-site content from an origin site into its localizations with LLMs from Anthropic, OpenAI and Google Gemini, through the Laravel AI SDK (`laravel/ai`). It fills translations into the open entry editor unsaved, translates single fields, runs bulk translations from listings, a status overview and the CLI, and tracks per field whether a translation is outdated or was edited by hand, so it updates only what has changed.

Stack: PHP `^8.3`, Statamic `^6.30` (Pro, multi-site), Laravel `^12.62 || ^13.15`, `laravel/ai ^1.0.1`, Vue 3 and Inertia in the Control Panel (CP), Pest 4 on `Statamic\Testing\AddonTestCase`, Vitest, and later Playwright against the sandbox.

## `TASKS.md` is the source of truth

`TASKS.md` holds the decisions (D0–D29), the architecture reference (§A1–§A16), the cross-cutting rules and the phased task list. Where this file and `TASKS.md` disagree, `TASKS.md` wins.

- Work through the tasks in order; a task is finished only when its **Self-check** passes.
- When a task is finished: flip its checkbox, append one line to the Done log, edit any later task that your findings change, and commit.
- Do not re-litigate a row of the Decisions table without the owner. Stop and ask when a finding contradicts one, when a dependency has a new major version, or when a self-check cannot pass as written.
- The research behind the plan is in `docs/research/` (R1–R6). Do not edit those reports; record new findings in `docs/research/07-spike-findings.md`.

## Commands

From the addon repo, `/Volumes/1TB/Sites/autotranslator`:

- `vendor/bin/pest`: the default suite (Unit and Feature). Run it after every change to `src/`, `tests/` or `resources/js/`.
- `composer test:live`: the opt-in live tests against real providers (Pest group `live`). Keys come from the environment or a git-ignored `.env.live`; providers without a key are skipped. The provider probe writes its measurements to `build/live-probe/` (git-ignored).
- SDK contract tests live in `tests/Feature/Sdk/`; build provider HTTP fakes from `tests/Fixtures/providers/` through `tests/Support/ProviderFixtures.php`. If an SDK update breaks them, read `docs/research/07-spike-findings.md` "SDK" before changing code.
- `vendor/bin/pint`: format PHP before a task is complete (`vendor/bin/pint --test` only checks).
- `npm test`: Vitest for `resources/js/**/*.test.js`.
- `npm run cp:build`: build the CP assets into `resources/dist/build`, which is committed.
- `npm run cp:dev`: Vite dev server with hot reload; it writes `resources/dist/hot` while it runs.
- CI (`.github/workflows/tests.yml`) runs Pint and Pest on PHP 8.3–8.5 × Laravel 12 and 13 with `composer update`, and fails when a fresh `npm run cp:build` differs from the committed `resources/dist/build`. It runs no live or browser tests.

There is no booted host app in this repo: under `vendor/bin/testbench` a root package is not in Statamic's addon manifest, so the addon's commands, routes and CP pages do not exist there. Run the addon's own `tolk:*` commands, and anything else that needs a booted addon, from the sandbox folder, `/Volumes/1TB/Sites/tolk-sandbox`, for example `php please tolk:doctor` *(T9.6)*.

A line marked *(Tn.n)* describes something that task builds; until then it does not work yet. The task that builds it verifies the line and removes the marker.

## Laravel Boost

- Boost is a dev dependency of the sandbox, not of the addon. The committed `.mcp.json` starts it as `php ../tolk-sandbox/artisan boost:mcp`, so its tools (`search-docs`, `application-info`, `read-log-entries`, `last-error`, `browser-logs`, `get-absolute-url`) work on the sandbox, where the addon is really installed. Boost 2.10 has no tinker or routes tool: run `php artisan tinker --execute="…"` or `php artisan route:list` from the sandbox folder instead. Boost starts only while the sandbox has `APP_ENV=local` or `APP_DEBUG=true`.
- Prefer Boost's documentation search over memory for Laravel and `laravel/ai` APIs; when it and the installed source disagree, the source in `vendor/` wins.
- Before working on the AI engine, read `vendor/laravel/ai/resources/boost/skills/ai-sdk-development/SKILL.md`.
- Before working on the CP, read `vendor/statamic/cms/resources/boost/guidelines/core.blade.php`.
- The sandbox's `boost.json` selects Claude Code and the `statamic/cms` guidelines. After a `composer update` there, `php artisan boost:update` refreshes its `CLAUDE.md` section and `.claude/skills/`.

## Architecture

- §A1: layout and class map. `src/ServiceProvider.php` is the single root provider; Statamic auto-discovers `Actions`, `Console/Commands`, `Fieldtypes`, `Listeners` and `routes/cp.php` (not recursively).
- §A2–§A4: which fields are translated, how field handlers turn values into leaves and segments with inline placeholders, and fingerprints.
- §A5–§A7: translation state (kept outside content, behind a repository; the file driver writes under `content/tolk/`), field status, and modes and eligibility.
- §A8–§A11: the pipeline (plan, units, assemble), the prompt contract, what a unit's result leads to, and saving content through one writer.
- §A12–§A15: runs, CP surfaces, authorization, and config and settings keys. §A16 covers the CLI-only `tolk:lang` and `tolk:forms`.

## Conventions

The permanent cross-cutting rules of `TASKS.md`, numbered as there:

3. **Built assets are committed.** Whenever `resources/js` or `resources/css` change, run `npm run cp:build` and commit `resources/dist/build` in the same commit.
4. **Service provider:** use `bootAddon()`, never `boot()`; call `parent::register()` in `register()`.
5. **No real API calls in the default test suite.** `Http::preventStrayRequests()` is on in the base TestCase. Live tests live in the Pest group `live` and run only on request.
6. **Driver-agnostic content access.** Use Statamic facades and object methods only. Never read or write content files directly, never use file modification times, query with `where('site', …)` not `locale`.
7. **Never write `values()` back into data**, and never call `data([...])` to change single fields; use `set()` / `merge()` / `remove()`.
8. **One writer.** Every server-side save of Statamic content goes through `Support\ContentWriter::write()` (§A11). Never save entries, terms, globals or trees from parallel code paths.
9. **Never hardcode `/cp`.** PHP uses `cp_route()`; JavaScript uses the global `cp_url()`.
10. **CP JavaScript:** `<script setup>` only; components from `@statamic/cms/ui`; do not bundle Vue, Pinia or Inertia; register everything inside one `Statamic.booting()` block in `resources/js/cp.js`; every UI string through `__()` with the English text as the key; never use `v-html` for content or model output.
11. **PHP style:** PHP 8.3 features (constructor promotion, readonly, enums, return types). No comments unless the reason is non-obvious. Enum cases in TitleCase with lowercase string values.
12. **Cache arrays and scalars only.** Plans, outcomes, runs and scan results are stored as arrays (`toArray()` / `fromArray()`), never as objects: hosts on Laravel 13 may restrict unserialisation. Cache-backed tests run against the `file` store (`useFileCache()`), because the `array` store does not serialise.
13. **Secrets:** API keys come from the environment only. Never log them, never put them in agent properties, plans, runs, state or queue payloads. Provider error messages are passed through one sanitiser before they are stored or returned.
14. **Never mutate the host's `ai.*` config.** Build providers with `Ai::build()`.
15. **Commands:** signatures have no `statamic:` prefix (`tolk:translate`), the class uses `Statamic\Console\RunsInPlease` directly, so the same name works with `php please`, `php artisan` and `$this->artisan()` in tests.

Commits are small and atomic with imperative sentence-case messages. Do not push, tag or open pull requests unless the owner asks.

## Sandbox

- `/Volumes/1TB/Sites/tolk-sandbox`, served by Herd at `http://tolk-sandbox.test` (CP at `/cp`). Statamic Pro with the sites `en` (the origin), `el` and `de`; flat files, no database and the `sync` queue, the zero-infrastructure baseline (D11). It is its own git repository; never push it.
- Its `SANDBOX.md` describes the users, the sites and the demo content. Credentials stay there, never in this repo.
- It is the only site the addon may be installed into. Never touch the owner's other projects under `/Volumes/1TB/Sites/`.
- Installation: a symlinked path repository `../autotranslator` with `reachweb/statamic-tolk:@dev`, so PHP changes in this repo, new classes included, are live in the sandbox at once. A change to this repo's `composer.json` needs `composer update reachweb/statamic-tolk` in the sandbox.
- CP assets: Statamic serves the addon's build from the sandbox's `public/vendor/statamic-tolk/build` (git-ignored there). `php artisan statamic:install` (run by the sandbox's `post-autoload-dump`) or `php artisan vendor:publish --tag=statamic-tolk --force` copies `resources/dist/build` there. For development, that folder is instead a symlink to this repo's `resources/dist/build` (through `vendor/reachweb/statamic-tolk`; the command is in `SANDBOX.md`), so `npm run cp:build` shows up without publishing again, and publishing keeps the symlink. While `npm run cp:dev` runs, the hot file serves the assets from Vite.
- Reseed: `php artisan demo:seed --fresh` in the sandbox folder rebuilds the demo content; it is idempotent, so `git status` there stays clean. `SANDBOX.md` also shows how to reset to the last sandbox commit with git.
- Fake engine *(T4.13)*: `TOLK_FAKE_ENGINE=true` in the sandbox `.env` replaces the provider engine with a deterministic one that returns `[lang] text` after `fake_delay_ms`. It is honoured only in the `local` and `testing` environments. The markers `[[review]]`, `[[invalid]]` and `[[refuse]]` in source text trigger a review correction, invalid output and a refusal (the article `fake-engine-markers`). Browser tests require it and never call a real provider.
- Provider keys (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`) go only into the sandbox `.env`, and only when the owner supplies them.
