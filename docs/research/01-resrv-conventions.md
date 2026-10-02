# 01 — Resrv conventions (research for the Statamic 6 AI auto-translator addon)

Research date: 2026-10-01. Read-only. Nothing was executed against the code (no tests, no builds, no installs) — every statement below comes from reading files or `git` metadata. Where a statement is my inference from source rather than something written in a file, it is marked **(inferred)**.

Path shorthands used in references:

- `RESRV/` = `/Volumes/1TB/Sites/umami/addons/reachweb/statamic-resrv/`
- `CMS/` = `RESRV/vendor/statamic/cms/` (statamic/cms **v6.19.0**)
- `BOOST/` = `RESRV/vendor/laravel/boost/` (laravel/boost **v2.4.6**)
- `SKILL/` = `/Users/afonic/.claude/skills/statamic-addon-v5-to-v6/`
- `VOUCH/` = `/Volumes/1TB/Sites/umami/addons/reachweb/statamic-resrv-vouchers/` (bonus source, see §0)
- `EASY/` = `/Volumes/1TB/Sites/easy-forms/addons/reachweb/statamic-easy-forms/`

---

## 0. Sources, state of the repos, and things you did not ask for but need to know

| Repo | What it is | First commit → last commit | Statamic |
|---|---|---|---|
| `RESRV/` | The reference addon. Branch `feature/manual-reservations`, HEAD `45d35784` (2026-09-09), **working tree dirty** (11 changed tracked files, 2 untracked). Tags up to `v6.4.0`. | 2021-04-11 → 2026-09-09 | `^6.11` (v6 port started 2026-05-16, `v6.0.0` tagged 2026-06-09) |
| `/Volumes/1TB/Sites/umami` | Plain Statamic host site that loads Resrv through a composer path repository. Not a git repo. | — | `^6.0` |
| `/Volumes/1TB/Sites/easy-forms` | **Host/demo site** (`reachweb/easy-forms-demo`). The addon itself is at `EASY/` (`reachweb/statamic-easy-forms`). | 2025-11-19 → 2026-06-26 | `^5.0 \| ^6.0` |
| `/Volumes/1TB/Sites/imprint` | **Not a Statamic addon.** Laravel 12 Shopify app (`laravel/laravel` skeleton). | 2025-12-15 → 2026-10-01 | none |

Three things outside the brief that change the plan (all skimmed only, not audited):

1. **`VOUCH/` — `reachweb/statamic-resrv-vouchers`** (2026-05-15 → 2026-07-02). The developer has already built one new Statamic 6 addon that explicitly "mirrors Resrv" (`VOUCH/CLAUDE.md:51`, `VOUCH/tasks.md:55`). It is the best available evidence of which Resrv conventions he keeps and which he drops when starting fresh. It also contains his own task-list format (`VOUCH/tasks.md`, 79 KB). Referenced throughout as "vouchers".
2. **`/Volumes/1TB/Sites/ai-agent/addons/reachweb/statamic-ai-agent`** (2025-12-06 → 2025-12-12, `statamic/cms ^6.0`). The developer's earlier AI addon. It already contains `src/Agent/Tools/Translation/TranslateEntryTool.php` and `BulkTranslateEntriesTool.php` (entry localization via `$originEntry->in($site)`, `makeLocalization()`, `$collection->sites()`), and its own HTTP driver (`src/Agent/Drivers/Claude/ClaudeDriver.php:67-71`, `Http::withHeaders([...])->timeout(120)->post(...)`). It is the origin of the `UserConfig.php` pattern in the upgrade skill.
3. **`laravel/ai` is not installed in any of the requested sources** ("not found" in `RESRV/composer.lock`). It is installed in four unrelated local projects; `/Volumes/1TB/Sites/istionyachting` has `laravel/ai v0.9.1` together with `statamic/cms v6.23.0`. That copy's `composer.json` requires `php ^8.3`, `illuminate/* ^12.0|^13.0`, `illuminate/json-schema ^12.62|^13.15`, `laravel/prompts ^0.3.6`. Resrv's lock is on `laravel/framework v13.9.0`, which would not satisfy `^13.15` **(inferred — verify in the laravel/ai research track)**.

---

## 1. composer.json, testbench, local serving

### 1.1 Package identity (`RESRV/composer.json`)

| Key | Value | Line |
|---|---|---|
| `name` | `reachweb/statamic-resrv` | 2 |
| `type` | `statamic-addon` | 14 |
| `license` | `proprietary` | 13 |
| `homepage` | `https://resrv.dev` | 12 |
| `authors[0]` | Iosif Chatzimichail, `iosif@reach.gr`, role Developer | 15-21 |
| `support.email` | `info@reach.gr` | 22-24 |
| PSR-4 | `Reach\\StatamicResrv\\` → `src` | 44-48 |
| PSR-4 dev | `Reach\\StatamicResrv\\Tests\\` → `tests`; `Workbench\\App\\` → `workbench/app/`; `Workbench\\Database\\Factories\\` → `workbench/database/factories/`; `Workbench\\Database\\Seeders\\` → `workbench/database/seeders/` | 49-56 |

Vendor namespace is `Reach\` while the composer vendor is `reachweb/`. Same in every addon by this developer (`Reach\StatamicEasyForms`, `Reach\StatamicAiAgent`, `Reach\StatamicResrvVouchers`).

### 1.2 require / require-dev (exact constraints, `RESRV/composer.json:25-43`)

```json
"require": {
    "php": "^8.4",
    "doctrine/dbal": "^3.0 || ^4.0",
    "laravel/framework": "^12.0 || ^13.0",
    "statamic/cms": "^6.11",
    "stripe/stripe-php": "^16.0",
    "moneyphp/money": "^4.6",
    "spatie/simple-excel": "^3.7",
    "marcorieser/statamic-livewire": "^5.2",
    "livewire/livewire": "^3.6.4 || ^4.0"
},
"require-dev": {
    "phpunit/phpunit": "^12.0 || ^13.0",
    "orchestra/testbench": "^10.0 || ^11.0",
    "laravel/pint": "^1.2",
    "spatie/laravel-ray": "^1.0",
    "laravel/boost": "^2.1",
    "orchestra/testbench-dusk": "^10.0 || ^11.0"
}
```

Installed versions (`RESRV/composer.lock`): statamic/cms v6.19.0, laravel/framework v13.9.0, inertiajs/inertia-laravel v2.0.24, phpunit 13.1.10, orchestra/testbench v11.1.0, testbench-dusk v11.2.0, laravel/pint v1.29.1, laravel/boost v2.4.6, laravel/mcp v0.7.0, laravel/prompts v0.3.17, livewire v4.3.0.

statamic/cms 6.19.0 itself requires `laravel/framework ^12.40.0 || ^13.0`, `inertiajs/inertia-laravel ^2.0`, `laravel/prompts ^0.3.0`, `nesbot/carbon ^3.0`, `pixelfear/composer-dist-plugin ^0.1.4` and declares no `php` constraint (`CMS/composer.json:11-43`).

Not present in Resrv's composer.json: `minimum-stability`, `prefer-stable`. (Vouchers, easy-forms and ai-agent all set `"minimum-stability": "dev", "prefer-stable": true`.)

### 1.3 scripts, extra, config (`RESRV/composer.json:57-81`)

```json
"scripts": {
    "test": "vendor/bin/phpunit",
    "test:stop": "vendor/bin/phpunit --stop-on-defect",
    "test-pgsql": "vendor/bin/phpunit --configuration phpunit.pgsql.xml",
    "test-pgsql:stop": "vendor/bin/phpunit --configuration phpunit.pgsql.xml --stop-on-defect",
    "test-coverage": "vendor/bin/phpunit --coverage-html coverage",
    "test:browser": "vendor/bin/phpunit -c phpunit.dusk.xml",
    "test:browser:headed": "DUSK_HEADLESS_DISABLED=1 vendor/bin/phpunit -c phpunit.dusk.xml"
},
"extra": {
    "statamic": { "name": "Statamic Resrv", "description": "A reservation engine for Statamic" },
    "laravel": { "providers": ["Reach\\StatamicResrv\\StatamicResrvServiceProvider"] }
},
"config": { "allow-plugins": { "pixelfear/composer-dist-plugin": true } }
```

- `extra.statamic` has only `name` and `description`. No `slug`, no `editions`. The slug therefore falls back to the package name: `Addon::slug()` returns `$this->slug ?? Str::slug(explode('/', $this->id)[1])` (`CMS/src/Addons/Addon.php:269-274`) → `statamic-resrv`.
- `allow-plugins` is there because statamic/cms uses `pixelfear/composer-dist-plugin` to download its own `resources/dist` (`CMS/composer.json:27,71-75`). Resrv does not use `extra.download-dist` for itself; its built assets are committed (§3.6).
- No composer script for Pint or linting.
- `composer.lock` and `package-lock.json` are committed in Resrv (`git ls-files`). `browser-tests.yml:37-39` relies on that: "composer.lock is committed and pins orchestra/testbench-dusk + PHPUnit 13".

### 1.4 Testbench (`RESRV/testbench.yaml`, 46 lines; comments shortened and `discovers` condensed here)

```yaml
laravel: '@testbench-dusk'

providers:
  - Statamic\Providers\StatamicServiceProvider
  - Inertia\ServiceProvider                       # Gotcha #10 — required by Statamic 6
  - Livewire\LivewireServiceProvider
  - MarcoRieser\Livewire\ServiceProvider
  - Reach\StatamicResrv\StatamicResrvServiceProvider
  - Workbench\App\Providers\WorkbenchServiceProvider

env:
  - APP_ENV=testing                 # REQUIRED — Statamic auto-boots; its CheckComposerJsonScripts middleware 500s unless env=testing
  - APP_URL=http://127.0.0.1:8001   # ... Bind port is set by `serve --port=8001`.
  - DB_CONNECTION=sqlite            # no DB_DATABASE — share the skeleton default database_path('database.sqlite') across processes
  - SESSION_DRIVER=file
  - CACHE_STORE=file
  - QUEUE_CONNECTION=sync
  - MAIL_MAILER=log
  - STATAMIC_LICENSE_KEY=

migrations:
  - database/migrations            # ... loadMigrationsFrom isn't picked up by the CLI migrate-fresh, so list it explicitly
  - workbench/database/migrations

seeders:
  - Workbench\Database\Seeders\DatabaseSeeder

workbench:
  start: '/'
  install: true
  health: false
  discovers: { web: true, api: true, commands: true, components: false, factories: true, views: true }
  assets:
    - laravel-assets
    - statamic-resrv   # publishes the addon's compiled frontend bundle (publish tag = addon slug)
  build:
    - asset-publish
    - create-sqlite-db
    - db-wipe
    - migrate-fresh
