# Installation

## Requirements

- PHP 8.3 or later.
- Statamic 6.35 or later, Pro edition, with multi-site on and at least two sites. Each site is one language; Tolk translates the content of one site into the others.
- Laravel 12.62 or later, or Laravel 13.15 or later.
- The Laravel AI SDK (`laravel/ai` 1.0.1 or later). Composer installs it with Tolk.
- An API key for at least one of Anthropic, OpenAI or Google Gemini.
- A cache store that supports locks: `file`, `redis`, `database`, `memcached` or `dynamodb`. A flat-file site on Statamic's defaults (the `file` cache and the `sync` queue, no database) needs nothing more.

Tolk is a commercial addon. A production site needs a license from the Statamic Marketplace; Tolk itself checks nothing.

## Install

```bash
composer require reachweb/statamic-tolk
```

Statamic publishes the Control Panel assets when Composer finishes (`php artisan statamic:install` runs after every `composer` command in a Statamic site). If the CP shows no Tolk banner after an update, publish them by hand:

```bash
php artisan vendor:publish --tag=statamic-tolk --force
```

The developer config is optional. Publish it only to change a value in it (see [Configuration](configuration.md)):

```bash
php artisan vendor:publish --tag=tolk-config
```

## API keys

Keys are read from the `.env` file, through the Laravel AI SDK's own variables:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...
```

One provider is enough. To give Tolk a key of its own, separate from the one the rest of the app uses, set `TOLK_ANTHROPIC_API_KEY`, `TOLK_OPENAI_API_KEY` or `TOLK_GEMINI_API_KEY`; Tolk uses those first.

Keys are never stored in the settings, in the translation state, in runs or in queued jobs, and provider error messages are cleaned of them before they are shown or stored. The settings page (**Tools › Addons › Tolk**, tab **Providers**) shows which providers have a key and where it comes from, and **Test connection** makes one small request to check it.

Then check the setup:

```bash
php please tolk:doctor
```

It lists the sites, the providers and models, the queue, the cache, where the state is kept, the text fields that are not localizable, and a few other things. Each check is OK, a warning or an error; the command exits with 1 when one is an error. The same checks are on the **Audit** page in the CP.

## Permissions

Tolk adds two permissions, in the **Tolk** group of a role:

| Permission | What it allows |
| --- | --- |
| `translate content` (Translate content) | Translating one item at a time: the banner and the offer in the entry and term forms, the "Translate from origin" field action, and Translate into… and Update translations from the origin. |
| `run bulk translations` (Run bulk translations) | The **Translate…** action in entry and term listings, the Overview, the Runs pages and the Audit page. |

Statamic's own permissions still apply on top of them:

- A user translates only into sites they may access, and only what they may edit. For entries that is Statamic's edit permission for the collection; for terms, global sets and navigation it is the matching edit permission, and site access is checked as well.
- Publishing follows Statamic: a translation published "same as origin" is published only when the user may publish the entry.
- The settings page needs **Tolk settings** (`edit reachweb/statamic-tolk settings`, which Statamic registers for every addon with settings) or **Configure addons**. Users with it may also choose a model outside the catalog, reset the month's usage on the Overview and delete the finished runs from the runs list.
- **Make localizable** on the Audit page also needs **Configure Fields**, because it changes blueprints.

Who sees a run: users with `run bulk translations` who may access every site of the run (the sites it translates into and the site it translates from) see it, follow it and drive it. The runs list and the Overview's latest runs leave out the others, and creating a run, a push or an Overview count for a site the user may not access is refused. A run's items are saved with the rights of the user who created it, whoever drives it.

A user with `translate content` but without `run bulk translations` can still follow and cancel the run they start from an entry's Translate into… or Update translations, and move it to their page when no queue worker takes it up; they do not see the runs pages.

## Where Tolk keeps things

| What | Where | Committed |
| --- | --- | --- |
| Settings | `resources/addons/statamic-tolk.yaml` | yes |
| Translation state (what Tolk remembers about each translated field) | `content/tolk/` | yes, with the content |
| Runs | `storage/statamic/tolk/runs/` | no |
| Monthly usage | `storage/statamic/tolk/usage/` | no |
| Plans, locks and cached counts | the cache store | no |

Commit `content/tolk/` together with the content it describes, and do not edit its files by hand. With Statamic's git integration on, Tolk holds back Statamic's commits while it saves and makes one commit per run or command ("Content translated"), and one when it records state on its own, such as after an editor saves a translation ("Translation state saved").

Statamic's commits hold only the paths in `statamic.git.paths`. Their defaults include `content/`, and with it the state, but not `lang/`, where `tolk:lang` and `tolk:forms` write the translation files. If you run these commands where git commits for you, as on a production server, add `lang_path()` to `statamic.git.paths` in `config/statamic/git.php`, or commit `lang/` yourself. Otherwise the commit holds the state without the files: a deploy that replaces the site's files loses them, and the texts show in the source language until the command runs, and is paid for, again. Both commands warn after they write while git is on and `lang/` is not tracked, and `tolk:doctor` and the Audit page warn once either command has kept state.

## In the Control Panel

- **Tools › Translations** opens the **Overview** (`cp/tolk/overview`), with **Runs**, **Audit** and **Settings** below it. The nav item shows for users with `run bulk translations`, and its **Settings** for those of them who may edit the settings.
- The settings are at `cp/addons/statamic-tolk/settings`: **Tools › Addons › Tolk** for users who may configure addons, **Tools › Tolk** for the others with the settings permission, and **Tools › Translations › Settings** as well.
- Entry and term forms show the Tolk banner above the tabs on a multi-site collection or taxonomy, for users with `translate content`.

## The interface language

Every text of Tolk's CP interface is the English key of a `__()` call, as Statamic's own are. To show Tolk in another language, add the translations to the site's `lang/{locale}.json`, for example `lang/el.json`:

```json
{
    "Translate into…": "Μετάφραση σε…",
    "Update translations": "Ενημέρωση μεταφράσεων"
}
```

The output of the commands, log lines, the prompts sent to the models and git commit messages stay in English, as Statamic's own commands do.

## Laravel Boost

Tolk ships a guideline for [Laravel Boost](https://laravel.com/docs/boost), so that an AI agent working on your site knows what Tolk does, where it keeps things, which commands exist and what not to touch. With Boost installed, `php artisan boost:update` offers Tolk among the packages with guidelines; pick it, or add `reachweb/statamic-tolk` to `packages` in `boost.json` and run the command again. Boost then writes a `=== reachweb/statamic-tolk/core rules ===` section into your agent's guidelines file.

## Next steps

- [Configuration](configuration.md): models, review, instructions, glossary and the developer config.
- [The editor](editor.md): translating one entry.
- [Bulk translations and runs](bulk-and-runs.md) and [the command line](cli.md).
- [Troubleshooting](troubleshooting.md).
