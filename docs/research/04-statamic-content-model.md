# 04 — Statamic 6 content model: verified PHP-side facts

Research date: 2026-10-01. Read-only research for the planned AI-translation addon.

## 0. Sources, method, conventions

| Tag | Meaning |
|---|---|
| **[S]** | Read in the Statamic **6.19.0** source at `/Volumes/1TB/Sites/umami/vendor/statamic/cms/` (version confirmed in `vendor/composer/installed.json`: `"version": "v6.19.0"`). All `src/...`, `config/...`, `routes/...`, `resources/js/...` paths below are relative to that directory. |
| **[D]** | Cross-checked against the official docs on statamic.dev (fetched 2026-10-01). |
| **[T]** | Observed by running a small standalone `php -r` script against `vendor/autoload.php` (tiptap-php 2.1.0 + Statamic's Bard node/mark classes). The Statamic application was **never booted** (booting can write Stache/cache files, which would violate the read-only constraint). |
| **[E]** | Read in `statamic/eloquent-driver` **master on GitHub** (composer.json requires `statamic/cms: ^6.0.0`; latest tags v5.12.0 / v5.12.1). Not installed in the umami project. A local checkout at `/Volumes/1TB/Sites/eloquent-driver` is v4.22 (Statamic 5) and was only used as a cross-check. |
| **[NV]** | Could not verify. |

Caveats that apply to the whole document:

- `/Volumes/1TB/Sites/umami` is a **single-site** project (`config/statamic/system.php:33` `'multisite' => false`, no `resources/sites.yaml`, no globals/navs/taxonomies content). Multi-site file layouts below are therefore derived from the Stache store classes and docs, not from a real multi-site project.
- Nothing was executed inside a booted Statamic app. Runtime ordering (events, Blink caches) comes from reading code.

---

## TL;DR — the findings that should shape the task list

1. **Localization granularity is the top-level field.** `localizable` is honoured only for top-level blueprint fields; nested fields cannot define it (`:can-define-localizable="false"` in the set/grid builders). A localization stores the *whole* top-level value (entire replicator/bard/grid) or inherits the whole value.
2. **`localizable` is enforced only in the CP's Vue layer.** No PHP code checks it on save (`Fields::localizable()` / `unlocalizable()` have zero callers in core). Flat-file (Stache) entries will store anything you write programmatically. **The Eloquent driver does enforce it**: on save it keeps only blueprint fields with `localizable: true` on a localization.
3. **`title` is not localizable by default** (ensured as `['type' => 'text', 'required' => true]`); `slug` is (`'localizable' => true`) for entries, but not for terms.
4. **Saving an origin entry re-saves every direct localization** (`Entry.php:445`), with full events for each. One origin save with N localizations = N+1 `EntrySaved` events (git commit job, static-cache invalidation, search update each).
5. **No built-in "origin changed since translation" tracking exists.** `updated_at` lives in each entry's own data and is only written by explicit `updateLastModified()` calls (CP controllers); `HasDirtyState` is in-memory only. On the Eloquent driver, a localization's `updated_at` appears to be bumped whenever the origin's data changes (inferred from the driver code, not executed). The addon needs its own change tracking.
6. **Addon metadata keys inside a localization's data are not safe.** A CP save of a localization replaces its data with `$values->only($request->input('_localized'))` (blueprint fields only) — non-blueprint keys are dropped. Eloquent drops them too. Root entries keep unknown keys (CP uses `merge`).
7. **The CP "localize" action always creates a draft** (`store()` forces `published(false)`), with empty data (everything inherited), origin's slug, appended to the target site's tree under the localized parent if it exists.
8. **Bard is an array of ProseMirror nodes (not wrapped in a `doc`)**, or an HTML string when `save_html` is on and there are no sets. Sets are root-level `set` nodes with data in `attrs.values`. HTML round-tripping via tiptap-php is **lossy** (set attrs, image `alt`, unknown marks/attrs lost; default `textAlign` attrs added) — walk the JSON tree instead.
9. **Internal links and relationships localize themselves at render time** (`LinkMark::convertHref`, `ResolveRedirect::findEntry`, `Entries::queryBuilder`, `Terms::queryBuilder`). `statamic://entry::<id>` hrefs, `entry::<id>` link values, entry IDs and term slugs can be copied/inherited untouched.
10. **Native addon settings exist in v6** (`resources/blueprints/settings.yaml` → CP page → `resources/addons/{slug}.yaml`), with Antlers resolution (`{{ config:... }}`) and **no encryption**. `Fieldtype::appendConfigField()` on the base class adds a config toggle to every fieldtype, including nested fields.
11. **Assets, forms, and nav trees have no origin/inheritance model.** Asset meta is one `data` map (not per-site). Nav trees are independent per site. Global variables and terms do have per-site data.
12. **With revisions enabled**, the CP saves published entries to a *working copy* only; `publishWorkingCopy()` re-hydrates from the working copy and **discards unsaved in-memory changes** if one exists.

---

## 1. Sites

### 1.1 Where sites are configured [S][D]

- File: `resources/sites.yaml` — `Statamic\Sites\Sites::path()` (`src/Sites/Sites.php:126-129`): `return resource_path('sites.yaml');`
- Fallback when the file is missing (`Sites.php:138-147`): one site keyed `default` with `name: '{{ config:app:name }}'`, `url: '/'`, `locale: '{{ config:app:locale }}'`.
- Format (docs): YAML map keyed by site handle.

```yaml
default:
  name: First Site
  url: /
  locale: en_US
second:
  name: Second Site
  url: /second/
  locale: en_US
  lang: fr            # optional
  attributes:         # optional, arbitrary
    theme: dark
```

- Values are Antlers-resolved with a `config` variable at construction (`Site.php:107-132`), so `{{ config:app:url }}` works; `rawConfig()` returns the unresolved values.
- The first key is the default site (`Sites.php:283-288`).
- With `statamic/eloquent-driver`, sites can be DB-backed (`config/eloquent-driver.php` → `'sites' => ['driver' => 'file' | 'eloquent']`) [E]. Never read `sites.yaml` directly; use the facade.

### 1.2 `Statamic\Sites\Site` API [S] (`src/Sites/Site.php`)

| Method | Line | Behaviour |
|---|---|---|
| `handle()` | 33 | Array key in sites.yaml |
| `name()` | 38 | `$this->config['name'] ?? $this->handle()` |
| `locale()` | 43 | `$this->config['locale']` (e.g. `en_US`, used for `setlocale(LC_TIME, …)`) |
| `shortLocale()` | 48 | `explode('-', str_replace('_', '-', $this->locale()))[0]` |
| `lang()` | 53 | `$this->config['lang'] ?? $this->shortLocale()` — this is what `app()->setLocale()` receives on the front end (`src/Http/Middleware/Localize.php:34`) |
| `url()` | 58 | `URL::tidy($this->config['url'], true)` |
| `absoluteUrl()` | 78 | |
| `direction()` | 63 | `TextDirection::of($this->lang())` → `'rtl'` for `['ar','fa','he','ps','ur']`, else `'ltr'` (`src/Support/TextDirection.php:7-12`). Not configurable; inferred from `lang`. |
| `attributes()` / `attribute($key, $default = null)` | 68 / 73 | Custom attributes map |
| `isDefault()` | 90 | |
| `rawConfig()` | 156 | Un-resolved config |
| `set($key, $value)` | 95 | Runtime override |
| `__toString()` | 161 | Returns handle |

Human-readable language names: core ships a `languages` dictionary (`src/Dictionaries/Languages.php`, labels in `lang/en/dictionary-languages.php`, e.g. `'el' => 'Greek'`); the site `lang` CP field uses it (`Sites.php:225-237`). `Statamic\Facades\Dictionary::find('languages')->get('fr')`.

### 1.3 Listing / resolving sites [S] (`src/Facades/Site.php`, `src/Sites/Sites.php`)

```php
use Statamic\Facades\Site;

Site::all();            // Illuminate Collection keyed by handle => Statamic\Sites\Site   (Sites.php:35)
Site::get('fr');        // ?Site                                                         (55)
Site::default();        // first site                                                    (45)
Site::current();        // by request URL, falls back to default                         (72)
Site::selected();       // CP-selected site: session('statamic.cp.selected-site')        (96)
Site::authorized();     // sites the current user can 'view'                             (40)
Site::hasMultiple();    // count > 1                                                     (50)
Site::multiEnabled();   // (bool) config('statamic.system.multisite', false)            (30)
Site::config();         // raw config array keyed by handle                              (273)
Site::setSites($array)->save();   // write sites (fires SiteCreated/SiteDeleted/SiteSaved) (106, 149)
```

`SitesController::update()` uses exactly `Site::setSites($sites)->save();` (`src/Http/Controllers/CP/Sites/SitesController.php:74`).

### 1.4 Enabling multi-site, Pro requirement [S][D]

- Config flag: `config/statamic/system.php` → `'multisite' => false` (`config/system.php:33`). The comment says: *"It is assumed Statamic Pro is also enabled."*
- Pro flag: `config/statamic/editions.php` → `'pro' => env('STATAMIC_PRO_ENABLED', false)`; `Statamic::pro()` (`src/Statamic.php:47-50`).
- Enforcement is an HTTP middleware only (`src/Http/Middleware/CheckMultisite.php:12-21`, pushed globally at `src/Providers/AppServiceProvider.php:53`):

```php
if (Statamic::pro() || ! Site::multiEnabled() || $request->is('_ignition*')) {
    return $next($request);
}
throw new StatamicProRequiredException('Statamic Pro is required to use multiple sites.');
```

  Console commands are not blocked by it.
- `Site::multiEnabled()` vs `Site::hasMultiple()` are different checks. File layouts switch on `multiEnabled()` (e.g. `Entry::buildPath()` at `src/Entries/Entry.php:551`), so a multisite-enabled install with one site still uses per-site directories.
- Conversion command: `php please multisite` (`src/Console/Commands/Multisite.php`, signature `statamic:multisite`). It enables Pro, flips the config flag by string replacement (`Multisite.php:151-171`), moves collection entries into `{collection}/{site}/`, re-saves trees, moves globals, creates nav trees, and adds `access {site} site` to every role (`Multisite.php:316-329`).
- Site permission: `access {site} site` is registered only when multisite is enabled (`src/Auth/CorePermissions.php:78-91`); checked by `SitePolicy::view` (`src/Policies/SitePolicy.php:17-24`).
- **[NV]** Whether additional sites cost extra. The docs licensing page says Pro includes "Multi-site, multilingual, and multi-user-editing" and mentions no per-site fee; the licensing payload (`src/Licensing/Outpost.php:114-127`) sends no site count.

---

## 2. Entries

Class: `Statamic\Entries\Entry` (`src/Entries/Entry.php`). Contracts `Statamic\Contracts\Entries\Entry` and `Statamic\Contracts\Data\Localization` are (near-)empty interfaces; all behaviour is on the concrete class and its traits.

### 2.1 Data access and inheritance semantics [S]

Traits: `ContainsData` (`src/Data/ContainsData.php`), `HasOrigin` (`src/Data/HasOrigin.php`), `ContainsComputedData`.

| Call | Returns | Source |
|---|---|---|
| `data()` | The entry's **own** data collection only (for a localization: just the overridden keys, plus `updated_at`/`updated_by` if set). With an argument it **replaces** all data. | `ContainsData.php:56-65` |
| `get($key, $fallback = null)` | Own data only: `$this->data[$key] ?? $fallback` | `:11-14` |
| `has($key)` | `$this->data->has($key)` — true even when the value is `null` | `:16-19` |
| `set($key, $value)` / `merge($data)` / `remove($key)` / `modify($key, $callback)` | Mutate own data | `:21-26, 67-72, 40-45, 47-54` |
| `value($key)` | Own value if the key exists, else origin's `value($key)` (recursive), else collection `inject` cascade; computed callback wins if registered | `HasOrigin.php:49-62` |
| `values()` | `collect()->merge(cascade)->merge(origin->values())->merge($this->data)->merge(computedData)` | `HasOrigin.php:34-47` |
| `keys()` | Union of the above key sets | `:15-32` |

```php
// src/Data/HasOrigin.php:49-62
public function value($key)
{
    $originFallbackValue = method_exists($this, 'getOriginFallbackValue') ? $this->getOriginFallbackValue($key) : null;
    $originValue = $this->hasOrigin() ? $this->origin()->value($key) : $originFallbackValue;
    $value = $this->has($key) ? $this->get($key) : $originValue;
    if (method_exists($this, 'hasComputedCallback') && $this->hasComputedCallback($key)) {
        return $this->getComputed($key) ?? $value;
    }
    return $value;
}
```

Consequences:

- **An explicit `null` in a localization overrides the origin** (`has()` is true, `get()` returns null). Localization files keep nulls: `Entry::shouldRemoveNullsFromFileData()` returns `false` and `fileData()` only strips them `if ($this->isRoot())` (`Entry.php:715-743`).
- "Null removal" is broader than the name suggests. `Statamic\Support\Arr::removeNullValues()` (`src/Support/Arr.php:226-233`) drops top-level values that are `null`, **`''`**, or an **empty array**:

```php
return array_filter($data, function ($item) {
    return is_array($item) ? ! empty($item) : ! in_array($item, [null, ''], true);
});
```

  So on a root entry, a field set to `''` or `[]` disappears from the file; on a localization it is kept and overrides the origin.
- `values()` mixes in collection `inject` values and computed values. Never persist `values()` back into `data()`.
- Not in `data()`: `id`, `origin`, `published`, `slug`, `date`, `locale`, `collection` — these are properties (`fileData()` at `Entry.php:715-738`; hydration at `src/Stache/Stores/CollectionEntriesStore.php:57-112`). `blueprint` stays in data for roots.

### 2.2 Localization API [S]

| Method | Line | Behaviour |
|---|---|---|
| `locale($locale = null)` | 114 | Getter returns `$locale ?? Site::default()->handle()`; setter accepts handle or `Site` |
| `site()` | 129 | `Site::get($this->locale())` |
| `origin($origin = null)` | `HasOrigin.php:64` | Getter resolves via `getOriginByString` (`Entry.php:1028-1031`: `$this->collection()->queryEntries()->where('id', $origin)->first()`), Blink-cached. Setter accepts ID or entry. |
| `hasOrigin()` / `isRoot()` | `HasOrigin.php:99 / 104` | |
| `root()` | `HasOrigin.php:109` | Walks `origin()` up |
| `ancestors()` | 844 | Origin chain |
| `directDescendants()` | 858 | `Entry::query()->where('collection', …)->where('origin', $this->id())->get()->keyBy->locale()`, Blink key `entry-descendants-{id}` |
| `descendants()` | 868 | Direct + nested, merged keyed by locale |
| `in($locale)` | 831 | Same locale → `$this`; non-root → `$this->root()->in($locale)`; else `$this->descendants()->get($locale)` (null if missing) |
| `existsIn($locale)` | 879 | `$this->in($locale) !== null` |
| `makeLocalization($site)` | 884 | See 2.3. **No guard against an existing localization** — check `existsIn()` first. |
| `detachLocalizations()` | 303 | Each direct localization: `->origin(null)->data($this->data()->merge($loc->data()))->save()` |
| `deleteDescendants($withEvents = true)` | 291 | |
| `delete()` | 253 | Throws `'Cannot delete an entry with localizations.'` if descendants exist |
| `sites()` | 710 | `$this->collection()->sites()` |

Origins can chain (fr → en, de → fr): the CP localizes from whichever entry is in the route.

Query columns to use (driver-safe): `->where('collection', $h)`, `->where('site', $handle)`, `->where('origin', $id)`. `where('locale', …)` works on Stache (via `getQueryableValue`) but maps to `data->locale` on Eloquent [E] (`EntryQueryBuilder::column()`), so prefer `site`.

### 2.3 How the CP creates a localization [S]

Route (`routes/cp.php:196`): `POST collections/{collection}/entries/{entry}/localize` → `collections.entries.localize`.

```php
// src/Http/Controllers/CP/Collections/LocalizeEntryController.php:11-25
public function __invoke(Request $request, $collection, $entry)
{
    $this->authorize('edit', $entry);
    $request->validate(['site' => 'required']);
    $localized = $entry->makeLocalization($site = $request->site);
    $localized->store(['user' => User::fromUser($request->user())]);
    return ['handle' => $site, 'url' => $localized->editUrl()];
}
```

```php
// src/Entries/Entry.php:884-898
public function makeLocalization($site)
{
    $localization = Facades\Entry::make()
        ->collection($this->collection)
        ->origin($this)
        ->locale($site)
        ->published($this->published)
        ->slug($this->slug());

    if ($callback = $this->addToStructure($site, $this->parent())) {
        $localization->afterSave($callback);
    }
    return $localization;
}
```

```php
// src/Revisions/Revisable.php:142-158
public function store($options = [])
{
    $return = $this
        ->published(false)
        ->updateLastModified($user = $options['user'] ?? null)
        ->save();

    if ($this->revisionsEnabled()) {
        $return = $this->makeRevision()->user($user)->message($options['message'] ?? null)->save();
    }
    return $return;
}
```

Resulting behaviour:

- **Data:** empty (no keys copied) — everything inherits. `store()` then adds `updated_at` (+ `updated_by`) to the localization's own data. (Eloquent driver differs: its `makeLocalization` does `parent::makeLocalization($site)->data($this->data())` [E].)
- **ID:** null until saved; the repository assigns a UUID (`src/Stache/Repositories/EntryRepository.php:132-139`).
- **Slug:** copied from the origin.
- **Published:** `makeLocalization` copies the origin's state, then `store()` forces **`false` (draft)**. The Vue form then *displays* published=true if the origin is published (`resources/js/components/entries/PublishForm.vue:715-717`), but that is unsaved until the user saves.
- **Structure tree** (`Entry.php:900-924`): nothing for unstructured collections or orderable ones (`max_depth === 1`). Otherwise an `afterSave` callback appends the new entry to the target site's tree: under `$this->parent()->in($site)` if the parent is localized there and is not the root, else at the end of the top level; then `$tree->save()`.
  - For orderable collections, `CollectionStructure::validateTree()` (`src/Structures/CollectionStructure.php:67-91`) auto-appends any entry of that site missing from the tree whenever the tree is read.
  - Implication for bulk creation: children localized before their parents land at the root level.
- **Events, in order** (`Entry::save()`, `Entry.php:400-479`): `EntryCreating` → `EntrySaving` → repository save → cascade to descendants → `taxonomize()` → afterSave callbacks (tree save ⇒ `CollectionTreeSaving`, `CollectionTreeSaved`) → `EntryCreated` → `EntrySaved`; plus `RevisionSaving`/`RevisionSaved` when revisions are enabled.
- **JS hook:** after creating, the form emits `this.$events.$emit('localization.created', { container })` (`PublishForm.vue:713`).

### 2.4 Collection settings that matter [S] (`src/Entries/Collection.php`)

| Setting | Accessor | Notes |
|---|---|---|
| `sites` | `sites($sites = null)` `:422-435` | Getter returns `[Site::default()->handle()]` when multisite is off or unset. Add a site: `$collection->sites([...])->save()`. Existing entries are not localized automatically. |
| `propagate` | `propagate()` `:437-445` | Only acts on the **first save of a new root entry** (`Entry.php:466-472`): `makeLocalization($siteHandle)->save()` (or `saveQuietly`) for every other site. Published state copied from origin; no `updated_at`. |
| `origin_behavior` | `originBehavior()` `:666-680` | `'select'` (default), `'root'`, `'active'`; anything else throws. **Used only by the CP JS** to choose which entry the localize POST targets. |
| `route` | `routes()` / `route($site)` `:96-118` | String for all sites or per-site map |
| `revisions` | `revisionsEnabled()` `:692-704` | False unless `config('statamic.revisions.enabled')` and `Statamic::pro()` |
| `title_format` | `titleFormats()` / `autoGeneratesTitles()` `:130-159` | Per-site; on save a localization's title is auto-set if it differs from the origin's (`Entry.php:420-427`) |
| `inject` | `cascade()` | Fallback values for `value()`/`values()` |
| `structure` | `structure()`, `hasStructure()`, `orderable()` `:172-175, 720-791` | |

`origin_behavior` in the CP (`PublishForm.vue:653-669, 877-884`; labels `lang/en/messages.php:37-40`):

- `select` — "Let the user select the origin"; a picker only appears when the collection has more than two sites, otherwise the root is used.
- `root` — "Use the site the entry was originally created in".
- `active` — "Use the site where the entry is currently being edited".

`DuplicateEntry` shows how core suspends propagation in memory: `$original->collection()->propagate(false);` (`src/Actions/DuplicateEntry.php:186-189`).

### 2.5 `localizable: true` — what it actually does [S][D]

- `Field::isLocalizable()` → `(bool) $this->get('localizable')` (`src/Fields/Field.php:210-213`). Default is falsy. The blueprint builder writes `localizable: false` as the default in multisite and strips the key in single-site (`src/Fields/FieldTransformer.php:47, 153, 170`).
- **No PHP enforcement.** `Fields::localizable()` / `unlocalizable()` (`src/Fields/Fields.php:126-138`) have no callers in core; the only other use of `isLocalizable()` is revision date restore (`Entry.php:781`).
- **CP enforcement is in Vue** (`resources/js/components/ui/Publish/Field.vue`):

```js
// :168-177
const isLocalizable = computed(() => props.config.localizable);
const isReadOnly = computed(() => {
    ...
    if (isTrackingOriginValues.value && isSyncable.value && !isLocalizable.value) return true;
    ...
});
// :182-190
const isSyncable = computed(() => {
    // Only top-level fields can be synced.
    if (isNested.value) return false;
    return isTrackingOriginValues.value && containerOriginValues.value.hasOwnProperty(fullPath.value)
});
const isSynced = computed(() => isSyncable.value && !localizedFields.value.includes(fullPath.value));
```

  So on a localization, a top-level field that is not localizable is read-only. Editing any nested field desyncs the **root** field (`desyncField(rootFieldPath.value)`, `:198-200`).
- Nested fields cannot define `localizable` in the builder: `resources/js/components/fieldtypes/replicator/SetsFieldtype.vue:6` and `.../grid/FieldsFieldtype.vue:5` pass `:can-define-localizable="false"`.
- Which fields count as "localized" is simply **which keys exist in the localization's data**: `'localizedFields' => $entry->data()->keys()->all()` (`EntriesController.php:141`).
- Defaults:
  - Entries: `title` ensured as `['type' => 'text' | 'hidden', 'required' => …]` — **not localizable**; `slug` ensured with `'localizable' => true` (`Collection.php:393-400`). User-defined config wins over ensured config (`src/Fields/Blueprint.php:276-281`).
  - Terms: `title` and `slug` ensured **without** `localizable` (`src/Taxonomies/Taxonomy.php:153-160`).
  - The fallback `default` blueprint has `content` as `markdown` with `localizable: true` (`AppServiceProvider.php:188-206`).
- Docs: *"While editing a localized entry, only the localizable fields will be editable. The non-localizable fields will be read-only."*

### 2.6 What a CP save does to a localization [S]

```php
// src/Http/Controllers/CP/Collections/EntriesController.php:218-239
$values = $fields->process()->values();
...
$values = $values->except(['slug', 'published']);
...
if ($entry->hasOrigin()) {
    $entry->data($values->only($request->input('_localized')));
} else {
    $entry->merge($values);
}
```

- `$values` contains blueprint fields only (`Fields::values()`, `src/Fields/Fields.php:170-186`; fields with `visibility: computed` are excluded).
- `_localized` is the Vue `localizedFields` array (`PublishForm.vue:565`), seeded from the data keys and updated by sync/desync.
- **Localization:** data is *replaced* by the localized blueprint fields. Non-blueprint keys are dropped. `updated_at`/`updated_by` are re-added by `updateLastModified()` at line 262.
- **Root:** values are *merged*; unknown keys survive.
- **Non-localizable field on a localization:**
  - Via CP: read-only, never enters `_localized`, so never stored → inherits.
  - Written programmatically on Stache: it is stored and `value()` returns it. The CP then shows it as desynced but read-only (the sync buttons are hidden for read-only fields, `Field.vue:263-264`) and keeps it on later CP saves because its key is in `_localized`.
  - On the Eloquent driver it is dropped at save (see §13).
- Date: `$entry->date(in_array('date', $request->input('_localized')) ? $date : null)` (`:226-233`). A localization without an explicit date has no date prefix in its filename (`Entry::buildPath()`, `:534-546`) and inherits.
- Slug: `$entry->slug($this->resolveSlug($request))` → `$request->slug` or `Str::slug($request->title ?? $entry->autoGeneratedTitle(), '-', $entry->site()->lang())` (`:445-458`). `Statamic\Support\Str::slug($string, $separator = '-', $language = 'en', $dictionary = ['@' => 'at'])` (`src/Support/Str.php:89`).
- URI uniqueness is validated only in the controller (`validateUniqueUri`, `:460-473`), not in `Entry::save()`.
- Blueprint validation runs only in CP controllers. Programmatic saves bypass validation (including `character_limit`, `required`, `max_items`).

### 2.7 File format (Stache)

Real single-site example (`/Volumes/1TB/Sites/umami/content/collections/rooms/simple-room.md`):

```yaml
---
id: c2e8e329-2f27-4e88-aed6-132cdfcf0b5b
blueprint: room
title: 'Simple Room'
author: 16907662-3ccb-401c-820d-f32b1ce8535d
updated_by: 16907662-3ccb-401c-820d-f32b1ce8535d
updated_at: 1770801917
---
```

Multi-site (derived from `Entry::buildPath()` `:530-556`, `Entry::fileData()` `:715-738`, and docs):

```
content/collections/{collection}/{site}/{YYYY-MM-DD[-HHmm[ss]].}{slug}.md
```

```yaml
# content/collections/blog/fr/2015-01-18.mon-premier-jour.md
---
id: 456
origin: 123
published: false        # only written when false
title: Mon Premier Jour
excerpt: null           # explicit null = "localized but empty"
updated_by: ...
updated_at: 1770801917
---
```

- `blueprint` is omitted on a localization when it matches the origin's.
- A string `content` key becomes the document body; a non-string `content` (Bard array) stays in front matter (`src/Data/ExistsAsFile.php:39-64`, `src/Yaml/Yaml.php:105-113`).

---

## 3. Taxonomy terms

### 3.1 Model [S]

- `Statamic\Taxonomies\Term` (`src/Taxonomies/Term.php`) holds **all locales' data in one object/file**: `protected $data` is a collection keyed by locale.
- `Statamic\Taxonomies\LocalizedTerm` (`src/Taxonomies/LocalizedTerm.php`) is a per-site view created by `Term::in($site)` (`Term.php:138-144`). It implements `Localization`.
- "Default locale" = first site of the taxonomy (`Term::defaultLocale()` `:151-154`). Every taxonomy site implicitly has a localization (`Term::localizations()` `:156-161`); there is no "create localization" step (`TermsController.php:130` hardcodes `'exists' => true`).
- `LocalizedTerm::hasOrigin()` = not default locale; `origin()` = default-locale term (`:156-159, 237-240`). No chains.
- Term ID: `"{taxonomy}::{slug}"` using the **default-locale slug** (`Term.php:49-52`).
- Terms can exist without a file, purely as entry associations (`src/Stache/Indexes/Terms/Value.php:10-26`).
- `Taxonomy::sites()` mirrors collections (`src/Taxonomies/Taxonomy.php:307-319`).

File (`Term::path()` `:81-88`, `Term::fileData()` `:117-136`, `TaxonomyTermsStore::makeItemFromFile()` `src/Stache/Stores/TaxonomyTermsStore.php:44-64`):

```yaml
# content/taxonomies/{taxonomy}/{default-slug}.yaml
title: News                # default-locale data at the root (null / '' / [] removed)
blueprint: tag             # only if set
localizations:
  fr:
    title: Actualités
    slug: actualites       # localized slug lives in the localization data
    updated_at: 1770801917
```

### 3.2 Read/write API for a site [S]

```php
use Statamic\Facades\Term;

$found = Term::find('tags::news');   // returns a LocalizedTerm (see note)
$fr    = $found->in('fr');            // LocalizedTerm for fr
$term  = $found->term();              // underlying Statamic\Taxonomies\Term  (LocalizedTerm.php:447)

$fr->data();                 // fr-only overrides                               (:79-88)
$fr->get('title');           // fr-only                                         (:60-63)
$fr->value('title');         // fr value, else default locale, else cascade     (:106-114)
$fr->values();               // cascade + default-locale data + fr data         (:97-104)
$fr->set('title', 'Actualités');   // (:65-72)
$fr->merge([...]);                 // (:90-95)
$fr->data([...]);                  // replace fr data
$fr->slug('actualites');           // non-default locale: stored as data['slug'] (:126-139)
$fr->updateLastModified($user);    // TracksLastModified; stored per locale
$fr->save();                       // $this->term->save() then LocalizedTermSaved (:387-394)
$fr->saveQuietly();                // $this->term->saveQuietly() — no events at all (:382-385)

// Lower level, on the Term:
$term->dataForLocale('fr');            // Collection                             (Term.php:288-297)
$term->dataForLocale('fr', [...]);     // set
Term::query()->where('taxonomy', 'tags')->where('site', 'fr')->get();   // LocalizedTerms in fr
```

Notes:

- `Term::find()` returns a `LocalizedTerm`, confirmed by `TaxonomyTermsStore::save()` calling `Term::find(...)->term()` (`:186-187`). By index order it should be the default-site localization, but that was **not executed [NV]** — always call `->in($site)`.
- `LocalizedTerm::has($key)` is `get($key) != null` (`:74-77`), unlike entries; `value()` uses `$this->data()->has($key)`.
- Terms have no persisted publish state (`Term::fileData()` has `// todo: add published bool`; `LocalizedTerm::status()` returns `'published'`).
- CP update mirrors entries: `if ($term->hasOrigin()) { $term->data($values->only($request->input('_localized'))); } else { $term->merge($values); }` (`TermsController.php:187-191`).
- Changing the **default** slug makes it a new term: the store deletes the old file (`TaxonomyTermsStore.php:185-188`) and `UpdateTermReferences` rewrites references across all content (`src/Listeners/UpdateTermReferences.php:38-45, 66-104`). A localized slug does not trigger this.
- Term references in entries are default-locale slugs; the `terms` fieldtype localizes on augment (`src/Fieldtypes/Terms.php:157-159, 184-186, 413-417`). No rewrite needed when translating entries.
- In the CP terms listing the item ID is site-agnostic and `TermActionController` resolves with `Term::find($item)`; the listing itself uses `Site::selected()` (`TermsController.php:60, 76`). A term action must read `Site::selected()` for the site.

---

## 4. Global sets

### 4.1 Model in v6 [S]

- `Statamic\Globals\GlobalSet` (`src/Globals/GlobalSet.php`) — handle, title, and a `sites` map of `site => origin site | null`.
- `Statamic\Globals\Variables` (`src/Globals/Variables.php`) — one object per (set, site); uses `ContainsData` + `HasOrigin`, implements `Localization`.
- Origin configuration moved onto the set in 6.0 (`src/UpdateScripts/UpdateGlobalVariables.php`, `isUpdatingTo('6.0.0-beta.4')`). Setting it on variables throws: `'The origin cannot be set directly. It must be defined on the global set.'` (`Variables.php:244-253`).

Files (`GlobalSet::path()` `:68-75`, `GlobalSet::fileData()` `:170-176`, `Variables::path()` `:84-92`):

```yaml
# content/globals/{handle}.yaml
title: Settings
sites:            # only written when multisite is enabled
  en: null
  fr: en          # fr inherits from en
```

```yaml
# content/globals/{site}/{handle}.yaml     (also in single-site: content/globals/default/{handle}.yaml)
company: Acme
```

### 4.2 API [S]

```php
use Statamic\Facades\GlobalSet;

$set = GlobalSet::findByHandle('settings');     // ?GlobalSet
$set->sites();                 // Collection of handles; [default] if none configured   (:185-195)
$set->origins();               // Collection site => origin|null                        (:212-219)
$set->in('fr');                // ?Variables — null if the site is not enabled on the set;
                               // an unsaved makeLocalization('fr') if nothing stored yet (:221-232)
$set->existsIn('fr');          // (:249-252)
$set->localizations();         // stored Variables keyed by locale, Blink-cached        (:254-264)
$set->inDefaultSite(); $set->inSelectedSite(); $set->inCurrentSite();

// Enable a site / set its origin:
$set->sites(['en' => null, 'fr' => 'en'])->save();
//   sites() accepts a list of handles (origin null) or a handle => origin map          (:197-209)
//   save() runs saveOrDeleteLocalizations(): creates missing Variables with ->save(),
//   deletes Variables for removed sites                                                (:130-141)

$vars = $set->in('fr');
$vars->data();  $vars->get('k');  $vars->value('k');  $vars->values();   // same semantics as entries
$vars->hasOrigin();  $vars->origin();
$vars->set('k', 'v')->save();    // GlobalVariablesCreating/Created (new), GlobalVariablesSaving/Saved
$vars->saveQuietly();
```

- Blueprint: `Blueprint::find('globals.'.$handle)` → `resources/blueprints/globals/{handle}.yaml`; without one, `Variables::fallbackBlueprint()` infers text fields from the values (`Variables.php:203-237`).
- CP update (`src/Http/Controllers/CP/Globals/GlobalVariablesController.php:104-116`): `$values = $fields->process()->values(); if ($set->hasOrigin()) { $values = $values->only($request->input('_localized')); } $set->data($values); $set->save();` — data is **replaced for both origin and localized variables**; non-blueprint keys never survive a CP save.
- Empty values (`null`, `''`, `[]`): removed for origin-less variables, kept for localized ones (`Variables::shouldRemoveNullsFromFileData()` `:255-258`). The Eloquent driver filters `null` for all variables [E].
- `Variables` does **not** use `TracksLastModified`: no `updated_at`/`updated_by`.
- Global variables have no publish state and no revisions.
- Actions on globals receive the `GlobalSet` (not `Variables`); the form passes context `['view' => 'form', 'site' => $site]` (`GlobalVariablesController.php:69`).

---

## 5. Navigation and collection structures

### 5.1 Classes and files [S]

| Thing | Class | File |
|---|---|---|
| Nav config | `Statamic\Structures\Nav` (extends `Structure`) | `content/navigation/{handle}.yaml` (`Nav::path()` `:70-76`) |
| Nav tree per site | `Statamic\Structures\NavTree` (extends `Tree`) | `content/trees/navigation/{site}/{handle}.yaml` in multisite, else `content/trees/navigation/{handle}.yaml` (`NavTree::path()` `:31-40`) |
| Collection structure | `Statamic\Structures\CollectionStructure` | inside the collection YAML (`structure:` key) |
| Collection tree per site | `Statamic\Structures\CollectionTree` | `content/trees/collections/{site}/{collection}.yaml` in multisite, else `content/trees/collections/{collection}.yaml` (`CollectionTree::path()` `:32-43`) |

Store directories: `config/stache.php:64-77`.

**Trees have no origin/inheritance between sites.** Each site's tree is independent. The `nav` tag uses the tree for the current site and returns nothing if it does not exist (`src/Tags/Structure.php:58`, `src/Structures/TreeBuilder.php:28-30`).

### 5.2 Where titles and custom data live [S]

Nav branch shape, exactly as the CP writes it (`src/Http/Controllers/CP/Navigation/NavigationTreeController.php:77-83`):

```php
$this->data[$id] = Arr::removeNullValues([
    'id' => $id,                          // branch UUID (BranchIdGenerator → Str::uuid())
    'entry' => $branch['entry'] ?? null,  // entry ID reference, if any
    'title' => $data->pull('title'),      // custom title (overrides the entry's title)
    'url' => $data->pull('url'),          // for URL items
    'data' => $data->all(),               // nav-blueprint field values
]);
// + 'children' => [...]
```

```yaml
# content/trees/navigation/fr/main.yaml
tree:
  -
    id: 6a2c...
    entry: 7f3e...        # an entry ID in this site
    title: 'Custom label'
    data:
      badge: Nouveau
    children:
      -
        id: 91bd...
        url: 'https://example.com'
        title: Externe
```

- `Page::title()` returns the branch title or the linked entry's `value('title')` (`src/Structures/Page.php:114-121`); `Page::data()` merges branch `data` over the entry's data (`:302-311`); `pageData()` is the branch data only (`:297-300`).
- Nav blueprint: `$nav->blueprint()` → `Blueprint::find('navigation.'.$handle)` (`resources/blueprints/navigation/{handle}.yaml`) or an empty one (`Nav.php:151-169`).
- Collection tree branches are `{entry: <id>, children: [...]}` only (`CollectionTree::idKey()` returns `'entry'`). Titles come from the entries. Real example: `/Volumes/1TB/Sites/umami/content/trees/collections/pages.yaml`.

### 5.3 Read/write API [S]

```php
use Statamic\Facades\Nav;

$nav  = Nav::findByHandle('main');
$nav->sites();                 // handles that have a tree                               (Nav.php:141-144)
$nav->existsIn('fr');          // (:146-149)
$tree = $nav->in('fr');        // ?NavTree via NavTreeRepository::find($handle, $site)   (:129-132)
$nav->trees();                 // keyed by site                                          (:134-139)

$tree->tree();                 // validated branch array                                 (Tree.php:45-56)
$tree->pages(); $tree->flattenedPages(); $tree->find($branchId);                    //  (:97-149)
$tree->tree($newArray)->save();      // NavTreeSaving → repository → NavTreeSaved        (:159-177)

// Create a tree for a new site (the CP does exactly this, NavigationController.php:181-182):
$tree = $nav->in($site) ?? $nav->makeTree($site);     // makeTree($site, $tree = [])  Structure.php:61-68
$tree->save();

// Collection structures:
$collection->structure()->in('fr');           // CollectionTree (unsaved empty tree if none stored)
$tree->append($entry); $tree->appendTo($parentId, $entry); $tree->move($id, $target); $tree->remove($entry);
```

- **`Tree` has no `saveQuietly()`**; tree saves always fire events.
- `NavTree::ensureBranchIds()` adds missing `id`s (`src/Structures/NavTree.php:62-71`).
- `CollectionStructure::validateTree()` runs on every `tree()` read: throws on duplicate entries, removes entries not belonging to that site/collection, appends missing ones (`:67-91`).
- `CollectionTreeSaved` listeners recompute URIs/order/parents (`src/Entries/UpdateStructuredEntryUris.php`, `UpdateStructuredEntryOrderAndParent.php`).
- To build a nav for a new site from an existing one: copy the branch array, map each `entry` to `Entry::find($id)?->in($site)?->id()`, translate `title` and the translatable `data` fields per `$nav->blueprint()`, and give branches fresh IDs if desired. There is no core helper for this.

---

## 6. Assets, forms, static strings

- **Asset metadata is not localizable per site** [S]. `Statamic\Assets\Asset` uses `ContainsData` without `HasOrigin`, does not implement `Localization`, and stores one `data` map in `{dir}/.meta/{basename}.yaml` (`src/Assets/Asset.php:289-328`). The only `Site::` reference in `src/Assets` is URL resolution (`AssetRepository.php:51`). Blueprint: `resources/blueprints/assets/{container}.yaml` (umami's defines a single `alt` text field).
  - A Bard image node can carry its own `alt` and falls back to the asset's `alt` (`src/Fieldtypes/Bard/ImageNode.php:41-47`), so per-entry alt text is possible inside Bard.
- **Classes implementing `Statamic\Contracts\Data\Localization`** (complete list, grep): `Entries\Entry`, `Taxonomies\LocalizedTerm`, `Globals\Variables`, `Structures\Tree`.
- **Forms are not localizable** [S]: no site handling in `src/Forms/Form.php`. Only the email subject passes through `__()` (`src/Forms/Email.php:61`) and emails use the site's `lang` (`:37`). Field `display`/`instructions` are passed to templates untranslated (`src/Tags/Concerns/RendersForms.php:149-158`).
- **Static UI strings** live in Laravel translation files, selected by `Site::lang()` via `app()->setLocale($site->lang())` (`Localize.php:34`): the project's `lang/{lang}.json` and `lang/{lang}/*.php` (umami has `lang/en/validation.php`). Core's own strings are in `lang/{locale}.json` and `lang/{locale}/*.php` under the `statamic` namespace.
- **Select-style option labels** are blueprint config, not content (§7.4).
- **Addon translations:** `AddonServiceProvider::bootTranslations()` loads `{addon}/lang` (or `resources/lang`) under the addon slug namespace (`src/Providers/AddonServiceProvider.php:490-516`).
- Core has an internal `Statamic\Translator` tool (`src/Translator/*`, Google Translate) for its own lang files; its `Placeholders::wrap()` protects `:placeholder` tokens with `<span class="notranslate">` (`src/Translator/Placeholders.php`). Not content-related.

---

## 7. Blueprint traversal and stored data shapes

### 7.1 Enumerating fields [S]

```php
$blueprint = $entry->blueprint();          // Statamic\Fields\Blueprint (dispatches EntryBlueprintFound)
$fields    = $blueprint->fields();         // Statamic\Fields\Fields          Blueprint.php:354-373
$fields->all();                            // Collection handle => Statamic\Fields\Field   Fields.php:101-104
$blueprint->field('title');                // ?Field                           Blueprint.php:394-397
$blueprint->hasField('title');             //                                  :375-378

$field->handle();        // Field.php:52
$field->type();          // Arr::get($this->config, 'type', 'text')            :101-104
$field->config();        // raw config array                                   :410-413
$field->get('key', $fallback);             //                                  :429-432
$field->isLocalizable(); //                                                    :210-213
$field->visibility();    // 'visible' | 'read_only' | 'computed' | 'hidden'    :121-132
$field->display();       //                                                    :111-114
$field->fieldtype();     // fresh Fieldtype clone bound to the field           :106-109
$field->handlePath();  $field->fieldPathKeys();  $field->parentField();  $field->parentIndex();   // :57-99
$field->fieldtype()->config('key');        // config merged over config-field defaults   Fieldtype.php:353-366
```

- `Blueprint::fields()` is flat across tabs/sections and already resolves fieldset imports (`import:` with optional `prefix` and per-field `config` overrides) and field references (`field: fieldset.handle`) (`Fields.php:230-316`). Duplicate handles throw.
- For entries, blueprints come from `$collection->entryBlueprints()` / `entryBlueprint($handle, $entry)` with `title`/`slug`/`date`/taxonomy fields ensured (`Collection.php:322-420`).

### 7.2 Nested definitions [S]

| Fieldtype | How to get the nested `Fields` | Data path prefix |
|---|---|---|
| `group` | `$field->fieldtype()->fields()` (`src/Fieldtypes/Group.php:95-98`) | `{handle}.` |
| `grid` | `$field->fieldtype()->fields($index = -1)` (`Grid.php:141-144`) or `new Fields($field->config()['fields'])` | `{handle}.{rowIndex}.` |
| `replicator` | `$field->fieldtype()->flattenedSetsConfig()` → Collection `setHandle => ['display' => …, 'fields' => [...]]` (`Replicator.php:276-298`); `$field->fieldtype()->fields($setHandle, $index = -1)` (`:136-151`) | `{handle}.{setIndex}.` (set handle at `.type`) |
| `bard` | Same two methods (Bard extends Replicator) | `{handle}.{nodeIndex}.attrs.values.` (set handle at `.attrs.values.type`) |
| imported fieldsets | Resolved transparently by `new Fields($config)` | — |

- Raw `config()['fields']` arrays may contain `['import' => 'fieldset']` items; always go through `new Fields(...)` / `->fields()`.
- `flattenedSetsConfig()` handles both the grouped format (`sets: {group: {display, sets: {handle: {...}}}}`) and the legacy flat format.
- **Core reference implementations of this exact recursion:**
  - `src/Console/Commands/MigrateDatesToUtc.php:102-244` — walks group/grid/replicator/bard with dotted prefixes and writes with `Arr::set` + `$item->data($data)`.
  - `src/Data/DataReferenceUpdater.php`, `src/Assets/AssetReferenceUpdater.php:33-67`, and each fieldtype's `iterateReferenceFields()` (`Replicator.php:362-376`, `Bard.php:880-894`, `Grid.php:285-302`, `Group.php:208-217`).
  - `src/Listeners/Concerns/GetsItemsContainingData.php:21-46` — lazy iteration over all entries, terms, global variables and users.

### 7.3 Stored shapes of text-bearing fieldtypes [S]

"Stored" = what is in `data()` / the YAML file, i.e. the output of the fieldtype's `process()`.

| Type | Stored shape | Source / notes |
|---|---|---|
| `text` | string; int/float when `input_type: number` | `src/Fieldtypes/Text.php:160-167`. `input_type` options include `email`, `url`, `tel`, `number`, `date`, `color`, `password` (`:21-41`). Config `antlers: true` means the value may contain Antlers. `character_limit` is client-side only. |
| `textarea` | string | No `process()`; `antlers` option |
| `markdown` | string (Markdown) | `Markdown.php`. Internal links/images as `(statamic://entry::id)` / `(statamic://asset::container::path)`, resolved on augment (`src/Fieldtypes/Concerns/ResolvesStatamicUrls.php:14-22`) |
| `bard` | see 7.5 | |
| `replicator` | list of sets: `[{id: 'aB3dE9xZ', type: 'set_handle', enabled: true, ...fieldValues}]` | `Replicator.php:108-115`: `array_merge([RowId::handle() => Arr::pull($row, '_id')], $row, $fields)` then `Arr::removeNullValues`. `enabled: true` **is** stored. Row id key is `config('statamic.system.row_id_handle', 'id')`; values are `Str::random(8)` (`src/Fieldtypes/RowId.php`). Disabled sets (`enabled: false`) are skipped on augment. |
| `grid` | list of rows: `[{id: '…', ...fieldValues}]` | `Grid.php:108-115` |
| `group` | assoc map of sub-field values, nulls removed | `Group.php:83-88` |
| `table` | `[{cells: ['a', 'b']}, {cells: ['c', 'd']}]` | No PHP `process()`; shape from `resources/js/components/fieldtypes/TableFieldtype.vue:189-203` and `CoreModifiers::table()` (`src/Modifiers/CoreModifiers.php:2738-2758`). Cells are strings (optionally Markdown). |
| `list` | flat list of scalars; numeric strings cast to int/float; empty items removed | `src/Fieldtypes/Lists.php:35-48` |
| `array` | dynamic: `{key: value}`; keyed (config `keys`): `{predefinedKey: value}` with nulls removed; with `expand: true`: `[{key: k, value: v}]`; empty → `null` | `src/Fieldtypes/Arr.php:115-134`. Keys are identifiers; values are the text. |
| `link` | string: `'entry::<id>'`, `'asset::<container>::<path>'`, `'@child'`, or a literal URL | `src/Fieldtypes/Link.php`; entry links localized on augment via `ResolveRedirect::item($value, $parent, $localize)` (`src/Routing/ResolveRedirect.php:72-87`) |
| `slug` | string | `Slug extends Text`. The entry's own slug is a property, not data. |
| `code` | string when `mode_selectable` is false; else `{code: '…', mode: 'htmlmixed'}`; falsy → `null` | `src/Fieldtypes/Code.php:155-180` (config default for `mode_selectable` is `true`) |
| `html` | **stores nothing** — renders `config.html` in the CP | `src/Fieldtypes/Html.php`, `resources/js/components/fieldtypes/HtmlFieldtype.vue` |
| `yaml` | parsed array (YAML string → array on save; kept as string if parsing fails); empty → `null` | `src/Fieldtypes/Yaml.php:37-48` |
| `video` | string (URL) | `src/Fieldtypes/Video.php` (no processing) |
| `taggable` | list of strings | `src/Fieldtypes/Taggable.php` |
| `hidden` | string | |

Wherever the table says "nulls removed" (replicator/grid rows, group, Bard set values, keyed arrays), the code is `Arr::removeNullValues()`, which also strips `''` and empty arrays at that level (§2.1). Data written by the CP therefore omits empty sub-fields; data written programmatically should follow the same convention if byte-stable diffs matter.

### 7.4 Options: stored value vs label [S]

`select`, `radio`, `checkboxes`, `button_group` all use `HasSelectOptions` (`src/Fieldtypes/HasSelectOptions.php`).

- **Stored:** the option **key** — scalar, or a list when `multiple` (checkboxes are always multiple). With `cast_booleans`, `'true'`/`'false'` become booleans (`:82-89, 144-153`).
- **Labels:** live in the blueprint field config `options`, in one of three formats normalised by `getOptions()` (`:25-49`): list of scalars (label = value), assoc `key: label`, or list of `{key, value}`.
- **Augmented:** `LabeledValue($value, $label)` or `[{key, value, label}]` (`:91-112`). The label is returned as configured; it is not passed through `__()` in PHP (`:131-142`).
- Therefore option labels are per-blueprint, not per-site. Nothing to translate in content.

### 7.5 Bard [S][T]

**Stored value** (`Bard::process()`, `src/Fieldtypes/Bard.php:322-353`):

- Default: a **list of ProseMirror nodes** — the `content` of a doc, not wrapped in `{type: 'doc'}`. `[['type' => 'paragraph']]` and `[]` become `null`.
- `save_html: true` **and no sets configured** (`shouldSaveHtml()` `:392-399`): an **HTML string** from `(new Augmentor($this))->withStatamicImageUrls()->convertToHtml($structure)`.
- `inline: true`: the inline content of the single paragraph (list of `text` / `hardBreak` nodes), unwrapped (`:326-328, 828-831`).
- Reading back: `preProcess()` (`:414-461`) converts a string with `renderHtmlToProsemirror()` (after `str_replace('src="statamic://', 'src="', $value)`), drops nodes without `type`, wraps/unwraps for inline mode.

**Node and mark vocabulary** (server-side extension list, `src/Providers/BardServiceProvider.php:12-48`):

- Nodes: `paragraph`, `heading` (`attrs.level`), `bulletList`, `orderedList`, `listItem`, `blockquote`, `codeBlock`, `horizontalRule`, `hardBreak`, `image`, `table`, `tableRow`, `tableCell`, `tableHeader`, `set`, `text`.
- Marks: `bold`, `italic`, `underline`, `strike`, `code`, `link`, `subscript`, `superscript`, `small`.
- Extension: `textAlign` on `heading`/`paragraph` (`attrs.textAlign`).

```yaml
my_bard:
  -
    type: heading
    attrs: { level: 2 }
    content:
      - { type: text, text: 'Hello world' }
  -
    type: paragraph
    content:
      - { type: text, text: 'Plain ' }
      -
        type: text
        marks:
          - { type: bold }
          - { type: link, attrs: { href: 'statamic://entry::7f3e...', rel: null, target: null, title: null } }
        text: 'bold link'
      - { type: image, attrs: { src: 'asset::assets::img/photo.jpg', alt: 'A photo' } }
  -
    type: set
    attrs:
      id: aB3dE9xZ
      enabled: false              # only stored when false (Bard.php:405-407)
      values:
        type: quote               # set handle
        text: 'Quoted text'       # set field values, in each fieldtype's stored shape
```

- **Sets:** `{type: 'set', attrs: {id, enabled?, values: {type: <handle>, ...}}}` (`Bard::processRow()` `:401-412`, `preProcessRow()` `:463-477`). In JS the node is `group: 'root'` and the document schema is `'(block | root)+'` (`resources/js/components/fieldtypes/bard/Set.js:18`, `Document.js:3-5`), i.e. **sets are root-level block nodes only. No "inline set" exists in 6.19.0.** "Inline" in Bard means the `inline` field option.
- **Links:** mark `link` with `attrs: {href, rel, target, title}` (`resources/js/components/fieldtypes/bard/Link.js:9-24`). Internal hrefs are `statamic://entry::<id>` or `statamic://asset::<container>::<path>`, optionally followed by `?query`/`#hash` (`Bard.php:791-794`). `attrs.title` is human text.
- **Images:** node `image` (inline in v6) with `attrs: {src, alt}`; `src` is `asset::<container>::<path>` or a URL (`Image.js:20-31`, `Bard.php:859-866`). `attrs.alt` is human text.
- **Custom nodes/marks:** addons register JS extensions and PHP ones via `Augmentor::addExtension($name, $extension)` / `replaceExtension()` (`src/Fieldtypes/Bard/Augmentor.php:112-127`). Data may contain types core does not know.
- **Legacy data:** `isLegacyData()` / `convertLegacyData()` handle v2-style `[{type: text, text: '<html>'}, {type: set_handle, ...}]` (`:548-587`).
- **Render-time link localization:** `LinkMark::convertHref()` (`src/Fieldtypes/Bard/LinkMark.php:59-80`) resolves `statamic://entry::<id>` to `($item->in(Site::current()->handle()) ?? $item)->url()` unless the field's `select_across_sites` is on. Origin entry IDs in links work on every site.

**Server-side conversion utilities** — `Statamic\Fieldtypes\Bard\Augmentor` (`src/Fieldtypes/Bard/Augmentor.php`), built on `Tiptap\Editor` (ueberdosis/tiptap-php 2.1.0):

| Method | Line | Notes |
|---|---|---|
| `__construct($fieldtype)` | 25 | Needs a Bard fieldtype instance (`$field->fieldtype()`) |
| `renderProsemirrorToHtml(array $value)` | 171 | Expects `['type' => 'doc', 'content' => $nodes]` |
| `convertToHtml($value)` | 105 | Takes the node list; runs Bard `augment` hooks; wraps in a doc |
| `renderHtmlToProsemirror(string $value)` | 166 | Returns `['type' => 'doc', 'content' => [...]]` |
| `withStatamicImageUrls()` | 30 | Uses `StatamicImageNode`/`StatamicLinkMark`, keeping `statamic://` URLs |
| `augment($value, $shallow = false)` | 37 | Front-end output; with sets returns `[{type: 'text', text: <html>}, {type: <set>, ...}]` |
| `withDisabledSets()`, `withoutAugmentingSets()` | 68, 75 | |
| `extensions()` | 191 | Resolved extension list |

`Tiptap\Editor`: `setContent($value)` (HTML, JSON string or array), `getDocument()`, `getJSON()`, `getHTML()`, `getText()`, `sanitize()`, `descendants($closure)`.

**HTML round-trip is lossy** [T] (tiptap-php 2.1.0 with Statamic's `SetNode`, `StatamicLinkMark`, `StatamicImageNode`, `Small`, `TextAlign`; app not booted, so not through `Augmentor` itself):

- `set` nodes render as `<set>{index}</set>` and parse back as `{"type":"set"}` — **`attrs` (id, values) are lost** (`SetNode.php:20-23`).
- `image` came back as `{"src":"statamic://asset::assets::img/photo.jpg"}` — **`alt` lost**, `src` gains the `statamic://` prefix (Bard's `preProcess` strips it again).
- Marks and attrs not registered server-side are dropped.
- `TextAlign` adds `"attrs":{"textAlign":"left"}` to paragraphs that had no attrs, so output is not identical to input.
- `null` link attrs (`rel`, `target`, `title`) are dropped.
- `statamic://` hrefs **do** survive with `StatamicLinkMark`; the plain `Tiptap\Marks\Link` strips them (Statamic's `LinkMark` adds `statamic`, `entry`, `asset` to `allowedProtocols`, `LinkMark.php:20-22`).
- Basic marks (`bold`, `link`, `hardBreak`) and heading attrs survive.

Bard hooks available: `Bard::hook('process' | 'pre-process' | 'pre-process-index' | 'augment' | 'preload' | 'extra-rules' | 'extra-validation-attributes' | 'pre-process-validatable', fn)` (`Bard.php:338, 460, 493, 519, 545, 681, 721, 835`).

---

## 8. Appending a config option to every fieldtype [S][D]

```php
// src/Fields/Fieldtype.php:293-311
protected function extraConfigFieldItems(): array
{
    return array_merge(
        self::$extraConfigFields[static::class] ?? [],
        Fieldtype::$extraConfigFields[Fieldtype::class] ?? [],
    );
}

public static function appendConfigFields(array $config): void
{
    $existingConfig = self::$extraConfigFields[static::class] ?? [];
    self::$extraConfigFields[static::class] = array_merge($existingConfig, $config);
}

public static function appendConfigField(string $field, array $config): void
{
    self::appendConfigFields([$field => $config]);
}
```

- Called on the base class it applies to **all** fieldtypes; called on a subclass (`Text::appendConfigField(...)`) only to that one. Docs confirm: *"You can also append a config field to all fieldtypes via the `Fieldtype` class"*.

```php
use Statamic\Fields\Fieldtype;

Fieldtype::appendConfigField('translatable', [
    'type' => 'toggle',
    'display' => 'Translate automatically',
    'default' => true,
]);
```

- **Where it shows:** the field settings stack in the blueprint/fieldset builder. `FieldsController::edit()`/`update()` build the form from `$fieldtype->configBlueprint()` (`src/Http/Controllers/CP/Fields/FieldsController.php:37-39, 76-78`), which merges extras into the only section or a new trailing one (`Fieldtype.php:209-252`). It also appears for **nested** fields (unlike `localizable`).
- **Where it is stored:** in the field's config in the blueprint/fieldset YAML. `FieldTransformer::inlineTabField()` strips values equal to the config field's default unless the config field has `'force_in_config' => true` (`src/Fields/FieldTransformer.php:51-59`, `src/Fields/ConfigField.php:16-19`).
- **Where to read it:**
  - `$field->get('translatable')` / `$field->config()['translatable'] ?? …` — raw; **missing when equal to the default**.
  - `$field->fieldtype()->config('translatable')` — merged with defaults (`Fieldtype.php:353-366`); prefer this.
  - In Vue: `props.config.translatable` (via `Field::toPublishArray()` → `preProcessedConfig()`, `Field.php:268-285, 434-448`).
- **Timing:** `configFields()` is Blink-cached per fieldtype handle (`Fieldtype.php:263-286`); register in the service provider's boot.
- **[NV]** The docs also show appending whole *sections* (an array without a string key). In the 6.19.0 source extras are mapped as `handle => field` (`Fieldtype.php:218-220, 275-279`), so I could not verify section support in this version.

---

## 9. `Statamic\Actions\Action` [S][D]

Base class: `src/Actions/Action.php` (abstract; traits `HasFields`, `HasHandle`, `HasTitle`, `RegistersItself`).

| Member | Line | Default / contract |
|---|---|---|
| `protected $confirm = true` | 18 | Show confirmation modal |
| `protected $dangerous = false` | 19 | Red button styling |
| `protected $fields = []` | 20 | Modal fields (`handle => field config`) |
| `protected $context = []` | 21 | Set via `context($context)`; `view` defaults to `'list'` (`:65-74`) |
| `protected $runnable = true`, `protected $component` | 22-23 | `runnable()`, `component()` (custom Vue modal component) |
| `$this->items` | 17, 30-35 | Selected items; available in `fieldItems()`, `confirmationText()`, etc. |
| `visibleTo($item)` | 37 | `true` |
| `visibleToBulk($items)` | 42 | True only if `visibleTo` passes for every item |
| `authorize($user, $item)` | 51 | `true` |
| `authorizeBulk($user, $items)` | 56 | True only if authorized on every item |
| `protected function fieldItems()` | 76 | Returns `$this->fields`; override for dynamic fields (core's `DeleteMultisiteEntry` declares it `public`) |
| `run($items, $values)` | 81 | Return nothing, a string message, or an array (`message`, `callback`) |
| `redirect($items, $values)` | 86 | Return URL or `false` |
| `download($items, $values)` | 91 | Return file path / `Response` or `false` |
| `icon()` | 96 | `$this->icon ?? ''` |
| `buttonText()` | 111 | `'Run action\|Run action on :count items'` (pluralised) |
| `confirmationText()` | 117 | `'Are you sure you want to run this action?\|…on :count items?'` |
| `warningText()` | 123 | `null` |
| `dirtyWarningText()` | 128 | Shown when the form has unsaved changes |
| `bypassesDirtyWarning()`, `triggersFullPageRefresh()`, `requiresElevatedSession()` | 134-147 | `false` |
| `static title()` | `HasTitle` | `__(static::$title ?? Str::title(Str::humanize(static::handle())))` |
| `static handle()` | `HasHandle` | `static::$handle` or snake-cased class name |

**Execution** (`src/Http/Controllers/CP/ActionController.php:16-76`): validate `action`, `selections`, `context` → resolve items → validate modal fields → `abort_unless` every item passes `authorize()` → `$values = $action->fields()->addValues(...)->process()->values()->all()` → `try { $response = $action->run($items, $values); } catch (Exception $e) { message = $e->getMessage() ?: 'Action failed'; success = false; }` → `redirect()` → `download()` → JSON `{message?, success, ...}`. When `context.view === 'form'` the response includes refreshed item data (`getItemData()`), so a form-view action can update the open publish form.

`run()` is **synchronous inside the HTTP request**; long work must be queued by the action itself.

**Visibility pipeline** (`src/Actions/ActionRepository.php:23-45`): `for($item, $context)` → `visibleTo` + `authorize`; `forBulk($items, $context)` → `visibleToBulk` + `authorizeBulk` (falls back to `for()` when one item is selected).

**Registration from an addon** (`src/Providers/AddonServiceProvider.php:61, 325-336`): `protected $actions = [MyAction::class];`, or auto-discovery of classes in the addon's `src/Actions/` folder extending `Action`, or `MyAction::register()`.

**Item types and contexts** (one global registry; every action is offered to every item type, so `visibleTo()` must type-check):

| Target | Resolved with | Controller | Context seen |
|---|---|---|---|
| Entries | `Entry::find($id)` | `Collections/EntryActionController.php` | list: `{collection}` (`resources/js/components/entries/Listing.vue:7`); form: `['collection' => handle, 'view' => 'form']` (`EntriesController.php:169`) |
| Terms | `Term::find($id)` (LocalizedTerm) | `Taxonomies/TermActionController.php` | `{taxonomy}`; form adds `view: form` |
| Collections | `Collection::find` | `Collections/CollectionActionController.php` | `['view' => 'form']` |
| Navs | `Nav::find` | `Navigation/NavigationActionController.php` | `['view' => 'form']` |
| Global sets | `GlobalSet::find` | `Globals/GlobalSetActionController.php` | `['view' => 'form', 'site' => $site]` |
| Assets | `Asset::find` | `Assets/ActionController.php` | `['container', 'folder', 'view' => 'form']` |
| Asset folders | `AssetFolder::find("{container}::{path}")` | `Assets/FolderActionController.php` | `container` |
| Forms | `Form::find` | `Forms/ActionController.php` | |
| Form submissions | `$form->submission($id)` | `Forms/SubmissionActionController.php` | `{form}` |
| Users | `User::find` | `Users/UserActionController.php` | |

- The entries listing auto-applies a Site filter set to `Site::selected()` (`src/Query/Scopes/Filters/Site.php:34-39`), so bulk selections are normally entries of the selected site — which may be localizations. The entry action context does **not** include the site; use `$entry->locale()`.
- Useful core references: `src/Actions/Publish.php` (visibleTo/visibleToBulk/authorize/texts/partial-failure exceptions), `src/Actions/DuplicateEntry.php` (roots vs localizations, `confirmationText()`/`warningText()` using `$this->items`, `redirect()` in form view), `src/Actions/DeleteMultisiteEntry.php` (`fieldItems()` with a `button_group`).

---

## 10. Events, quiet saves, and core listeners

### 10.1 Events per type [S]

All extend `Statamic\Events\Event`; `dispatch()` stamps `$event->authenticatedUser = User::current()` (`src/Events/Event.php:15-22`). `*Saving`/`*Creating` events halt on the first non-null listener response; if a listener returns `false`, `save()` returns `false`.

| Operation | Events, in order | Source |
|---|---|---|
| `Entry::save()` | `EntryCreating`* → `EntrySaving` → [persist] → descendants' saves → afterSave callbacks → `EntryCreated`* → `EntrySaved` → [propagation]. (*new only) | `Entry.php:400-479` |
| `Term::save()` | `TermCreating`* → `TermSaving` → [persist] → `TermCreated`* → `TermSaved` | `Term.php:213-250` |
| `LocalizedTerm::save()` | the above, then `LocalizedTermSaved` | `LocalizedTerm.php:387-394` |
| `Variables::save()` | `GlobalVariablesCreating`* → `GlobalVariablesSaving` → [persist] → `GlobalVariablesCreated`* → `GlobalVariablesSaved` | `Variables.php:129-166` |
| `GlobalSet::save()` | `GlobalSetCreating`* → `GlobalSetSaving` → [persist] → variables created/deleted (with events) → `GlobalSetCreated`* → `GlobalSetSaved` | `GlobalSet.php:91-141` |
| `NavTree::save()` | `NavTreeSaving` → [persist] → `NavTreeSaved` | `Tree.php:159-177`, `NavTree.php:42-50` |
| `CollectionTree::save()` | `CollectionTreeSaving` → (`CollectionTreeEntriesMovedOrRemoved`) → [persist] → `CollectionTreeSaved` | `CollectionTree.php:45-63` |
| `Nav::save()` | `NavCreating`* → `NavSaving` → `NavCreated`* → `NavSaved` | `Nav.php:34-55` |
| `Revision::save()` | `RevisionSaving` → `RevisionSaved` | `src/Revisions/Revision.php:134-145` |
| `Sites::save()` | `SiteCreated`/`SiteDeleted` per change, `SiteSaved` per site | `Sites.php:149-164` |

`EntrySaved` extras (`src/Events/EntrySaved.php`): `public $entry`, `public $initiator`, `isInitial()` → `$this->entry->id() === $this->initiator->id()`. For a localization re-saved because its origin was saved, `isInitial()` is `false` (`src/Entries/InitiatorStack.php`).

Blueprint events: `EntryBlueprintFound($blueprint, $entry = null)`, `TermBlueprintFound`, `GlobalVariablesBlueprintFound`, `NavBlueprintFound` let an addon add fields/columns at runtime; core's `src/Entries/AddSiteColumnToBlueprint.php:10-21` is the model (`$event->blueprint->ensureField('site', [...])`, limited to listing routes).

### 10.2 `saveQuietly()` [S]

Defined on (grep, complete): `Entry`, `Collection`, `Term`, `LocalizedTerm`, `Taxonomy`, `GlobalSet`, `Variables`, `Blueprint`, `Fieldset`, `Asset`, `AssetContainer`, `User`, `Form`, `Submission`. **Not** on `Tree`/`NavTree`/`CollectionTree`, `Nav`, `Revision`.

`Entry::saveQuietly()` (`:393-398`) skips `EntryCreating/Saving/Created/Saved`, saves descendants and propagated localizations quietly too, but still:

- runs afterSave callbacks → a tree save, with its events and listeners;
- runs `taxonomize()` and URI updates;
- leaves search indexes and the static cache **stale** (their listeners never fire).

### 10.3 Core listeners that react to saves [S]

| Listener | Events | Condition | Queued? | Effect |
|---|---|---|---|---|
| `Statamic\Git\Subscriber@commit` (`src/Git/Subscriber.php:33-44`) | All 51 events in `src/Events/Concerns/ListensForContentEvents.php:12-64`, incl. `EntrySaved`, `TermSaved`, `GlobalVariablesSaved`, `NavTreeSaved`, `CollectionTreeSaved`, `RevisionSaved`, `SiteSaved`, `AddonSettingsSaved`, `BlueprintSaved` | Subscribed only if `Statamic::pro()` (`src/Git/ServiceProvider.php:13-23`); acts only if `statamic.git.enabled && statamic.git.automatic` and the event is not in `statamic.git.ignored_events` | Dispatches `Statamic\Git\CommitJob` (`ShouldQueue`) on `statamic.git.queue_connection`, optionally delayed by `dispatch_delay` minutes (`src/Git/Git.php:89-99`) | `git add` + `git commit` over the tracked paths (`config/git.php:97-109`, incl. `content`, `resources/addons`, `resources/sites.yaml`), plus push if `statamic.git.push` |
| `Statamic\StaticCaching\Invalidate` (`src/StaticCaching/Invalidate.php:28-54`) | `EntrySaved`→`refreshEntry`, `LocalizedTermSaved`, `GlobalVariablesSaved`, `NavSaved`, `NavTreeSaved`, `CollectionTreeSaved`, `CollectionTreeEntriesMovedOrRemoved`, `AssetSaved`, `FormSaved`, `BlueprintSaved`, deletes | Subscribed only if `config('statamic.static_caching.strategy')` is set (`src/StaticCaching/ServiceProvider.php:81-82`) | `implements ShouldQueue` | `DefaultInvalidator`: flush everything when rules are `'all'`; otherwise the entry's URL plus **all descendants' URLs** plus configured rule URLs (`src/StaticCaching/DefaultInvalidator.php:32-62, 127-140`); re-warms if `background_recache` |
| `Statamic\Search\UpdateItemIndexes` (`src/Search/UpdateItemIndexes.php:18-55`) | `EntrySaved`, `TermSaved` (all localizations), `AssetSaved`, `UserSaved`, deletes, `EntryScheduleReached` | Always subscribed (`src/Search/ServiceProvider.php:36`) | `implements ShouldQueue` | `Search::updateWithinIndexes($item)` per index (`src/Search/Search.php:57-71`) |
| `Statamic\Listeners\UpdateTermReferences` | `TermSaved`, `TermDeleted` | `statamic.system.update_references !== false` | `ShouldQueue` | When the default slug changed: scans all content and rewrites references |
| `Statamic\Listeners\UpdateAssetReferences` | asset events | same | `ShouldQueue` | n/a for translation |
| `UpdateStructuredEntryUris`, `UpdateStructuredEntryOrderAndParent` | `CollectionTreeSaved` | always | sync | Recompute URIs/order/parent for changed IDs |

There is no batching or de-duplication of these per-save side effects.

### 10.4 What saving hundreds of entries costs [S]

Per `Entry::save()`:

1. `Entry::find($id)` to decide `isNew` (`:402`).
2. Stache write: file write, then **every index of the collection's store is updated and re-cached** (`src/Stache/Stores/BasicStore.php:112-125`; `Index::updateItem()` → `put` + `cache()`, `src/Stache/Indexes/Index.php:121-128`). Cost grows with collection size.
3. **Cascade:** `$this->directDescendants()->each->{$withEvents ? 'save' : 'saveQuietly'}();` (`:445`) — recursive, each with full events.
4. `taxonomize()` (`:447`) updates term association indexes.
5. URI recalculation when a route-relevant field is dirty (`:449-452, 481-518`).
6. afterSave callbacks (tree save + its events/listeners).
7. `EntrySaved` listeners (10.3).
8. Propagation for new roots in `propagate` collections.

Git specifics:

- When the commit job runs on a `sync` queue connection, each event runs `git add`/`commit` (and push) inline: hundreds of commits. (`dispatch_delay` is "Not supported by default `sync` queue driver", `config/git.php:59`.)
- On a real queue, the first job commits everything pending; later jobs find no changes (`groupTrackedContentPathsByRepo()` filters by `status()`, `Git.php:143-161`) but still run. `CommitJob` is not unique.
- Core's own bulk pattern: `Statamic\Git\Subscriber::withoutListeners(function () { ... })` (`src/Events/Subscriber.php:66-73`), used by `DataReferenceUpdater::saveItem()` (`src/Data/DataReferenceUpdater.php:175-184`, comment: *"Save item without triggering individual git commits, as these should be batched into one larger commit."*), followed by one event. `Git::dispatchCommit($message)` exists, but the `Git` class constructor throws when `statamic.git.enabled` is false (`Git.php:24-29`), so guard on config.
- An addon can register its own event for auto-commit with `Git::listen(MyEvent::class)` (`Git.php:36-39`).

Other levers that exist in core:

- `Stache::disableUpdatingIndexes()` (`src/Stache/Stache.php:205-210`); the only core user is the multisite command, together with `Stache::clear()`.
- `Search::updateWithinIndexes($entry)` to refresh search after quiet saves.
- `Statamic\Events\Subscriber::disable()` / `enable()` for subscribers extending that base class. `StaticCaching\Invalidate` and `Search\UpdateItemIndexes` do **not** extend it, so they cannot be toggled this way.
- `Entry::query()->lazy($chunkSize = 1000)` and `->chunk($count, $callback)` exist on both Stache and Eloquent builders (`src/Query/Builder.php:839, 887`; `src/Query/EloquentQueryBuilder.php:559, 629`).

---

## 11. Revisions [S][D]

**Enabled when** all three hold: `config('statamic.revisions.enabled')` (`env('STATAMIC_REVISIONS_ENABLED', false)`, `config/revisions.php:16`), `Statamic::pro()`, and `revisions: true` on the collection (`Collection::revisionsEnabled()` `:692-704`). Check with `$entry->revisionsEnabled()` (`Entry.php:931-934`). Entries only; terms/taxonomies return false. `Collection::defaultPublishState()` returns `false` when revisions are on (`:656-664`).

**Storage:** Stache store `revisions`, default `storage/statamic/revisions` (`config/stache.php:108-113`); key `collections/{collection}/{site}/{id}` (`Entry::revisionKey()` `:745-752`); files `{key}/{timestamp}.yaml` and `{key}/working.yaml` (`Revision::path()` `:92-99`). Per localization.

**Revision attributes** (`Entry::revisionAttributes()` `:754-763`): `id`, `slug`, `published`, `date`, `data` = `$this->data()->except(['updated_by', 'updated_at'])`.

**API** (`src/Revisions/Revisable.php`):

| Method | Line | Behaviour |
|---|---|---|
| `revisions()` / `revision($ref)` / `latestRevision()` | 12-25 | Non-working revisions keyed by timestamp |
| `makeRevision()` | 27 | Unsaved `Revision` (action defaults to `'revision'`) from current attributes |
| `makeWorkingCopy()` | 35 | Unsaved revision with action `'working'` |
| `workingCopy()` / `hasWorkingCopy()` | 53-65 | |
| `fromWorkingCopy()` | 44 | If revisions are off: `$this`. Else `makeFromRevision($this->workingCopy())`: a **clone** with `published`, `data`, `slug` (and date) replaced from the working copy; if none exists, the clone is unchanged (`Entry::makeFromRevision()` `:765-787`) |
| `saveToWorkingCopy()` | 67 | Updates the existing working copy; returns `false` if revisions are off or none exists |
| `deleteWorkingCopy()` | 79 | |
| `publishWorkingCopy($options = [])` | 84 | `$item = $this->fromWorkingCopy(); $item->published(true)->updateLastModified($user)->save();` → revision with action `'publish'` → delete working copy. Returns the item (or `false`). |
| `unpublishWorkingCopy($options = [])` | 113 | Same with `published(false)` and action `'unpublish'` |
| `store($options = [])` | 142 | `published(false)->updateLastModified($user)->save()` + revision |
| `createRevision($options = [])` | 160 | `$this->fromWorkingCopy()->makeRevision()->user(...)->message(...)->save()` |

`Publishable::publish($options = [])` / `unpublish()` (`src/Data/Publishable.php:20-44`) route to the working-copy methods when revisions are enabled; otherwise `published(true|false)->save()`. Options: `user`, `message`.

**What the CP does:**

| CP action | Code | Effect |
|---|---|---|
| Open edit form | `EntriesController::edit()` `:100` `$entry = $entry->fromWorkingCopy();` | Shows working-copy data |
| Save, revisions on, entry **published** | `:249-256` `$entry->makeWorkingCopy()->user(User::current())->save(); $entry = $entry->fromWorkingCopy();` | **Only the working copy is written.** Entry file untouched; no `EntrySaved`. |
| Save, revisions on, entry **unpublished** | `:257-263` `$entry->updateLastModified(User::current())->save();` | Entry saved directly; no revision |
| Save, revisions off | same branch; `published` set from the request if the user may publish | |
| Create, revisions on | `store()` `:430-434` → `$entry->store([...])` | Draft + revision |
| Publish / Unpublish | `PublishedEntriesController::store()/destroy()` → `$entry->publish([...])` / `unpublish([...])` | Entry saved from working copy + `'publish'`/`'unpublish'` revision; working copy deleted |
| Create revision | `EntryRevisionsController::store()` → `$entry->createRevision([...])` | |
| Restore | `RestoreEntryRevisionController` | Published: revision becomes the working copy. Unpublished: `makeFromRevision($target)->published(false)->save()` |

**Programmatic implications:**

- `$entry->save()` always writes the real entry and creates no revision or working copy.
- If a published entry has a working copy, a direct `save()` is invisible in the CP form (which loads the working copy), and a later Publish **overwrites it** with the working copy's data.
- `$entry->publish()` on a modified in-memory entry **loses the modifications if a working copy exists** (`publishWorkingCopy()` starts from `fromWorkingCopy()`). To publish programmatic changes, write them into the working copy first (`$entry->makeWorkingCopy()->user($user)->save()`), then `publish()`.
- CP-created localizations go through `store()`, so they are drafts with one revision.

---

## 12. Addon settings, permissions, nav, editions

### 12.1 Native addon settings (new in v6) [S][D]

- **Declaring:** `resources/blueprints/settings.yaml` in the addon is auto-registered (`AddonServiceProvider::bootSettingsBlueprint()` `:825-836`), or call `$this->registerSettingsBlueprint(array $blueprint)` in `bootAddon()` (`:818-823`).
- **CP page:** `GET|PATCH {cp}/addons/{addon}/settings` (`routes/cp.php:336-337`), `AddonSettingsController` (`src/Http/Controllers/CP/Addons/AddonSettingsController.php`), built with `Statamic\CP\PublishForm`. A nav item named after the addon is added automatically: as a child of Tools › Addons for users with `configure addons`, otherwise as a standalone Tools item gated by `->can('editSettings', $addon)` (`src/CP/Navigation/CoreNav.php:263-271, 294-305`).
- **Permission:** `"edit {$addon->package()} settings"`, auto-registered for addons with a settings blueprint (`CorePermissions.php:223-235`); `configure addons` or super grants everything (`src/Policies/AddonPolicy.php:9-28`).
- **Storage:** `resources/addons/{slug}.yaml` (`src/Addons/FileSettings.php:12-15`), plain YAML of the **raw** values. Git-tracked by default (`config/git.php:100`). Eloquent driver can store them in the DB (`'addon_settings' => ['driver' => …]`) [E].
- **API** (`src/Addons/Addon.php:351-386`, `src/Addons/Settings.php`, contract `src/Contracts/Addons/Settings.php`):

```php
use Statamic\Facades\Addon;

$addon = Addon::get('vendor/package');            // by composer package name
$addon->hasSettingsBlueprint();  $addon->settingsBlueprint();  $addon->settingsUrl();
$addon->setting('api_key', $default);             // shortcut for settings()->get()
$settings = $addon->settings();                   // Statamic\Contracts\Addons\Settings
$settings->all();                                 // resolved values (blueprint defaults applied)
$settings->raw();                                 // unresolved, as stored
$settings->get('nested.key', $default);           // dot notation
$settings->set('key', $value);  $settings->set([...]);   // array form replaces everything
$settings->save();                                // AddonSettingsSaving → repository → AddonSettingsSaved
$settings->delete();
```

- **Env/config references:** values are Antlers-parsed with a `config` variable: `return (string) Antlers::parse($value, ['config' => Cascade::config()]);` (`Settings.php:105-114`). Docs example: `{{ config:services:example:api_key }}`. There is no direct `env()` access; go through a config key.
- **Typing pitfall:** every resolved scalar is cast to **string** (`Settings.php:113`). Use `raw()` for booleans/integers. **[NV]** the exact string produced for `true`/`false` (not executed).
- **Encryption: none.** No `encrypt`/`Crypt` in `src/Addons`, `src/CP/PublishForm.php` or `src/Fieldtypes`. A key typed into the settings page is stored in plain text and committed by git automation unless the user enters an Antlers config reference.
- Classic config still works: `{addon}/config/{slug}.php` is merged and publishable (`AddonServiceProvider::bootConfig()` `:467-488`).

### 12.2 Permissions [S][D]

```php
use Statamic\Facades\Permission;

Permission::extend(function () {
    Permission::group('autotranslator', 'Auto Translator', function () {
        Permission::register('translate entries')->label('Translate entries');
        Permission::register('translate {collection} entries', function ($permission) {
            $permission->label('Translate :collection entries')
                ->replacements('collection', fn () => Collection::all()->map(fn ($c) => ['value' => $c->handle(), 'label' => $c->title()]));
        });
    });
});
```

- `Permissions::extend($callback)` (`src/Auth/Permissions.php:39-42`), `register($permission, $callback = null)` (`:55-68`), `group($name, $label, $permissions = null)` (`:121-138`), `make()` (`:44-53`).
- `Statamic\Auth\Permission` fluent methods: `label()`, `description()`, `children([...])`, `addChild()`, `replacements($placeholder, $callback)`, `group()`.
- Check with `$user->hasPermission('translate entries')` or gates/policies (`protected $policies` on the addon provider).
- Relevant core permissions: `access {site} site`, `view|edit|create|delete|publish|reorder {collection} entries`, `edit other authors {collection} entries`, `edit {taxonomy} terms`, `edit {global} globals`, `edit {nav} nav`, `configure addons` (`CorePermissions.php`).

### 12.3 CP nav [S][D]

```php
use Statamic\Facades\CP\Nav;

Nav::extend(function ($nav) {
    $nav->tools('Translations')              // magic: findOrCreate(section, name)   Nav.php:185-188
        ->route('autotranslator.index')
        ->icon('earth')
        ->can('translate entries')
        ->children([
            $nav->item('Queue')->route('autotranslator.queue'),
        ]);
});
```

- `Nav::extend(Closure)` (`src/CP/Navigation/Nav.php:16-19`), `create($name)` / `item($name)` (`:27-45`), `find`, `findOrCreate`, `remove`.
- `NavItem` (`src/CP/Navigation/NavItem.php`): `section()`, `route($name, $params = [])`, `url()`, `icon()`, `can($ability, $arguments = [])`, `children()`, `view()`, `order()`, `hidden()`, `attributes()`, `extra()`.
- CP routes: `routes/cp.php` in the addon root or `$routes['cp']` (`AddonServiceProvider::bootRoutes()` `:534-569`).
- Alternative surface: Utilities — `Utility::extend(fn () => Utility::register('handle')->inertia('component', fn)->title()->icon()->description()->routes(fn ($router) => …))` (`src/Facades/Utility.php`, `src/CP/Utilities/CoreUtilities.php`), with an automatic `access {handle} utility` permission.

### 12.4 Editions and licensing [S][D]

- Declare in the addon's `composer.json`: `"extra": {"statamic": {"editions": ["free", "pro"]}}`, read by `Manifest::formatPackage()` (`src/Addons/Manifest.php:55`). The first is the default.
- The site selects one in `config/statamic/editions.php`: `'addons' => ['vendor/package' => 'pro']`.
- Check:

```php
// src/Addons/Addon.php:486-495
public function edition()
{
    $configured = config('statamic.editions.addons.'.$this->package());
    if ($configured && ! $this->editions()->contains($configured)) {
        throw new \Exception("Invalid edition [$configured] for addon ".$this->package());
    }
    return $configured ?? $this->editions()->first();
}
```

  `Addon::get('vendor/package')->edition() === 'pro'`.
- Licence object: `$addon->license()` → `Statamic\Licensing\AddonLicense` (`valid()`, `invalidReason()`, `versionLimit()`, `existsOnMarketplace()`, `edition()`). Statamic's outpost request includes each addon's `version` and `edition` (`Outpost.php:129-137`). Docs: *"You don't need to check whether a license is valid, Statamic does that automatically for you."*
- Other `extra.statamic` keys: `name`, `slug`, `description`, `url`, `developer`, `developer-url` (`Manifest.php:52-69`). `$addon->isCommercial()` comes from marketplace data.

---

## 13. Storage drivers

### 13.1 Architecture [S][E]

- Core binds repository contracts to Stache implementations **only if not already bound** (`AppServiceProvider.php:150-172`). `statamic/eloquent-driver` rebinds them per content type.
- Eloquent driver config (`config/eloquent-driver.php`, master): independent `'driver' => 'file' | 'eloquent'` switches for `addon_settings`, `asset_containers`, `assets`, `blueprints`, `collections`, `collection_trees`, `entries`, `fieldsets`, `forms`, `form_submissions`, `global_sets`, `global_set_variables`, `navigations`, `navigation_trees`, `revisions`, `taxonomies`, `terms`, `tokens`, `sites`. Mixed setups are normal.

### 13.2 Unknown keys in entry data

| Scenario | Stache (flat files) [S] | Eloquent driver [E] |
|---|---|---|
| Root entry, programmatic `save()` | Preserved (`fileData()` merges all of `data()`; `null` / `''` / `[]` values removed) | Preserved in the `data` JSON column, except keys equal to column names (`id, site, origin_id, published, slug, uri, data, date, collection, created_at, updated_at, order, blueprint`); nulls filtered |
| Root entry, CP save | Preserved (`$entry->merge($values)`) | Preserved (same controller) |
| **Localization**, programmatic `save()` | Preserved | **Not preserved** — only blueprint fields with `localizable: true` are kept as localized; other keys are not re-hydrated and vanish on the next save |
| **Localization**, CP save | **Dropped** (`$entry->data($values->only($_localized))`) | Dropped |
| Localized term, CP save | Dropped (`TermsController.php:187-191`) | — |
| Global variables, CP save | **Dropped for origin and localized** (`$set->data($values)`) | — |
| Nav branch `data`, CP edit of that branch | Rebuilt from nav-blueprint fields (`NavigationTreeController.php:62-85`) | — |

Core itself stores a non-blueprint key on duplicated entries (`'duplicated_from' => $original->id()`, `DuplicateEntry.php:82`).

A key that is a real blueprint field with `visibility: hidden` and `localizable: true` survives CP saves on Stache: hidden fields are not shown but their values are still submitted (`omitValue: false`, `resources/js/components/field-conditions/ShowField.js:23-31`), and the key is in `_localized` because it exists in the data. It would also pass the Eloquent filter. This is fragile and was not executed.

### 13.3 Eloquent driver behaviour for localizations [E]

`Statamic\Eloquent\Entries\Entry::makeModelFromContract()` (master `src/Entries/Entry.php:55-144`):

```php
if ($source->hasOrigin()) {
    if ($blueprint = $source->blueprint()) {
        $localizedBlueprintFields = $blueprint->fields()->localizable()->all()->map->handle()->all();
        $originData = $origin->data();
        $localizedFields = [];
        foreach ($localizedBlueprintFields as $blueprintField) {
            if ($data->has($blueprintField)) {
                if ($data->get($blueprintField) === $originData->get($blueprintField)) {
                    $data->forget($blueprintField);
                } else {
                    $localizedFields[] = $blueprintField;
                }
            }
        }
        $data = $originData->merge($data);
        $data->put('__localized_fields', $localizedFields);
        ...
```

and on read (`fromModel()`, `:18-20`):

```php
$data = isset($model->data['__localized_fields'])
    ? collect($model->data['__localized_fields'])->mapWithKeys(fn ($field) => [$field => $model->data[$field] ?? null])
    : $model->data;
```

Consequences:

1. Only fields with **`localizable: true`** can be overridden on a localization. A translated `title` is silently lost unless the blueprint marks `title` localizable.
2. A localized value **identical to the origin's** (`===`) is un-localized.
3. The localization row stores the origin's data merged in, so the row changes whenever the origin's data changes.
4. `updated_at` comes from the model column: `fromModel()` sets `updated_at` from `$model->updated_at`, `lastModified()` returns it, and `makeModelFromContract` writes `'updated_at' => $source->lastModified()`. Combined with (3) and the save cascade, a localization's `updated_at` moves whenever its origin's data changes (inferred from the code plus Eloquent's dirty-timestamp behaviour; not executed).
5. `updated_by` on a localization is not in `__localized_fields`, so `lastModifiedBy()` is null for localizations.
6. `makeLocalization($site)` copies the origin's data (`parent::makeLocalization($site)->data($this->data())`).
7. `Entry::find()` is Blink-cached per ID (`EntryRepository::find()`).
8. `taxonomize()` is skipped when taxonomies are eloquent-driven.
9. Global variables: nulls are filtered for all sites (`'data' => $data->filter(fn ($v) => $v !== null)`), so an explicit `null` override cannot be stored (an empty string passes that filter).
10. Terms reuse core `fileData()` (default data + `localizations`), so the terms API is the same.

### 13.4 Driver-agnostic rules that follow

Do:

- Use facades and object methods only: `Entry::query()/find()/make()`, `$entry->save()`, `Term`, `GlobalSet`, `Nav`, `Collection`, `Site`, `Blueprint`, `Addon::get()->settings()`.
- Query with `where('site', …)`, `where('origin', …)`, `where('collection', …)`.
- Translate only top-level fields with `localizable: true`; treat a text field without it as "inherited" and report it.
- Keep translation metadata outside content data.
- Expect "same as origin" to collapse into "inherited" on Eloquent.

Avoid:

- Reading or writing content files, `->path()`, `->initialPath()`, `fileLastModified()`, `Stache::store(...)`.
- Relying on file mtimes or on comparing `updated_at` between origin and localization.
- Relying on explicit `null` overrides being stored everywhere (not for Eloquent global variables).
- `where('locale', …)` in entry queries.

---

## 14. Console commands

### 14.1 Registration from an addon [S][D]

```php
// src/Providers/AddonServiceProvider.php:408-421
protected function bootCommands()
{
    if ($this->app->runningInConsole()) {
        $commands = collect($this->commands)
            ->merge($this->autoloadFilesFromFolder('Commands', Command::class))
            ->merge($this->autoloadFilesFromFolder('Console/Commands', Command::class))
            ->unique()->all();
        $this->commands($commands);
    }
    return $this;
}
```

- Either list classes in `protected $commands = [...]` or place them in `src/Commands/` or `src/Console/Commands/`.
- Registered **only when running in console**. `Artisan::call()` from a web request will not find them; keep logic in services/jobs.
- Scheduling: override `protected function schedule(Schedule $schedule)` (`:731-734`).

### 14.2 `php please` conventions [S]

- Signature prefixed `statamic:` plus the trait `Statamic\Console\RunsInPlease` → `php please foo:bar` and `php artisan statamic:foo:bar` (`src/Console/RunsInPlease.php:27-38` strips the prefix).
- The trait must be used **directly on the command class**: `in_array(RunsInPlease::class, class_uses($command))` (`src/Console/Please/Application.php:31`); `class_uses()` ignores parent classes.
- Optional `protected $hiddenInPlease`.
- Core style: `EnhancesCommands` (`checkLine()`, `crossLine()`), `ValidatesInput`, `Illuminate\Console\ConfirmableTrait`, `Laravel\Prompts` (`confirm`, `text`, `progress`), `$this->components->info/warn/error/task/bulletList`.

### 14.3 Core commands worth reading [S]

| Command | File | Why |
|---|---|---|
| `statamic:multisite` | `src/Console/Commands/Multisite.php` | The only core multisite command. Shows `Site::setSites([...])->save()`, `$collection->sites([...])->save()`, `$nav->makeTree($site, $tree)->save()`, role permissions, `Stache::disableUpdatingIndexes()` + `Stache::clear()`, silencing Partyline, and a `Hookable` `after` hook (`Multisite::hook('after', fn)`). |
| `statamic:migrate-dates-to-utc` | `src/Console/Commands/MigrateDatesToUtc.php` | Closest structural analogue: iterates all content lazily, recurses through group/grid/replicator/bard, mutates with `Arr::set`, `saveQuietly()` only `if ($item->isDirty())`, `progress()` bar. |
| `statamic:stache:refresh/warm/clear/doctor` | `Stache*.php` | Cache maintenance after bulk changes |
| `statamic:search:update` | `src/Search/Commands/Update.php` | Rebuild search after quiet saves |
| `statamic:static:clear` / `static:warm` | `StaticClear.php`, `StaticWarm.php` | Static cache after bulk changes |
| `statamic:git:commit` | `src/Git/CommitCommand.php` | Manual commit of tracked paths |
| `statamic:pro:enable` | `ProEnable.php` | Used by `Statamic::enablePro()` |

No core command adds a site, localizes entries, or copies content between sites.

---

## 15. Change tracking between origin and localizations [S][E]

**Verified: nothing in core detects "origin changed since the localization was last updated".** Grep over `src/` and `resources/js/` for out-of-sync/outdated/stale/needs-translation concepts found nothing relevant. What exists:

| Mechanism | What it is | Why it is not enough |
|---|---|---|
| `updated_at` / `updated_by` | Keys in each entry's (or localized term's) **own** data. Written only by `updateLastModified($user = null)` (`src/Data/TracksLastModified.php:24-35`), called from CP update/store, `store()`, publish/unpublish, `DuplicateEntry`. Disabled entirely by `statamic.system.track_last_update = false`. | Plain `save()` does not touch it. No field-level granularity (changes on every CP save). Global variables have none. On Eloquent it tracks the row, which changes with the origin (§13.3). |
| `lastModified()` | `updated_at` from own data, else file mtime (`TracksLastModified.php:10-15`) | A localization's file is rewritten on every origin save (cascade), so mtime is meaningless. |
| `HasDirtyState` | `isDirty($attributes = null)`, `isClean()`, `getOriginal($key = null, $fallback = null)`, `syncOriginal()` (`src/Data/HasDirtyState.php`). Entry attributes: collection, locale, origin, slug, date, published, path + data except `updated_at` (`Entry.php:233-244`). | In-memory only: "changed since this instance was loaded". Original state is synced on Stache retrieval (`BasicStore.php:26-40`) and at the end of `save()` (`Entry.php:476`). |
| Save cascade + `EntrySaved::isInitial()` | Lets a listener tell an origin edit from a cascaded re-save | Signals a save, not what changed |
| Revisions | Per-localization snapshots | No cross-localization comparison |
| CP sync state | `localizedFields` = keys present in the localization | Says a field is overridden, not that the override is stale |

Building blocks that are available:

- In `EntrySaving`/`EntrySaved` listeners, `$event->entry->isDirty('field')`, `isDirty(['a','b'])` and `getOriginal('field')` still reflect the pre-save state, because `syncOriginal()` runs after `EntrySaved` is dispatched (`Entry.php:463` vs `:476`). Queued listeners receive a serialized entry including `original`.
- Entries loaded through the Eloquent driver also call `syncOriginal()` in `fromModel()`.
- With revisions enabled, edits to a published origin only reach `EntrySaved` at publish time.

---

## 16. Writing values programmatically

### 16.1 API [S]

```php
// Top-level:
$entry->set('title', 'Bonjour');             // ContainsData::set                    :21-26
$entry->merge(['title' => '…', 'intro' => '…']);                                  // :67-72
$entry->remove('intro');                     // localization: back to inheriting     :40-45
$entry->set('intro', null);                  // localization: explicit empty override
$entry->data([...]);                         // REPLACES all data (drops updated_at etc.)   :56-65

// Nested (core's own pattern — AssetReferenceUpdater.php:37-62, MigrateDatesToUtc.php:140-156):
$data = $entry->data()->all();
Arr::set($data, 'blocks.2.heading', 'Titre');                 // replicator set
Arr::set($data, 'body.4.attrs.values.caption', 'Légende');    // bard set
$entry->data($data);

// Persist:
$entry->updateLastModified($user);           // optional; sets updated_at / updated_by
$ok = $entry->save();                        // false if an EntryCreating/EntrySaving listener returned false
```

`Statamic\Support\Arr` (`src/Support/Arr.php`) is a standalone class that forwards unknown static calls to `Illuminate\Support\Arr` (`__callStatic`, `:321-324`) and wraps `set(&$array, $key, $value)` (`:280`), `pull` (`:295`) and `forget` (`:309`). Its own `get()` / `has()` convert `:` to `.` in the key (`:21-44`), so both act as path separators there.

### 16.2 Pitfalls [S]

1. **A localization's `data()` holds only overridden keys.** `Arr::set($data, 'blocks.2.heading', …)` on a localization that inherits `blocks` creates a broken sparse array. Start from the resolved value: `$value = $loc->value('blocks'); /* modify */ $loc->set('blocks', $value);` — the whole top-level value becomes localized.
2. **Never write `values()` back** (it includes origin, `inject` and computed values).
3. **`data($array)` replaces everything**, including `updated_at`/`updated_by` and `blueprint`. Prefer `set`/`merge`.
4. **`process()` / `preProcess()` do not run.** They are only called by CP controllers (`$fields->addValues($request)->process()->values()` on save, `->preProcess()` on load). Write the **stored shape** (§7.3). Examples: `list` stores ints for numeric strings; `array` with `expand` stores `[{key, value}]`; `code` may be `{code, mode}`; `yaml` stores a parsed array; Bard with `save_html` stores HTML; `select` with `cast_booleans` stores booleans; row `id`s and replicator `enabled` are part of the stored rows. Running a value through `$field->fieldtype()->process()` is not a safe normaliser: it expects CP-shaped input (`_id` instead of `id`, Bard set values with `_id`, etc.).
5. **Keep structural keys intact** when translating nested content: replicator/grid `id`, replicator `type`/`enabled`, Bard `attrs.id` / `attrs.enabled` / `attrs.values.type`, nav branch `id`/`entry`.
6. **Computed fields:** `Collection::computed()` callbacks win in `value()`/`values()` but are never stored (`src/Data/ContainsComputedData.php`); blueprint fields with `visibility: computed` are excluded from CP saves (`Fields.php:172-177`). Skip both.
7. **Non-data properties:** slug (`$entry->slug()`), published (`$entry->published()`), date (`$entry->date()`), blueprint, origin, locale.
8. **Auto titles:** with `title_format`, `save()` regenerates the title in the entry's site language (`Entry.php:420-427, 1080-1099`).
9. **Slug changes** make the route dirty → URI recalculation for mounted collections and child pages (`:449-452`). Flat-file name collisions are resolved with a numeric suffix (`CollectionEntriesStore.php:194-230`), but duplicate URIs are not prevented outside the CP.
10. **Empty values vanish on roots:** `fileData()` removes top-level `null`, `''` and `[]` values on root entries (`Arr::removeNullValues`, §2.1); on localizations they are kept and mean "override with empty".
11. **Antlers-enabled fields** (`antlers: true` on text/textarea/markdown/bard) can contain `{{ … }}` code.
12. **No validation** on programmatic saves (required, `character_limit`, `max_items`, slug rules, unique URI).
13. **Blink caches** are per-process: `entry-{id}-blueprint`, `entry-descendants-{id}`, `structure-*`, `entry-uris`, collection trees. `save()` clears the relevant ones (`:433-441`); in long-running processes re-fetch entries rather than holding stale instances.
14. **Check before creating:** `makeLocalization()` does not check for an existing localization; `in($site)` returns null when missing.
15. **Revisions** (§11): a working copy on a published entry shadows direct saves.
16. **Term slugs:** changing the default-locale slug renames the term and rewrites references; localized slugs do not.
17. **Tree saves cannot be quiet** (§10.2).
18. **Dotted paths are unsafe for user-supplied keys.** Blueprint handles and numeric indexes are fine, but `array`-fieldtype keys are free text and may contain `.` (and `:` for `Arr::get`/`has`). Walk such arrays directly instead of addressing them by dotted path.
19. **`updateLastModified()` without a user throws on a `LocalizedTerm`** (by code reading, not executed): the trait calls `$this->remove('updated_by')` when `$user` is null (`TracksLastModified.php:30-32`), `LocalizedTerm` defines no `remove()`, and `HasAugmentedInstance::__call()` throws `BadMethodCallException` (`src/Data/HasAugmentedInstance.php:123-134`). Entries are fine (`ContainsData::remove()`). In a CLI context either pass a user or set `updated_at` manually.

### 16.3 Code paths core itself uses (consistent with the above)

```php
// Create-or-get a localization, mirroring LocalizeEntryController + DuplicateEntry:
$loc = $origin->in($site) ?? $origin->makeLocalization($site);   // afterSave callback handles the tree
$loc->merge($translatedTopLevelFields);
$loc->slug($slug);
$loc->published(false);                                          // CP parity: drafts
$loc->updateLastModified($user)->save();                         // or ->store(['user' => $user]) for draft + revision

// Terms:
Term::find("tags::{$slug}")?->in($site)?->merge($translated)->save();

// Globals:
$set->sites($set->origins()->put($site, $originSite)->all())->save();   // enable the site with an origin
$set->in($site)->merge($translated)->save();

// Nav:
($nav->in($site) ?? $nav->makeTree($site))->tree($translatedBranches)->save();
```

---

## 17. Could not verify

1. **Extra licence cost per additional site.** Docs say Pro includes multi-site; no per-site fee found; no site count in the licensing payload.
2. **Which localization `Term::find()` returns in multi-site** (likely default site by index order; not executed).
3. **Exact string values `Settings::get()` returns for booleans/integers** (cast to string in code; not executed).
4. **Appending whole config *sections* via `appendConfigFields`** (in docs; not supported by the 6.19.0 code as read).
5. **Real multi-site file layouts.** umami is single-site; layouts come from store classes and docs.
6. **Eloquent driver behaviour** comes from GitHub master (tags to v5.12.1), not an installed copy; the `updated_at` bump on cascade is inferred, not executed.
7. **Runtime behaviour in general** (event ordering, Blink, queue interplay): from reading code; the app was never booted.
8. **Bard round-trip through `Augmentor` itself** was not run (needs the app); the lossy results come from tiptap-php with Statamic's extension classes.
9. **Survival of a hidden localizable metadata field across CP saves** — deduced from `ShowField.js` and the controller; not executed.
10. **"Inline sets"** — no such concept in 6.19.0 core; if a third-party extension provides them, their shape is unknown.
11. **`LocalizedTerm::updateLastModified()` without a user throwing** (§16.2 item 19) — deduced from the code path, not executed.
12. **Which listing/site an entry bulk action's items come from in every CP view** — verified for the collection entries listing (Site filter auto-applies `Site::selected()`) and the entry form; other entry pickers were not traced.
