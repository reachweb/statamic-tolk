# Changelog

All notable changes to Tolk are documented in this file.

## Unreleased

### Added

- **Settings** in the **Tools › Translations** menu, below **Audit**, for users who may edit Tolk's settings.
- **Translated slugs**, a setting that lists the collections whose new localizations get a slug made from their translated title, the way Statamic makes slugs in the target site's language. It is empty by default, so new localizations keep their origin's slug as before, and an existing slug is never changed.
- **Automatic updates**, off by default (the settings' new Automation tab): saving a published entry in its origin updates its localizations in the background, as **Update translations** does, and with **Create missing localizations** also translates it into the sites that have none. They can be limited to some collections, and in collections with revisions published at once. Each update is a run, listed as an automatic update, followed in the origin's banner after the save, and counted under **Automatic updates** in the Overview's usage; the localization's banner says when an automatic update last saved it. With a queue worker the update runs on the queue; without one, in the process that saved the entry, after its response.
- **Reset** on the Overview's usage card, which deletes the month's usage, and on the runs list, which deletes the finished runs; both ask first and are offered to users who may edit Tolk's settings.

### Changed

- Tolk's model catalog is no longer part of `config/tolk.php`, so each update of Tolk brings its new models, prices and request options to every site, including a site that published the config. `models` in the config now only adds models or changes Tolk's, by id: an entry replaces the keys it names and keeps Tolk's others. **If you published the config from 1.0.0-beta.1**, delete the entries under `models`, or keep only the keys you changed: each entry of the copy keeps replacing Tolk's model with the same id, and the copy's Claude Haiku 5.5 sends a thinking option that Anthropic refuses.
- The editor's translation options are grouped and shorter, so they fit a laptop screen: the model and the reviewer side by side, the reviewer enabled only while it has something to do, the actions that take effect at once under their own heading, and the offer preference as a switch.
- The banner of a localization shows its counts and its buttons on one line, without repeating that it is up to date; the origin's list of translations lines up and colours each status.
- A status has the same colour in the banner, the origin's list and the Overview.
- The editor names models as the model selects do ("Gemini 3.8 Flash" rather than `gemini-3.8-flash`): in the banner's summary, the offer and the reviewer's changes.
- The origin's banner is hidden while every localization is up to date.
- A page skipped because its collection's home page is not translated into the site yet says why (the first page saved into the site's empty tree would become its home page) and links to the home page, in the push results and on the run's page.

### Fixed