```

Workbench directory (`RESRV/workbench/`):

- `app/Providers/WorkbenchServiceProvider.php` — makes the served app boot the addon. Key method `registerAddonManifest()` (lines 66-88): the addon is the repo's *root* composer package, "so it never lands in vendor/composer/installed.json and Statamic never discovers it" (lines 49-52), so the provider appends a manifest entry by hand. It also points Stache stores at `workbench_path('content/...')` (96-115), sets `statamic.editions.pro => true` (99), registers one site (165-175) and stubs `Statamic\Licensing\Outpost` (246-249).
- `database/seeders/DatabaseSeeder.php` — delegates to `tests/Browser/Concerns/SeedsBookableContent`.
- `routes/web.php` — harness routes under `/__t/...`.
- `resources/views/*.blade.php` and `resources/views/dusk/*.blade.php`.
- Git-ignored: `/workbench/storage`, `/workbench/database/*.sqlite`, `/workbench/content` (`RESRV/.gitignore:18-22`).

### 1.5 How the addon is served locally

Two mechanisms exist side by side:

1. **Host Statamic site with a path repository** (interactive CP work). `/Volumes/1TB/Sites/umami/composer.json` has `"reachweb/statamic-resrv": "*"` plus `"repositories": [{"type": "path", "url": "addons/reachweb/statamic-resrv"}]`. Result on disk: `umami/vendor/reachweb/statamic-resrv` is a symlink to the addon, and `umami/public/vendor/statamic-resrv/build` and `.../frontend` are symlinks to `RESRV/resources/dist/build` and `RESRV/resources/frontend`. The host's `post-autoload-dump` runs `php artisan statamic:install`, which is what triggers asset publishing (§3.6). Easy-forms uses the same layout with `"reachweb/statamic-easy-forms": "*@dev"` and `"options": {"symlink": true}`.
2. **Testbench workbench** (browser tests). `php vendor/bin/testbench workbench:build`, then testbench-dusk serves the app on port 8001. `vendor/bin/testbench <command>` is the stand-in for `php artisan` in the package (old `CLAUDE.md`, §8.2).

---

## 2. Service provider

### 2.1 Classes

```php
// RESRV/src/StatamicResrvServiceProvider.php:9-15  (the class named in extra.laravel.providers)
class StatamicResrvServiceProvider extends AggregateServiceProvider   // Illuminate\Support\AggregateServiceProvider
{
    protected $providers = [
        ResrvProvider::class,
        ResrvLivewireProvider::class,
    ];
}
```

- `Reach\StatamicResrv\Providers\ResrvProvider extends Statamic\Providers\AddonServiceProvider` (`RESRV/src/Providers/ResrvProvider.php:83`) — everything CP/back-end.
- `Reach\StatamicResrv\Providers\ResrvLivewireProvider extends AddonServiceProvider` (`.../ResrvLivewireProvider.php:25`) — Livewire components only.
- Vouchers copies this shape exactly: `StatamicResrvVouchersServiceProvider extends AggregateServiceProvider` + `src/Providers/VouchersProvider.php`.
- Easy-forms and ai-agent use the other shape: one `ServiceProvider extends AddonServiceProvider` directly in `src/`.

### 2.2 Consequence of the provider living in `src/Providers/` (important)

`AddonServiceProvider::shouldBootRootItems()` (`CMS/src/Providers/AddonServiceProvider.php:885-904`) only returns true when the provider file sits in the autoloaded directory (`src/`). For `ResrvProvider` it is false, so these "root items" are skipped and Resrv does them by hand in `bootAddon()`:

| Core auto-behaviour (root provider only) | Core lines | What Resrv does instead |
|---|---|---|
| `bootConfig()` — merges `config/{slug}.php` under key `{slug}`, publish tag `{slug}-config` | 467-488 | `mergeConfigFrom(config/config.php, 'resrv-config')` + publish tag `resrv-config` (`ResrvProvider.php:240-242, 264`) |
| `bootTranslations()` — `lang/` or `resources/lang`, namespace `{slug}`, tag `{slug}-translations` | 490-516 | `loadTranslationsFrom(resources/lang, 'statamic-resrv')` (234) |
| `bootViews()` — namespace `$viewNamespace ?? packageName` | 641-655 | `loadViewsFrom(resources/views, 'statamic-resrv')` (236) |
| `bootBlueprints()` / `bootFieldsets()` namespaces | 782-816 | not done |
| `bootSettingsBlueprint()` — auto-registers `resources/blueprints/settings.yaml` | 825-836 | `registerSettingsBlueprint(...)` called explicitly (269-271) |
| Default route discovery (`routes/cp.php`, `web.php`, `actions.php`) | 534-569 | explicit `$routes` property (87-90) — explicit entries always work |

Class auto-discovery (`autoloadFilesFromFolder`, 838-883) is resolved relative to the provider's own directory, so from `src/Providers/` nothing in `src/Fieldtypes`, `src/Listeners`, etc. is found. Hence every registration in `ResrvProvider` is an explicit array, and the comment at line 108: "Not auto-discovered — Statamic only scans src/Providers/UpdateScripts."

For reference, what a root provider in `src/` gets for free in 6.19.0: `Tags`, `Scopes` (+ `Query/Scopes`, `Query/Scopes/Filters`), `Actions`, `Dictionaries`, `Fieldtypes`, `Modifiers`, `Widgets`, `Commands` and `Console/Commands`, `UpdateScripts`, `Subscribers`, and `Listeners` (discovered by reflection on public `handle*`/`__invoke` methods and their first parameter's type — lines 277-295).

### 2.3 Registration mechanisms used by `ResrvProvider` (all line refs to `RESRV/src/Providers/ResrvProvider.php`)

| Mechanism | How | Lines |
|---|---|---|
| CP + web routes | `protected $routes = ['cp' => .../routes/cp.php, 'web' => .../routes/web.php]` | 87-90 |
| Action routes | not used ("not found") | — |
| Middleware | `protected $middlewareGroups = ['statamic.web' => [SetResrvAffiliateCookie::class]]` | 92-96 |
| Commands | `protected $commands = [...]` (7 classes) and again via `Artisan::starting(fn ($artisan) => $artisan->resolveCommands($this->commands))` | 98-106, 291-293 |
| Update scripts | `protected $updateScripts = [MigrateConfigToSettings::class]` | 109-111 |
| Dictionaries | `protected $dictionaries = [CountryPhoneCodes::class]` | 113-115 |
| Fieldtypes | `protected $fieldtypes = [...]` (6) | 117-124 |
| Tags | `protected $tags = [Resrv::class, ResrvCheckoutRedirect::class]` | 126-129 |
| Scopes / filters | `protected $scopes = [...]` (5 filters + `ResrvSearch`) | 131-138 |
| Listeners | `protected $listen = [...]`, including `NormalizeAvailabilityFieldValue::class.'@handleCreated'` | 140-206 |
| Subscribers | not used | — |
| Vite | `protected $vite = ['input' => ['resources/js/cp.js', 'resources/css/cp.css'], 'publicDirectory' => 'resources/dist', 'hotFile' => __DIR__.'/../../resources/dist/hot']` | 208-215 |
| `$scripts` / `$stylesheets` | not used | — |
| Publishables | `protected $publishables = [__DIR__.'/../../resources/frontend' => 'frontend']` | 217-219 |
| Singletons | `PaymentGatewayManager`, `ActivityLog` in `register()` (calls `parent::register()` first) | 221-230 |
| Boot method | `public function bootAddon(): void` | 232-294 |
| Migrations | `loadMigrationsFrom(.../database/migrations)` | 238 |
| Extra publish tags | `resrv-config`, `resrv-checkout-views`, `resrv-blueprints`, `resrv-forms`, `resrv-language`, `resrv-emails` | 240-262 |
| Settings blueprint | `registerSettingsBlueprint(...)` then `mergeAddonSettings(...)` | 266-273 |
| CP nav | `Nav::extend(fn ($nav) => $nav->create(ucfirst(__('Reservations')))->section('Resrv')->can('use resrv')->route('resrv.reservations.index')->icon('<svg ...>'))`; 8 unconditional items + 3 conditional (Activity Log, Affiliates, Settings) | 296-371 |
| Permissions | `Permission::extend(fn () => Permission::group('statamic-resrv', 'Reserv Permissions', fn () => Permission::register('use resrv', fn ($permission) => $permission->label(...)->description(...))))` | 438-449 |
| PHP hooks | `Collection::hook('fetched-entries', $callback)` on `Statamic\Tags\Collection\Collection` | 451-456 |
| Cache hardening | `$this->registerSerializableClasses([...])` in `register()` | 466-473 |
| Terminating callback | `$this->app->terminating(fn () => Rate::resetEntryCollectionCache())` | 288 |
| Statamic Actions, widgets, utilities, modifiers, policies, Inertia page registration in PHP, `schedule()` | not used ("not found") | — |

Notes:

- One permission only: `use resrv`. Nav items use raw inline SVG strings for icons. The whole CP route group is gated by it (§4).
- Vouchers adds a comment explaining why permissions go through `Permission::extend()` and never `$this->app->booted()`: "in Statamic 6 bootAddon() runs while the booted callbacks are iterating, so booted() fires the callback immediately AND re-queues it, registering the permission twice" (`VOUCH/src/Providers/VouchersProvider.php:118-120`).
- Vouchers nav uses children: `$nav->create(__('Vouchers'))->section('Resrv')->can(...)->route(...)->children([$nav->item(__('List'))->route(...)->can(...), ...])` (`VouchersProvider.php:134-147`).

### 2.4 Configuration and settings: native Statamic 6 addon settings + a small developer config file

Three layers, merged at boot into a single `config('resrv-config.*')` namespace:

```php
// RESRV/src/Providers/ResrvProvider.php:380-399
protected function mergeAddonSettings(array $blueprintFields): void
{
    $addon = Addon::get('reachweb/statamic-resrv');

    if (! $addon || ! $addon->hasSettingsBlueprint()) {
        return;
    }

    $merged = array_merge(
        SettingsBlueprint::defaultsFromFields($blueprintFields),   // 1. `default:` lines in the settings blueprint
        config('resrv-config'),                                    // 2. developer config file / test overrides
        $addon->settings()->raw()                                  // 3. values saved in the CP (always win)
    );
    ...
    config(['resrv-config' => $merged]);
}
```

- **Blueprint**: `RESRV/resources/blueprints/settings.yaml` (654 lines; tabs `general_information`, `engine_settings`, `checkout_settings`, `emails_settings`, `currency_settings`; standard `tabs → sections → fields` shape with `default`, `validate`, `if`, `width`, grids, `form`/`entries` fieldtypes and one custom fieldtype `resrv_email_template`).
- **Registration**: `protected function registerSettingsBlueprint(array $blueprint): self` binds `"statamic.addons.{slug}.settings_blueprint"` (`CMS/src/Providers/AddonServiceProvider.php:818-823`).
- **Storage**: `resource_path("addons/{$this->addon()->slug()}.yaml")` → `resources/addons/statamic-resrv.yaml` on the site (`CMS/src/Addons/FileSettings.php:12-15`).
- **UI and routes come from core**, not from the addon: `addons/{addon}/settings` GET/PATCH named `addons.settings.edit` / `addons.settings.update` (`CMS/routes/cp.php:336-337`), handled by `AddonSettingsController` using `PublishForm::make($addon->settingsBlueprint())->asConfig()->icon('cog')->title($addon->name())->values($addon->settings()->raw())->submittingTo(...)` (`CMS/src/Http/Controllers/CP/Addons/AddonSettingsController.php:24-29`). Core also adds a Tools nav item per addon with a settings blueprint (`CMS/src/CP/Navigation/CoreNav.php:292-303`) and registers a permission `edit {package} settings` (`CMS/src/Auth/CorePermissions.php:223-234`). Authorization is `AddonPolicy::editSettings` — super users or `configure addons` pass in `before()` (`CMS/src/Policies/AddonPolicy.php:9-28`). Resrv adds its own extra "Settings" nav item in the Resrv section: `->can('configure addons')->url($addon->settingsUrl())` (`ResrvProvider.php:363-369`).
- **Settings API** (`CMS/src/Contracts/Addons/Settings.php`): `addon(): Addon`, `all(): array`, `raw(): array`, `get(string $key, $default = null)`, `set(string|array $key, mixed $value = null): self`, `save(): bool`, `delete(): bool`. Reached through `Addon::get('vendor/package')->settings()` or `->setting($key, $default)` (`CMS/src/Addons/Addon.php:367-377`). Saving dispatches `AddonSettingsSaving` / `AddonSettingsSaved`.
- **Why `raw()`**: `all()`/`get()` pass every value through Antlers and cast to string (`CMS/src/Addons/Settings.php:98-114`), so booleans and integers come back as strings. Resrv's comment: "raw() avoids Settings::resolveAntlersValue() stringifying booleans/integers" (`ResrvProvider.php:373-379`). The flip side: through `all()`/`get()` a setting may contain Antlers such as `{{ config:... }}` (the parse call passes `['config' => Cascade::config()]`).
- **Defaults**: Resrv reads only explicit top-level `default:` keys via its own helper `Reach\StatamicResrv\Support\SettingsBlueprint::fields(array $blueprint): array`, `::defaults(array $blueprint): array`, `::defaultsFromFields(array $fields): array` (`RESRV/src/Support/SettingsBlueprint.php:13,37,46`), deliberately not `Field::defaultValue()` and never grid sub-fields.
- **Developer config**: `RESRV/config/config.php` (57 lines) keeps only `payment_gateway`, `payment_gateways`, and three `env()` Stripe keys. Its docblock: "Only the keys below belong in this file. All user-facing settings ... are managed in the Control Panel under Resrv → Settings and stored in resources/addons/statamic-resrv.yaml" (lines 5-13). Secrets stay in env/config, not in the CP settings.
- **Migration tooling**: update script `MigrateConfigToSettings extends Statamic\UpdateScripts\UpdateScript` with `shouldUpdate($newVersion, $oldVersion)` returning `$this->isUpdatingTo('6.0.0')` (`RESRV/src/UpdateScripts/MigrateConfigToSettings.php:10-13`), command `resrv:settings:migrate {--dry-run}`, and a warning section injected into the blueprint when a published config file shadows CP keys (`ResrvProvider.php:406-436`).
- **Tests for this**: `RESRV/tests/Settings/*` (6 files). `SettingsOverlayPrecedenceTest` writes `resources/addons/statamic-resrv.yaml` inside `resolveApplicationConfiguration()` because the overlay runs in `bootAddon()`.

History: `registerSettingsBlueprint` arrived in commit `137fc987` "Replace Forma with Statamic 6 config" on 2026-05-16 — the same day the upgrade skill was written. The skill's `UserConfig.php` + `SettingsController` pattern (§9) is **not** what Resrv uses. Vouchers uses a plain config file only; its tasks.md records the decision: "Plain `config/resrv-vouchers.php` (no Forma, no UserConfig blueprint ...). Revisit if user-facing settings are added later." (`VOUCH/tasks.md` Decisions table).

### 2.5 Editions / licensing

Not found. No `editions` in `extra.statamic`, no edition checks in `src/` (grep for `edition|licens|Outpost` in `RESRV/src` returns nothing). License handling is the Marketplace only (`README.md:101-104`). Tests force `statamic.editions.pro = true` (`RESRV/tests/TestCase.php:197`), which multi-site needs.

---

## 3. CP frontend

### 3.1 `RESRV/package.json` (30 lines; `dependencies` condensed onto shared lines here)

```json
{
    "name": "statamic-resrv",
    "private": true,
    "type": "module",
    "scripts": {
        "cp:dev": "vite",
        "cp:build": "vite build",
        "frontend:dev": "vite -c vite-frontend.config.js",
        "frontend:build": "vite build -c vite-frontend.config.js && vite build -c vite-frontend-css.config.js"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.0.0",
        "laravel-vite-plugin": "^3.0.0",
        "vite": "^8.0.0"
    },
    "dependencies": {
        "@fullcalendar/core": "^6.1.0", "@fullcalendar/daygrid": "^6.1.0",
        "@fullcalendar/interaction": "^6.1.0", "@fullcalendar/vue3": "^6.1.0",
        "@internationalized/date": "^3.12.1",
        "@reachweb/alpine-calendar": "^1.3.0",
        "@statamic/cms": "file:./vendor/statamic/cms/resources/dist-package",
        "axios": "^1.6.0", "dayjs": "^1.10.4", "lodash": "^4.17.21",
        "tailwindcss": "^4.0.0", "vuedraggable": "^4.1"
    }
}
```

- `vue` and `@vitejs/plugin-vue` are not listed. `@vitejs/plugin-vue ^6.0.0` arrives as a dependency of the `@statamic/cms` package (`CMS/resources/dist-package/package.json`), and `vue` is externalised to `window.Vue` by the Statamic Vite plugin (`.../src/vite-plugin/externals.js`).
- `@statamic/cms` resolves from the addon's **own** `vendor/` (`file:./vendor/...`), so `composer install` must run in the addon before `npm install`. Vouchers points at the host instead: `file:../../../vendor/statamic/cms/resources/dist-package` and lists `@vitejs/plugin-vue ^6.0.0` explicitly.
- No JS test runner, no ESLint/Prettier config. `jsconfig.json` only includes `./resources/js/**/*`.

### 3.2 `RESRV/vite.config.js` (CP bundle, 17 lines)

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        statamic(),
        tailwindcss(),
        laravel({
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
            publicDirectory: 'resources/dist',
            hotFile: 'resources/dist/hot',
            refresh: true,
        }),
    ],
});
```

