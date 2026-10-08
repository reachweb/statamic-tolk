# Changelog

All notable changes to Tolk are documented in this file.

## Unreleased

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
