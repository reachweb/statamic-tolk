# The command line

Every command works with `php please` and with `php artisan`. They run without a CP user, so they may translate anything and are not limited by permissions.

| Command | What it does |
| --- | --- |
| [`tolk:doctor`](#tolkdoctor) | Checks the setup. |
| [`tolk:translate`](#tolktranslate) | Translates entries, terms, global sets and navigation into other sites. |
| [`tolk:site`](#tolksite) | Translates the whole website into one site, after preparing it. |
| [`tolk:status`](#tolkstatus) | Shows how much is translated into each site. |
| [`tolk:baseline`](#tolkbaseline) | Records the origin of fields translated by hand. |
| [`tolk:prune`](#tolkprune) | Cleans up state and runs. |
| [`tolk:profile`](#tolkprofile) | Writes a site profile from the content. |
| [`tolk:lang`](#tolklang) | Translates the app's Laravel translation files in `lang/`. |
| [`tolk:forms`](#tolkforms) | Translates the texts of the site's forms. |
| [`tolk:work`](#tolkwork) | A worker process of `tolk:translate` (internal). |

Commands that call a model check first that its provider has an API key. The output of the commands is in English.

## `tolk:doctor`

Checks that the site is set up for translating, and prints each check as OK, a warning or an error.

| Option | Meaning |
| --- | --- |
| `--fix-localizable` | Offers to make the text fields that are not localizable localizable, blueprint by blueprint. |
| `--json` | Prints the checks as JSON. |

```bash
php please tolk:doctor
php please tolk:doctor --fix-localizable
```

The checks: the sites; the providers, their keys and the models the settings choose; the queue and how runs are driven; the cache store and its locks; where the state is kept, and whether Statamic's git integration tracks it and, once `tolk:lang` or `tolk:forms` has run, `lang/` ([Where Tolk keeps things](installation.md#where-tolk-keeps-things)); the text fields that are not localizable; nested fields whose handles suggest data rather than text (`anchor`, `url`, `class` and the like), which you may want to switch off; collections, taxonomies and global sets not enabled for some sites, and global sets without an origin for a site; whether the PHP of the command line has the pcntl extension, which Ctrl-C needs to cancel a run of `tolk:translate` cleanly (the Audit page leaves this check out, since the Control Panel's PHP may be another); and notes on revisions, git, static caching and the PHP workers a browser-driven run keeps busy.

With `--fix-localizable` it asks per blueprint whether to make the listed fields localizable (default no) and saves the blueprint. Fields imported from a fieldset are listed with the fieldset to change, not changed. The Audit page in the CP does the same.

Exit code 1 when a check is an error.

## `tolk:translate`

Translates entries, terms, global sets and navigation into other sites, and saves the translations.

| Option | Meaning |
| --- | --- |
| `--entry=` | Translate the family of this entry, by id. Any of its localizations names it. Repeatable. |
| `--collection=` | Translate every entry of this collection. Repeatable. |
| `--taxonomy=` | Translate every term of this taxonomy. Repeatable. |
| `--globals=` | Translate this global set. Repeatable. |
| `--nav=` | Translate this navigation. Repeatable. |
| `--all` | Every collection, taxonomy and global set that has more than one site, and every navigation. Not with the five options above. |
| `--site=` | The site to translate into. Required; repeatable. |
| `--from=` | The site to translate a missing localization from, where the entry has one there, and a navigation from, where it has a tree there. By default a localization is translated from its origin, and a navigation from the tree it was last translated from, or the default site's tree when it was translated from no other site or that site has no tree now. Terms are always translated from their taxonomy's first site, and a global set from the origin it names for each site. It cannot be the only `--site`; next to other sites, its own navigation is the source and is left out. |
| `--mode=` | `missing` (the default: only what is not translated yet), `update` (also outdated translations), `all` (everything again) or `review` (review the existing machine translations and save the corrections; see [Review](review.md#reviewing-existing-translations)). |
| `--force` | Overwrite fields edited by hand. In mode `review`: review them too; they stay edited by hand. |
| `--publish` | Publish a new localization when its origin is published, instead of saving it as a draft. Without it, the `new_localization_status` setting decides. |
| `--publish-changes` | On a collection with revisions, publish the changes to a published localization instead of leaving them in its working copy (see [Revisions](#revisions)). |
| `--translator=` | The model to translate with, as `provider:model`. Default: the settings. |
| `--review` | Review the translations with a second model and save its corrections. Default: the `review_enabled` setting. |
| `--no-review` | Do not review, whatever the settings say. |
| `--reviewer=` | The model to review with, as `provider:model`. Implies `--review`. |
| `--concurrency=` | The worker processes that translate in parallel. Default: `concurrency.cli` (4); `1` translates in the command's own process. |
| `--queue` | Hand the run to the queue and print its address in the CP, instead of running it here. |
| `--no-estimate` | Start without scanning the run for an estimate first. |
| `--dry-run` | Show what would be translated and the estimate, without calling a model or saving anything. |
| `--yes` | Translate without asking for confirmation. |

Examples:

```bash
# Every page into Greek
php please tolk:translate --collection=pages --site=el

# Articles and topics into Greek and German, updating outdated translations too
php please tolk:translate --collection=articles --taxonomy=topics --site=el --site=de --mode=update

# Everything into German with the fast Anthropic model, reviewed by GPT-6.1 Sol
php please tolk:translate --all --site=de --translator=anthropic:claude-haiku-5-5 --reviewer=openai:gpt-6.1-sol

# The global set and the main navigation
php please tolk:translate --globals=site_settings --nav=main --site=el

# Only look
php please tolk:translate --collection=pages --site=el --dry-run
```

The command first scans the run and prints what it would do:

```text
Targets: 6 (Greek). Translator: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5). Mode: missing.

  Entries → Greek ............................................. 6 to translate
  Segments ............................................ 102 (5,042 characters)
  Estimated tokens ................................. 8,978 input, 1,934 output
  Estimated cost ....................................................... $0.04

 ┌ Translate and save them? ────────────────────────────────────┐
 │ ● Yes / ○ No                                                 │
 └──────────────────────────────────────────────────────────────┘
```

Then it translates, with a progress bar, and ends with a line per target, a summary table, the failed targets with their errors, the fields left partly untranslated, and the text fields that were not translated, with the reason (not localizable, hidden, switched off, excluded, a generated title).

- `--dry-run` prints a line per target with the fields it would translate, and keeps nothing: no run, no state. A target that waits for others the run translates first, such as a German page translated from the Greek one, reads "nothing to do yet": the run plans it again once they are done.
- `--no-estimate` skips the scan; useful on a large site when you already know what it costs.
- Targets left out are counted with the reason ("3 targets are in collections excluded in the settings"): a collection excluded in the settings, a site the collection does not have, a global set without an origin for the site, and the like. A page whose root page is not translated into that site yet is named ("Translate Home into Greek first.").

### How the command runs

The command creates a run, like the CP's runs, and drives it itself. It starts `--concurrency` worker processes ([`tolk:work`](#tolkwork)) that translate in parallel, and saves the translated items in its own process, one at a time (see [Why one writer](bulk-and-runs.md#why-one-writer)). The workers are started through the site's `please` file with PHP's `proc_open`; when either is missing, the command translates on its own, one item at a time.

The run is listed on the CP's Runs page with its progress.

- **Ctrl-C** while the command scans or at the question translates nothing and keeps no run. During the run it cancels the run after the current requests: the workers stop before their next request, and everything saved stays saved. A second Ctrl-C stops the workers at once. The cancelled run can be resumed on its run page, which saves the items already translated and translates the rest. The command counts the targets translated but not saved: resume the run within `plan_ttl` (2 hours) to save them without paying for them again, since running the command again, or resuming later, translates them anew. Except at the question, this needs PHP's pcntl extension on the command line; `tolk:doctor` says whether PHP has it.
- **Ctrl-C without pcntl** (always on Windows, and on PHP builds without it, as some Docker images are) ends the command and its workers at once. The requests under way are lost, though the provider charges for them. The run stays *Running* until it reads ["This run is not moving"](troubleshooting.md#this-run-is-not-moving) after `runs.claim_ttl` (5 minutes): then press **Resume** on its run page, or let [`tolk:prune`](#tolkprune) stop it. With Statamic's automatic git commits, what it saved is committed only when it ends or is stopped. Ctrl-C while the command scans, or on Windows at the question, leaves the run *Not started*: it translates nothing unless you start it on its run page, and `tolk:prune` deletes it after `runs.retention_days`. To stop a run cleanly there, press **Cancel** on its run page.
- **A crash** of the command does not leave its workers translating for nobody. A fatal error, such as `memory_limit`, cancels the run as the command ends, unless it leaves PHP too little memory to do so. When the command is killed on its own (`kill -9`, the out-of-memory killer), or the fatal error could not cancel the run, its workers cancel it before their next request (with PHP's posix extension, which Windows lacks). Resume the cancelled run on its run page, as after Ctrl-C. A run nothing cancelled reads ["This run is not moving"](troubleshooting.md#this-run-is-not-moving) after `runs.claim_ttl` (5 minutes).
- **`--queue`** creates a queue run, starts its lane jobs and prints the run page's address; the command then ends and a queue worker does the work. It is refused on a connection without a worker, such as `sync`; use `--concurrency` there.

### Exit codes

`0` when everything was translated or had nothing to do, or the question was declined. `1` for invalid input, a provider without a key, a target that failed, a run that was aborted or cancelled, or Ctrl-C. A page skipped because its root page is missing is not a failure. When Ctrl-C ends the command at once, without pcntl, the shell reports the interrupt instead (130 in Bash and Zsh).

### Revisions

On a collection with revisions, new localizations are drafts with a revision (published through a publish revision with `--publish` and a published origin), and translations of a published localization go to its working copy and stay unpublished. `--publish-changes` publishes them, but only when the localization had no unpublished changes before; otherwise the target gets a warning and the changes wait in the working copy. An unpublished localization is saved directly, unless it has unpublished changes, which then get the translations. The revision message is "Translated from {site} with Tolk". See [Bulk translations and runs](bulk-and-runs.md#revisions).

## `tolk:site`

Translates the whole website into one site: every entry, term, global set and navigation, in mode `missing`. It prepares the site first.

| Argument or option | Meaning |
| --- | --- |
| `site` | The site to translate the website into. |
| `--from=` | The site to translate a missing localization from, where the entry has one there, and a navigation from, where it has a tree there. Also the origin of the global sets this command enables for the site. Default: the origin, and for a navigation the tree it was last translated from, else the default site's. |
| `--publish` | Publish a new localization when its origin is published, instead of saving it as a draft. |
| `--translator=` | The model to translate with, as `provider:model`. |
| `--review` | Review the translations with a second model. Default: the settings. |
| `--no-review` | Do not review, whatever the settings say. |
| `--reviewer=` | The model to review with. Implies `--review`. |
| `--concurrency=` | The worker processes. Default: `concurrency.cli`; `1` translates in this process. |
| `--queue` | Hand the run to the queue instead of running it here. |
| `--dry-run` | Show what would be changed and translated, without changing the configuration, calling a model or saving anything. |
| `--yes` | Answer every question with its default (below). |

```bash
php please tolk:site de --dry-run
php please tolk:site de
```

The site must exist: Tolk does not create sites. Add it under **Sites** in the CP first. The command checks everything it was given before it changes anything, then runs six steps, each with its own question:

1. **The site.** It must exist, and it must not be the site the others are translated from: the `--from` site, or the default site without `--from`.
2. **Collections and taxonomies** that do not include the site are listed, with the offer to add it (default yes). A collection that should stay out belongs in the **Excluded collections** setting, which this step skips.
3. **Global sets** where the site is not enabled, or has no origin, are listed with the offer to enable it, with `--from` or the default site as its origin, or another site of the set when it has neither (default yes).
4. **Routes**: collections whose route is a per-site map without an entry for the site are named, so you can add one.
5. **Text fields that are not localizable**, per blueprint, with the offer to make them localizable (default no). On Statamic's default blueprints `title` is not localizable, so without this step everything would be translated except the titles.
6. **Nested fields that look like data** (`anchor`, `url`, …) are listed, with the question "Continue, and translate them as text?" (default yes). Changing them is left to you; declining translates nothing.

Then it runs `tolk:translate --all --site={site} --mode=missing` with the options you gave, so its estimate, question, progress and report follow, and its exit code is the command's.

- `--yes` accepts the safe default of each step: it enables the site where it is missing and leaves blueprints alone. A default blueprint's `title` then stays untranslated until you run `tolk:doctor --fix-localizable`.
- `--dry-run` asks nothing and changes no configuration: it lists each step's findings and what it would translate, as if you had accepted steps 2 and 3, so its estimate matches the run's.

It ends with what it does not translate:

```text
Not translated by this command:
  ⇂ The alt text of assets, which a later version of Tolk translates
  ⇂ The app's translation files in lang/: php please tolk:lang de
  ⇂ The texts of forms: php please tolk:forms de
  ⇂ Fields of third-party fieldtypes, such as those of SEO addons, until a Tolk handler is registered for their fieldtype
```

## `tolk:status`

Shows how much of each collection, taxonomy, global set and navigation is translated into each site: the number of items missing, untranslated, outdated, needing attention and up to date, and the fields translated by hand without a record of their origin (untracked).

| Option | Meaning |
| --- | --- |
| `--site=` | Show only this site. Repeatable. |
| `--collection=` | Show this collection. Repeatable. |
| `--taxonomy=` | Show this taxonomy. Repeatable. |
| `--globals=` | Show this global set. Repeatable. |
| `--nav=` | Show this navigation. Repeatable. |
| `--json` | Print the counts as JSON. |

```bash
php please tolk:status
php please tolk:status --collection=articles --site=el --json
```

```text
+------------+---------------+------+---------+--------------+----------+-----------------+---------+------------------+
| Type       | Handle        | Site | Missing | Untranslated | Outdated | Needs attention | Current | Untracked fields |
+------------+---------------+------+---------+--------------+----------+-----------------+---------+------------------+
| Collection | pages         | el   | 6       | 0            | 0        | 0               | 0       | 0                |
| Collection | articles      | el   | 11      | 0            | 0        | 0               | 2       | 12               |
| Taxonomy   | topics        | el   | 0       | 5            | 0        | 0               | 0       | 0                |
| Global set | site_settings | el   | 0       | 1            | 0        | 0               | 0       | 0                |
| Navigation | main          | el   | 1       | 0            | 0        | 0               | 0       | 0                |
+------------+---------------+------+---------+--------------+----------+-----------------+---------+------------------+
```

Without options it shows everything with more than one site. Collections and taxonomies excluded in the settings are left out unless you name them. Each item counts once, by its worst status; [Statuses](statuses.md) explains them.

## `tolk:baseline`

Records the origin of fields translated by hand, so that a later change to the origin is noticed.

| Option | Meaning |
| --- | --- |
| `--site=` | Record only in this site. Repeatable. Default: every site. |
| `--collection=` | Record in this collection. Repeatable. |
| `--taxonomy=` | Record in this taxonomy. Repeatable. |
| `--globals=` | Record in this global set. Repeatable. |
| `--nav=` | Record in this navigation. Repeatable. |
| `--include-copies` | Also record the fields whose text is a copy of their origin's, so translating does not replace them. |
| `--protect` | Also turn machine-translated fields into fields edited by hand, so Translate and Update leave them alone. Needs `--collection`, `--taxonomy`, `--globals` or `--nav`. |
| `--dry-run` | Count the fields without recording anything. |

```bash
php please tolk:baseline --dry-run
php please tolk:baseline --collection=articles --site=el
php please tolk:baseline --nav=main --site=el --protect
```

This is the way in for a site that was translated by hand before Tolk was installed. Such fields read "edited by hand" and *untracked*: Tolk leaves them alone, but cannot tell when their origin changes. The baseline records, for each field edited by hand, the origin text it now belongs to. The fields stay edited by hand, and when the origin changes later they show as "needs attention" (see [Statuses](statuses.md#baselines)).

It records every field edited by hand in the selection, tracked or not, so it also clears "needs attention" on fields whose origin changed. Check those first (`tolk:status` counts them), or limit the command with `--site` and the container options.

A field whose text equals its origin's (a copy nobody translated) reads untranslated, so the next run translates it. `--include-copies` records those too, for fields that are meant to stay the same in both languages, so translating leaves them alone.

`--protect` is the editor's **Protect this entry from updates** for every item of the containers you name: their machine translations, up to date or outdated, become fields edited by hand with a record of the current origin, so Translate and Update leave them alone and a later change to the origin shows as needing attention ([Statuses](statuses.md#protecting-translations)). It is the only way to protect a global set or a navigation, which have no editor; a navigation whose pages were added, removed or moved by hand needs it, because only text changes keep a tree from being rebuilt ([Navigation](content-types.md#navigation)). It refuses to run without a container option, so it never protects every translation of a site at once.

The command prints a table of the fields recorded per container and site, and makes one commit with Statamic's git integration.

## `tolk:prune`

Cleans up. Run it daily.

| Option | Meaning |
| --- | --- |
| `--dry-run` | Show what would be cleaned up, without changing anything. |

```bash
php please tolk:prune --dry-run
php please tolk:prune
```

It:

- deletes the translation state of content that was deleted, and of entries that are no longer localizations;
- deletes the state left under a term's old slug when the slug was changed in the term's file name, outside Statamic (a slug changed in the CP takes the term's state with it);
- checks a navigation, a translation file and the form texts against the site they were translated from, which need not be the default site, so their state is kept also when they were translated from another site into the default site's language or tree;
- keeps the state of content in a site the configuration leaves out, and of translations made from such a site, in case the site comes back;
- keeps the state of entries and terms in a site their collection or taxonomy no longer has, for the same reason, also after the Stache is cleared or warmed; an entry deleted meanwhile has its state deleted once the site is back in its collection;
- records the collection and site in an entry's state written by Tolk 1.0.0-beta.1, which lacks them: until it has, that state is kept for a site taken out of its collection only while the Stache still knows the entry, so after upgrading from 1.0.0-beta.1 run `tolk:prune` once before you take a site out of a collection;
- resolves translations delivered to the editor that were saved, and drops those never saved after 30 days;
- stops runs that made no progress for `runs.claim_ttl` seconds (5 minutes by default): it commits what they saved and marks them aborted, so their page offers **Resume**;
- deletes runs without activity for `runs.retention_days` days (30 by default).

With `-v` or `--dry-run` it lists the references of the state it deletes; stopped runs are listed with their CP address.

Schedule it in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tolk:prune')->daily();
```

## `tolk:profile`

Writes a site profile for the translation prompts from the content of the default site (see [Context and glossary](context-and-glossary.md#the-site-profile)) and prints it, with names the model suggests keeping untranslated.

| Option | Meaning |
| --- | --- |
| `--model=` | The model to write the profile with, as `provider:model`. Default: the translator of the settings. |
| `--save` | Save the profile as the site profile in the settings, after showing it and asking. |

```bash
php please tolk:profile
php please tolk:profile --save
```

`--save` shows the current profile, if there is one, and asks before it replaces it. The suggested names are only printed: add them to the glossary with **Do not translate** on. In the CP, **Generate** on the settings page does the same and can add the names to the glossary for you.

## `tolk:lang`

Translates the app's Laravel translation files into the languages of other sites: `lang/{lang}.json` and the PHP files directly in `lang/{lang}/` (not those in its subfolders). The interface texts of a Statamic site's templates (`{{ trans key="…" }}`, `__()`) come from these files.

| Argument or option | Meaning |
| --- | --- |
| `sites` | The sites to translate into, by handle. Default: every site whose language differs from the source language. |
| `--from=` | The site whose language the files are translated from. Default: the default site. |
| `--group=` | Translate only this file: the name of a PHP file without `.php`, or `_json` for the JSON file. Repeatable. |
| `--mode=` | `missing` (the default: only strings that are not translated yet), `update` (also outdated translations), `all` (everything again) or `review` (review the existing machine translations). |
| `--force` | Overwrite strings edited by hand (in mode `review`: review them too). |
| `--review` | Review the translations with a second model. Default: the settings. |
| `--no-review` | Do not review, whatever the settings say. |
| `--translator=` | The model to translate with, as `provider:model`. |
| `--reviewer=` | The model to review with. Implies `--review`. |
| `--dry-run` | Show what would be translated, without calling a model or writing anything. |
| `--yes` | Rewrite PHP files that this command did not write without asking, although their comments and layout are lost. |

```bash
php please tolk:lang de
php please tolk:lang el de --group=messages
php please tolk:lang --dry-run
```

- **Sites and languages.** Sites are named by handle and stand for their language (Statamic's `lang` of the site, for example `de` for a site with the locale `de_DE`). Sites with the same language share its files, so they are translated once. Without a site, the command translates into every language other than the source's.
- **Each string is translated on its own**, with its key as context. Laravel's `:parameters` (`:name`, `:Name`, `:NAME`) stay as they are; a colon after a letter or a digit, as in `10:30` or `Note:`, is not a parameter.
- **Pluralised strings** (`{0} No rooms|{1} One room|[2,*] :count rooms`): each form is translated on its own and keeps its `{n}` or `[a,b]` condition. Every `|` counts, because a file does not say which strings the site reads with `trans_choice()`: a string such as `Blog | :site` is translated in two parts the same way, each keeping its place. A translated part that holds a `|`, which would add a form and shift the ones after it, is sent back for a repair; after two repairs that part stays in the source language. A language with more plural forms than the source (Russian has three where English has two) needs its extra forms added by hand: the command lists every string with a `|` at the end, so add the forms to the pluralised ones and leave the others as they are.
- **PHP files are written anew**, as a short-array file with the source's nesting. The comments and layout of a PHP file the command did not write are lost, so the command asks before it rewrites one ("Translate and rewrite them?", default no); `--yes` answers yes. A file it wrote itself is never asked about again. JSON files are written pretty-printed with four spaces.
- **Keys only the target file has are kept**, unless the source file nests its keys differently there: a string `password` in `lang/de/validation.php` where `lang/en/validation.php` now has `password.letters` and `password.mixed`, or the reverse. One PHP file cannot hold both, and Laravel looks up the source's keys, so the command removes such a key when it writes the file and names it under the file's line. Values that are not text (numbers, booleans, and strings with nothing to translate, such as URLs and email addresses) are copied from the source where the target file has none. They are copied whenever the command writes the file, so one added to the source later arrives with the next string translated into that file.
- **The state** lives under `content/tolk/lang/`, like the content's, so a second run sends nothing, an edited source string is translated again with `--mode=update`, and a string edited by hand is kept.
- **Git.** With Statamic's git integration on, the command makes one commit ("Content translated") of the paths in `statamic.git.paths`, whose defaults hold the state but not `lang/`. While `lang/` is not tracked, the command warns after it writes: add `lang_path()` to `statamic.git.paths`, or commit `lang/` yourself, or a deploy that replaces the site's files loses the translations ([Where Tolk keeps things](installation.md#where-tolk-keeps-things)).

`--dry-run` lists per file the strings by status and what would be sent, with an estimate. Exit code 1 for invalid input, a file that cannot be read (nothing is sent then), a file that could not be written or a provider that rejects the key.

## `tolk:forms`

Translates the texts of the site's forms into the languages of other sites. Statamic forms are not localizable, so their texts are written as JSON translation strings into `lang/{lang}.json`, which Statamic and Laravel look up.

| Argument or option | Meaning |
| --- | --- |
| `sites` | The sites to translate into, by handle. Default: every site whose language differs from the source language. |
| `--from=` | The site whose language the form texts are in. Default: the default site. |
| `--form=` | Translate only the texts of this form, by handle. Repeatable. |
| `--mode=` | `missing` (the default), `update`, `all` or `review`, as for `tolk:lang`. |
| `--force` | Overwrite translations edited by hand (in mode `review`: review them too). |
| `--review` | Review the translations with a second model. Default: the settings. |
| `--no-review` | Do not review, whatever the settings say. |
| `--translator=` | The model to translate with, as `provider:model`. |
| `--reviewer=` | The model to review with. Implies `--review`. |
| `--dry-run` | Show what would be translated, without calling a model or writing anything. |

```bash
php please tolk:forms de
php please tolk:forms --form=contact --dry-run
```

- **What it collects**: each form's title; the label (`display`, or for a field without one the label Statamic makes from its handle), `instructions` and `placeholder` of each top-level field; the labels of its options, never the option values; and the subject of each email.
- **Each text is its own key** in `lang/{lang}.json`, keyed by the source text, as Laravel's JSON files are. A text used by several forms is translated once. Because the key is the text, a changed label is a new key: the old one is listed as unused and left in the file, never deleted.
- **A `|` in a form text is text**: Statamic looks the texts up with `trans()`, which picks no plural form, so a label such as `Rating | 1 to 5` is translated whole, unlike a string of `tolk:lang`.
- **A text `lang/{source}.json` already has** as a key is left to `tolk:lang`, which translates that file. A text that moves between that file and a form keeps its record: the command that takes it over finds the translation as the other one left it, so a machine translation is not read as edited by hand.
- **A text that is a translation key** is left out too, and named. Laravel looks in `lang/{lang}.json` before anything else, so an entry there would hide the key's own translation. A key of a PHP file of the source language, such as a label `forms.contact.name` with a line under `contact.name` in `lang/{source}/forms.php`, is left to `tolk:lang`, which translates that file; a package's key, such as `acme::forms.name`, is left to the package. When an earlier run translated such a text before it became a key (its line was added to the PHP file, or its package installed, later), the command lists the entry it wrote, which still hides the key's translation, and leaves it to you to delete.
- **An email subject with Antlers** (`{{ name }} wrote to you`) is left out and named: Statamic renders the subject before it looks up its translation, so its key would never match.
- **The state** lives under `content/tolk/forms/`. The command commits and warns about an untracked `lang/` as `tolk:lang` does.

Statamic passes only the email subjects through the translator. For the other texts, add `| trans` in the site's form templates, as the command reminds you:

```antlers
{{ form:contact }}
    {{ fields }}
        <label for="{{ id }}">{{ display | trans }}</label>
        {{ field }}
        {{ if instructions }}
            <p id="{{ id }}-instructions">{{ instructions | trans }}</p>
        {{ /if }}
    {{ /fields }}
{{ /form:contact }}
```

Use `{{ title | trans }}` where a template shows the form's title. Placeholders and option labels are written by Statamic's field views, which `{{ field }}` renders. Publish them:

```bash
php artisan vendor:publish --tag=statamic-forms
```

and in `resources/views/vendor/statamic/forms/fields/` change the placeholder in `text.antlers.html` and `textarea.antlers.html`, and the option label in `select.antlers.html`, `radio.antlers.html` and `checkboxes.antlers.html`:

```antlers
{{ if placeholder }}placeholder="{{ placeholder | trans }}"{{ /if }}

{{ label !== null ? (label | trans) : option }}
```

`tolk:forms` asks nothing before it writes, because a JSON file keeps its layout. Exit codes as for `tolk:lang`.

## `tolk:work`

A worker process that `tolk:translate` and `tolk:site` start, as many as their concurrency. It translates the items of one run and never saves content; the command that started it does. It is hidden from `php please list`, and you do not need to run it yourself.

| Argument or option | Meaning |
| --- | --- |
| `run` | The id of the run. |
| `--lane=` | The lane of this worker, which gives its worker id (`cli-1`, `cli-2`, …). Default: 1. |
| `--parent=` | The process id of the command that started the worker. When that process ends before the worker, the worker cancels the run before its next request and stops; a run resumed on its run page meanwhile it leaves running. Needs PHP's posix extension, which Windows lacks. |