Identical in vouchers. The two `vite-frontend*.config.js` files build the public Livewire/Alpine bundle and are irrelevant to a CP-only addon.

### 3.3 Entry file and registration (`RESRV/resources/js/cp.js`, 43 lines)

```js
import AvailabilityFieldtype from './fieldtypes/Availability.vue';
import ReservationsIndex from './pages/Reservations/Index.vue';
// ...

Statamic.booting(() => {
    Statamic.$components.register('resrv_availability-fieldtype', AvailabilityFieldtype);   // 5 fieldtypes
    Statamic.$inertia.register('resrv::Reservations/Index', ReservationsIndex);             // 14 pages
});
```

- Fieldtype component name = fieldtype handle + `-fieldtype` (`resrv_cutoff-fieldtype` for PHP class `ResrvCutoff`).
- Inertia page handle = `resrv::<Dir>/<Name>`, file `resources/js/pages/<Dir>/<Name>.vue`. Vouchers: `resrv-vouchers::Vouchers/Index`.
- Vouchers also registers a widget with `Statamic.component('resrv-vouchers-widget', VouchersWidget)` (`VOUCH/resources/js/cp.js:8`); `Statamic.component(name, component)` is an alias for `$components.register` (`CMS/resources/js/bootstrap/statamic.js:172-174`).
- Core API available on the global (`CMS/resources/js/bootstrap/statamic.js:56-174`): `Statamic.booting(cb)`, `Statamic.configuring(cb)`, `Statamic.booted(cb)`, `Statamic.component(name, component)`, and getters `$config $preferences $callbacks $hooks $toast $conditions $slug $bard $echo $pinia $keys $permissions $components $inertia $date $number $progress $colorMode $contrast $fieldActions $dirty $events $reveal $commandPalette`, plus `Statamic.user`.
- Addon Inertia pages are resolved after core pages through `inertia.get(name)` (`statamic.js:215-223`); `register(name: string, component: Component): void` (`CMS/resources/js/components/Inertia.ts:6-8`).

Layout of `RESRV/resources/js/`: `cp.js`, `fieldtypes/` (5), `pages/<Feature>/<Name>.vue` (14), `components/` (29), `composables/` (7: `useToast.js`, `useFormHandler.js`, `useSlugify.js`, `useInputOptions.js`, `useDateRangeModel.js`, `useRateEditability.js`, `useReservationDisplay.js`). All 48 `.vue` files use `<script setup>`; none use the Options API.

### 3.4 Imports from the Statamic package

Usage counts across `RESRV/resources/js`: `@statamic/cms/ui` 41 files, `@statamic/cms/inertia` 21, `@statamic/cms` 5. `@statamic/cms/api`, `/bard`, `/save-pipeline` are not used in Resrv.

- From `@statamic/cms`: only `Fieldtype` (the 5 fieldtype components).
- From `@statamic/cms/inertia`: `Head`, `Link`, `useForm`, `router`.
- From `@statamic/cms/ui` (by frequency): `Button` 32, `Card` 25, `Field` 19, `Input` 17, `Header` 13, `Switch` 11, `Stack` 11, `Select` 11, `DateRangePicker` 9, `Badge` 9, `Alert` 9, `Dropdown`/`DropdownMenu`/`DropdownItem` 8, `StatusIndicator` 7, `DropdownSeparator` 7, `Textarea` 6, `ConfirmationModal` 5, `Combobox` 5, `Panel` 3, `Icon` 3, `Checkbox` 3, `Modal` 2, `Label` 2, `Description` 2, `CheckboxGroup` 2, `Separator`, `Pagination`, `Listing`, `Heading`, `DatePicker` 1 each.

Full export lists in 6.19.0 (`CMS/resources/dist-package/src/`):

- `index.js` (`@statamic/cms`): `Fieldtype, IndexFieldtype, FieldtypeMixin, HasActionsMixin, HasInputOptionsMixin, HasPreferencesMixin, IndexFieldtypeMixin, InlineEditForm, DateFormatter, NumberFormatter, ItemActions, RelatedItem, RestoreRevision, RevisionHistory, RevisionPreview, SaveButtonOptions, SortableList, requireElevatedSession, requireElevatedSessionIf, clone, debounce, deepClone, resetValuesFromResponse`.
- `api.js` (`@statamic/cms/api`): `bard, callbacks, commandPalette, components, conditions, config, contrast, dateFormatter, dirty, echo, events, fieldActions, hooks, inertia, keys, numberFormatter, permissions, portals, preferences, progress, reveal, slug, stacks, colorMode, toast`.
- `inertia.js`: `Form, Head, Link, router, toggleArchitecturalBackground, useArchitecturalBackground, useForm, usePoll`.
- `save-pipeline.js`: `AfterSaveHooks, BeforeSaveHooks, Pipeline, PipelineStopped, Request`.
- `bard.js`: `ToolbarButtonMixin`.
- `ui.js`: 125 exports, including `PublishContainer, PublishForm, PublishFields, PublishLocalizations, PublishSections, PublishTabs, injectPublishContext, publishContextKey, Listing*, Modal, Stack*, Widget, registerIconSet`.
- Other package exports (`package.json`): `./ui.css`, `./tailwind.css`, `./vite-plugin`.

Globals used without import in Resrv's Vue files: `__()` and `cp_url()` (e.g. `components/AffiliatesPanel.vue:119,135`). They are window globals defined in `CMS/resources/js/bootstrap/globals.js` (`cp_url` 6-9, `__` 87-89, also `__n`, `docs_url`, `resource_url`, `data_get`, `data_set`, `clone`, `markdown`) and attached in `CMS/resources/js/index.js:12-15`. Toasts come from the component instance: `getCurrentInstance().proxy.$toast.success(message)` (`RESRV/resources/js/composables/useToast.js`).

### 3.5 Tailwind

`RESRV/resources/css/cp.css:1-5`:

```css
@import "@statamic/cms/tailwind.css";

@source "../views/**/*.{antlers.html,blade.php}";
@source "../js/**/*.{vue,js}";
@source "../../src/**/*.php";
```

- `@statamic/cms/tailwind.css` (`CMS/resources/dist-package/src/tailwind.css`) puts the addon's Tailwind into `addon-theme` / `addon-utilities` layers with `source(none)`, imports Statamic's `ui.css` theme tokens and defines `@custom-variant dark (&:where(.dark, .dark *));`. Because of `source(none)` the explicit `@source` lines are required.
- This replaced `@import "tailwindcss";` in commit `efaa8804` "Correctly import tailwind" (2026-05-16). The skill still shows the old form (§9).
- Rest of the file is `@apply`-based component CSS with `:is(.dark ...)` selectors. No `tailwind.config.js`, no PostCSS config.

### 3.6 Built assets and publishing

- **Built CP assets are committed**: `resources/dist/build/manifest.json` + `resources/dist/build/assets/cp-*.{js,css}` are tracked; only `/resources/dist/hot` is ignored (`RESRV/.gitignore:10`). Vouchers states the rule: "`resources/dist/build` is **committed** (Packagist installs need the built CP assets ...). Whenever `resources/js` or `resources/css` change, run `npm run cp:build` and commit the fresh dist alongside." (`VOUCH/CLAUDE.md:85`). CI has no npm step.
- **Publishing**: `registerVite()` publishes `{addon}/resources/dist/build/` to `public/vendor/{packageName}/build/` under the tag `{slug}` and calls `Statamic::vite($name, ['hotFile' => ..., 'buildDirectory' => "vendor/{$name}/build", 'input' => ...])` (`CMS/src/Providers/AddonServiceProvider.php:670-695`). `bootPublishAfterInstall()` hooks `Statamic::afterInstalled` to run `vendor:publish --tag={slug} --force` (762-780); those callbacks run from `php artisan statamic:install` (`CMS/src/Console/Commands/Install.php:125`). For Resrv the tag is `statamic-resrv` and the target `public/vendor/statamic-resrv/build`.
- **HMR**: `npm run cp:dev` writes `resources/dist/hot`; the provider's `hotFile` must point at the same file.

---

## 4. CP controllers, routes, authorization

### 4.1 Route file (`RESRV/routes/cp.php`, 103 lines, 82 routes)

```php
Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->middleware('can:use resrv')
    ->group(function () {
        Route::get('/resrv/reservation', 'ReservationCpController@index')->name('reservation.index');      // JSON for the listing
        Route::get('/resrv/reservations', 'ReservationCpController@indexCp')->name('reservations.index');  // Inertia page
        Route::post('/resrv/reservation/{id}/confirm-payment', 'ReservationCpController@confirmPayment')->whereNumber('id')->name('reservation.confirmPayment');
        // ...
    });
```

- The file is loaded through `Statamic::pushCpRoutes` (`CMS/.../AddonServiceProvider.php:595-600`), so it sits inside Statamic's CP prefix and middleware. Resulting names are `statamic.cp.resrv.*` and URLs follow `statamic.cp.route` (`RESRV/tests/Utilities/CpRouteConfigTest.php:23-33`). Always generated with `cp_route('resrv....')`.
- Convention: `indexCp()` / `calendarCp()` / `createCp()` return the Inertia page; `index()` etc. return JSON. Page route names are plural, data route names singular (`reservations.index` vs `reservation.index`).
- Resrv uses string `Controller@method` syntax with `Route::namespace`. Vouchers uses the array form with `->prefix('resrv-vouchers')` (`VOUCH/routes/cp.php:5-15`).
- `RESRV/routes/web.php` holds only the payment webhook routes, with `->withoutMiddleware([VerifyCsrfToken::class])`.

### 4.2 Controllers

All 16 controllers extend `Illuminate\Routing\Controller` (not `Statamic\Http\Controllers\CP\CpController`).

```php
// RESRV/src/Http/Controllers/ReservationCpController.php:43-57
public function indexCp()
{
    return Inertia::render('resrv::Reservations/Index', [
        'filters' => Scope::filters('resrv', []),
        'listUrl' => cp_route('resrv.reservation.index'),
        'showUrlTemplate' => cp_route('resrv.reservation.show', 'RESRVURL'),
        'refundUrl' => cp_route('resrv.reservation.refund'),
        // ...
    ]);
}
```

- Page methods are typed `: Inertia\Response` in the newer controllers (`RateCpController.php:23`, `AffiliateCpController.php:24`).
- URLs are passed to the page as props from `cp_route()`. For per-row URLs a placeholder is used: PHP `cp_route('resrv.reservation.show', 'RESRVURL')`, JS `props.showUrlTemplate.replace('RESRVURL', reservation.id)` (`resources/js/pages/Reservations/Index.vue:39`). Components that build URLs themselves use `cp_url('resrv/...')`. Hardcoded `/cp/...` was a real bug, fixed in commit `552f30ad` "Respect the configured CP route in CP JavaScript URLs".
- Listing endpoint: `index(FilteredRequest $request)` uses the `Statamic\Query\Scopes\Filters\Concerns\QueriesFilters` trait, clamps `perPage` to 1..100, and returns a `ResourceCollection` with `data`, `meta.columns` and `meta.activeFilterBadges` (`ReservationCpController.php:88-105`, `src/Resources/ReservationResource.php:39-77`). Columns come from a PHP-built blueprint (`src/Blueprints/ReservationBlueprint.php`) via `HasRequestedColumns`. Date cells must be `$fieldtype->preProcessIndex($date)` or the Listing renders "now" (`ReservationResource.php:98-105`).
- Mutations called by Inertia forms return `back()` when `$request->inertia()`, otherwise JSON (`AffiliateCpController.php:59-63`).
- Validation: 7 Form Requests in `src/Http/Requests/`, plus inline `$request->validate([...])`.

