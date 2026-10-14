## Tolk

- This application uses Tolk (`reachweb/statamic-tolk`), a Statamic addon that translates multi-site content from an origin site into its localizations with large language models from Anthropic, OpenAI or Google Gemini, through the Laravel AI SDK (`laravel/ai`).
- Editors translate in the entry and term forms (the translations are filled into the open form, unsaved, for them to review and save), one field at a time, in bulk from a listing, from the Overview under **Tools › Translations** (`cp/tolk/overview`), and from the command line.
- The source of a translation is always the target's origin as Statamic defines it. A new localization keeps its origin's slug, unless its collection is in the `translated_slugs` setting, which makes its slug from the translated title. An existing slug is never changed.
- Tolk tracks per field whether a translation is current, outdated (the origin changed since) or edited by hand, and updates only what changed. Text a human edited is never overwritten unless an operation asks for it (`--force`, "Overwrite manual edits").
- The documentation is in `vendor/reachweb/statamic-tolk/docs/` (one file per topic) and `vendor/reachweb/statamic-tolk/README.md`. Read the topic file before changing how Tolk is set up.

### Requirements and API keys

- Statamic Pro with at least two sites (`resources/sites.yaml`), PHP 8.3 or later, Laravel 12 or 13.
- API keys come from `.env` only; one provider is enough. Tolk reads its own variables first, `TOLK_ANTHROPIC_API_KEY`, `TOLK_OPENAI_API_KEY` and `TOLK_GEMINI_API_KEY` (`tolk.providers.*.key`), and only for a provider without one the AI SDK's `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` and `GEMINI_API_KEY` (`ai.providers.*.key`), which the rest of the application shares. To change the key Tolk uses, change the variable it reads: while a `TOLK_` variable is set, changing the SDK's variable does not reach Tolk but does change the key of the application's other AI features. Never put a key in Tolk's settings, in `config/tolk.php` or in content. The settings page only shows whether a provider has a key and where it comes from.
- `php please tolk:doctor` checks the setup: the keys (naming the config each one comes from), the sites, how runs are driven, the cache store, and the text fields that are not localizable.

### Where Tolk keeps things

- **Settings** (models, review, the context sent with every translation, the glossary, excluded collections and fields): the settings page under **Tools › Addons › Tolk** or **Tools › Translations › Settings** (`cp/addons/statamic-tolk/settings`), stored in `resources/addons/statamic-tolk.yaml` and committed. It needs the **Tolk settings** permission or **Configure addons**.
@verbatim
- Statamic parses every addon setting as Antlers. A text in the settings that holds `{{` Statamic cannot read as Antlers (`Keep {{ and }} unchanged.`) goes between `{{ noparse }}` and `{{ /noparse }}`, which Tolk leaves out of what it sends to the model. The settings page refuses it otherwise, and written into the file by hand it makes every page that reads the settings fail. `tolk:profile --save` puts a generated profile that holds `{{` between those tags itself.
@endverbatim
- **The developer config**, `config/tolk.php`: models to add to Tolk's catalog or to change in it (by id; Tolk's own catalog is updated with each release), timeouts, batch sizes, concurrency, drivers and paths. Publish it with `php artisan vendor:publish --tag=tolk-config` only when a value has to change.
- **Translation state**, what Tolk remembers about each translated field: `content/tolk/`. Commit it together with the content it describes. With content in a database (Statamic's Eloquent driver), set `state.path` in `config/tolk.php` to a folder every deploy keeps, such as `storage_path('statamic/tolk/state')`, and back it up with the database: a deploy that replaces the app's folder would lose it, and every translation would then read as edited by hand. **Do not edit the files under `content/tolk/` by hand**, and do not write or delete them from your own code: a wrong value makes a field read untranslated, outdated or edited by hand. The exception is a file that a git merge left with conflict markers or a field twice: Tolk cannot read it and never writes over it, so that item's status is unknown and translating it fails. `php please tolk:doctor` lists such files; resolve the conflict (keep each field's record from the side whose text the content kept) or delete the file, after which its translations read as edited by hand. `php please tolk:prune` removes the records of deleted content. A term's records are filed under its slug in the taxonomy's first site: change that slug through Statamic (the CP, or `slug()` and `save()` on the term), never by renaming the term's file, so its records move with it.
- **Runs** in `storage/statamic/tolk/runs/` and **monthly usage** in `storage/statamic/tolk/usage/`; plans, locks and cached counts in the cache store. None of these is committed. A plan holds the text being translated and the model's answers until the translation is saved or `plan_ttl` runs out; a `file` or `database` cache store keeps an expired one until `php artisan cache:clear`, so run it, while no translation is running, when that text has to be erased. On several servers (a load balancer, or queue workers on servers of their own), the cache store, `runs.path` and `usage.path` must be shared by every server and worker, and `state.path` too when the content folder is not shared; a queue worker that cannot see a run logs a warning naming `runs.path` and leaves the run unmoved.
- With Statamic's git integration on, Tolk makes one commit per run or command ("Content translated") and one when it records state on its own ("Translation state saved"). Statamic commits only `statamic.git.paths`, whose defaults hold `content/` (the state) but not `lang/`: where `tolk:lang` or `tolk:forms` runs with git on, add `lang_path()` to `statamic.git.paths` or commit `lang/` yourself, or a deploy that replaces the site's files loses their translations. Both commands, `tolk:doctor` and the Audit page warn while it is not tracked.

