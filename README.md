# Tolk for Statamic

Tolk translates multi-site Statamic content from an origin site into its localizations with large language models from Anthropic, OpenAI or Google Gemini. It works in the entry editor, where translations are filled into the open form for the editor to review and save, on single fields, in bulk from listings and from the command line. It tracks which translations are outdated or edited by hand, so it updates only what has changed.

- **In the editor**: create a localization and Tolk offers to translate it; Translate and Update fill the form, unsaved, field by field. Bard, Replicator, Grid, Markdown and tables keep their structure, formatting and links.
- **One field at a time**: "Translate from origin" in a field's actions.
- **In bulk**: a listing action, an Overview of every collection, taxonomy, global set and navigation by language, and runs with an estimate before they start, progress, cancel and resume. No queue or database needed.
- **From the command line**: translate collections, or the whole website into a new language in one command, plus the app's `lang/` files and the texts of forms.
- **Updates, not retranslations**: when the origin changes, only the changed paragraphs are sent again. Text a human edited is never overwritten unless you ask. Optionally, saving an entry updates its translations by itself.
- **Quality**: a site profile, instructions, per-language formality and a glossary go with every request; an optional second model reviews each translation and its corrections are applied and listed with reasons.
- **Your own API keys**, from `.env`. Cost estimates before every bulk run, and usage per run and per month.

## Requirements

- PHP 8.3 or later
- Statamic 6.35 or later, Pro edition (multi-site needs it), with at least two sites
- Laravel 12.62 or later, or Laravel 13.15 or later
- Laravel AI SDK (`laravel/ai`) 1.0.1 or later, installed with the addon
- An API key for at least one of Anthropic, OpenAI or Google Gemini

## Installation

```bash
composer require reachweb/statamic-tolk
```

Add an API key to `.env` (one provider is enough):

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...
```

Then give your roles Tolk's permissions under **Users › Permissions**: `translate content` for the editor, `run bulk translations` for listings, the Overview, the Runs pages and the Audit page. Statamic's own site, edit and publish permissions still apply. See [Installation](docs/installation.md) for the details.

## Quick start

1. Check the setup:

   ```bash
   php please tolk:doctor
   ```

   If it lists text fields that are not localizable (on Statamic's default blueprints, `title` is not), make them localizable, so Tolk can translate them:

   ```bash
   php please tolk:doctor --fix-localizable
   ```

2. Optional, but it makes translations better: open **Tools › Addons › Tolk**, press **Generate** next to **Site profile**, check the text and save.

3. Open an entry in the CP. In the **Sites** panel, click a site the entry has no localization in. Tolk offers to translate it: press **Translate**, read the filled fields and press **Save**.

4. Translate a whole collection from the command line. It shows an estimate and asks before it starts:

   ```bash
   php please tolk:translate --collection=pages --site=de
   ```

   Or the whole website, entries, terms, global sets and navigation, into a new site:

   ```bash
   php please tolk:site de
   ```

5. Open **Tools › Translations** to see what is translated into each site, and update what is outdated.

## Documentation

- [Installation](docs/installation.md): requirements, keys, permissions, where Tolk keeps things
- [Configuration](docs/configuration.md): every setting and config key
- [The editor](docs/editor.md): the banner, the offer, the options, field actions, translating from the origin
- [Bulk translations and runs](docs/bulk-and-runs.md): the listing action, run pages, drivers, queues, the Overview
- [The command line](docs/cli.md): every command with examples
- [Context and glossary](docs/context-and-glossary.md): the site profile, instructions and glossary
- [Statuses](docs/statuses.md): untranslated, outdated, edited by hand, and how Tolk tells them apart
- [Review](docs/review.md): a second model checking the first
- [Content types](docs/content-types.md): entries, terms, global sets, navigation, fieldtypes and limits
- [Extending](docs/extending.md): handlers for other fieldtypes, and events
- [Privacy and data](docs/privacy.md): what is sent to which provider
- [Troubleshooting](docs/troubleshooting.md)

## License

Tolk is commercial software, not free software. You can try it in local development, where Statamic runs commercial addons in trial mode; a site in production needs its own license from the Statamic Marketplace. The terms are in [LICENSE.md](LICENSE.md).

## Support

Email [info@reach.gr](mailto:info@reach.gr). Changes are listed in the [changelog](CHANGELOG.md).