### 4.3 Authorization

- One mechanism: route-group middleware `can:use resrv` (`routes/cp.php:5`) plus `->can('use resrv')` on nav items.
- No policies, no `Gate::` calls, no `$this->authorize()`. The one Form Request `authorize()` returns `true` (`AffiliateCpRequest.php:16-19`).
- Vouchers adds test helpers for permission tests: `signInUserWithoutPermissions()` and `signInWithPermissions(array $permissions)` using `Role::make(...)->addPermission($permissions)->save()` (`VOUCH/tests/TestCase.php:91-112`).

### 4.4 Page component shape

```vue
<!-- RESRV/resources/js/pages/DataImport/Store.vue -->
<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Button, Card } from '@statamic/cms/ui';

defineProps({ indexUrl: { type: String, required: true } });
</script>

<template>
    <div class="max-w-xl mx-auto">
        <Head :title="__('Resrv Data import')" />
        <Header :title="__('Import availability')">
            <Button :href="indexUrl" variant="primary">{{ __('Import again') }}</Button>
        </Header>
        <Card class="p-4 lg:px-8 lg:py-6">...</Card>
    </div>
</template>
```

- Wrapper `max-w-page mx-auto` or `max-w-xl mx-auto`; `<Head>` then `<Header>` with action buttons in its slot.
- Listing page: `<Listing ref="listing" :url="listUrl" :filters="filters" sort-column="created_at" sort-direction="desc" preferences-prefix="resrv.reservations" push-query>` with `#cell-<column>` and `#prepended-row-actions` slots; refresh via `listing.value?.refresh()` (`pages/Reservations/Index.vue:174-251`).
- Edit panels: `<Stack :open="true" :title size="narrow" @closed>` with `#header-actions`, fields as `<Field :label :error="form.errors.x"><Input v-model="form.x" /></Field>`, and Inertia `useForm` + `form[method](url, { preserveScroll: true, preserveState: true, onSuccess, onError })` (`components/AffiliatesPanel.vue`).
- File upload page: `useForm` + `form.post(url, { forceFormData: true })` (`pages/DataImport/Index.vue:10-21`).
- Confirmation: `<ConfirmationModal v-if="id" :open="true" :title :danger :button-text :busy @confirm @cancel>`.

---

## 5. Existing usage, best example per topic

| Topic | In Resrv | Best example |
|---|---|---|
| Statamic bulk Actions (`Statamic\Actions\Action`) | **Not found.** `$actions` unused, no `src/Actions`. Only a test calls core `DuplicateEntry` (`tests/Listeners/NormalizeAvailabilityFieldValueTest.php:28`). Also absent in vouchers, easy-forms, ai-agent. | Core reference below. |
| Field actions (JS) | **Not found** in any of the developer's addons. | Core reference below. |
| Publish-form JS hooks | **Not found** (`Statamic.$hooks` unused). | Core reference below. |
| Custom fieldtypes | 6 PHP classes, 5 Vue components. | `RESRV/src/Fieldtypes/ResrvCutoff.php` + `RESRV/resources/js/fieldtypes/Cutoff.vue`. Select-based with no Vue: `src/Fieldtypes/ResrvEmailTemplate.php`. |
| Queued jobs | 7 in `src/Jobs`, standard `ShouldQueue` + `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`. | `RESRV/src/Jobs/ProcessDataImport.php` (has `failed()`), dispatched at `src/Http/Controllers/DataImportCpController.php:76`. |
| Job batches / chains | **Not found** (`Bus::batch`, `Batchable` absent). | — |
| Events + listeners | 8 events, 24 listeners, wired in `$listen`. | Own event: `src/Events/ReservationConfirmed.php`. Thin listener that dispatches a job: `src/Listeners/SendNewReservationEmails.php` (`SendEmails::dispatchAfterResponse($event->reservation)`). Statamic-event listener: `src/Listeners/NormalizeAvailabilityFieldValue.php`. |
| Console commands | 7, prefix `resrv:`. | `src/Console/Commands/MigrateSettings.php`; multi-option signature in `Housekeeping.php:53-56`. |
| Lang files | `resources/lang/en/{cp,frontend,country_phone_codes}.php`, namespace `statamic-resrv`. | `resources/lang/en/frontend.php` (128 lines). |
| HTTP client | **Not found** in Resrv (Stripe goes through `stripe/stripe-php`). | Outside the brief: `statamic-ai-agent/src/Agent/Drivers/Claude/ClaudeDriver.php:67-71`. |
| Caching | `Cache::remember` ×7, `forget` ×16, `rememberForever` ×1, `lock` ×1, `put/has/get`. No Blink. | `src/Helpers/AvailabilityFieldHelper.php` + invalidation in `src/Listeners/ClearAvailabilityFieldCache.php`. Lock: `src/Livewire/Traits/HandlesDirectGatewayPayment.php:120`. |

### 5.1 Fieldtype pattern (Vue 3)

```vue
<!-- RESRV/resources/js/fieldtypes/Cutoff.vue:77-86, 188 -->
<script setup>
import { Fieldtype } from '@statamic/cms';
import { Alert, Button, Card, DateRangePicker, Field, Input, Label, Panel, Switch } from '@statamic/cms/ui';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { update, expose, isReadOnly } = Fieldtype.use(emit, props);
// ...
defineExpose(expose);
</script>
```

- Template root is `<element-container>`; read-only handled with `:inert="isReadOnly"`.
- `Fieldtype.use(emit, props)` returns `{ name, isReadOnly, replicatorPreview, defineReplicatorPreview, fieldPathKeys, defineFieldActions, fieldActions, update, updateDebounced, updateMeta, expose }` (`CMS/resources/js/components/fieldtypes/fieldtype.js:97-109`). Props: `value, config, handle, meta, readOnly, showFieldPreviews, namePrefix, fieldPathPrefix, metaPathPrefix, id` (`props.js`). Emits: `update:value, update:meta, focus, blur, replicator-preview-updated` (`emits.js`).
- Two multi-site details in Resrv's fieldtypes: `Cutoff.vue:114-119` clones rows so edits "don't mutate props.value in place, which would make the isEqual guard suppress real updates (and the emit that records this field as localized on a synced multisite entry)"; and `Cutoff.vue:151-164` skips `update()` when nothing changed "so just opening an entry never flags it dirty".
- PHP side: `class ResrvCutoff extends Statamic\Fields\Fieldtype` with `protected $icon = 'time'`, `augment($value)`, `preload(): array`, `process($data)`. `preload()` returns the **origin** id for localized entries: `if ($this->field->parent()->hasOrigin()) { $parent = $this->field->parent()->origin()->id(); }` (`ResrvCutoff.php:45-48`); `ResrvAvailability` uses `->root()->id()` (line 53).

### 5.2 Queued job pattern

```php
// RESRV/src/Http/Controllers/DataImportCpController.php:75-76
// Capture the actor at dispatch time — the job may run queued, where User::current() is null.
ProcessDataImport::dispatch($cacheKey, app(ActivityLog::class)->cpActor());
```

`cpActor(): ?array` returns `['id' => (string) $user->id(), 'name' => $user->name() ?: (string) $user->email()]` (`RESRV/src/Support/ActivityLog.php:171-184`). The job declares the property with a class-level default "so jobs serialized before this property existed unserialize to null" (`ProcessDataImport.php:27-43`). Most emails use `dispatchAfterResponse`, not the queue. Tests: `Queue::fake()` + `Queue::assertPushed(ProcessDataImport::class, 1)` (`tests/DataImport/DataImportCpTest.php:37-47`), or calling `(new ProcessDataImport)->handle()` directly.

### 5.3 Console command naming

`resrv:install`, `resrv:import-entries`, `resrv:settings:migrate {--dry-run : ...}`, `resrv:upgrade-to-rates {--dry-run : ...}`, `resrv:send-abandoned-emails`, `resrv:cancel-lapsed-holds {--dry-run : ...}`, `resrv:housekeeping {--days=30 : ...} {--log-days=365 : ...} {--dry-run : ...}`.

- Classes in `src/Console/Commands/`, extend `Illuminate\Console\Command`, 6 of 7 `use Statamic\Console\RunsInPlease` (so `php please resrv:...` works).
- `--dry-run` is the standard safety flag. Newer commands type `handle(): int` and return `self::SUCCESS`.
- Install command pattern: a series of `$this->confirm(...)` each calling `callSilent('vendor:publish', ['--tag' => '...'])` (`InstallResrv.php`). Vouchers: `resrv-vouchers:install`.

### 5.4 UI strings

- **CP strings are plain English keys**, not namespaced keys: 715 `__('...')` calls in `resources/js`, 0 of them `__('statamic-resrv::...')`. PHP CP code does the same (`__('Reservations')`, `__('Use Statamic Resrv')`). `resources/lang/en/cp.php` has two unused-looking keys (`save`, `cancel`).
- **Frontend (customer-facing) strings are namespaced**: `trans('statamic-resrv::frontend.xxx')`, 173 usages.
- How JS gets translations: `JavascriptComposer::translations()` sends `app('translator')->toJson()` (`CMS/src/Http/View/Composers/JavascriptComposer.php:124-130`), which flattens every namespaced PHP lang file as `namespace::file.key` plus the JSON translations under `*` (`CMS/src/Extensions/Translation/Translator.php:36-51`). So both styles work in CP JavaScript; a plain key with no JSON translation renders as itself.
- Core's own rule: short strings use the string as key; "Longer phrases or sentences should use keys and have their translations stored in an appropriate translation file" (`CMS/CLAUDE.md:92-97`).

### 5.5 Core reference for what Resrv does not have (statamic/cms 6.19.0)

**Bulk Actions** — `abstract class Statamic\Actions\Action implements Arrayable` (`CMS/src/Actions/Action.php`):

- Properties: `protected static $binding = 'actions'; protected $items; protected $confirm = true; protected $dangerous = false; protected $fields = []; protected $context = []; protected $runnable = true; protected $component;` (15-23)
- Methods: `visibleTo($item)` 37, `visibleToBulk($items)` 42, `authorize($user, $item)` 51, `authorizeBulk($user, $items)` 56, `context($context)` 65, `protected fieldItems()` 76, `run($items, $values)` 81, `redirect($items, $values)` 86, `download($items, $values)` 91, `icon(): string` 96, `component(): ?string` 101, `runnable(): bool` 106, `buttonText()` 111, `confirmationText()` 117, `warningText()` 123, `dirtyWarningText()` 128, `bypassesDirtyWarning(): bool` 134, `triggersFullPageRefresh(): bool` 139, `requiresElevatedSession(): bool` 144, `toArray()` 149. `public static function title()` comes from `HasTitle`.
- Simple example: `CMS/src/Actions/Publish.php` (uses `$this->context['view'] === 'list'`, `$user->can('publish', $entry)`, returns `trans_choice(...)` as the success message, throws `\Exception` for failures).
- Multi-site example with a form field: `CMS/src/Actions/DeleteMultisiteEntry.php` (`visibleTo` checks `$item->collection()->sites()->count() > 1`; `fieldItems()` returns a `button_group`; `canChangeBehavior()` checks `User::current()->can('view', $descendant->site())`).
- Registration: `protected $actions = [...]` or auto-discovery from `Actions/` next to the provider (`AddonServiceProvider.php:325-336`).

**Field actions (JS)** — registry `Statamic.$fieldActions.add(name, action)` / `.get(name)` (`CMS/resources/js/components/field-actions/FieldActions.js:8-18`). `name` is `` `${config.type}-fieldtype` `` (`fieldtype.js:84`). Action object keys read by `FieldAction` (`FieldAction.js:14-25`): `title`, `run`, `confirm`, `disabled` (default false), `visible` (true), `visibleWhenReadOnly` (false), `icon` ('image'), `quick` (false), `dangerous` (false); `visible/disabled/quick/dangerous/icon` may be functions of the payload. Payload for composable-based fieldtypes: `{ fieldPathPrefix, handle, value, config, meta, update, updateMeta, isReadOnly }` (`fieldtype.js:66-76`). A fieldtype can add its own with `defineFieldActions(actions)`. Actions only render when the field config has `actions` enabled (`components/ui/Publish/Field.vue:92-94`).