- Missing space between paragraphs in Translate into…, the offer and the push results.
- `tolk:lang` writes a key of a PHP translation file that holds a dot (`'faq.title' => …`) as one key, as the source has it, instead of nesting it. A key that is also the start of another (`'faq'` next to `'faq.title'`) no longer loses its translation, which made `__()` return an array, and is no longer sent again on every run.
- `tolk:lang` tracks keys that read as numbers, such as `'404'` in `lang/en/errors.php` or `"2026"` in `lang/en.json`. Their translations were recorded under the wrong key, so they read "edited by hand" and `--mode=update` never refreshed them. Keys translated by 1.0.0-beta.1 read "edited by hand" until they are translated again with `tolk:lang --force --group=<file>` (`_json` for the JSON file).
- `tolk:lang` copies strings with nothing to translate, such as URLs, email addresses and phone numbers, from the source into the target file, as it copies numbers, so `__('site.url')` no longer shows `site.url` on a translated site (a JSON file has no fallback language). They are copied whenever the command writes the file.
- A text that moves between `lang/{source}.json` and a form, such as a form label added to the JSON file, keeps its record, so `tolk:lang` and `tolk:forms` no longer read its translation as edited by hand.
- `tolk:forms` collects the label of a form field without a `display`, which Statamic makes from the field's handle (`your_message` becomes "Your Message") and shows in the submission email and in validation messages.
- When the provider rejects the key or the model, `tolk:lang` and `tolk:forms` report the file they were translating as failed, count it in the summary and name the files they did not get to, instead of leaving the file's line empty and reporting Failed 0.
- Requests to OpenAI's models from GPT-5.6 on no longer pay for cache writes: by default OpenAI wrote every whole request to its prompt cache, at 1.25 times the input price, and never read it back. The usage figures also price GPT-6.1 Sol's cache reads at its own rate, 5% of the input price.
- The Overview's usage listed the calls of `tolk:translate` and `tolk:site` under **Runs**; they now count under **Command line**, unless the run is handed to the queue with `--queue`.
- The listing action's **Translate into** no longer offers the site that the selected entries or terms are translated from, which created a run with nothing to translate; a run into only that site is refused with the reason.
- A run's page shows the reviewer's corrections: an item the reviewer corrected says how many, and **View changes** shows each with the text before and after and the reason, as the editor does.
- "1 call" instead of "1 calls" in a run's usage and the Overview's usage of the month, and the count can be pluralised in a host's translation.
- On the page of a stopped run, the error is on a line of its own instead of running into the result ("0 done.The AI provider…").
- On a narrow screen, the tables of the Overview, the runs list and a run's page scroll sideways inside their card, as Statamic's listings do, instead of running off the card and scrolling the whole page.
- The description of the **Translate content** permission no longer says it gives the listing action, which needs **Run bulk translations**; it now names what it gives, as the installation guide does.
- The editor's options review with the reviewer they show when you choose another model. Before, the reviewer marked "(default)" was replaced by the one the settings would pick for that model.
- The editor's options remember both the model and the reviewer when you change both. Before, one of the two choices could be lost.
- The editor says "Reviewed with …" only when the reviewer checked the text. Before, it said so whenever review was on, also when no review ran: nothing needed a model, every review failed, the provider rejected the reviewer, or the translation was cancelled first.
- The editor's options offer **Review** with **Overwrite manual edits** checked on a localization whose fields were all edited by hand, such as one translated before Tolk was installed. Before, Review and the reviewer were only available while the form held machine translations.
- After a failed translation the editor's banner still shows **Options** (and **Review** where it applies), so you can choose another model when the provider rejected one. Before, it offered only **Retry**, which repeated the rejected model.
- A page that a run, Translate into… or the command line creates in a structured or orderable collection is placed at its origin's position among the pages already in the target site's tree, instead of at the end of its level in the order the translations finished. Pages already in the tree keep their order.
- A field saved with exactly the text Tolk produced reads as machine-translated, also when a hand-typed text of the field was saved before it, from a second tab or as an edit that was then undone. It used to stay "edited by hand", so Update skipped it.
- Update keeps the translation of each copy of a text that appears more than once in a field (a repeated paragraph, label or table cell), instead of giving every copy the first copy's translation, which lost a review correction or a translation made in context.
- Translating a field again keeps the ids of the localization's Replicator and Grid rows and Bard sets where the origin's have none (content imported or saved by code), in the editor too, instead of giving every row a new id each time, which rewrote unchanged lines in git.
- A cancelled or aborted run, or a cancelled Translate into…, no longer lists items as "Translating" when they had units left: they go back to waiting, and Resume continues them at once, without repeating what was already translated.
- Cancel with review on no longer drops the translations already on their way: they are still reviewed and filled into the form, instead of the summary saying that no fields were translated.
- A Markdown field emptied in the origin shows empty in the localization after Update or "Translate from origin", instead of keeping its old text on screen until the page is reloaded.
- After Translate, Update or Review the keyboard focus moves to Cancel while the translation runs, instead of resting on a status count whose tooltip covered the page's header.
- The confirmation of "Translate from origin" gives the keyboard focus back to the field's actions menu after Esc or Cancel, and moves it to the banner's progress after Translate, instead of leaving it at the top of the page.
- `tolk:site --dry-run` plans and estimates the content of the collections, taxonomies and global sets it would enable for the site, and lists their fields that are not localizable, as the run does. It used to leave them out, so the dry run of a new site found almost nothing to translate.
- After Ctrl-C or an abort, `tolk:translate` and `tolk:site` count the targets translated but not saved apart from those not translated, and advise resuming the run in the Control Panel within `plan_ttl` (two hours by default) to save them without paying for them again. They no longer advise running the command again when that would translate them anew, or when the cause of an abort would stop it too.
- Ctrl-C at the question of `tolk:translate` and `tolk:site` ("Translate and save them?") deletes the run it prepared, instead of leaving it on the Runs page as not started, where anyone allowed to run bulk translations could start it. The question is now asked with Laravel Prompts.
- `tolk:translate` refuses `--from` when it names the only `--site`, and warns that the navigation of the `--from` site is left out when it is one of several sites, instead of leaving it out without a word.
- The progress bars of `tolk:translate` and `tolk:site` stop where the work stopped after Ctrl-C or an abort, instead of jumping to 100%, and the terminal's progress indicator is reset.
- The dry run of `tolk:translate` says a page whose home page is not translated into the site yet "would be skipped", as the run skips it, instead of "would fail".
- `tolk:status` and the Overview say that `tolk:baseline` records the origin of each field translated by hand, instead of "to record it", which could be read as recording the change.
- `php please tolk:site` no longer stops with a `TypeError` when it hands over to `tolk:translate`, after the questions that change the configuration. `php artisan tolk:site` was not affected.

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
- Events for a translated target, a started run and a finished run; a Laravel Boost guideline for host apps.
