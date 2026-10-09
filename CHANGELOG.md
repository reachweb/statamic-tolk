# Changelog

All notable changes to Tolk are documented in this file.

## Unreleased

### Added

- **Settings** in the **Tools › Translations** menu, below **Audit**, for users who may edit Tolk's settings.
- **Translated slugs**, a setting that lists the collections whose new localizations get a slug made from their translated title, the way Statamic makes slugs in the target site's language. It is empty by default, so new localizations keep their origin's slug as before, and an existing slug is never changed.
- **Reset** on the Overview's usage card, which deletes the month's usage, and on the runs list, which deletes the finished runs; both ask first and are offered to users who may edit Tolk's settings.

### Changed

- The editor's translation options are grouped and shorter, so they fit a laptop screen: the model and the reviewer side by side, the reviewer enabled only while it has something to do, the actions that take effect at once under their own heading, and the offer preference as a switch.
- The banner of a localization shows its counts and its buttons on one line, without repeating that it is up to date; the origin's list of translations lines up and colours each status.
- A status has the same colour in the banner, the origin's list and the Overview.
- The editor names models as the model selects do ("Gemini 3.8 Flash" rather than `gemini-3.8-flash`): in the banner's summary, the offer and the reviewer's changes.
- The origin's banner is hidden while every localization is up to date.
- A page skipped because its collection's home page is not translated into the site yet says why (the first page saved into the site's empty tree would become its home page) and links to the home page, in the push results and on the run's page.

### Fixed

- Missing space between paragraphs in Translate into…, the offer and the push results.
- `tolk:lang` writes a key of a PHP translation file that holds a dot (`'faq.title' => …`) as one key, as the source has it, instead of nesting it. A key that is also the start of another (`'faq'` next to `'faq.title'`) no longer loses its translation, which made `__()` return an array, and is no longer sent again on every run.

## 1.0.0-beta.1 - 2026-10-08

### Added

- Translation of multi-site Statamic content from an origin site into its localizations with models from Anthropic, OpenAI and Google Gemini, through the Laravel AI SDK, with your own API keys.
- In the entry and term editor: a banner with the status of every field, an offer to translate a new localization, Translate, Update and Review that fill the open form unsaved, "Translate from origin" in a field's actions, and Translate into… from the origin.
- Bard, Replicator, Grid, Group, Markdown, table, list, array and text fields keep their structure, formatting, links and Antlers tags; a registration API for other fieldtypes, with per-field "Translate automatically" and "Translation hint" options.
- Bulk translations: a listing action for entries and terms, an Overview of every collection, taxonomy, global set and navigation by site, and run pages with an estimate before the start, progress, usage, cancel, resume and retry. Runs are driven by the browser, a queue or worker processes, with no database and no queue required.
- Commands: `tolk:translate`, `tolk:site`, `tolk:status`, `tolk:doctor`, `tolk:baseline`, `tolk:profile`, `tolk:prune`, `tolk:work`, `tolk:lang` and `tolk:forms`.
- Per-field tracking of outdated translations and manual edits: only changed paragraphs are sent again, and text edited by hand is kept unless asked otherwise.
- Context for every request: a generated, editable site profile, global and per-language instructions with formality, a glossary with do-not-translate terms, and the field's label path.
- An optional review by a second model, with the corrections applied and listed with their reasons, and Review again with any model.
- Cost estimates before bulk runs, usage per run and per month, and new localizations saved as drafts by default.
- An Audit page and `tolk:doctor` for the setup, the provider keys and the fields that are not localizable.
- Events for a translated target, a finished run and a changed field; a Laravel Boost guideline for host apps.