> Possible core bug to check before relying on async field actions **(inferred from source, not run)**: `FieldAction.run()` does `const progress = this.#payload.vm.$progress;` when `run` returns a Promise (`FieldAction.js:62-66`). The Options-API mixin payload includes `vm: this` (`Fieldtype.vue:88-100`), but the composable payload has it commented out (`fieldtype.js:67`: `// vm: this,`). Core fieldtypes on the composable in 6.19.0: Text, Select, Hidden, Icon, Revealer, Theme, FormattingLocales, DateIndex. Textarea, Markdown, Bard, Replicator, Grid, etc. still use the mixin. A promise-returning action on a `text` field would hit `undefined.$progress` after `run` has already been called.

**Publish-form JS hooks** — `Statamic.$hooks.on(key, callback, priority = 10)` where the callback signature is `(resolve, reject, payload)` (`CMS/resources/js/components/Hooks.js:6-12, 31-37`). Keys fired by the save pipeline: `entry.saving` / `entry.saved`, `term.saving` / `term.saved`, `global-set.saving` / `global-set.saved`, `user.saving` / `user.saved` (`components/ui/Publish/SavePipeline.js:105-138`). Entry payloads: saving `{ collection, values }`, saved `{ collection, reference, response }` (`components/entries/PublishForm.vue:559-571`). The entry save request also sends `_localized: this.localizedFields`.

**PHP hooks** — Resrv's only use: `Statamic\Tags\Collection\Collection::hook('fetched-entries', function ($entries, $next) { ...; return $next($entries); })` (`ResrvProvider.php:451-456`, `src/Traits/HandlesAvailabilityHooks.php:11-41`).

**Listing filters** — `class ReservationStatus extends Statamic\Query\Scopes\Filter` with `protected $pinned = true`, `static title()`, `fieldItems()`, `autoApply()`, `apply($query, $values)`, `badge($values)`, `visibleTo($key)` returning `$key === 'resrv'` (`RESRV/src/Filters/ReservationStatus.php`); fetched with `Scope::filters('resrv', [])`.

**Widgets** (vouchers only) — `class Vouchers extends Statamic\Widgets\Widget` with `component()` returning `VueComponent::render('resrv-vouchers-widget', [...])` (`VOUCH/src/Widgets/Vouchers.php`).

---

## 6. Testing

### 6.1 Framework and versions

- **Resrv: PHPUnit only, deliberately.** `phpunit/phpunit ^12.0 || ^13.0` (13.1.10 installed), `orchestra/testbench ^10.0 || ^11.0`, `orchestra/testbench-dusk ^10.0 || ^11.0`. Commit `add8bb29`: "T1: Add orchestra/testbench-dusk dev dependency (no Pest)"; README: "still plain PHPUnit, no Pest" (`README.md:60`).
- 129 headless test files + 11 browser test files; 1,910 `public function test_snake_case()` methods, 27 `#[Test]` attributes, 0 camelCase test names. Other attributes in use: `#[DataProvider]`, `#[Depends]`, `#[BeforeServing]`.
- Every other project by the developer uses **Pest 4**: easy-forms (`pestphp/pest ^4.1`), ai-agent (`^4.0` + `pest-plugin-laravel ^4.0`), imprint (`^4.1` + `pest-plugin-browser ^4.3`), vouchers (`^4.0` + `pest-plugin-laravel ^4.0`, on top of `AddonTestCase`).

### 6.2 Base TestCase (`RESRV/tests/TestCase.php`, 399 lines)

```php
class TestCase extends AddonTestCase            // Statamic\Testing\AddonTestCase
{
    use FakesViews;
    use PreventSavingStacheItemsToDisk;          // tests/PreventSavingStacheItemsToDisk.php wraps Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk
    use RefreshDatabase;
    use WithFaker;

    protected string $addonServiceProvider = StatamicResrvServiceProvider::class;
```

What `AddonTestCase` (`CMS/src/Testing/AddonTestCase.php`) does for you:

- `setUp()`: `withoutMix()`, `withoutVite()` (23-24); if the test class uses `PreventsSavingStacheItemsToDisk`, sets `$this->fakeStacheDirectory` to `<addon>/tests/__fixtures__/dev-null` and redirects every Stache store there (26-33); mocks `Version::get` and `CP\Nav::build` / `clearCachedUrls` (35-38).
- `getPackageProviders()`: `StatamicServiceProvider`, `\Inertia\ServiceProvider`, `$this->addonServiceProvider` (52-65).
- `getEnvironmentSetUp()`: reads `dirname(provider file)/../composer.json` — so the class in `$addonServiceProvider` must live directly in `src/` — and injects the addon into `Statamic\Addons\Manifest` with `id`, `slug`, `version => 'dev-main'`, `namespace`, `autoload`, `provider` (78-97). Sets `inertia.testing.ensure_pages_exist = false`, `inertia.testing.page_paths = [<addon>/resources/js/pages]` (99-100), `statamic.users.repository = 'file'`, `statamic.stache.watcher = false`, and every Stache store directory under `<addon>/tests/__fixtures__/content/...` and `.../users` (102-116).

What Resrv's TestCase adds:

- `setUp()` (43-73): `deleteFakeStacheDirectory()` up front to clear leftovers from interrupted runs; `resetPostgresSequences()`; `preventOutpostRequests()` (`$this->instance(Outpost::class, \Mockery::mock(Outpost::class)->shouldIgnoreMissing())`); `withoutExceptionHandling()`; and the default site:

```php
Site::setSites([
    'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
]);
```

- `getPackageProviders()` (150-157): merges parent with `LivewireServiceProvider`, the statamic-livewire provider, `RayServiceProvider`.
- `resolveApplicationConfiguration($app)` (159-210): `statamic.search.indexes = ['cp' => ['driver' => 'null']]` (search indexing was "~0.7s per save and the single largest cost in the suite"); `cache.default = array`; `session.driver = array`; `queue.default = sync`; `statamic.users.repository = file`; `statamic.stache.watcher = false`; `statamic.forms.forms`; **`statamic.editions.pro = true`**; then pushes `routes/cp.php` and `routes/web.php` via `Statamic::pushCpRoutes` / `pushWebRoutes`.
- Helpers: `signInAdmin()` (`User::make()->id(1)->email('test@test.com')->makeSuper()` then `$this->be($user)`, 225-232); `multisite($site = 'en')` → `Site::setCurrent($site)` (145-148); `makeStatamicItem(?array $data = null)` (234-250); `makeStatamicItemWithResrvAvailabilityField(?array $data = null, string $collectionHandle = 'pages')` (252-268); `ensureCollectionExists(string $handle, string $route = '/{slug}')` (287-297); `ensureCollectionWithResrvField(string $handle)` (299-321); `assertDatabaseHasJsonColumn(...)` (327-344); gateway mocks (350-378).
- A handler-leak workaround: `captureHandlerBaseline()` / `restoreHandlerBaseline()` (87-136) for PHPUnit's "did not remove its own exception handlers" error.

Content fixtures are created in code, never from files on disk (`tests/__fixtures__/` holds only `dev-null/.gitkeep`):

```php
// collection + blueprint (TestCase.php:305-318)
$collection = Collection::make($handle)->routes('/{slug}');
$collection->save();

Blueprint::make()->setContents([
    'sections' => ['main' => ['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
        ['handle' => 'slug', 'field' => ['type' => 'text', 'display' => 'Slug']],
        ['handle' => 'resrv_availability', 'field' => ['type' => 'resrv_availability', 'display' => 'Resrv Availability']],
    ]]],
])->setHandle($handle)->setNamespace('collections.'.$handle)->save();

// entry (TestCase.php:243-249)
Entry::make()->collection('pages')->slug($slug = Str::random(6))->data($data ?? $entryData)->save();
return Entry::query()->where('slug', $slug)->first();
```

Blueprints are file-backed, not Stache, so tests write them to `resources/blueprints/collections/` inside the addon; that path is git-ignored (`.gitignore:23-26, 39-41`).

### 6.3 Multi-site in tests (`RESRV/tests/Multisite/MultisiteAvailabilityTest.php:28-82`)

```php
Site::setSites([
    'en' => ['name' => 'English', 'url' => 'http://localhost/',    'locale' => 'en_US', 'lang' => 'en'],
    'el' => ['name' => 'Greek',   'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
]);
Site::setCurrent('en');

$collection = Collection::make('rooms')->routes('/{slug}')->sites(['en', 'el'])->save();

$this->originEntry = Entry::make()->collection('rooms')->locale('en')->slug('test-room')->data([...]);
$this->originEntry->save();

$this->localizedEntry = $this->originEntry->makeLocalization('el');
$this->localizedEntry->slug('test-room-el');
$this->localizedEntry->data(['title' => 'Δοκιμαστικό Δωμάτιο', ...]);
$this->localizedEntry->save();
```

Runtime multi-site idioms in `src/`: `Site::hasMultiple()`, `$entry->hasOrigin()`, `$entry->origin()`, `$entry->root()->id()` (`src/Traits/HandlesMultisiteIds.php:19-23`, `src/Fieldtypes/ResrvAvailability.php:44,53`).

### 6.4 Fakes, mocks, factories

- Fakes: `Mail::fake()` ×144, `Event::fake()` ×71, `Queue::fake()` ×1, `Bus::fake()` ×1. No `Http::fake()` (no HTTP client).
- Config overrides in tests: `Config::set('resrv-config.*', ...)` ×384.
- Mockery in 8 files; a `FakePaymentGateway` class is bound when `app()->environment('testing')` (`ResrvProvider.php:275-280`).
- Factories: 15 in `src/Database/Factories/` (namespace `Reach\StatamicResrv\Database\Factories`, shipped in the main autoload); models use `HasFactory` + `protected static function newFactory() { return XFactory::new(); }`.
- Inertia assertions: `->assertInertia(fn (AssertableInertia $page) => $page->component('resrv::DynamicPricing/Index'))` (`tests/DynamicPricing/DynamicPricingCpTest.php:36-37`); also `->where('enabled', false)`.
- Command tests: `$this->artisan('resrv:settings:migrate', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertExitCode(0)` (`tests/Console/MigrateSettingsCommandTest.php`).
- Shared trait `tests/CreatesEntries.php` (`makeStatamicItemWithAvailability(...)`, `createEntries()`, `findOrCreateCollection()`, `makeBlueprint()`).

### 6.5 DB config

`RESRV/phpunit.xml` (schema `https://schema.phpunit.de/13.1/phpunit.xsd`, `cacheDirectory=".phpunit.cache"`):

```xml
<env name="APP_ENV" value="testing"/>
<env name="APP_KEY" value="base64:..."/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="CACHE_DRIVER" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="QUEUE_DRIVER" value="sync"/>
<env name="MAIL_MAILER" value="array"/>
<ini name="memory_limit" value="4096M"/>
```

- One testsuite `Resrv` that **enumerates 24 directories explicitly** (lines 5-28). A new `tests/<Dir>/` is not run until it is added to both `phpunit.xml` and `phpunit.pgsql.xml`. `tests/Browser`, `tests/Support`, `tests/Postgres` are not listed.
- `phpunit.pgsql.xml` is identical except `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=resrv_test`, `DB_USERNAME=postgres`, `DB_PASSWORD=""` (lines 40-45). PostgreSQL is not run in CI.
- `phpunit.dusk.xml`: suite `Browser` → `tests/Browser/`; no `DB_DATABASE` (file SQLite shared between processes), `SESSION_DRIVER=file`, `CACHE_STORE=file`.

### 6.6 Browser and JS tests

- Browser: `orchestra/testbench-dusk`, `tests/Browser/BrowserTestCase.php` extends `Orchestra\Testbench\Dusk\TestCase` with `DatabaseTruncation`, `SeedsBookableContent`, `WithWorkbench`; headless by default via `DuskOptions::withoutUI()` unless `DUSK_HEADLESS_DISABLED`. It covers the public Livewire funnel only — there are no browser tests of the CP.
- JS unit tests: not found (no Vitest/Jest anywhere in Resrv, vouchers, easy-forms, ai-agent).

### 6.7 Commands

```bash
composer test                 # vendor/bin/phpunit (SQLite in-memory)
composer test:stop            # --stop-on-defect
composer test-pgsql           # needs `createdb resrv_test`
composer test-coverage
php vendor/bin/testbench dusk:chrome-driver --detect
php vendor/bin/testbench workbench:build
composer test:browser         # or test:browser:headed
vendor/bin/phpunit tests/Availability/AvailabilityTest.php
vendor/bin/phpunit --filter "testMethodName"
vendor/bin/pint
npm run cp:build              # npm run cp:dev for HMR
```

---

## 7. Tooling, CI, releases, docs

| Item | Resrv | Notes |
|---|---|---|
| Pint config | **No `pint.json`** → default `laravel` preset. `vendor/bin/pint` run by hand (several "Pint" commits). | Vouchers: `{"preset": "laravel"}`. ai-agent: `laravel` + `concat_space: none`, `not_operator_with_successor_space: false`. |
| Static analysis | Not found (no PHPStan/Larastan/Psalm/Rector). | Same in all his addons. |
| `.editorconfig` | Not found. | — |
| `declare(strict_types=1)` | 0 files in `src/`. | Return types are common in newer code; older code is untyped. |
| CI | `.github/workflows/tests.yml` and `browser-tests.yml`. | Below. |
| CHANGELOG.md | Not found. | Vouchers has one (`## Unreleased` → `### Added / Changed / Breaking`). |
| Release notes | `RELEASE-v6.0.0.md`, `RELEASE-v6.5.0.md` at repo root. | Below. |