### Which fields are translated

- A top-level blueprint field is translated when it is `localizable: true`, its fieldtype has a handler, its **Translate automatically** option is on, it is not hidden or computed, and its handle is not in the **Excluded fields** setting. A field that is not localizable is shared by every site and left alone. Statamic's default blueprints leave `title` non-localizable: `php please tolk:doctor --fix-localizable` or the Audit page (**Tools › Translations › Audit**) makes such fields localizable; prefer them to editing blueprint YAML by hand.
- Fieldtypes with a handler: Text (input type `text`), Textarea, Markdown, Bard, Replicator, Grid, Group, Table, List and Array. Structure, formatting, links, set types, ids and values that are not text stay as in the origin (a row or set the origin stores without an id keeps the localization's); only the text changes. Any other fieldtype (assets, selects, relationships, the fieldtypes of other addons) is left alone until a handler is registered for it (below).
- Tolk adds two options to the settings of every such field in the blueprint editor: `tolk_translate` (**Translate automatically**, default on; `false` leaves the field out, which is also the way to leave out a field nested in a Replicator, Grid, Group or Bard set, since nested fields have no `localizable` flag) and `tolk_hint` (**Translation hint**, guidance sent to the model with that field's text, such as "product name, keep in English").

### Permissions

- `translate content`: the banner and the offer in the entry and term forms, the "Translate from origin" field action, and Translate into… and Update translations from the origin.
- `run bulk translations`: the Translate… action in listings, the Overview, the Runs pages and the Audit page.
- Statamic's own site, edit and publish permissions still apply on top: a user translates only into sites they may access and only what they may edit.

### Commands

Every command works with `php please` and `php artisan`. They run without a CP user, so they are not limited by permissions; `--help` lists the options.

- `tolk:doctor`: checks the setup; `--fix-localizable` makes the listed text fields localizable, blueprint by blueprint, after asking.
- `tolk:translate`: translates entries (`--entry`, `--collection`), terms (`--taxonomy`), global sets (`--globals`) and navigation (`--nav`), or everything (`--all`), into `--site` (repeatable). `--mode` is `missing` (the default), `update` (outdated translations too), `all` (everything again) or `review`; `--force` overwrites fields edited by hand. It prints an estimate and asks before it starts: `--yes` skips the question, `--dry-run` keeps nothing. It saves server-side; new localizations are drafts unless `--publish` or the **New localizations** setting says otherwise.
- `tolk:site {site}`: translates the whole website into one existing site, after preparing it in six steps with a question each (adding the site to collections, taxonomies and global sets, the localizable fields, nested fields that look like data). `--yes` accepts the safe defaults, `--dry-run` changes nothing.
- `tolk:status`: how much of each collection, taxonomy, global set and navigation is translated into each site.
- `tolk:baseline`: records the origin of fields translated by hand before Tolk was installed, so that later changes to the origin are noticed. The adoption path for an existing site. With `--protect` and a container option it also turns machine translations into fields edited by hand, so Translate and Update leave them alone: the only way to protect a global set or a navigation. Only text marks a field edited by hand, so a navigation whose pages were added, removed or moved by hand is otherwise rebuilt from the source tree by the next Update.
- `tolk:prune`: deletes the state of deleted content and old runs, and stops stalled runs. Schedule it daily: `Schedule::command('tolk:prune')->daily()`.
- `tolk:profile`: writes a site profile for the prompts from the default site's content.
- `tolk:lang`: translates the app's Laravel translation files, `lang/{lang}.json` and the PHP files directly in `lang/{lang}/`, into the languages of the other sites; `:parameters` stay as they are.
- `tolk:forms`: translates the texts of forms (titles, labels, instructions, placeholders, option labels and email subjects) as JSON translation strings into `lang/{lang}.json`. A text that is a translation key (of `lang/{source}.json`, a PHP file such as `forms.contact.name`, or a package's `namespace::key`) is left out, so `tolk:lang` or the package translates it. Form templates must pass the form texts through `trans`, as the command reminds you:

@verbatim
<code-snippet name="A form template after tolk:forms" lang="antlers">
{{ form:contact }}
    {{ fields }}
        <label for="{{ id }}">{{ display | trans }}</label>
        {{ field }}
    {{ /fields }}
{{ /form:contact }}
</code-snippet>
@endverbatim

- `tolk:work`: the worker process that `tolk:translate` and `tolk:site` start. Never run it yourself.

A command that translates calls a paid provider and saves content. Prefer `--dry-run` while exploring, and do not run `tolk:translate` or `tolk:site` unless asked to.

With the `auto_update` setting on (**Update translations automatically**, off by default), every save of a published entry in its origin, from code, an import or a seeder too, starts a run that updates its localizations (for a new entry of a collection with `propagate: true`, the ones core creates for it), and with `auto_update_create` creates the missing ones, calling a paid provider. Check `resources/addons/statamic-tolk.yaml` before saving entries in bulk from code, and ask before an import that would trigger it.

### Translating another fieldtype

- A fieldtype of this application or of another addon is translated once a **handler** is registered for it: a class implementing `Reach\StatamicTolk\Contracts\FieldHandler` with one method, `map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed`, which returns the value with every piece of text replaced by `$translate($leaf)` and nothing else changed. The rule: when `$translate` returns each leaf's own text, `map()` returns a value identical (`===`) to the one it was given.
- The `Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves` trait does the work for strings: `$this->plain($text, $hint, $translate, $context->with($key, $label))` offers one string and returns its translation, and `$this->hint($field->fieldtype())` reads the field's hint. `$context->walker->mapFields()` walks nested fields, as the Grid and Replicator handlers do.
- Register it in the published `config/tolk.php` under `fieldtypes` (`'seo_meta' => \App\Tolk\SeoMetaHandler::class`), or from `AppServiceProvider::boot()` with `Statamic::booted(fn () => Tolk::registerFieldtype('seo_meta', SeoMetaHandler::class))`. Keep the `Statamic::booted()` wrapper: `boot()` runs before Statamic boots its addons, so called directly it registers the handler, but a fieldtype of an addon booting after Tolk is not registered yet and gets no per-field options. From an addon, call it from `bootAddon()` after the addon's fieldtypes are registered, guarded with `class_exists(Tolk::class)`. A handler in `tolk.fieldtypes` wins over one an addon registers for the same fieldtype.
- The worked example and the three tests to copy (identity, stable leaves, containment) are in `docs/extending.md`.

@verbatim
<code-snippet name="A handler for a fieldtype with a meta title and description" lang="php">
<?php

namespace App\Tolk;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

final class SeoMetaHandler implements FieldHandler
{
    use MakesLeaves;

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $hint = $this->hint($field->fieldtype());

        foreach (['title' => 'Meta title', 'description' => 'Meta description'] as $key => $label) {
            if (is_string($value[$key] ?? null)) {
                $value[$key] = $this->plain($value[$key], $hint, $translate, $context->with($key, $label));
            }
        }

        return $value;
    }
}
</code-snippet>
@endverbatim

### Events

Three events in `Reach\StatamicTolk\Events`, fired by server-side saves only (runs, Translate into…, the commands). The editor saves nothing itself: when an editor saves a translated localization, Statamic fires its own `EntrySaved` or `TermSaved`.

- `TargetTranslated`: translations were saved into one target. `ref` (a `TargetRef` with `type`, `id` and `site`), `handles` (the fields saved), `models` (as `provider:model`) and `usage`.
- `RunStarted`: a run moved from pending to running. `runId`, `driver` (`RunDriver`: `browser`, `queue` or `cli`), `createdBy` (`['id' => …, 'name' => …]`, or `null` from the command line and for an automatic update of an entry saved without a signed-in user) and `counts` (items per status).
- `RunFinished`: a run ended, completed, completed with errors, cancelled or aborted; a resumed run fires it again when it ends. `runId`, `status` (`RunStatus`), `counts` and `usage`.
- `usage` is a `UsageMap`; `$event->usage->toArray()` gives, per model, the input, output, cache read, cache write and reasoning tokens and the number of calls.

### What is public

- Public and stable: `Reach\StatamicTolk\Tolk`, the `FieldHandler` contract, `Leaf`, `WalkContext`, `ContentWalker`, the `MakesLeaves` trait and the events. Everything else under the addon's `src/` is internal and may change in any release: do not call it from the application.
- Tolk saves content through Statamic's own API: Statamic's `EntrySaved` and `TermSaved` fire, a published localization's translations go to its working copy on a collection with revisions, and the git integration commits as described above.
- On a collection with revisions, Tolk translates from the origin as it is published, never from its working copy, and a translation waiting in a localization's working copy counts as up to date (in `tolk:status` and on the Overview) although visitors see it only once someone publishes it.
