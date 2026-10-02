# 03 — Statamic 6 Control Panel front-end extension points

Research for the AI auto-translator addon. Read-only. Date: 2026-10-01.

## 0. Scope, sources, how to read this report

**Sources**

| Tag | Source |
|---|---|
| `[SRC]` | Read in the installed Statamic **6.19.0** source (`/Volumes/1TB/Sites/umami/vendor/statamic/cms`, composer.lock `v6.19.0`, released 2026-05-13). Paths below are relative to that directory unless absolute. |
| `[RUN]` | Executed. The real 6.19.0 files were loaded headlessly in Node 22 (in-memory ESM loader + `@vue/compiler-sfc` / `@vue/runtime-core` 3.5.34 taken from the resrv addon's `node_modules`), with stubs only for UI primitives and the `Statamic` global. No files were written. Not a browser test. |
| `[DOC]` | Official v6 docs (`https://statamic.dev/...`, raw markdown via the `.md` URLs; `llms.txt` states "This is the documentation for Statamic 6"). |
| `[1P]` | First-party addon source on GitHub: `statamic/collaboration` (master, requires `statamic/cms ^6.15.0`), `statamic/importer` (2.x, requires `^6.25`). |
| `[UP]` | Upstream `statamic/cms` branch `6.x` on GitHub, latest tag in its CHANGELOG **6.34.1 (2026-09-23)**. Compared file-by-file with the installed 6.19.0. |
| `[UNVERIFIED]` | Could not verify (reasoned from code, not executed in a browser, or not found). |

**Important context:** the reference project runs 6.19.0, which is 15 minor versions behind upstream. Several multi-site quirks found in 6.19.0 are fixed upstream (section 10.2). The addon's minimum supported version changes what the task list must work around.

**Globals available to addon JS** `[SRC]` `resources/js/index.js:17-22`, `resources/js/bootstrap/globals.js`:
`window.Statamic`, `window.Vue`, `window.Pinia`, `window.__STATAMIC__` (backing object for the `@statamic/cms` package), and helper functions `cp_url`, `docs_url`, `resource_url`, `data_get`, `data_set`, `clone`, `__`, `__n`, `uniqid`, `escapeHtml`, `markdown`, `str_slug`, `snake_case`, `truncate`.

---

## 1. Entry publish form localization flow

### 1.1 Components

| Piece | File |
|---|---|
| Inertia page `entries/Edit` | `resources/js/pages/entries/Edit.vue` (renders `EntryPublishForm` with `publish-container="base"`, `method="patch"`, lines 39-69) |
| Publish form | `resources/js/components/entries/PublishForm.vue` (Options API, 893 lines) |
| Site/localization switcher | `resources/js/components/ui/Publish/Localizations.vue` (exported as `PublishLocalizations`; imported as `LocalizationsCard`, `PublishForm.vue:283`) and row component `ui/Publish/Localization.vue` |
| Container (state owner) | `resources/js/components/ui/Publish/Container.vue` |

The switcher is rendered in the `#actions` slot of `PublishTabs` `[SRC]` `entries/PublishForm.vue:168-173`:

```vue
<LocalizationsCard
    v-if="showLocalizationSelector"
    :localizations
    :localizing="localizing !== null"
    @selected="localizationSelected"
/>
```

- Shown when `localizations.length > 1` (`PublishForm.vue:467-469`).
- Panel heading is `__('Sites')`; up to 5 sites render as buttons, more than 5 as a `Combobox` (`Localizations.vue:32-61`). Row shows badges Origin / Active / Root and a red, struck-through style when `!localization.exists` (`Localization.vue:18-34`).
- The `#actions` slot is the sidebar when the container is wider than 920 px, otherwise it is appended inside every tab (`ui/Publish/Tabs.vue:46, 212, 216-221`).
- Not to be confused with the global header site selector (`components/global-header/SiteSelector.vue:41-45`): that does `window.location = cp_url('select-site/' + handle)` (full reload, `SelectSiteController.php:11-22`) and only changes the CP's selected site.

Each item of `localizations` `[SRC]` `src/Http/Controllers/CP/Collections/EntriesController.php:147-163`:

```php
'handle', 'name', 'active', 'exists',
'root'   => $exists ? $localized->isRoot() : false,
'origin' => $exists ? $localized->id() === optional($entry->origin())->id() : null,
'published', 'status',
'url'    => $exists ? $localized->editUrl() : null,
'livePreviewUrl'
```

### 1.2 Click handling (client) `[SRC]` `entries/PublishForm.vue:632-669`

```js
localizationSelected(localization) {
    if (!this.canSave) {                       // read-only OR any $progress task running
        if (localization.exists) this.editLocalization(localization);
        return;
    }
    if (localization.active) return;
    if (this.isDirty) { this.pendingLocalization = localization; return; }   // "Unsaved Changes" modal
    this.switchToLocalization(localization);
},
switchToLocalization(localization) {
    this.$dirty.remove(this.publishContainer);
    this.localizing = localization;
    if (localization.exists) {
        this.editLocalization(localization);
    } else if (this.localizations.length > 2 && this.originBehavior === 'select') {
        this.selectingOrigin = true;                                         // "Create Localization" modal
    } else {
        this.createLocalization(localization);
    }
    if (this.isBase) window.history.replaceState({}, '', localization.url);
},
```

Prompts:

1. **Unsaved changes** (only if the container is dirty): `confirmation-modal` titled "Unsaved Changes", body "Are you sure? Unsaved changes will be lost.", button "Continue" (`PublishForm.vue:242-250`).
2. **Origin selection** (only if the target does not exist AND `localizations.length > 2` AND `originBehavior === 'select'`): modal "Create Localization" with a `Select` labelled "Origin" (instructions `messages.entry_origin_instructions`: "The new localization will inherit values from the entry in the selected site."), button "Create" (`PublishForm.vue:228-240`). Options are the existing localizations (`originOptions`, `:516-523`).
3. In every other case there is **no confirmation**: clicking a non-existent site immediately creates and stores the localization.

`originBehavior` comes from the collection: `'select'` (default) | `'root'` | `'active'` (`src/Entries/Collection.php:61, 666-680`). `selectedOrigin` is initialised once in `created()` (`PublishForm.vue:880-883`): `'active'` → the localization active at mount, otherwise the root localization. It is not recomputed after an in-place switch `[SRC]`.

### 1.3 (a) Target localization already exists

`editLocalization` `[SRC]` `PublishForm.vue:671-699`:

```js
return this.$axios.get(localization.url).then((response) => {
    clearTimeout(this.trackDirtyStateTimeout);
    this.trackDirtyState = false;
    const data = response.data;
    this.values = data.values;             this.originValues = data.originValues;
    this.originMeta = data.originMeta;     this.meta = data.meta;
    this.localizations = data.localizations; this.localizedFields = data.localizedFields;
    this.hasOrigin = data.hasOrigin;       this.actions = data.actions;
    this.itemActions = data.itemActions;   this.fieldset = data.blueprint;
    this.permalink = data.permalink;       this.site = localization.handle;
    this.reference = data.reference;       this.localizing = false;
    this.initialPublished = data.values.published; this.readOnly = data.readOnly;
    this.trackDirtyStateTimeout = setTimeout(() => (this.trackDirtyState = true), 500);
});
```

- Server: the same `EntriesController@edit` action; it returns the view data as JSON because of `if ($request->wantsJson()) return collect($viewData);` (`EntriesController.php:172-174`). Payload keys: `title, reference, editing, actions{save,publish,unpublish,revisions,restore,createRevision,editBlueprint}, values(+id), extraValues, meta, collection, blueprint, readOnly, locale, localizedFields, originBehavior, hasOrigin, originValues, originMeta, permalink, localizations, hasWorkingCopy, revisionsEnabled, canManagePublishState, previewTargets, autosaveInterval, itemActions` (`:121-170`).
- **In-place state swap, not an Inertia visit.** The URL is changed with `window.history.replaceState`. The `PublishContainer` is not re-created (no `:key`), so `publish-container-created` does **not** fire again; `reference` and `site` props change reactively. `[RUN]` (container and a pushed component survive a reference/site swap; mount count stays 1).
- Dirty tracking is disabled for 500 ms after the swap ("after any fieldtypes do a debounced update").
- No JS hook and no event fires for a switch to an existing localization.
- Not refreshed by the swap: `extraValues`, `isWorkingCopy`, the command palette entries added in `mounted()` (`:824-849`).

### 1.4 (b) Target localization does not exist

Client `[SRC]` `PublishForm.vue:701-720`:

```js
createLocalization(localization) {
    this.selectingOrigin = false;
    if (this.isCreating) { this.$nextTick(() => this.redirectTo(localization.url)); return; }
    const originLocalization = this.localizations.find((e) => e.handle === this.selectedOrigin);
    const url = originLocalization.url + '/localize';
    this.$axios.post(url, { site: localization.handle }).then((response) => {
        this.editLocalization(response.data).then(() => {
            this.$events.$emit('localization.created', { container: this.$refs.container });
            if (this.originValues.published) this.setFieldValue('published', true);
        });
    });
},
```

Endpoint: `POST {cp}/collections/{collection}/entries/{originEntryId}/localize`, route name `collections.entries.localize` (`routes/cp.php:196`), body `{ site }`.

Controller `[SRC]` `src/Http/Controllers/CP/Collections/LocalizeEntryController.php:11-25` (identical upstream):

```php
$this->authorize('edit', $entry);
$request->validate(['site' => 'required']);
$localized = $entry->makeLocalization($site = $request->site);
$localized->store(['user' => User::fromUser($request->user())]);
return ['handle' => $site, 'url' => $localized->editUrl()];
```

What it does:

- `Entry::makeLocalization($site)` (`src/Entries/Entry.php:884-898`): new entry in the same collection with `->origin($this)->locale($site)->published($this->published)->slug($this->slug())`. **No data is copied**; every field is inherited from the origin until localized.
- **Structure tree placement** (`Entry.php:900-924`, runs as an `afterSave` callback): nothing if the collection is orderable (max depth 1) or has no structure. Otherwise it takes the origin's parent page, looks up that parent's counterpart in the target site (`optional($parent)->in($site)`), and appends the new entry under it (`$tree->appendTo($parent->id(), $entry)`); if there is no counterpart or the parent is the tree root, it appends at the top level (`$tree->append($entry)`); then `$tree->save()`.
- **Slug**: same as the origin's slug.
- **Published state**: `store()` (`src/Revisions/Revisable.php:142-158`) forces `->published(false)`, calls `updateLastModified($user)->save()`, and creates a revision if revisions are enabled. The stored localization is therefore always a **draft**, regardless of `makeLocalization` copying the origin's published flag.
- Server events from `save()`: `EntryCreating`, `EntrySaving`, `EntryCreated`, `EntrySaved` (`Entry.php:410-464`).
- **Response**: `{ "handle": "<site>", "url": "<edit url of the new localization>" }`.

Then on the client:

1. `editLocalization(response.data)` performs the same GET + in-place swap as in 1.3.
2. `this.$events.$emit('localization.created', { container: this.$refs.container })` (`:713`). `[RUN]` (emulated promise/prop structure): at this moment the container already holds the new localization's values/site/reference and `trackDirtyState` is `false`, staying false for 500 ms.
3. If the origin is published, the form sets `published = true` locally and desyncs it (`:715-717`, `:794-798`). This is unsaved form state.
4. URL: in 6.19.0 `replaceState` runs in `switchToLocalization` with `localization.url`, which is `null` for a non-existent localization, so the address bar keeps the previous entry's URL after creation `[SRC]`. Upstream moved the `replaceState` into `editLocalization` `[UP]`.

Other cases:

- On the **create** page (`isCreating`), selecting another site does `router.get(localization.url)`, a full Inertia visit to the create form of that site (`:704-707`; URLs built in `EntriesController.php:338-348`).
- If the collection has `propagate: true`, localizations are created server-side for all sites on first save (`Entry.php:466-472`); the client flow above is not involved and `localization.created` does not fire.

### 1.5 Taxonomy terms `[SRC]`

- Form: `resources/js/components/terms/PublishForm.vue`; same `PublishLocalizations` switcher (`:102-107`).
- Every site of the taxonomy always "exists": `TermsController@edit` sets `'exists' => true` for all localizations (`src/Http/Controllers/CP/Taxonomies/TermsController.php:123-137`). Origin is the taxonomy's first site (`LocalizedTerm.php:141-159, 237-240`).
- Switching is an in-place swap via `axios.get(localization.url)` + `replaceState` (`terms/PublishForm.vue:416-449`); dirty prompt as for entries; no `trackDirtyState` window (it calls `clearDirtyState()` on next tick).
- There is **no create step**, no `/localize` route for terms (`routes/cp.php:217-234`), and **no `localization.created` event**. `createLocalization` exists in the component (`:451-456`) but is unreachable from the edit page. On the term create page it would POST to a route that does not exist `[UNVERIFIED at runtime]`.
- Save posts `published` and `_localized` (`:351-355`); server keeps only `_localized` keys when the term has an origin (`TermsController.php:187-191`).
- 6.19.0 `pages/terms/Edit.vue` does not pass `itemActionUrl` (`:5-30, 36-63`), so PHP item actions on the term edit page have no URL. Changelog 6.30.0 "Fix item actions on the term edit page (#15290)" `[UP]`.

### 1.6 Global sets `[SRC]`

- Form: `resources/js/components/globals/PublishForm.vue`. Switcher is a header `Select` (`components/SiteSelector.vue`), not `PublishLocalizations` (`:44-49`).
- Only localizations that already exist and that the user may edit are listed (`GlobalVariablesController.php:56-64, 133-139`). Which sites exist and their origins is configured on the global set config screen (`globals/{set}/edit`, fieldtype `global_set_sites`, `GlobalsController.php:66-112`). There is no create-localization flow in the publish form.
- Switching: in-place swap via `axios.get(localization.url)` + `replaceState` (`globals/PublishForm.vue:288-311`). 6.19.0 does not refresh `originMeta` on switch `[SRC]`; upstream does `[UP]`.
- No `localization.created`; hooks are `global-set.saving` / `global-set.saved`.
- `<PublishComponents />` is **not rendered** in this form (the container is self-closing, `:71-87`), in 6.19.0 and upstream.

### 1.7 Navigation trees `[SRC]`

- Page: `resources/js/pages/navigation/Show.vue`. Each site has an independent tree, no origin relationship.
- Switching site is a **full Inertia visit**: `router.get(this.sites.find((s) => s.handle === site).url)` (`:251-253`). Server: `NavigationController@show` (`src/Http/Controllers/CP/Navigation/NavigationController.php:82-149`); if the nav has no tree for the site it redirects to the first tree or creates one.
- The page editor (`components/structures/PageEditor.vue`) is a `PublishContainer` named `tree-page` in a `Stack`; its `originValues` are the **linked entry's** values, not another site's (`NavigationPagesController.php:49-72`).

---

## 2. Addon hooks into that flow

### 2.1 `Statamic.$hooks` — complete list `[SRC]`

API (`resources/js/components/Hooks.js:6-25`): `Statamic.$hooks.on(key, callback, priority = 10)`; callbacks run sequentially, higher priority first; signature `callback(resolve, reject, payload)`.

Every `run` call site in 6.19.0 (grep of `$hooks.run`, `BeforeSaveHooks`, `AfterSaveHooks`):

| Hook | Fired from | Payload |
|---|---|---|
| `entry.saving` | `entries/PublishForm.vue:559-562` (via `SavePipeline.js:115`) | `{ collection, values }` |
| `entry.saved` | `entries/PublishForm.vue:568-571` (`SavePipeline.js:130-135`) | `{ collection, reference, response }` |
| `entry.publishing` | `entries/PublishActions.vue:117-121` | `{ collection, message }` |
| `entry.published` | `entries/PublishActions.vue:149-155` | `{ collection, reference, message, response }` |
| `term.saving` | `terms/PublishForm.vue:347-350` | `{ taxonomy, values }` |
| `term.saved` | `terms/PublishForm.vue:356-359` | `{ taxonomy, reference, response }` |
| `global-set.saving` | `globals/PublishForm.vue:244-247` | `{ globalSet, values }` |
| `global-set.saved` | `globals/PublishForm.vue:252-255` | `{ globalSet, reference, response }` |
| `user.saving` | `users/PublishForm.vue:174-176` | `{ values }` |
| `user.saved` | `users/PublishForm.vue:178-180` | `{ reference, response }` |
| `revision.restored` | `revision-history/Restore.vue:45` | `{ reference }` |

- **No hook fires on localization switch or creation.**
- `reference` in the `*.saved` payloads is `this.initialReference` (the entry the page was loaded with). After an in-place localization switch it is stale. Use `response.data.data.id` / `.reference` (`src/Http/Resources/CP/Entries/Entry.php:11-24`).
- In 6.19.0 rejecting a `*.saving` hook leaves the save pipeline promise unsettled, so the form stays in the saving state (`SavePipeline.js:113-117`, no `reject` forwarding). Changelog 6.28.0 "Fix save button hanging forever when a save hook fails (#15194)" `[UP]`.

### 2.2 Event bus `[SRC]`

API (`resources/js/composables/global-event-bus.js`): `Statamic.$events.$on / $once / $off / $emit` (tiny-emitter). Also `import { events } from '@statamic/cms/api'`.

Events emitted by core 6.19.0:

| Event | Emitted from | Payload |
|---|---|---|
| `publish-container-created` | `ui/Publish/Container.vue:340-356` (onMounted) | `{ name, reference (ref), site (ref), values (ref), setFieldValue, setValues, meta (ref), setMeta, setFieldMeta, pushComponent, fieldFocus, focusField, blurField }` `[RUN]` |
| `publish-container-destroyed` | `Container.vue:358-361` | `{ name }` |
| `localization.created` | `entries/PublishForm.vue:713` only | `{ container }` = the container's exposed instance: `name, values, saving, saved, revealerFields, setFieldValue, desyncField, clearDirtyState, pushComponent, visibleValues, setValues, setMeta, setExtraValues` (`Container.vue:363-377`; upstream adds `meta`) |
| `reset-action-modals` | `publish/HasActions.js:29` | none |
| `asset.saved` | `assets/Editor/Editor.vue:462` | asset |
| `live-preview.{containerName}.refresh` | `inputs/relationship/Item.vue:132` (listened in `LivePreview.vue:299-301`) | none |
| `removeFromSelections`, `replaceInSelections`, `root-form-save`, `nav.toggle`, `stacks.*` | listing/blueprint/nav internals | n/a |

Core precedent for `localization.created`: `fieldtypes/SlugFieldtype.vue:97-116` resets the slug when `handle === 'slug' && container.name === this.publishContainer.name && this.config.localizable`.

Listened for but never emitted in 6.19.0: `container.{name}.saving` (`DateFieldtype.vue:135`), `livepreview.opened/closed/resizing` (`MarkdownFieldtype.vue:632-634`).

First-party precedent `[1P]` `statamic/collaboration` `resources/js/collaboration.js` and `Workspace.js`:

```js
Statamic.$events.$on('publish-container-created', container => {
    if (!container.reference.value) return;
    manager.addWorkspace(container);
});
// detects in-place localization switches:
watch(() => [this.container.reference.value, this.container.site.value], () => this.restart(), { flush: 'sync' });
// injects UI into the publish form:
this.statusBarComponent = this.container.pushComponent('CollaborationStatusBar', { props: { channelName } });
this.statusBarComponent.on('unlock', (targetUser) => { ... });
```

### 2.3 Component registration, overriding, appending `[SRC]`

`resources/js/components/Components.js`, `Component.js`, `bootstrap/statamic.js`:

- `Statamic.$components.register(name, component)` — queued before boot, then `app.component(name, component)` (`Components.js:13-32`). Must be called inside `Statamic.booting(() => { ... })` `[DOC]`.
- Addon registrations are applied **after** all core registrations (`statamic.js:328-333`), and Vue's `app.component` overwrites an existing name, so an addon can replace any component that core resolves **by name** (e.g. `text-fieldtype`, `confirmation-modal`, `EntryPublishForm` as used by the inline stack editor `inputs/relationship/InlinePublishForm.vue:16-31`). The entry edit page imports its form directly (`pages/entries/Edit.vue:2`), so it cannot be overridden this way. Core Inertia pages win over addon pages (`statamic.js:215-223`). Overriding was not exercised at runtime `[UNVERIFIED]`.
- `Statamic.$components.append(name, { props })` → returns a `Component` (`id, name, props, events, prop(), on(), destroy()`); rendered at the end of the layout (`pages/layout/Layout.vue:95-101`), i.e. **outside** any publish container. Used by core for `field-action-modal` (`field-actions/modal.js`).
- The object returned by `append()` / `pushComponent()` is the raw instance: `.on()` / `.prop()` calls made after the first render do not trigger a re-render `[RUN, reactivity only]`. Set handlers synchronously right after creating it (as Collaboration does) and keep live state in a store.
- Lifecycle: `Statamic.booting(cb)` (before app creation, config available), `Statamic.configuring(cb)` (after registrations, `Statamic.$app` available), `Statamic.booted(cb)` (`statamic.js:55-66, 192, 251, 338`).

### 2.4 Where an addon can inject UI into the entry publish form

| Location | Mechanism | Verified |
|---|---|---|
| Top of the form body (above the tabs) | `pushComponent(name, { props })` from the `publish-container-created` payload (or the `localization.created` container). Rendered by `<PublishComponents />` (`ui/Publish/Components.vue`), which the entry form places at `entries/PublishForm.vue:99` and the term form at `terms/PublishForm.vue:79`. The component is **inside** the container and can call `injectPublishContext()`. Documented in-repo: `resources/js/stories/docs/PublishContainer.mdx:46, 123` ("Components pushed by addons"). | `[RUN]` `[1P]` |
| Header "dots" actions dropdown | PHP `Statamic\Actions\Action` classes visible for the entry with context `view => 'form'` (section 7). No JS API to add items. | `[SRC]` |
| Header buttons | Only `<slot name="action-buttons-right" />` (`entries/PublishForm.vue:69`), fillable by a parent component, not by addons on the core page. The header root has `data-ui-header` (`ui/Header.vue:13`); teleporting into it is an unsupported DOM hack. | `[SRC]` / hack `[UNVERIFIED]` |
| Sidebar | No injection API (`#actions` slot belongs to the form). Alternative: add an addon fieldtype to the blueprint's `sidebar` tab from PHP (e.g. in an `EntryBlueprintFound` listener with `ensureField(..., 'sidebar')`); it renders below the actions slot (`Tabs.vue:216-221`) and is inside the container. | `[SRC]` (PHP side not exercised) |
| Per field | Field actions (section 3). | `[RUN]` |
| Modals / stacks anywhere | `Statamic.$components.append(...)`, or render `Modal` / `ConfirmationModal` / `Stack` from the pushed component (they teleport to portal targets, `ui/Modal/Modal.vue:66-80, 168`). | `[SRC]` `[1P]` |
| Command palette | Section 2.5. | `[SRC]` `[DOC]` |

Not available: globals form, nav page editor and generic `PublishForm::make()` pages do not render `<PublishComponents />`, so `pushComponent` has no visible effect there `[SRC]`.

Portals API: `Statamic.$portals` (`create(name, data)`, `destroy(id)`, `all()`, `stacks()`), `Statamic.$stacks`, global `<portal name>` component (`components/portals/*`). There are no named portal targets in the publish form header or sidebar.

### 2.5 Command palette `[SRC]` `[DOC]`

`resources/js/components/CommandPalette.js`:

```js
const command = Statamic.$commandPalette.add({
    text: 'Translate from origin',          // string or array (rendered "A » B"); must be unique
    action: () => {},                        // or url: '...'
    icon: 'wand',                            // default 'wand'
    category: Statamic.$commandPalette.category.Actions,   // 'Actions' | 'Miscellaneous' (default)
    when: () => true,                        // evaluated reactively
    prioritize: false, trackRecent: false, openNewTab: false, keys: undefined,
    persist: false,
});
command.remove();
```

- All non-`persist` commands are cleared on every Inertia navigation start (`command-palette/CommandPalette.vue:292`: `router.on('start', () => Statamic.$commandPalette.clear())`). Page-specific commands must be re-added when the page mounts (e.g. in the `publish-container-created` handler) or registered once with `persist: true` and a `when` guard.
- `Statamic.$commandPalette.preventIf(fn)` blocks opening.
- Declarative wrapper: `<ui-command-palette-item>` / `CommandPaletteItem` from `@statamic/cms/ui` (`ui/CommandPalette/Item.vue`), removes itself on unmount.
- PHP links: `Statamic\Facades\CommandPalette::add(text:, url:, icon:, openNewTab:, trackRecent:, category:, keys:)` (`src/CommandPalette/Palette.php:28-51`). CP nav items are added automatically.
- PHP item actions of the entry form are added to the palette once, in `mounted()` (`entries/PublishForm.vue:843-848`).

---

## 3. Field actions

Files: `resources/js/components/field-actions/{FieldActions.js, FieldAction.js, toFieldActions.js, HasFieldActions.js, FieldActions.vue, FieldActionModal.vue, modal.js}`. `FieldAction.js`, `FieldActions.js`, `toFieldActions.js`, `HasFieldActions.js`, `fieldtype.js`, `Fieldtype.vue` are byte-identical upstream `[UP]`.

### 3.1 Registration `[SRC]` `[RUN]`

```js
Statamic.$fieldActions.add(binding, action);   // FieldActions.js:8-14
```

- `binding` is an exact string; there is **no wildcard**. `get(name)` returns `this.actions[name] || []`.
- For fields the binding is `` `${config.type}-fieldtype` `` (`HasFieldActions.js:21-23`, `fieldtype.js:83-87`): the fieldtype **handle**, not the Vue component name. Examples: `text-fieldtype`, `textarea-fieldtype`, `markdown-fieldtype`, `bard-fieldtype`, `replicator-fieldtype`, `grid-fieldtype`, `entries-fieldtype` (component is `relationship-fieldtype`), `taggable-fieldtype` (component `tags-fieldtype`).
- Set-level bindings: `'replicator-fieldtype-set'` (`fieldtypes/replicator/Set.vue:78-80`) and `'bard-fieldtype-set'` (`fieldtypes/bard/Set.vue:288-290`). **Grid rows have no binding** (`grid/Row.vue` only has Duplicate/Delete).
- "All fieldtypes" = loop over a list of handles. The registry is a plain object, so register before fieldtypes render (in `Statamic.booting`).
- Addon actions are listed before the fieldtype's own `internalFieldActions` (`toFieldActions.js:4-8`).
- Display requires the field config `actions` to be truthy ("Show Actions" toggle, default `true`, `src/Fields/Field.php:609-616`) and at least one visible action (`ui/Publish/Field.vue:92-94, 267-269`). Works for nested fields inside replicator/bard/grid/group sets too `[RUN]`.
- `Field.vue` reads the actions from the fieldtype's template ref (`fieldtype.value.fieldActions`, `Field.vue:88-90`). A `<script setup>` fieldtype therefore only gets field actions if it calls `defineExpose(expose)`. Among core composition-API fieldtypes only `text`, `hidden`, `revealer` and `theme` do; `select`, `icon`, `html` and `formatting_locales` do not, so actions registered for them never render (same upstream) `[SRC]`.
- UI (`FieldActions.vue`): non-quick actions go into a "dots" dropdown (title only, no icon); quick actions are icon buttons with the title as tooltip and honour `disabled`. Fullscreen headers of bard/replicator/grid/group/table/code/markdown show them as well (`publish/FullscreenHeader.vue`).

### 3.2 Action definition `[SRC]` `FieldAction.js:14-49`

| Option | Type | Default |
|---|---|---|
| `title` | string | required |
| `run(payload)` | function | required |
| `visible` | bool or `(payload) => bool` | `true` |
| `visibleWhenReadOnly` | bool | `false` (action hidden when `payload.isReadOnly`) |
| `disabled` | bool or fn | `false` |
| `quick` | bool or fn | `false` |
| `icon` | string or fn; icon name or raw `<svg…>` string (`ui/Icon/Icon.vue:31-35`) | `'image'` |
| `dangerous` | bool or fn | `false` |
| `confirm` | `true` or `{ title, text, buttonText, warningText, dangerous, fields }` | none |

### 3.3 Payload — exact keys `[RUN]`

| Binding | Payload keys | Source |
|---|---|---|
| Options-API fieldtypes (mixin `Fieldtype.vue`; e.g. textarea, markdown, bard, replicator, grid, group, slug, table, list, array, link, assets, relationship…) | `vm, fieldPathPrefix, handle, value, config, meta, update, updateMeta, isReadOnly` | `fieldtypes/Fieldtype.vue:88-100` |
| Composition-API fieldtypes (`Fieldtype.use` + `defineExpose(expose)`; core: **text**, hidden, revealer, theme; and addon fieldtypes built the documented v6 way) | `fieldPathPrefix, handle, value, config, meta, update, updateMeta, isReadOnly` — **no `vm`** | `fieldtypes/fieldtype.js:66-76` |
| `replicator-fieldtype-set` | `index, values, config, update(handle, value), updateMeta(handle, value), isReadOnly` | `replicator/Set.vue:65-76` |
| `bard-fieldtype-set` | same as above | `bard/Set.vue:271-286` |

With `confirm`, the payload also has `confirmation` (`FieldAction.js:54-58`).

Semantics:

- `handle` is the field's own handle; `fieldPathPrefix` is the parent path for nested fields (`blocks.0` for a replicator set, `content.2.attrs.values` for a bard set, `rows.1` for a grid row, `group` for a group) and `undefined` at top level. Full path = `[fieldPathPrefix, handle].filter(Boolean).join('.')` (`Field.vue:68`). Root handle = `(fieldPathPrefix || handle).split('.')[0]`.
- `update(value)` emits `update:value`; `Field.vue:96-101` then calls `setFieldValue(fullPath, value)` **and** `desyncField(rootFieldPath)`. So a field-level action automatically writes the nested path, marks the **root** field as localized and marks the form dirty. `[RUN]`: nested `blocks.0.text` → `values.blocks[0].text` updated and `localizedFields` gained `"blocks"`.
- `updateMeta(meta)` emits `update:meta` → `setFieldMeta(metaFullPath, meta)` (`Field.vue:103-105`).
- Set-level `update(handle, value)` calls `setFieldValue` directly and does **not** desync the root field; the set payload also carries no path or parent handle `[SRC]`. In a localization the change would be dropped on save unless the addon desyncs the root field itself.
- `value`, `meta` and `config` are the field's current form (pre-processed) value, its meta and its publish config (including `type`, `localizable`, bard `sets`/`buttons`, etc.).

### 3.4 Async `run` and loading state `[RUN]`

`FieldAction.js:51-68`:

```js
const response = this.#run(payload);
if (response instanceof Promise) {
    const progress = this.#payload.vm.$progress;
    const name = this.#payload.fieldPathPrefix ?? this.#payload.handle;
    progress.loading(name, true);
    response.finally(() => progress.loading(name, false));
}
```

- Options-API fieldtypes: returning a Promise shows the global top progress bar under the name `fieldPathPrefix ?? handle` and, because the entry form's `canSave` depends on `$progress.isComplete()` (`entries/PublishForm.vue:431-437`), disables Save while it runs. `[RUN]` progress calls `[["teaser",true],["teaser",false]]`.
- Composition-API fieldtypes (including core `text`) and set bindings: returning a Promise throws `TypeError: Cannot read properties of undefined (reading '$progress')` after `run` has already been invoked; the work still completes but no loading state is shown and an unhandled rejection is logged `[RUN]`. Same code upstream `[UP]`. Workaround: do not return the promise; drive `Statamic.$progress.loading(name, true/false)` manually.
- There is no per-button spinner.

### 3.5 Confirmation / fields modal `[SRC]` `[DOC]`

- `confirm: true` → generic "Are you sure?"; object form → `title` (defaults to the action title), `text`, `buttonText`, `warningText`, `dangerous`, `fields` (`FieldAction.js:70-78`, `FieldActionModal.vue:41-61`).
- With `fields` (blueprint field definitions keyed by handle) the modal posts to `cp_url('field-action-modal/resolve')` and `.../process` (`FieldActionModalController.php`) and resolves `confirmation = { confirmed: true, values, meta, processed }`. Cancelling skips `run`.

### 3.6 Reading site, entry reference, origin values from an action

- Options-API fieldtypes: `vm.publishContainer` is the container context with refs unwrapped (`Fieldtype.vue:40-52`): `.site`, `.reference`, `.originValues`, `.values`, `.meta`, `.localizedFields`, `.blueprint`, `.name`, `.readOnly`, and functions `.setFieldValue`, `.setFieldMeta`, `.desyncField`, `.syncField`. `[RUN]`: `site: fr | reference: entry::fr-id | originValues.teaser: Teaser | typeof desyncField: function`. `vm.injectedPublishContainer` holds the raw refs.
- Composition-API fieldtypes and set bindings: the payload has no link to the container. The addon needs its own registry, filled from `publish-container-created` (gives `site`, `reference`, `values`, `meta`, setters) and/or from a pushed component that stores its injected context (gives the full context). Choosing the right container when several are mounted (stacked inline editors) is a heuristic `[UNVERIFIED]`.
- The **origin site handle** is not in the container context (the `localizations` array lives in the form component). It has to come from the addon's own endpoint.

---

## 4. Publish container state API

There is no Vuex or Pinia store for publish forms in v6. State lives in refs inside `ui/Publish/Container.vue` and is shared by provide/inject. `Statamic.$pinia` only exposes `{ defineStore }` for addon stores (`statamic.js:104-106`). Confirmed by `[DOC]` upgrade guide ("These no longer use Vuex (or Pinia)") and by grep (no `$store` / `storeName` anywhere in `resources/js`).

### 4.1 Access routes

| From | How |
|---|---|
| Any component rendered inside the container (pushed component, fieldtype, custom field) | `import { injectPublishContext } from '@statamic/cms/ui'; const ctx = injectPublishContext();` — returns `null` outside a container. Options API: `inject: { publishContext: { from: publishContextKey } }`. Injection key string is `'PublishContainerContext'` (`util/createContext.js`, `[RUN]`). |
| Options-API fieldtype | `this.publishContainer` (refs unwrapped) / `this.injectedPublishContainer` (`Fieldtype.vue:15-19, 40-52`) |
| Outside the container | `publish-container-created` payload (subset, see 2.2) or the `localization.created` `container` |

### 4.2 Context keys `[RUN]`

`asConfig, blueprint, blurField, components, container, desyncField, direction, errors, extraValues, fieldFocus, fieldLocks, focusField, hiddenFields, isDirty, isTrackingOriginValues, localizedFields, meta, name, originValues, parentContainer, previews, readOnly, reference, rememberTab, revealerFields, revealerValues, setFieldMeta, setFieldPreviewValue, setFieldValue, setHiddenField, setMeta, setRevealerField, setValues, site, syncField, unsetRevealerField, values, visibleValues, withoutDirtying`, plus whatever the form passes via `:provide` (entries: `isWorkingCopy`, `revisionsEnabled`, `entries/PublishForm.vue:90`).

Data keys are refs or computeds (use `.value`); `isDirty` is a computed, not a function. **`originMeta` is not provided.** (`Container.vue:287-338`.)

### 4.3 Reading

`ctx.values.value` (all current values), `ctx.meta.value`, `ctx.visibleValues.value` (what gets posted), `ctx.originValues.value`, `ctx.localizedFields.value`, `ctx.blueprint.value` (`{ title, handle, tabs:[{handle, display, sections:[{display, fields:[…]}]}], empty, fqh, token }`, `src/Fields/Blueprint.php:450-463`), `ctx.site.value`, `ctx.reference.value` (`entry::{id}`, `term::{taxonomy}::{slug}::{site}`, `globals::{handle}::{site}`), `ctx.readOnly.value`, `ctx.name.value` (`'base'` on full pages; `relate-fieldtype-inline-{uid}` in stacks).

### 4.4 Writing `[RUN]`

```js
ctx.setFieldValue(path, value);   // data_set on the values ref; dot paths, numeric segments index arrays
ctx.setFieldMeta(path, value);
ctx.setValues(obj); ctx.setMeta(obj);
ctx.desyncField(handle);          // add to localizedFields + mark dirty
ctx.syncField(handle);            // native confirm(), then restore origin value + meta, remove from localizedFields
ctx.withoutDirtying(() => { ... });
```

Verified sequence on the real container:

```
setFieldMeta('blocks', …); setFieldValue('blocks', […]); desyncField('blocks');
setFieldValue('title','Bonjour'); desyncField('title');
→ parent v-model updated, localizedFields = ["blocks","title"], Statamic.$dirty has 'base',
  emitted update:modelValue and update:modifiedFields
```

- **Dirty state** is triggered automatically by a deep watcher on the values (`Container.vue:161-168`, `192-194`) whenever tracking is on; `desyncField` also calls `dirty()`. Global API: `Statamic.$dirty.add(name) / remove(name) / has(name) / names() / count() / state(name, bool) / disableWarning()` (`composables/dirty-state.js:113-123`).
- While `trackDirtyState` is false (the 500 ms after a localization switch or save), 6.19.0 still adds to `localizedFields` in `desyncField` but does not flag dirty `[RUN]`. Upstream returns early from `desyncField` in that window `[UP]` (see 10.2).

### 4.5 Nested values and meta for sets `[SRC]`

| Fieldtype | Value shape (form) | Value path of a nested field | Meta path |
|---|---|---|---|
| Replicator | `[{ _id, type, enabled, ...fields }]` | `field.{index}.{handle}` | `field.existing.{set._id}.{handle}` |
| Bard | ProseMirror node array; sets are `{ type: 'set', attrs: { id, enabled, values: { type, ...fields } } }` | `field.{nodeIndex}.attrs.values.{handle}` | `field.existing.{attrs.id}.{handle}` |
| Grid | `[{ _id, ...fields }]` | `field.{index}.{handle}` | `field.existing.{row._id}.{handle}` |
| Group | `{ ...fields }` | `field.{handle}` | `field.{handle}` |

(`replicator/Set.vue:48-49`, `bard/Set.vue:223-237`, `grid/Row.vue:9-10`, `GroupFieldtype.vue:21-22`.)

Meta produced by PHP `preload()`: replicator `{ existing, new, defaults, collapsed }` (`src/Fieldtypes/Replicator.php:231-263`), grid `{ defaults, new, existing }` (`Grid.php:207-216`), bard `{ existing, new, defaults, … }` (`Bard.php:612+`). Set/row ids come from the stored `id` key (`config('statamic.system.row_id_handle')`) or a random 8-char id when none is stored (`Replicator.php:124-134`, `RowId.php`), so origin and localization values can have different `_id`s when content has no stored ids.

Required meta for **new** sets: each set id needs an entry in `meta.existing`. Core does `updateSetMeta(set._id, data.new)` before inserting (`Replicator.vue:222-236`, `grid/ManagesRowMeta.js:3-11`), getting `data.new` from `meta.new[handle]` or from `POST cp/fieldtypes/replicator/set` `{ token: blueprint.token, reference, field, set }` → `{ new, defaults }` (`Replicator.vue:238-265`, `ReplicatorSetController.php`). When replacing a whole field, set the whole field meta from a server-side `preProcess()` (same as `ExtractsFromEntryFields.php:32-49`: `$blueprint->fields()->addValues($values)->preProcess()` → `->values()` and `->meta()`).

Reaction of fieldtypes to an external value change `[SRC]`: text/textarea/replicator/grid render from props; Bard watches `value` and resets the editor (`bard/BardFieldtype.vue:442-452`); Markdown watches `value` (`MarkdownFieldtype.vue:694-696`); Table/List/Array watch `value`; Link derives its state from `meta` and watches `meta` (`LinkFieldtype.vue:132-140`), so it needs value and meta together.

---

## 5. How localizable fields are represented

- **`localizable` flag**: a per-field blueprint config key, present in the field's publish config only when set in YAML (`Field::toPublishArray()` merges `$this->config`, `src/Fields/Field.php:268-285, 434-448`). `Field::isLocalizable()` = `(bool) $this->get('localizable')` (`:210-213`). The blueprint builder shows the toggle only for top-level fields; it is disabled for replicator sets and grid fields (`replicator/SetsFieldtype.vue:6`, `grid/FieldsFieldtype.vue:5`). The auto-ensured `title` field is not localizable unless the blueprint says so; `slug` is ensured with `localizable: true` (`src/Entries/Collection.php:391-400`).
- **Read-only rule** (`ui/Publish/Field.vue:168-177`): in a localization, a top-level field that exists in the origin and is not `localizable` is read-only.

  ```js
  if (isTrackingOriginValues.value && isSyncable.value && !isLocalizable.value) return true;
  ```
- **Syncable / synced** (`Field.vue:182-192`): only top-level fields; syncable when origin values are tracked and contain the handle; `isSynced = isSyncable && !localizedFields.includes(handle)`.
- **Per-field UI** (`Field.vue:263-264`): two ghost icon buttons in the label, `synced` (tooltip `messages.field_synced_with_origin`, click → desync) and `unsynced` (tooltip `messages.field_desynced_from_origin`, click → sync), shown only when `!isReadOnly && isSyncable`. Any edit of a field or of a nested field desyncs the root field (`Field.vue:96-101, 198-200`).
- **`localizedFields`**: array of top-level handles. Server initial value is `$entry->data()->keys()->all()` (`EntriesController.php:141`), i.e. the keys the localization stores itself. Bound as `v-model:modified-fields="localizedFields"` (`entries/PublishForm.vue:86`); container prop `modifiedFields` (`Container.vue:63-66, 105, 156-159, 176-180`).
- **`originValues` / `originMeta`**: the origin entry's pre-processed values and meta (`EntriesController.php:117-119, 144-145`), `null` when the entry has no origin. Container props (`Container.vue:46-53`); only `originValues` is exposed in the context. `syncField` uses both (`Container.vue:227-233`).
- **`hasOrigin`**: boolean in the edit payload and form data (`EntriesController.php:143`, `PublishForm.vue:370`); not in the container context. Use `isTrackingOriginValues` plus a non-empty `originValues`. The create page passes `originValues = {}`.
- **`syncFieldConfirmationText`**: container prop (default `__('Are you sure?')`, `Container.vue:72-76`); entries pass `messages.sync_entry_field_confirmation_text` ("Are you sure? This field's value will be replaced by the value in the originating entry."), terms the term variant. Shown with the browser's native `confirm()`.
- **What is POSTed on save** (`entries/PublishForm.vue:563-567`, `SavePipeline.js:71-75`): `axios.patch(actions.save, { ...visibleValues, _blueprint: fieldset.handle, _localized: localizedFields, _parent: parent })`. All visible values are sent; the server filters.
- **Server** (`EntriesController.php:226-239`): when the entry has an origin, `$entry->data($values->only($request->input('_localized')))` — the entry's data is **replaced** by exactly the `_localized` keys; `date` is kept only if `'date'` is in `_localized`; `slug` and `published` are handled separately. The server does not check the `localizable` flag.
- Terms: same with `published` in the body (`terms/PublishForm.vue:351-355`, `TermsController.php:187-191`). Globals: `_blueprint`, `_localized` (`globals/PublishForm.vue:248-251`, `GlobalVariablesController.php:110-114`).

---

## 6. Addon CP pages, package exports, UI kit, toasts, progress, Vite

### 6.1 Inertia pages from an addon `[SRC]` `[DOC]`

```js
import Foo from './pages/Foo.vue';
Statamic.booting(() => Statamic.$inertia.register('my-addon::Foo', Foo));
```
```php
return \Inertia\Inertia::render('my-addon::Foo', ['message' => 'Hello']);
```

- Resolver: core pages first, then `inertia.get(name)`; the CP `Layout` is applied automatically (`statamic.js:201-235`, `components/Inertia.ts`).
- CP routes: `routes/cp.php` of the addon is auto-registered inside the authenticated CP group with no extra prefix (`AddonServiceProvider.php:534-600`, `routes/cp.php:157-158`); build URLs with `cp_url('...')` / `cp_route()`.
- `@statamic/cms/inertia` exports (6.19.0): `Form, Head, Link, router, toggleArchitecturalBackground, useArchitecturalBackground, useForm, usePoll` (`packages/cms/src/inertia.js`). Upstream adds `usePage` `[UP]`.
- Example in the reference addon: `/Volumes/1TB/Sites/umami/addons/reachweb/statamic-resrv/resources/js/cp.js:22-43`, pages under `resources/js/pages/`.

### 6.2 Package exports `[SRC]` `packages/cms/src/*.js` (identical to `resources/dist-package`)

- **`@statamic/cms`**: `Fieldtype, IndexFieldtype, FieldtypeMixin, HasActionsMixin, HasInputOptionsMixin, HasPreferencesMixin, IndexFieldtypeMixin, InlineEditForm, DateFormatter, NumberFormatter, ItemActions, RelatedItem, RestoreRevision, RevisionHistory, RevisionPreview, SaveButtonOptions, SortableList, requireElevatedSession, requireElevatedSessionIf, clone, debounce, deepClone, resetValuesFromResponse`.
- **`@statamic/cms/api`**: `bard, callbacks, commandPalette, components, conditions, config, contrast, dateFormatter, dirty, echo, events, fieldActions, hooks, inertia, keys, numberFormatter, permissions, portals, preferences, progress, reveal, slug, stacks, colorMode, toast` (the same singletons as `Statamic.$…`).
- **`@statamic/cms/save-pipeline`**: `AfterSaveHooks, BeforeSaveHooks, Pipeline, PipelineStopped, Request`.
- **`@statamic/cms/bard`**: `ToolbarButtonMixin`. Also `@statamic/cms/vite-plugin`, `@statamic/cms/tailwind.css`, `@statamic/cms/ui.css`.
- **`@statamic/cms/ui`** (full list): `Alert, AuthCard, Avatar, Badge, Button, ButtonGroup, Calendar, Card, CardList, CardListItem, CardPanel, CharacterCounter, Checkbox, CheckboxGroup, CodeEditor, Combobox, CommandPaletteItem, ConfirmationModal, Context, ContextFooter, ContextHeader, ContextItem, ContextLabel, ContextMenu, ContextSeparator, CreateForm, DatePicker, DateRangePicker, Description, DocsCallout, DragHandle, Dropdown, DropdownItem, DropdownLabel, DropdownMenu, DropdownSeparator, DropdownFooter, DropdownHeader, Editable, ErrorMessage, EmptyStateItem, EmptyStateMenu, Field, Header, Heading, HoverCard, Icon, Input, InputGroup, InputGroupAppend, InputGroupPrepend, Label, Listing, ListingCustomizeColumns, ListingFilters, ListingHeaderCell, ListingPagination, ListingPresets, ListingPresetTrigger, ListingRowActions, ListingSearch, ListingTable, ListingTableBody, ListingTableHead, ListingToggleAll, LivePreview, LivePreviewPopout, MiddleEllipsis, Modal, ModalClose, ModalTitle, Pagination, Panel, PanelFooter, PanelHeader, Popover, PublishComponents, PublishContainer, publishContextKey, injectPublishContext, PublishField, PublishFields, PublishFieldsProvider, PublishForm, PublishLocalizations, PublishSections, PublishTabs, Radio, RadioGroup, Select, Separator, Slider, Skeleton, SplitterGroup, SplitterPanel, SplitterResizeHandle, StatusIndicator, Subheading, Switch, TabContent, Stack, StackClose, StackHeader, StackFooter, StackContent, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, TabList, TabProvider, Tabs, TabTrigger, Text, Textarea, TimePicker, TimezoneHoverCard, Timezones, ToggleGroup, ToggleItem, Widget, registerIconSet, registerIconSetFromStrings`.
- Every UI export is also registered globally as `ui-<kebab-name>` (`bootstrap/ui.js`), e.g. `<ui-button>`, `<ui-badge>`. Other globals: `confirmation-modal`, `element-container`, `portal`, `slugify` (`bootstrap/components.js`).
- Component docs: Storybook at `https://ui.statamic.dev`; sources in `resources/js/stories/docs/*.mdx`.

### 6.3 UI components relevant to the addon `[SRC]` (props from `resources/js/components/ui/*`)

| Need | Component(s) | Key props / notes |
|---|---|---|
| Modal | `Modal` (+ `ModalClose`, `ModalTitle`) | `title, icon, open (v-model:open), beforeClose, dismissible, blur`; slots `trigger`, default, `footer`; emits `update:open, opened, dismissed`. Content mounts only while open. |
| Confirm dialog | `ConfirmationModal` | `open, title, bodyText, buttonText ('Confirm'), cancellable, submittable, cancelText, danger, disabled, busy, blur`; emits `confirm, cancel`. Passing `busy` keeps the modal open, shows a loading overlay and blocks closing (`Modal/ConfirmationModal.vue:53-76`). |
| Slide-over | `Stack` (+ `StackHeader/Content/Footer/Close`) | `title, icon, open (v-model:open), beforeClose, size ('narrow' / 'half' / 'full'), inset, showCloseButton, wrapSlot`; slots `trigger`, default, `header-actions`, `footer-start`, `footer-end`. |
| Buttons | `Button`, `ButtonGroup` | `variant` (`default, primary, danger, filled, ghost, ghost-pressed, subtle, pressed`), `size` (`2xs, xs, sm, base, lg`), `icon, iconAppend, iconOnly, text, href, target, loading, disabled, inset, round, type, as` |
| Selects | `Select`, `Combobox` | `options, optionLabel, optionValue, modelValue, clearable, placeholder, size, variant, disabled, readOnly`; Combobox adds `multiple, searchable, taggable, maxSelections, ignoreFilter` and emits `search, selected, added` |
| Badges | `Badge`, `StatusIndicator` | Badge: `text, color` (default, amber, black, blue, cyan, emerald, fuchsia, green, indigo, lime, orange, pink, purple, red, rose, sky, teal, violet, white, yellow), `size (sm / default / lg), pill, icon, iconAppend, prepend, append, href, as`. StatusIndicator: `status` (published, scheduled, expired, draft, hidden), `showDot, showLabel, private` |
| Alerts | `Alert` | `text, heading, variant (default / warning / error / success), icon`; default slot |
| Tabs | `Tabs`, `TabList`, `TabTrigger`, `TabContent` | `Tabs: modelValue, unmountOnHide`; `TabTrigger: name, text`; `TabContent: name` |
| Code / diff display | `CodeEditor` | `modelValue, mode` (includes `'diff'`, CodeMirror diff syntax highlighting only), `readOnly, lineNumbers, lineWrapping, allowModeSelection, showModeLabel, colorMode, title`. There is **no dedicated diff viewer component**. |
| Progress | none | No progress-bar component exists. Core's upload progress is hand-rolled Tailwind (`components/assets/Upload.vue:11-13`). Indeterminate options: `Button :loading`, `<Icon name="loading" />`, `Skeleton`, `ConfirmationModal :busy`. |
| Layout / data | `Header`, `Card`, `CardPanel`, `Panel` (+Header/Footer), `Table*`, `Listing*`, `Dropdown*`, `Popover`, `HoverCard`, `Heading`, `Subheading`, `Description`, `Text`, `Separator`, `Switch`, `Checkbox`, `Radio`, `Input`, `Textarea`, `Field`, `Label`, `EmptyState*`, `Pagination`, `Icon` | `Icon: name` (built-in name, `set::name`, or raw `<svg>` string), `set`. Built-in names include `ai-spark`, `ai-sparks`, `wand`, `magic-wand-action`, `dictionary-language-book`, `earth`, `globe-arrow`, `sync`, `synced`, `unsynced`, `loading`, `checkmark`, `warning-diamond`, `info` (`resources/svg/icons`, 548 files). |

### 6.4 Toast API `[SRC]` `[DOC]`

`resources/js/components/Toasts.js`:

```js
Statamic.$toast.success(message, opts);   // also this.$toast in Options API, or `toast` from @statamic/cms/api
Statamic.$toast.error(message, opts);
Statamic.$toast.info(message, opts);
// opts.duration in ms; default 3500; position bottom-left
```

- A falsy `duration` is deleted (`:44-48`), so `duration: false` falls back to 3500 ms `[SRC]`.
- Server side: `Statamic\Facades\CP\Toast::success|error|info($message)->duration($ms)`. Toasts are appended as `_toasts` to the next CP JSON response (`src/Http/Middleware/CP/AddToasts.php`) or Inertia page props, and displayed by an axios interceptor on **core's** axios instance and an Inertia `success` listener (`Toasts.js:11-33`).
- Core's axios instance (CSRF header, `X-Requested-With`, toast interceptor) is `Statamic.$app.config.globalProperties.$axios` (`statamic.js:271-273, 335-336`); it is not exported from `@statamic/cms`. The resrv addon bundles its own `axios` and relies on the XSRF cookie.
- Options beyond `duration` are spread into the vue-toasted call; an action button on a toast is plausible but `[UNVERIFIED]`.

### 6.5 Progress API `[SRC]` `[DOC]`

`resources/js/composables/progress-bar.js` (nprogress top bar, indeterminate, multi-source):

```js
Statamic.$progress.loading(name, bool);   // start/stop a named task
Statamic.$progress.start(name); Statamic.$progress.complete(name);
Statamic.$progress.names(); Statamic.$progress.count(); Statamic.$progress.isComplete();
```

Side effect: while any task is active the entry form's Save/Publish buttons are disabled (`entries/PublishForm.vue:431-445`) and `localizationSelected` takes its `!canSave` branch (1.2).

### 6.6 Vite build `[SRC]` `[DOC]` `[1P]`

`package.json`: `"@statamic/cms": "file:./vendor/statamic/cms/resources/dist-package"` (so the addon needs `statamic/cms` in its own `vendor`), plus `vite`, `laravel-vite-plugin`, optionally `tailwindcss` + `@tailwindcss/vite`.

`vite.config.js` (reference addon `/Volumes/1TB/Sites/umami/addons/reachweb/statamic-resrv/vite.config.js`):

```js
import statamic from '@statamic/cms/vite-plugin';
export default defineConfig({
    plugins: [
        statamic(),
        tailwindcss(),
        laravel({ input: ['resources/js/cp.js', 'resources/css/cp.css'], publicDirectory: 'resources/dist', hotFile: 'resources/dist/hot', refresh: true }),
    ],
});
```

- `statamic()` = `[externals(), vue(options.vue)]` (`packages/cms/src/vite-plugin/index.js`). `externals` maps every `import … from 'vue'` to `window.Vue` (`externals.js:11-48`). The `@statamic/cms*` modules are thin shims that destructure `window.__STATAMIC__`, so core code is never bundled. Do not bundle your own Vue, Pinia or Inertia; use `Statamic.$pinia.defineStore` and `@statamic/cms/inertia`.
- Service provider: `protected $vite = ['input' => [...], 'publicDirectory' => 'resources/dist', 'hotFile' => ...];` (`AddonServiceProvider.php:121, 458-463, 670-695`); built files are published to `public/vendor/{package}/build`.
- CSS: `@import "@statamic/cms/tailwind.css";` plus `@source` globs (reference addon `resources/css/cp.css`).
- Load order: core bundle in `<head>`, addon modules in the body, then a deferred `Statamic.config(StatamicConfig); Statamic.start()` (`resources/views/partials/scripts.blade.php`).
- PHP → JS data: `Statamic::provideToScript(['myAddon' => [...]])` → `Statamic.$config.get('myAddon.key')` (`src/Statamic.php:239-244`, `components/Config.js`). Built-in keys include `sites` (`[{ name, handle, lang, direction }]`, authorized sites), `selectedSite`, `multisiteEnabled`, `user`, `cpUrl`, `csrfToken`, `broadcasting` (`JavascriptComposer.php:38-101`).
- HMR / Vue devtools need the dev build: `php artisan vendor:publish --tag=statamic-cp-dev` and `APP_DEBUG=true` `[DOC]`.

---

## 7. Bulk Actions UI (PHP `Statamic\Actions\Action`)

### 7.1 Where actions surface `[SRC]`

- **Registration**: classes in the addon's `src/Actions` are auto-registered, or list them in `$actions` (`AddonServiceProvider.php:325-336`).
- **Listings**: row dropdown via `ui/Listing/RowActions.vue` → `components/actions/ItemActions.vue` (actions are lazy-loaded on hover/focus/click with `POST {actionUrl}/list`, `{ selections: [id], context }`, `ItemActions.vue:60-93`); multi-select via the floating toolbar `ui/Listing/BulkActions.vue` + `BulkActionsFloatingToolbar.vue` (`POST {actionUrl}/list` with all selections, `components/actions/BulkActions.vue:47-69`). Bulk actions are also added to the command palette (`BulkActions.vue:27-39`). Entries listing passes `:action-context="{ collection }"` (`entries/Listing.vue:6-7`); routes `collections.entries.actions.run` / `.bulk` (`routes/cp.php:185-186`).
- **Publish form**: the header "dots" dropdown (`entries/PublishForm.vue:9-37`) lists `itemActions` = `Action::for($entry, ['collection' => …, 'view' => 'form'])` (`EntriesController.php:169`). Sorted by title, dangerous last (`actions/Actions.js:12-19`). The dropdown is hidden when there are no actions.
- Visibility and auth: `visibleTo($item)`, `visibleToBulk($items)`, `authorize($user, $item)`, `authorizeBulk` (`src/Actions/Action.php:37-63`, `ActionRepository.php:23-45`). `$this->context['view']` is `'list'` or `'form'`.

### 7.2 Serialized action and the modal `[SRC]`

`Action::toArray()` (`Action.php:149-170`): `handle, title, icon, component, runnable, confirm, buttonText, confirmationText, warningText, dirtyWarningText, bypassesDirtyWarning, requiresElevatedSession, dangerous, fields, values, meta, context`.

`components/actions/ConfirmableAction.vue`:

- `$confirm = false` → runs immediately; otherwise a `confirmation-modal` with `confirmationText()` / `warningText()` (pluralised with `__n(text, selectionCount)`, e.g. `'Run action|Run action on :count items'`), `buttonText()`, red styling when `$dangerous`.
- **`fieldItems()` / `$fields`**: fieldset-style definitions; rendered in the modal as a `PublishContainer` named `confirm-action` (`:128-139`); submitted values are validated server-side (`ActionController.php:30-32`, 422 errors shown in the modal) and passed to `run($items, $values)`.
- In a dirty publish form the modal adds `dirtyWarningText()` ("Any unsaved changes will not be reflected in this action's behavior.") unless `bypassesDirtyWarning()` (`:35-42`).
- **Custom modal body**: `protected $component = 'my-component'` renders `<component :is="action.component" :action="action" :values="values" />` instead of the texts, and `protected $runnable = false` hides the run button (`:99-104`, `:submittable="action.runnable"`; changelog #12612, #12641). The component receives the serialized action and the values object, **not** the selected ids `[SRC]`; getting ids to it (e.g. by overriding `toArray()`) is `[UNVERIFIED]`.

### 7.3 `run()` results and client handling `[SRC]`

Server `src/Http/Controllers/CP/ActionController.php:16-76` (synchronous, inside the HTTP request):

| PHP | Response | Client (`components/actions/Actions.js:34-105`) |
|---|---|---|
| exception thrown | `{ message, success: false }` (HTTP 200) | publish form: error toast (`publish/HasActions.js:31-33`). Listings: the message is shown with `$toast.success` because `success` is not checked (`ui/Listing/BulkActions.vue:30-41`, `RowActions.vue:38-48`) |
| validation / auth failure | HTTP 422 / 403 | promise rejects; listings show an error toast (`response.message` or "Action failed"); field errors are shown in the modal; the publish form shows no toast (`HasActions.js:27`) |
| `redirect($items, $values)` returns a URL (evaluated **after** `run`) | `{ redirect, bypassesDirtyWarning, triggersFullPageRefresh }` | `router.get(redirect)`, or `window.location = redirect` when `triggersFullPageRefresh()`; dirty warning disabled when `bypassesDirtyWarning()` |
| `download($items, $values)` returns a path/Response | file response | blob download via `content-disposition` |
| `run` returns a string | `{ message, success: true }` | success toast |
| `run` returns an array | array + `success` | `message` → toast; `callback: ['name', ...args]` → `Statamic.$callbacks.call(name, ...args)`; other keys reach the `completed` handler |
| `run` returns nothing | `{ success: true }` | toast "Action completed" |

- JS callbacks: `Statamic.$callbacks.add(name, fn)` (`components/Callbacks.js`); built-ins `copyToClipboard`, `bustAndReloadImageCaches`, `removeFromSelections`, `replaceInSelections` (`bootstrap/App.vue:37-63`).
- `message: false` suppresses the toast in listings (`ui/Listing/BulkActions.vue:35-41`, `RowActions.vue:43-48`) but not in publish forms (`publish/HasActions.js:31-35`).
- After a listing action: `refresh()` and `clearSelections()`.
- **In the entry publish form**, when the context view is `form` the response gets `data = getItemData()` (`EntryActionController.php:21-33`: entry resource + fresh `values` + `itemActions`), and the form then does `this.values = resetValuesFromResponse(response.data.values, …)` with dirty tracking off (`entries/PublishForm.vue:809-822`). Consequences `[SRC]`:
  - any unsaved edits in the form are replaced by the saved values;
  - `meta` and `localizedFields` are **not** refreshed. If an action saved new localized fields server-side, the form's stale `localizedFields` would be posted as `_localized` on the next save and the server would drop those fields (section 5). A server-side "translate and save" action run from the form must force a reload (`redirect()` to the edit URL, optionally `triggersFullPageRefresh()`).
  - A JS callback returned by the action runs before the values reset, so values it writes into the form are overwritten.

### 7.4 Long-running actions

None built in `[SRC]`: no queueing, batching, progress or polling in `ActionController` or the action components. While the request is open the modal shows its `busy` overlay and listings set `Statamic.$progress.loading('action', true)` (`ui/Listing/BulkActions.vue:24`). The addon must dispatch its own jobs and build its own progress UI.

---

## 8. Real-time / long-running feedback available in the CP

| Facility | Facts |
|---|---|
| Broadcasting / Echo | `Statamic.$echo` (`components/Echo.js`) wraps Laravel Echo + pusher-js. It is started only when `Statamic.$config.get('broadcasting.enabled')` (`bootstrap/App.vue:23-26`), which requires Laravel's `BroadcastServiceProvider` to be loaded and a driver other than `null`; option mapping exists for `pusher` and `reverb` only (`src/Providers/BroadcastServiceProvider.php:12-61`). API: `Statamic.$echo.booted((echo) => …)`, `Statamic.$echo.config((cfg) => cfg)`, proxied `channel, private, join, leave, listen, socketId, …`. Used by the Collaboration addon `[1P]`. Not available on a default install. |
| Polling | `usePoll(interval, requestOptions, { autoStart, keepAlive })` from `@statamic/cms/inertia` (Inertia v2; reloads page props; returns `{ start, stop }`; throttled 90 % in background tabs unless `keepAlive`). Exported since #12764; core itself never uses it. Alternative: own `setInterval` + axios. Each Inertia reload fires `router.on('start')`, which clears non-persistent command palette items. |
| Toasts | Section 6.4 (client API, server `Toast` facade, `_toasts`). |
| Progress bars | Only the global indeterminate bar `Statamic.$progress` (6.5). No determinate progress component (6.3). |
| Other busy states | `Button :loading`, `Icon name="loading"`, `Skeleton`, `ConfirmationModal :busy`, `Statamic.$dirty`. |
| Core long tasks | Core utilities (e.g. search index update, `UpdateSearchController.php`) run synchronously in the request; there is no core pattern for queued work with progress. |

---

## 9. Dashboard widgets and utilities (front-end side) `[SRC]` `[DOC]`

**Widgets**

- PHP: class extending `Statamic\Widgets\Widget` in `src/Widgets` (auto-registered, or `$widgets`); `component()` returns `VueComponent::render('my-widget', $props)` (`src/Widgets/VueComponent.php`), or `html()` returns markup. Handle = snake-cased class name without `_widget`.
- JS: `Statamic.$components.register('my-widget', MyWidget)`; `pages/Dashboard.vue:57` renders `<component :is="widget.component.name" v-bind="widget.component.props" />`. Use the `Widget` UI component (`title, icon, href`; slots default, `actions`, `footer`).
- Placement is configured by the site (`config/statamic/cp.php` `widgets` or the user's `widgets` preference): `type`, `width` (`sm|md|lg|full`, legacy numbers mapped), `sites`, `can`/`permissions`, `classes` (`DashboardController.php:39-78`).

**Utilities**

```php
Utility::extend(function () {
    Utility::register('auto_translator')
        ->inertia('my-addon::Utility', fn ($request) => [...props])
        ->title('…')->navTitle('…')->icon('…')->description('…')->docsUrl('…')
        ->routes(fn ($router) => $router->post('/', [Controller::class, 'run'])->name('run'));
});
```

- `->inertia()` takes an addon-registered Inertia page; alternatives `->action(Controller)` or `->view()` (`src/CP/Utilities/Utility.php`, `UtilityRepository.php:73-93`, `UtilitiesController.php:24-40`).
- URL `/cp/utilities/{slug}`, route names `utilities.{slug}` and `utilities.{slug}.{name}`, permission `access {handle} utility` created automatically.

---

## 10. Discrepancies and version drift

### 10.1 Official docs vs 6.19.0 source

| Doc claim | Source |
|---|---|
| Field actions: "reach into the publish form store… `run: ({ store, storeName }) => store.state.publish[storeName].values`" (`control-panel/field-actions`) | No `store` / `storeName` exists; no Vuex. Use `vm.publishContainer` or `injectPublishContext`. |
| Field action payload includes `vm` and `fieldVm` | `vm` only for Options-API fieldtypes; `fieldVm` is commented out in both set components. |
| "pass the name of the Vue component" to `$fieldActions.add` | Binding is `${config.type}-fieldtype`. |
| "return a Promise from your `run` function. A loading graphic will be automatically applied." | Throws for composition-API fieldtypes and sets (3.4). |
| `entry.saving` payload contains the entry reference | Payload is `{ collection, values }`. |
| `component.prop('prop-name', newValue)` updates props (`vue-components/overview`) | Raw-object mutation is not reactive after first render `[RUN, reactivity only]`. |
| `PublishContainer.mdx`: `isDirty()` is a function; Options API example reads `this.publishContext.values.something` | `isDirty` is a computed ref; injected refs need `.value`. |
| Toast `duration` can be disabled | Falsy duration falls back to the 3500 ms default. |

### 10.2 Upstream drift, installed 6.19.0 → 6.34.1 `[UP]`

Diffed against `statamic/cms@6.x`. Changelog entries are matched to the diffs by title; the PRs themselves were not opened.

| Area | 6.19.0 | Upstream 6.x |
|---|---|---|
| `Container.desyncField` | always adds to `localizedFields` | `if (!trackingDirtyState.value) return;` first — a no-op during the 500 ms after a switch/save and inside `withoutDirtying`. Changelog 6.30.0 "Fix unsynced fields after switching sites (#15267)". |
| Container `defineExpose` | no `meta` | adds `meta` |
| Save pipeline | hook rejection hangs; no meta reset | rejection forwarded (6.28.0 #15194); response `meta` applied via `resetMetaFromResponse`; entry form uses `v-model:meta` |
| URL after switching/creating | `replaceState` before the request, `null` URL after creation | `replaceState(localization.url + window.location.hash)` after the data loads (6.22.0 #14846, 6.30.0 #15230) |
| Term edit item actions | `itemActionUrl` missing | fixed (6.30.0 #15290) |
| Localizations panel | heading "Sites" | heading "Working In", new `heading` prop, table layout (6.28.0 #15191, #15202) |
| Globals switch | no dirty-tracking window, `originMeta` not refreshed | both added |
| Package exports | — | `@statamic/cms/inertia` adds `usePage`; `@statamic/cms/ui` adds `ContentDirection, useContentDirection, useUiDirection` |
| Unchanged | `FieldAction.js`, `fieldtype.js`, `Fieldtype.vue`, `FieldActions.js`, `toFieldActions.js`, `HasFieldActions.js`, `ui/Publish/Components.vue`, `Components.js`, `Hooks.js`, `CommandPalette.js`, `actions/*.vue`, `actions/Actions.js`, `publish/HasActions.js`, `statamic.js`, `api.js`, `LocalizeEntryController.php`, `ActionController.php`, `Action.php`, the `localization.created` emit, the `publish-container-created` payload, globals still without `<PublishComponents />` | |

---

## 11. Recommended mechanisms

### (i) Offer "translate this entry" right after switching to or creating a localization

1. At module load register `Statamic.$events.$on('publish-container-created', (c) => { … })`. Filter on `c.reference.value?.startsWith('entry::')` (terms: `term::`). Call `c.pushComponent('auto-translator-banner', { props: { containerName: c.name } })` once per container and `destroy()` it on `publish-container-destroyed`. Register the component with `Statamic.$components.register(...)` inside `Statamic.booting`. This is the documented, first-party-used injection point and it was verified `[RUN]`.
2. In the pushed component call `injectPublishContext()` and derive the offer from `isTrackingOriginValues`, `originValues`, `localizedFields`, `blueprint` (top-level fields with `localizable: true`), `site`, `reference`, `readOnly`. Watch `[reference.value, site.value]` to handle in-place switches to existing localizations (the Collaboration addon's pattern); a fresh page load is covered by the component mounting.
3. For "just created", listen to `Statamic.$events.$on('localization.created', ({ container }) => …)` and match on `container.name`; use it to open the offer prominently (expanded banner, or a `ConfirmationModal` via `Statamic.$components.append`).
4. Keep banner state in a Pinia store (`Statamic.$pinia.defineStore`) keyed by container name; do not rely on `component.prop()`.
5. Optional: `Statamic.$commandPalette.add({ text, category: Statamic.$commandPalette.category.Actions, when, action })` from the same handler (re-add per page, or `persist: true` with a `when` guard).
6. The origin site handle and any "already translated" status must come from an addon endpoint (not in the context).

### (ii) Per-field "translate" action

- Inside `Statamic.booting`, loop over supported fieldtype handles and call ``Statamic.$fieldActions.add(`${handle}-fieldtype`, { title, icon, quick, visible, run })``. The handle list can be supplied from PHP with `Statamic::provideToScript`.
- Use **field-level** bindings for nested fields too; `update(value)` / `updateMeta(meta)` handle nested paths, desync the root field and mark the form dirty `[RUN]`. Avoid the `*-fieldtype-set` bindings for translation in localizations (no desync, no path).
- `visible: ({ config, fieldPathPrefix, isReadOnly, vm }) => …`: non-localizable top-level fields in a localization are already read-only, so the action is hidden by default.
- Do **not** return a Promise from `run` unless `payload.vm` exists; manage `Statamic.$progress.loading(name, true/false)` yourself and report errors with `Statamic.$toast.error`.
- Container data: `vm.publishContainer` when present, otherwise the addon's registry (from (i)).
- Options that need user input: `confirm: { fields: {…} }`.

### (iii) Fill translated values into the open form without saving, marked as localized

From the pushed component's injected context (or a registry entry holding it), per top-level handle:

```js
ctx.setFieldMeta(handle, meta[handle]);     // when the endpoint returns meta (sets, relationships, link…)
ctx.setFieldValue(handle, values[handle]);
ctx.desyncField(handle);                     // adds to localizedFields; core then posts it in `_localized`
```

- Verified `[RUN]`: parent `v-model` and `localizedFields` update, form becomes dirty, nothing is saved until the editor saves.
- The endpoint should return values and meta in **publish-form shape**, produced server-side by `$blueprint->fields()->addValues($translatedRawData)->preProcess()` → `->values()` / `->meta()` (what `ExtractsFromEntryFields` does). Restrict to fields with `localizable: true`; the server does not enforce the flag.
- Apply only once dirty tracking is active again (at least ~500 ms after a switch or `localization.created`): in 6.19.0 earlier writes are not flagged dirty, upstream ignores `desyncField` in that window. A defensive fallback is pushing to `ctx.localizedFields.value` and calling `Statamic.$dirty.add(ctx.name.value)` directly.
- Do not use a PHP item Action plus JS callback for this in the publish form (7.3).
- For globals and nav pages `pushComponent` is not rendered; use a blueprint-injected fieldtype or the Options-API `vm.publishContainer` route, or handle them server-side.

### (iv) Progress for a multi-entry translation run

- Entry point: a PHP `Action` (bulk action in listings; `fieldItems()` for target sites/options) and/or a `Utility` / addon Inertia page.
- `run()` dispatches queued work (the addon's own batch); `redirect()` (evaluated after `run`) returns the URL of an addon Inertia page registered with `Statamic.$inertia.register`.
- That page polls with `usePoll(2000, { only: ['run'] })` from `@statamic/cms/inertia` (or its own axios polling) and renders a hand-built determinate bar (Tailwind, as in `components/assets/Upload.vue:11-13`) with `Badge` / `StatusIndicator` / `Table` / `Alert`. Finish with `Statamic.$toast` or a server `Toast`.
- Optional enhancement when `Statamic.$config.get('broadcasting.enabled')`: `Statamic.$echo.booted(echo => echo.private(…).listen(…))`. Polling must remain the baseline.
- `Statamic.$progress.loading('auto-translator', bool)` is suitable only as an indeterminate indicator, and it disables Save on open publish forms.
- A dashboard `Widget` can surface recent runs.

---

## 12. Could not verify / open flags

1. Nothing was tested in a real browser or against a running CP. `[RUN]` items used a headless renderer with stubbed UI primitives; the entry form component itself (`entries/PublishForm.vue`) was read, not executed.
2. Pushed components sit inside `LivePreview`'s default slot, which is wrapped in a portal-vue `<v-portal :disabled>` (`ui/LivePreview/LivePreview.vue:310-312`). Whether they are re-mounted (losing local state) when Live Preview opens/closes was not verified.
3. Choosing the correct container for field actions on composition-API fieldtypes when several containers are mounted (stack editors) is a heuristic.
4. `desyncField` behaviour inside the 500 ms window differs between 6.19.0 and upstream; the exact version that introduced the guard is inferred from the changelog (6.30.0).
5. Reading suggests that in 6.19.0 a fieldtype's echo update after an in-place switch (e.g. Bard re-emitting its value) can add a field to `localizedFields` without user action; this matches the upstream fix title but was not reproduced.
6. Passing selected ids to a custom action modal component (`$component`) and overriding core components by name were not exercised.
7. Toast options other than `duration` (e.g. an action button) and `Teleport` into `[data-ui-header]` are unverified ideas, not supported APIs.
8. Term create page: `createLocalization` posts to a non-existent `/localize` route (reading only).
9. Statamic's bundled Vue version was not determined (`package.json` says `^3.4.27`); the `[RUN]` checks used Vue 3.5.34.
10. PHP-side details mentioned in passing (blueprint-found listeners, `Fieldtype::appendConfigField`, events) were read but are outside this front-end report's verification scope.