**`tests.yml`** ("Run Tests"): on push, pull_request, daily cron. Matrix `php: [8.4, 8.5]` × `laravel: [12.*, 13.*]`, `prefer-stable`, ubuntu-latest. Skips on `[ci skip]`. Uses `actions/checkout@v1` and `tj-actions/changed-files@v41` to skip when nothing relevant changed. Pins testbench per Laravel version (`composer require "orchestra/testbench ^10.0"` for L12, `^11.0` for L13), `composer update --prefer-stable --prefer-dist`, then `vendor/bin/phpunit`. **No Pint step, no npm step, no PostgreSQL job.** The changed-files list mentions `phpunit.xml.dist`, which does not exist in the repo.

**`browser-tests.yml`**: PHP 8.4 only, `composer install` from the committed lock, `testbench dusk:chrome-driver --detect`, `testbench workbench:build`, `composer test:browser`, uploads screenshots/console/source on failure.

**Release process (observed, not documented anywhere)**: lightweight tags `vX.Y.Z` on `main` (`v6.0.0-beta1..3`, `v6.0.0`, `v6.1.0`, `v6.2.0`, `v6.2.1`, `v6.3.0`, `v6.4.0`), mostly pointing at PR merge commits. Feature work on `feature/*`, `fix/*`, `improvement/*`, `refactor/*` branches merged by PR. `gh release *` is in the Claude permission allowlist. `RELEASE-vX.Y.Z.md` is written on the feature branch and renamed as the target version moves (commits "Rename the manual-reservations release notes to v6.4.0", "Re-point ... at v6.5.0").

**RELEASE file structure**: both start `# Statamic Resrv vX.Y.Z` → one-paragraph summary → blockquote warning/migration note → `## Highlights` (emoji-titled `###` subsections with doc links). `RELEASE-v6.0.0.md` adds `## Requirements` before Highlights and ends with `## Breaking changes (summary)` + a link to the upgrade guide; `RELEASE-v6.5.0.md` ends with `## Other changes` and `## For developers`.

**Commit messages**: imperative, sentence case, no prefix, often long and specific ("Stop dropping option value descriptions on CP save"). Vouchers uses conventional-commit prefixes instead (`feat(cp): ...`, `fix(scan): ...`, `test(scan): ...`, `docs: ...`).

