# Marketplace listing

The texts and images for Tolk's listing on the Statamic Marketplace. Keep them in step with the [README](../README.md): the listing is what buyers read first, and an outdated one is a pitfall other translation addons have fallen into.

## Name

Tolk

## Short description

Translate multi-site content into every language with Claude, GPT or Gemini: in the editor, in bulk and from the command line, with updates that send only what changed and never overwrite your edits.

## Description

Tolk translates multi-site Statamic content from an origin site into its localizations with large language models from Anthropic, OpenAI or Google Gemini, using your own API keys.

In the entry editor, Tolk offers to translate a new localization and fills the translations into the open form, unsaved, so an editor reads them and presses Save. One field at a time, a "Translate from origin" button sits in each field's header. In bulk, a listing action and an Overview of every collection, taxonomy, global set and navigation start runs with a cost estimate, progress, cancel and resume, and the run keeps going in the browser when there is no queue. From the command line, one command translates a whole website into a new language, and two more translate the app's `lang/` files and the texts of forms.

Tolk tracks, per field, whether a translation is outdated or was edited by hand. An Update sends only the paragraphs that changed and never overwrites text a human wrote unless you ask. A site profile, instructions, per-language formality and a glossary go with every request, and an optional second model reviews each translation and applies its corrections with reasons. New localizations are saved as drafts by default.

## Features

Built around what the Statamic market had no answer for:

- **Large language models, your keys.** Claude, GPT and Gemini through the Laravel AI SDK, with the model per provider chosen in the settings or per translation. No DeepL account, no middleman.
- **Context editors control.** A model-generated, editable site profile, global instructions, per-language instructions with formality, a glossary with do-not-translate terms, and per-field options, all on a settings page in the Control Panel.
- **A second model reviews.** Off by default; when on, a reviewer of your choice checks each translation, its corrections are applied, and the editor lists what changed and why.
- **Updates, not retranslations.** Field-level tracking of origin changes, with only changed paragraphs sent again; text edited by hand is protected; an Overview shows what is missing or outdated in every site.
- **Every content type.** Entries, taxonomy terms, global sets and navigation, plus translation files and form texts, in the Control Panel and from the command line.
- **A whole website in one command.** `tolk:site` enables the new site where needed, runs the setup checks and translates everything, with an estimate first.
- **Review-safe publishing.** New localizations are drafts unless you choose otherwise.
- **Cost visibility.** An estimate before every bulk run, and usage per run and per month.
- **Structured fields survive.** Bard, Replicator, Grid, Group, Markdown, tables, lists and arrays keep their structure, formatting, links and Antlers tags; a registration API covers custom fieldtypes.
- **No infrastructure.** Flat files and no queue are enough; a queue and worker processes are used when you have them.

## Requirements

- Statamic 6.35 or later, Pro edition, with at least two sites
- PHP 8.3 or later; Laravel 12.62 or later, or Laravel 13.15 or later
- An API key for at least one of Anthropic, OpenAI or Google Gemini

## Screenshots

![The banner on a Greek localization whose origin changed: one field outdated, one edited by hand, four up to date](images/banner.png)

*The banner in the editor: the status of every field, and Translate, Update and Review.*

![The offer to translate a new localization, with the number of fields and the model](images/offer.png)

*The offer when a localization is created.*

![A run page with the estimate, progress and per-item results](images/run.png)

*A run: the estimate, progress, usage and the result of each item.*

![The Overview: every collection, taxonomy, global set and navigation by site](images/overview.png)

*The Overview: what is translated into each site, with buttons that translate what is missing or outdated.*

![The reviewer's changes, each with a reason](images/review.png)

*The reviewer's changes, with a reason for each.*

## Links

- Documentation: the [README](../README.md) and [docs](installation.md)
- Changelog: [CHANGELOG.md](../CHANGELOG.md)
- Support: info@reach.gr