**Docs**: the real documentation is an external site (https://resrv.dev). In-repo: `docs/available-dates-feature.md` (one file) and root-level feature/upgrade guides (`AVAILABILITY_COLLECTION.md`, `CART-BASED-FLOW.md`, `UPGRADE_TO_RATES.md`, `UPGRADE-PAYMENT-GATEWAYS.md` — the last two say they are "written for both humans and LLMs").

**README structure** (`RESRV/README.md`, 109 lines): title + logo → intro → `## Documentation` → `## Requirements` → `## Features` (bullets) → `## Testing` (headless suite, `### Browser tests`, gotchas, a "what is and isn't browser-tested" table) → `## License` → `## Issues and pull requests`. Vouchers' README: Requirements, How it works, Installation, Configuration, Email customization, CP usage, Voucher lifecycle, Troubleshooting, Developer reference, Tests, License.

**Migrations**: `database/migrations/` (71 files), anonymous classes, `YYYY_MM_DD_000000_description.php`, tables prefixed `resrv_`, loaded with `loadMigrationsFrom`. Rule from the old CLAUDE.md: "Migrations must work across SQLite (testing), MySQL/MariaDB, and PostgreSQL."

---

## 8. AI-dev tooling

### 8.1 What is in the Resrv repo today

| Item | State |
|---|---|
| `composer.json` | `"laravel/boost": "^2.1"` in require-dev (line 41). The only tracked mention of Boost in the repo. |
| `boost.json` | Not found. |
| `.mcp.json` | Not on disk; listed in `.gitignore:12`. Existed in git from `e6373f43` (2026-02-10) to `bac7960f` (2026-03-24). |
| `CLAUDE.md` | Not on disk; listed in `.gitignore:30`. Existed in git from `add8bb29` (2026-06-30) to `fe224cc5` "Clean up" (2026-07-15). |
| `AGENTS.md` | Not found. |
| `.ai/`, `resources/boost/` | Not found. |
| `.claude/` | Untracked. Contains `settings.local.json` (permission allowlist; `"enableAllProjectMcpServers": true`, `"enabledMcpjsonServers": ["laravel-boost"]`; allows `mcp__laravel-boost__application-info`, `database-schema`, `search-docs`, and `mcp__context7__*`) and `scheduled_tasks.lock`. No skills, no agents, no commands. |
| Other ignored AI files | `.gitignore:13-14, 31-37`: `ISSUES.md`, `PLAN.md`, `maintain-prompt.md`, `loop-prompt.md`, `replace-resetonboot.md`, `browser-testing.md`, `REPORT.md`, `TASKS.md`, `claude-loop.sh`, `.loop-logs/`. None are on disk. `README.md:85` still links to the ignored `browser-testing.md`. |

The deleted `.mcp.json` was:

```json
{ "mcpServers": { "laravel-boost": { "command": "./vendor/bin/testbench", "args": ["boost:mcp"] } } }
```

`BoostServiceProvider::shouldRun()` returns false when `app()->runningUnitTests()` or when the env is not `local` and `app.debug` is not true (`BOOST/src/BoostServiceProvider.php:185-201`). Resrv's current `testbench.yaml` sets `APP_ENV=testing`, so `vendor/bin/testbench boost:mcp` would not start Boost there **(inferred)**. Vouchers solves it the other way: its `testbench.yaml` has `APP_ENV: local`, `APP_DEBUG: true`, `workbench.install: false`, and its CLAUDE.md documents `vendor/bin/testbench boost:mcp` ("point Claude Code's MCP client at that command with `cwd` set to the addon root. Boost skips itself when `app()->runningUnitTests()`, so PEST runs are unaffected", `VOUCH/CLAUDE.md:79`). Those two testbench.yaml files serve different purposes (browser-test host vs. Boost host) and conflict on `APP_ENV`.

### 8.2 Conventions an agent was expected to follow in Resrv (last committed `CLAUDE.md`, `git show fe224cc5^:CLAUDE.md`, 306 lines)

Hand-written part (lines 1-185) — headings: Project Overview, Commands, Architecture (Bootstrap, Event-Driven Reservation Lifecycle, hooks for sibling addons, ...), Testing, Database Compatibility, Configuration, Frontend Stack. Quotes that are rules rather than description:

- "Run artisan commands (package context — no php artisan available): `vendor/bin/testbench <command>`"
- "The reservation lifecycle is fully event-driven. **All side effects happen through listeners**, not in controllers"
- "Listeners that mutate the mailable **must be synchronous** (do not `implements ShouldQueue`)"
- "**Traits over inheritance** — Cross-cutting concerns ... are composed via traits in `src/Traits/`"
- "Migrations must work across SQLite (testing), MySQL/MariaDB, and PostgreSQL."
- "All config is read through `config('resrv-config.*')` ... One owner per key"
- "`settings()->raw()` is used deliberately — `all()`/`get()` stringify booleans/integers via Antlers."
- "Tests override values with post-boot `Config::set('resrv-config.*', ...)`"

Boost-generated part (lines 189-306, inside `<laravel-boost-guidelines>`), containing only `foundation`, `boost` and `php` rules. Relevant lines:

- "You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming."
- "Do not create verification scripts or tinker when tests cover that functionality"
- "Stick to existing directory structure; don't create new base folders without approval." / "Do not change the application's dependencies without approval."
- "If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run cp:build` (admin) or `npm run frontend:build` (frontend). Ask them."
- "You must only create documentation files if explicitly requested by the user."
- "Always use curly braces for control structures", "Use PHP 8 constructor property promotion", "Always use explicit return type declarations", enum keys TitleCase, "Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex."

Vouchers' rules (`VOUCH/CLAUDE.md:81-89`, tracked in git) are the more recent statement:

- "Run `vendor/bin/pest` after every task that touches `src/` or `tests/`."
- "Run `vendor/bin/pint` before considering a task complete."
- "`resources/dist/build` is **committed** ... run `npm run cp:build` and commit the fresh dist alongside."
- "**PHPDoc / comments:** none, unless the WHY is non-obvious. Follow Resrv's existing style."
- "Commits: small, atomic, conventional-commit-ish. One phase ≈ one or two commits. Update `tasks.md` (checkbox + done-log line) when finishing a task, then commit."
- "**[tasks.md](tasks.md) is the source of truth for everything in this repo** ... Each task has a checkbox; flip `[ ]` → `[x]` when done and append a one-line note to the 'Done log' at the bottom."

`VOUCH/tasks.md` structure (the developer's own format for a detailed task list): `## Project at a glance` → `## Current build state (as of <date>)` → `## Decisions reference (do not re-litigate without user input)` (two-column table) → `## Cross-cutting agent rules` (numbered) → `## Phase N — <name>` with tasks as `- [ ] **T2.1 <title>**` + sub-bullets + `**Self-check:** ...` → `## Acceptance criteria (treat as the gate)` → `## Out of scope (do not implement without re-asking the user)` → `## Done log (append-only — one line per completed task)` with entries like `T1.1 done 2026-05-15: ...`. Rule 7 there: "PHP 8.3+. Use constructor property promotion, return types, readonly where natural."

### 8.3 How a third-party package ships Boost guidelines and skills (from Boost 2.4.6 source)

`BOOST/README.md` contains no documentation; it points to https://laravel.com/docs/boost. The mechanism below is read from the code.

**Discovery** (`BOOST/src/Support/Composer.php`):

- `Composer::packages()` reads the **consuming app's root `composer.json`** `require` + `require-dev` (lines 45-63). Only direct dependencies count.
- For each, `vendor/<vendor>/<package>/resources/boost/guidelines` and `.../resources/boost/skills` are checked with `is_dir` (78-89).
- First-party = `laravel/*` plus `livewire/livewire`, `livewire/flux`, `livewire/flux-pro`, `livewire/volt`, `inertiajs/inertia-laravel`, `pestphp/pest`, `phpunit/phpunit` (10-23). Everything else (including `statamic/cms` and any `reachweb/*` addon) is third-party.
- `ThirdPartyPackage::discover()` builds the list shown in the installer as `"<name> (guidelines, skills)"` (`BOOST/src/Install/ThirdPartyPackage.php:25-59`).

**Guidelines**: `resources/boost/guidelines/*.blade.php` or `*.md` (directories named `skill` are excluded; `GuidelineComposer::guidelinesDir()`, `BOOST/src/Install/GuidelineComposer.php:254-275`). They are stored with `$guidelines->put($package, $guideline)` inside the loop over files (228-241), so **only one file per package survives — the last by name** **(inferred)**. Use a single `resources/boost/guidelines/core.blade.php`. The content is appended to the agent's guideline file under the heading `=== <vendor/package> rules ===` (line 77).

**Skills**: each sub-directory of `resources/boost/skills/` containing `SKILL.md` or `SKILL.blade.php` (`SkillComposer::discoverSkillsFromDirectory()` and `findSkillFile()`, `BOOST/src/Install/SkillComposer.php:173-227`). YAML front matter with non-empty `name` and `description` is mandatory, otherwise the skill is ignored (201-205). The whole directory is copied to the agent's skills path; `.blade.php` files are rendered and written as `.md` (`BOOST/src/Install/SkillWriter.php:226-256`). Skill names may not contain `..`, `/` or `\` (335-340).

**Blade rendering** (`BOOST/src/Concerns/RendersBladeGuidelines.php`): `.blade.php` files go through `Blade::render` with an `$assist` variable (`GuidelineAssist`). Backticks, `<?php`, `@volt` and `<x-` are protected (25-32). `@boostsnippet("name", "lang") ... @endboostsnippet` becomes a fenced block (45-58). **`{{ ... }}` is not protected**: easy-forms needed commit `f15d544` "Fix backtick issue with Laravel Boost" to change `` `{{ easyform }}` `` to `` `@{{ easyform }}` ``; its other examples sit in `@verbatim ... @endverbatim`. A translator addon's guidelines will contain Antlers and Vue `{{ }}`.

**Selection and output in the consuming app**:

- `php artisan boost:install {--guidelines} {--skills} {--mcp}` asks "Which third-party AI guidelines/skills would you like to install?" (`BOOST/src/Console/InstallCommand.php:42-45, 215-234`). The choice is stored in `boost.json` under `packages` (`BOOST/src/Support/Config.php:57-68`); third-party content is filtered to that list (`GuidelineComposer.php:242-248`, `SkillComposer.php:100-106`).
- `boost.json` keys: `agents`, `guidelines`, `skills`, `mcp`, `packages`, `sail`, `cloud`, `nightwatch` (`Config.php`).
- `php artisan boost:update {--discover} {--ignore-skills}`; `--discover` offers packages not yet in `boost.json` (`BOOST/src/Console/UpdateCommand.php:19-21, 53-73`).
- Other commands: `boost:mcp`, `boost:add-skill {repo?} {--list} {--all}`, `boost:list-skills`, `boost:execute-tool {tool} {arguments}`.
- Claude Code targets: `.mcp.json`, `CLAUDE.md`, `.claude/skills` (`BOOST/src/Install/Agents/ClaudeCode.php:50-63`). Guidelines are written between `<laravel-boost-guidelines>` tags; hand-written content above them is kept.
- App-level overrides live in `.ai/guidelines/` and `.ai/skills/` (`GuidelineComposer.php:23`, `SkillComposer.php:121-133`).

**Working example by the same developer**: `EASY/resources/boost/guidelines/core.blade.php` (82 lines, tracked). The host demo site's `boost.json` has `"packages": ["reachweb/statamic-easy-forms", "statamic/cms"]` and its `CLAUDE.md` contains `=== reachweb/statamic-easy-forms rules ===` (line 397) and `=== statamic/cms rules ===` (line 472). Structure of that guideline file: `## Easy Forms` → one-paragraph description → "Full documentation: https://easy-forms.dev/llms-full.txt" → Basic Usage → Tag Parameters → Supported Field Types → Features → Examples (`<code-snippet name="..." lang="antlers">` inside `@verbatim`) → File Structure → Namespace. It ships no skills.

### 8.4 Does statamic/cms ship Boost content?

- Yes, guidelines only: `CMS/resources/boost/guidelines/core.blade.php` (98 lines). No `resources/boost/skills`, no `.ai/` directory.
- Content: what Statamic is, folder structure, the `please` CLI, core concepts, templating, Control Panel, extending. The addon-relevant lines (88-97): "The Control Panel is built with Inertia.js and Vue 3." / "When running the `make:fieldtype` or `make:widget` commands, Statamic will install the necessary npm packages and configure Vite." / "Running `setup-cp-vite` in an application context will also do this for you. You'll be able to use `npm run cp:dev` and `npm run cp:build`" / "You should use Statamic's UI Components where possible ... UI Components can be imported from `@statamic/cms/ui`" / Storybook https://ui.statamic.dev / "Statamic Documentation: https://statamic.dev/llms.txt".
- `CMS/CLAUDE.md` also exists (core contributors' file). It documents how the `@statamic/cms` npm package resolves everything from `window.__STATAMIC__` and the localisation rule quoted in §5.4.

---

## 9. Gotchas flagged by the v5→v6 skill

The skill (`SKILL/`, single commit, 2026-05-16) drives an 8-phase upgrade. Its `statamic-upgrade-skill/` sub-directory is an identical copy. Every gotcha it states, grouped as requested; `[R]` marks items that matter for a multi-site translator.

**Service provider** (`references/03-service-provider.md`)

1. The hook is `bootAddon()`, not `boot()`. "Overriding `boot()` silently disables auto-discovery."
2. `$vite` values must match the Vite inputs. `hotFile` depth: from `src/ServiceProvider.php` it is `__DIR__.'/../resources/dist/hot'`, from `src/Providers/Foo.php` it is `__DIR__.'/../../resources/dist/hot'` — "wrong path silently disables HMR".
3. Removed/renamed: `Site::setConfig([...])` → `Sites::setSites([...])`; `NavItem::active($regex)` removed; `Entry::addLocalization($site)` → `Entry::makeLocalization($site)` `[R]`; `ApiController::filterSortAndPaginate($q)` → `updateAndPaginate($q)`; `cache.default = 'statamic'` no longer exists.
4. Auto-discovery folders: `src/Tags`, `src/Modifiers`, `src/Fieldtypes`, `src/Widgets`, `src/Scopes`, `src/Console/Commands`, `src/Listeners`.
5. Missing nav item: usually the route name does not match `->route(...)`, or `protected $routes` is missing. "Inside `routes/cp.php`, **always** chain `->name(...)`".
6. Permission hierarchy: "granting a child implies the parent in v6's permission UI."

**Vite / Tailwind 4** (`02-composer-and-deps.md`, `04-vite-and-tailwind.md`)

7. Plugin order matters: `statamic()` first, `tailwindcss()` second, `laravel()` last.
8. `vite ^8` "is the floor for the new `@statamic/cms/vite-plugin`"; `"type": "module"` is required.
9. `@statamic/cms` is a `file:` dependency on `vendor/statamic/cms/resources/dist-package`; `composer install` must have run first, otherwise "Cannot find module '@statamic/cms/vite-plugin'".
10. Scripts are named `cp:dev` / `cp:build` to leave room for `frontend:*`.
11. Tailwind v4 is import-driven; delete `tailwind.config.js`, `postcss.config.js`, `webpack.mix.js`, Babel config; custom tokens go in `@theme`.
12. "Tailwind classes silently missing — Tailwind v4 scans differently."
13. Optional prebuilt dist through `pixelfear/composer-dist-plugin` + `extra.download-dist` ("Skip it for now if the user hasn't asked").

**Vue 3** (`05-vue3-migration.md`)

14. `<script setup>` by default; `this.foo` → `foo.value` / `props.foo`; `$emit` → `defineEmits`.
15. `beforeDestroy` → `onBeforeUnmount`, `destroyed` → `onUnmounted`; watchers: `immediate` must be explicit; mixins → composables.
16. Removed: `Vue.extend`, `$listeners`, `$on/$off/$once`, filters, `Vue.component`, `slot=`/`slot-scope=`.
17. `Statamic.$components.register(...)` "**must** live inside `Statamic.booting()`"; "Registering before that point fails silently." One `booting()` block at the bottom of `cp.js`.
18. Library replacements: `vue-select@4`, `vuedraggable@4`, `@fullcalendar/vue3`.
19. Symptoms: blank component → registration not inside `booting()`; "Cannot read property of undefined (reading 'value')" → missing `.value`.

**Fieldtypes** (`06-fieldtype-migration.md`)

20. Pass `Fieldtype.props` / `Fieldtype.emits` straight into `defineProps` / `defineEmits`; use `Fieldtype.use(emit, props)`.
21. "**Always** call `defineExpose(expose)`. Forgetting it is the #1 cause of 'my fieldtype saves nothing'."
22. `watch(localValue, …, { deep: true })` for arrays/objects.
23. "Always dirty → calling `update()` synchronously on mount."
24. Component name = snake_case handle + `-fieldtype`.
25. Custom icon sets need `Statamic\Icons\Icon::register('<set>', <dir>)`.

**Inertia / UI components** (`07-cp-pages-inertia.md`)

26. Blade CP pages still work but give full reloads and no HMR; native path is `Inertia::render('<addon>::<Dir>/<Name>', [...])` + `Statamic.$inertia.register` with the identical string. Mismatch → blank page, "no component registered for X".
27. `<Listing>` needs a JSON endpoint in Statamic's listing format `{data: [...], meta: {...}}`; `Inertia::render` returns only shell metadata.
28. `PublishForm::make($blueprint)->title()->values()->submittingTo()` returns an Inertia response itself; settings pages add `->asConfig()`. **The skill's namespace is wrong for 6.19.0**: it writes `Statamic\CP\Publish\PublishForm` (`references/07-cp-pages-inertia.md`, `templates/SettingsController.php.tpl:7`), but the only class is `Statamic\CP\PublishForm` (`CMS/src/CP/PublishForm.php`): `static make(Blueprint $blueprint): self` 24, `icon(string $icon): self` 29, `title(string $title): self` 36, `values(array $values): self` 43, `parent($parent): self` 50, `readOnly(bool $readOnly = true): self` 57, `submittingTo(string $url, string $method = 'PATCH'): self` 64, `asConfig()` 72, `submit(array $values): array` 79, `toResponse($request)` 92.
29. Use components from `@statamic/cms/ui`; Storybook at https://ui.statamic.dev.

**Settings** (`08-userconfig-pattern.md`)

30. Replace `edalzell/forma` with `UserConfig.php` (YAML at `resources/<addon>.yaml`, Blink-cached) + `SettingsController` + `resources/blueprints/config.yaml` + `$nav->settings('<Addon>')`. Keep `config/<addon>.php` for developer-only values.

**API changes** (`09-api-breaking-changes.md`)

31. `GlobalSetSaving/Saved/Created` → `GlobalVariablesSaving/Saved/Created` `[R]` (globals are per-locale variables).
32. `Entry::query()->where('status', ...)` "throws in v6" → `->whereStatus(...)`.
33. `makeLocalization()` "Returns the new localised entry; persist with `->save()`" `[R]`.
34. `'searchables' => 'all'` removed.
35. Link fieldtype values are now objects `{ url, title }` `[R]`.
36. `{{ relate }}` tag gone; wildcard tag parameters are literals.
37. Bard strike emits `<strike>` instead of `<s>` `[R]`.
38. `urlencode` / `rawurlencode` modifiers now encode slashes.
39. "Super users no longer bypass every permission check" (host can restore with `Gate::before`). Not verified against core here.
40. Carbon 3: diffs return floats and can be negative.
41. Dates stored in app timezone; REST/GraphQL return UTC; `localize_dates_in_modifiers`.
42. `section` fieldtype deprecated in favour of blueprint sections `[R]` (matters when walking blueprint fields).
43. Bard `inline: break` → `inline: true` + `inline_hard_breaks: true` `[R]`.
44. `@include('statamic::partials.docs-callout')` → `<ui-docs-callout>`.

**Testing** (`10-testing.md`)

45. Base class → `Statamic\Testing\AddonTestCase` with `protected string $addonServiceProvider`; drop manual provider registration.
46. "`phpunit.xml` and `pest.xml` shouldn't both exist."
47. "Cannot find Vite manifest" → build once or stub Vite (core's `AddonTestCase` already calls `withoutVite()`).

**Process** (`SKILL.md`, `00-workflow.md`)

48. PHP 8.3 / Laravel 12 minimums. One phase per commit, each gated by a verification step. Livewire front-end is out of scope. "Do **not** open a PR, push, or merge unprompted."

### 9.1 Where the skill is out of date compared with current Resrv

| Topic | Skill says | Resrv / core 6.19.0 actually |
|---|---|---|
| Settings | `UserConfig.php` + own `SettingsController`, YAML at `resources/<addon>.yaml` | Native addon settings: `resources/blueprints/settings.yaml` + `registerSettingsBlueprint()`, YAML at `resources/addons/<slug>.yaml`, core controller and routes (§2.4). No `UserConfig`, no `SettingsController` in Resrv. |
| Tailwind entry | `@import "tailwindcss";` | `@import "@statamic/cms/tailwind.css";` + `@source` lines (§3.5) |
| Provider classes | Root provider extends `AddonServiceProvider`; sub-providers can stay plain | Root is `AggregateServiceProvider`; both sub-providers extend `AddonServiceProvider` |
| Permissions | `Permission::group(...)` called directly in `bootAddon()` | Wrapped in `Permission::extend(function () { ... })` |
| Nav | `->section('Tools')`, `$nav->settings('Resrv')->route('resrv.settings')` | Own section `'Resrv'`; settings item is `->can('configure addons')->url($addon->settingsUrl())`. `$nav->settings(...)`/`$nav->tools(...)` are magic calls → `findOrCreate(Str::studlyToTitle($name), $arguments[0])` (`CMS/src/CP/Navigation/Nav.php:185-188`). |
| Listeners | "paired with `$listen` in provider" | Core auto-discovers listeners by reflection for a root provider (§2.2) |
| Versions | PHP `^8.3`, PHPUnit `^11 || ^12` | PHP `^8.4`, PHPUnit `^12 || ^13`, `statamic/cms ^6.11` |
| Config file name | Rename to `config/resrv.php` | Still `config/config.php`, key `resrv-config` |
| PublishForm class | `Statamic\CP\Publish\PublishForm` | `Statamic\CP\PublishForm` (the skill's namespace does not exist in 6.19.0) |

### 9.2 Gotchas found in the code that the skill does not mention

- `Inertia\ServiceProvider` must be registered when booting Statamic 6 outside `AddonTestCase` (`testbench.yaml:5`).
- A root package is never in `vendor/composer/installed.json`, so Statamic does not discover the addon in a workbench; inject a `Manifest` entry (`workbench/app/Providers/WorkbenchServiceProvider.php:47-88`).
- Testbench's CLI `migrate-fresh` does not see `loadMigrationsFrom`; list the directory under `migrations:` (`testbench.yaml:21-23`).
- Laravel 13 `cache.serializable_classes`: allow-list cached classes with `registerSerializableClasses([...])` in `register()`, and prefer not caching objects at all (`ResrvProvider.php:458-473`, `CMS/.../AddonServiceProvider.php:720-729`, `RELEASE-v6.0.0.md:59-62`).
- Custom CP route: never hardcode `/cp/...` (commit `552f30ad`, `tests/Utilities/CpRouteConfigTest.php`).
- `User::current()` is null in queued jobs; capture the actor at dispatch (§5.2).
- "A throwing listener halts the rest of the chain", so order listeners by importance (`ResrvProvider.php:173-176`).
- Test hygiene: stale Stache files from interrupted runs (`TestCase.php:49-55`); search indexing cost (163-174); Outpost HTTP calls (138-143); blueprints written to `resources/blueprints/collections/` by tests.
- When an addon depends on another addon in tests, the other addon must be in the `Manifest` under its real package id, or settings-blueprint defaults silently do not merge (`VOUCH/CLAUDE.md:77`).

---

## 10. Cross-check: easy-forms and imprint (plus the two bonus addons)

**Are they newer v6-native addons?**

- `easy-forms`: the directory is a demo host site; the addon inside it is newer than Resrv (first commit 2025-11-19, v2.1.0) but **not v6-native** — it supports `statamic/cms ^5.0 | ^6.0` and is front-end only (one Antlers tag, Alpine, no CP JavaScript, no Inertia, no Vue). It says nothing about CP conventions.
- `imprint`: **not a Statamic addon**. A Laravel 12 + Inertia v2 + Vue 3 Shopify app. It is the most recently active repo (last commit 2026-10-01), so it shows current general habits, not addon structure.

| Convention | Resrv | easy-forms addon | imprint (app) | vouchers (bonus) | ai-agent (bonus) |
|---|---|---|---|---|---|
| Last commit | 2026-09-09 | 2026-06-26 | 2026-10-01 | 2026-07-02 | 2025-12-12 |
| statamic/cms | `^6.11` | `^5.0 \| ^6.0` | — | `^6.0` | `^6.0` |
| php | `^8.4` | not set | `^8.2` | `^8.3` | `^8.2` |
| Provider | Aggregate root + `src/Providers/ResrvProvider` | single `src/ServiceProvider.php` | — | Aggregate root + `src/Providers/VouchersProvider` | single `src/ServiceProvider.php` |
| Tests | PHPUnit 12/13, "no Pest" | Pest `^4.1` | Pest `^4.1` + browser plugin | Pest `^4.0` + plugin-laravel | Pest `^4.0` + plugin-laravel |
| Test base | `AddonTestCase` | `AddonTestCase` (4-line TestCase) | Laravel TestCase | `AddonTestCase` | `AddonTestCase` (4-line TestCase) |
| testbench | `^10.0 \|\| ^11.0` | `^10.0` | — | `^10.0 \|\| ^11.0` | `^9.2 \|\| ^10.0` |
| `pint.json` | none | none | none found | `{"preset":"laravel"}` | laravel + 2 rules |
| Settings | native addon settings + dev config | plain `config/easy-forms.php` | — | plain `config/resrv-vouchers.php` | `UserConfig.php` |
| CP JS entry | `resources/js/cp.js` | none | `resources/js/app.js` | `resources/js/cp.js` | `resources/js/addon.js` |
| Vite | 8, `laravel-vite-plugin ^3` | 7 (lib/IIFE build) | 7, plugin `^2` | 8, plugin `^3` | 7, plugin `^2` |
| Built assets committed | yes (`resources/dist/build`) | yes (`dist/`) | no (`/public/build` ignored, CI builds) | yes | yes |
| `composer.lock` committed | yes | no (ignored) | yes | yes | no (ignored) |
| Boost | `^2.1` dev-dep, no config files | ships `resources/boost/guidelines/core.blade.php`; Boost installed in the host app | `^2.0`, `boost.json` agents `claude_code` + `codex` | `^2.1` dev-dep via `testbench boost:mcp` | none |
| CLAUDE.md | git-ignored, deleted | on disk, untracked | tracked (+ `AGENTS.md`) | tracked | tracked |
| Changelog | `RELEASE-vX.Y.Z.md` | none | `UPGRADE.md` ("Unreleased" sections, mandatory per change) | `CHANGELOG.md` | none |
| Task list file | ignored `TASKS.md`/`PLAN.md` | — | tracked `TASKS.md` | tracked `tasks.md` | tracked `TASKS.md` + `SPEC.md` |
| CI | PHP 8.4/8.5 × L12/13, `checkout@v1` | include-matrix, `fail-fast: false`, `checkout@v4`, `vendor/bin/pest` | Pest + Playwright jobs, builds assets | none | include-matrix, Pest |

**Where they differ from Resrv, and which looks like the latest preference**

- **Test framework**: Pest 4 on `Statamic\Testing\AddonTestCase` everywhere except Resrv, including the addon built to mirror Resrv. Resrv's PHPUnit looks like legacy weight (1,900 existing tests), not a preference. This is the clearest divergence.
- **Provider shape**: split. The two Resrv-family addons use the aggregate + `src/Providers/` shape; the two standalone addons use a single root `ServiceProvider`. No single "latest" answer; "mirror Resrv" points to the aggregate shape, at the cost described in §2.2.
- **Settings**: the native settings blueprint in Resrv (2026-05-16 onward) is newer than ai-agent's `UserConfig` (2025-12) and is the only CP-settings implementation he has shipped on Statamic 6 core APIs.
- **AI tooling**: outside Resrv he commits `CLAUDE.md` (vouchers, ai-agent, imprint) and a task file. Resrv is the outlier in ignoring them. imprint adds `AGENTS.md`, `.agents/skills`, `.codex/config.toml`.
- **Change log**: imprint's rule is the strictest and newest: "**Always** update `UPGRADE.md` in the same change whenever you add or modify a migration, an `.env`/config key ..." (`imprint/CLAUDE.md:78-84`). Vouchers uses `CHANGELOG.md` with an `Unreleased` section.
- **Commit style**: Resrv and imprint use plain imperative sentences; vouchers uses conventional-commit prefixes.
- **Lang files**: imprint uses JSON (`lang/{en,fr,de,es,it,ja,pt-BR}.json`) with a `TranslationCompletenessTest`; Resrv uses PHP array files for front-end strings and bare English keys in the CP.

---

## 11. Conventions to mirror in the new addon — checklist

Identity and composer

- [ ] Package `reachweb/statamic-<name>`, namespace `Reach\Statamic<Name>\` → `src`, tests `Reach\Statamic<Name>\Tests\` → `tests`.
- [ ] `"type": "statamic-addon"`, `"license": "proprietary"`, author block as in Resrv, `support.email`.
- [ ] `extra.statamic.{name,description}` and `extra.laravel.providers` with one class; no `slug` (derive from package name), no `editions`.
- [ ] `config.allow-plugins.pixelfear/composer-dist-plugin: true` (plus `pestphp/pest-plugin` if Pest).
- [ ] `require`: `php`, `statamic/cms`, `laravel/framework ^12.0 || ^13.0` stated explicitly; `require-dev`: testbench `^10.0 || ^11.0`, `laravel/pint`, `laravel/boost ^2.1`.
- [ ] Composer scripts `test` and `test:stop`.

Service provider

- [ ] Use `bootAddon()`, never `boot()`; call `parent::register()` in `register()`.
- [ ] `protected $routes`, `$vite` (`resources/js/cp.js`, `resources/css/cp.css`, `publicDirectory => 'resources/dist'`, correct `hotFile` depth), `$commands`, `$listen`, `$fieldtypes`, `$scopes`, `$updateScripts` as explicit arrays.
- [ ] Permissions inside `Permission::extend()` → `Permission::group('<slug>', '<Label>', ...)` → `Permission::register('use <name>', fn ($p) => $p->label(...)->description(...))`.
- [ ] Nav inside `Nav::extend()` with `->section(...)->can(...)->route(...)->icon(...)`.
- [ ] If the provider is not in `src/`: load translations, views, migrations, config and the settings blueprint by hand, as Resrv does.

Settings

- [ ] User-facing settings in `resources/blueprints/settings.yaml` (native addon settings); secrets and class references in a small `config/*.php` with `env()`.
- [ ] Read through one config namespace merged at boot (blueprint defaults → config → `$addon->settings()->raw()`); never `settings()->all()` for typed values.
- [ ] Pin blueprint defaults with a test.

CP frontend

- [ ] `package.json`: `"type": "module"`, scripts `cp:dev` / `cp:build`, `vite ^8.0.0`, `laravel-vite-plugin ^3.0.0`, `@tailwindcss/vite ^4.0.0`, `tailwindcss ^4.0.0`, `@statamic/cms` as a `file:` dependency.
- [ ] `vite.config.js` with `statamic()`, `tailwindcss()`, `laravel({...})` in that order.
- [ ] `cp.css` starts with `@import "@statamic/cms/tailwind.css";` + `@source` lines.
- [ ] One `Statamic.booting()` block in `cp.js`; pages as `<addon>::<Dir>/<Name>` in `resources/js/pages/<Dir>/<Name>.vue`; fieldtypes as `<handle>-fieldtype`.
- [ ] `<script setup>` only; UI from `@statamic/cms/ui`; `Head`/`useForm`/`router` from `@statamic/cms/inertia`; shared logic in `resources/js/composables/use*.js`.
- [ ] Every UI string through `__()`; every URL through `cp_route()` props or `cp_url()`.
- [ ] Commit `resources/dist/build`; ignore only `resources/dist/hot`; rebuild and commit dist with every JS/CSS change.

Controllers and routes

- [ ] `routes/cp.php` as one group: `->name('<addon>.')->middleware('can:<permission>')`; every route named.
- [ ] `indexCp()` returns `Inertia::render(...)` with URLs as props; `index()` returns JSON; mutations return `back()` for Inertia requests.
- [ ] Validation in Form Requests under `src/Http/Requests`.

Back-end patterns

- [ ] Side effects in listeners; listeners stay thin and dispatch jobs.
- [ ] Jobs: `ShouldQueue` + the four standard traits, a `failed()` method, actor captured at dispatch.
- [ ] Commands: `<prefix>:<verb>` signatures, `RunsInPlease`, `--dry-run` where data changes, `handle(): int`.
- [ ] Short-TTL `Cache::remember` with explicit `Cache::forget` on the relevant Statamic events; cache scalars/arrays, not objects.
- [ ] Multi-site: resolve to the origin with `hasOrigin()` / `origin()` / `root()`; create localizations with `makeLocalization($site)` then `save()`.
- [ ] Enums in `src/Enums` with TitleCase cases and string values; traits in `src/Traits`; services in `src/Support`.

Testing

- [ ] `tests/TestCase.php` extends `Statamic\Testing\AddonTestCase` with `$addonServiceProvider`, `PreventsSavingStacheItemsToDisk`, `Site::setSites([...])` in `setUp()`, `statamic.editions.pro = true`, null search index, mocked Outpost.
- [ ] `tests/__fixtures__/dev-null/.gitkeep`; build collections, blueprints and entries in code.
- [ ] Helpers `signInAdmin()`, `signInWithPermissions()`, `ensureCollectionExists()`, a multi-site entry builder.
- [ ] `phpunit.xml` with SQLite `:memory:`, array cache/session/mail, sync queue.
- [ ] Inertia pages asserted with `assertInertia(fn (AssertableInertia $page) => $page->component('...'))`.

Tooling and docs

- [ ] `vendor/bin/pint` before finishing any task (default `laravel` preset).
- [ ] GitHub Actions `tests.yml`: PHP 8.4/8.5, Laravel 12/13 matrix with matching testbench pins, `[ci skip]` guard.
- [ ] Tags `vX.Y.Z` on `main`; release notes or changelog updated in the same change.
- [ ] README: Requirements, Features, Installation/Configuration, Testing, License.

AI-dev tooling

- [ ] `CLAUDE.md` with Project Overview, Commands, Architecture, Testing, conventions.
- [ ] `testbench.yaml` with `APP_ENV: local`, `APP_DEBUG: true` so `vendor/bin/testbench boost:mcp` runs.
- [ ] Ship `resources/boost/guidelines/core.blade.php` (single file, `@verbatim` around `{{ }}`), optionally `resources/boost/skills/<skill>/SKILL.md` with `name` + `description` front matter.
- [ ] Task list in the `VOUCH/tasks.md` format (decisions table, cross-cutting rules, phased `T<n>.<m>` tasks with self-checks, done log).

---

## 12. Decisions the planner has to make (the sources disagree)

1. **PHPUnit or Pest.** Resrv is PHPUnit by explicit choice; every newer project, including the Resrv-mirroring vouchers addon, is Pest 4 on `AddonTestCase`.
2. **Provider location.** Aggregate + `src/Providers/` (Resrv, vouchers) means manual wiring of config, translations, views and the settings blueprint. A single root `src/ServiceProvider.php` (easy-forms, ai-agent) gets all of that automatically.
3. **Commit `CLAUDE.md` and the task file, or ignore them.** Resrv ignores; vouchers, ai-agent and imprint commit.
4. **PHP floor.** Resrv `^8.4`; vouchers `^8.3`; laravel/ai 0.9.1 `^8.3`.
5. **One `testbench.yaml`, two needs.** A served workbench wants `APP_ENV=testing` (Resrv's comment about `CheckComposerJsonScripts`); Boost wants `local`. Vouchers chose Boost and has no served workbench.
6. **Release notes format.** `RELEASE-vX.Y.Z.md` (Resrv), `CHANGELOG.md` (vouchers) or `UPGRADE.md` (imprint).
7. **CP string keys.** Bare English keys (Resrv practice) or namespaced keys in `lang/en/*.php` for longer strings (core's stated rule). For an addon whose subject is translation this choice is visible to users.
