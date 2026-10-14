# Privacy and data

Tolk sends the content it translates to the AI provider of the model you choose. This page lists what is sent, where it goes, and what the providers say they keep. Read it before translating content with personal or confidential information in it.

## Where the data goes

Requests go directly from your server to the provider's API: Anthropic (`api.anthropic.com`), OpenAI (`api.openai.com`) or Google (`generativelanguage.googleapis.com`), or the address set in `providers.*.url`. Nothing passes through Reach or any other third party, and Tolk sends no data anywhere else.

Each request goes to the provider of the model that makes it: the translator, the reviewer when review is on, and the fallback when the translator keeps failing. With a translator and a reviewer of different providers, both providers receive the content.

## What is sent

**To translate**, for each request:

- the text of the fields being translated, in pieces, with each field's label and **Translation hint**;
- the document: its title, its collection or taxonomy name, its URL on the site, and a summary of up to 600 characters of the source;
- neighbouring paragraphs, up to 300 characters each, when only part of a field is sent;
- the settings' context: the site profile, the instructions, the language's instructions and formality, and the glossary rows for the language;
- the source and target language names.

**To review**: the same, with each piece of source text and its translation.

**To generate a site profile** (**Generate** on the settings page, `tolk:profile`): up to 12,000 characters of the default site's content, the site's name and address, the home page, a few entries of each collection, the global sets and the navigation's titles, including fields that are not localizable. Collections and taxonomies excluded in the settings, and excluded fields, are left out.

**To test a connection**: a short fixed prompt with no content.

**Not sent**: API keys (only in the request's authentication header, to the provider they belong to), the names or email addresses of CP users, assets and images, form submissions, link targets in Bard and Markdown, fields that are not translated (not localizable, switched off, excluded, or of a fieldtype without a handler), and values that are not text.

## What the providers keep

As the providers documented it on 2026-10-01. Their terms change: check the linked pages, and your agreement with them, before you rely on this.

### Anthropic

- Inputs and outputs of the API are not used to train models, unless you give feedback or opt in ([is my data used for training](https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training)).
- They are deleted within 30 days, except under a zero-data-retention agreement, for usage-policy enforcement (content flagged for a violation is kept up to 2 years), or as the law requires ([how long data is stored](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data), [API data retention](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention)).
- Zero data retention is available by arrangement with Anthropic.
- Tolk does not use the Batch API, which keeps requests longer.

### OpenAI

- Data sent to the API is not used to train models, unless you opt in ([your data](https://developers.openai.com/api/docs/guides/your-data)).
- Abuse-monitoring logs are kept up to 30 days, unless the law requires longer.
- The Responses API stores responses for 30 days unless the request says otherwise. **Tolk sends every request with `store: false`**, so no response is stored as application state.
- Zero data retention and modified abuse monitoring need OpenAI's approval.

### Google Gemini

- On **paid** usage (a Google Cloud project with billing), prompts and responses are not used to improve Google's products. Prompts, context and output are kept 55 days for abuse monitoring. The Gemini Developer API offers no zero data retention; Google points to Vertex AI for that, which Tolk does not support ([Gemini API terms](https://ai.google.dev/gemini-api/terms), [usage policies](https://ai.google.dev/gemini-api/docs/usage-policies), [zero data retention](https://ai.google.dev/gemini-api/docs/zdr)).
- On the **free tier**, Google uses the content to improve its products, and human reviewers may read it; Google asks not to send sensitive, confidential or personal information. In the EEA, Switzerland and the UK, only paid services may be used when making an API client available to users. **Use a key of a project with billing enabled for Tolk**, and do not translate real content with a free-tier key.

## What Tolk keeps on your server

| What | Where | Contains |
| --- | --- | --- |
| Translation state | `content/tolk/` | Per field: fingerprints of the source and of the translation, the model names and the time. No text. |
| Plans | the cache (`cache_store`), until the translation is saved, else for `plan_ttl` seconds (2 hours); a `file` or `database` cache keeps them longer, see below | The text being translated and the model's answers. |
| Runs | `storage/statamic/tolk/runs/`, until `tolk:prune` deletes them (30 days) | The name (or email address) of the user who created it; items' titles, statuses, the fields saved, warnings (which can quote a few words of text) and errors; with review on, each correction with the text before and after it. |
| Usage | `storage/statamic/tolk/usage/` | Tokens, calls and cost per model and month. No text. |
| Logs | the app's log | Requests a provider rejected as malformed are logged by their shape, without content. Provider error messages are cleaned of API keys before they are stored or shown. |

Tolk deletes a plan once its translation is saved: a run or a command when it saves the item, the editor when you save the form with the translation. Any other plan is kept until `plan_ttl` runs out, such as a translation the editor cancelled or never saved, or a run's item that failed while it was translated or was translated but not saved (a cancelled or aborted run keeps those for **Resume**).

An expired plan is not always gone. A `file` or `database` cache store deletes an expired entry only when something reads it again, which never happens to a finished plan, so its text stays in the cache's files (`storage/framework/cache/data`) or table until the cache is cleared. Redis and Memcached drop expired entries on their own. To remove that text, for example after a request to erase someone's personal data, run `php artisan cache:clear` while no translation is running. With `cache_store` set to a store of its own, `php artisan cache:clear <store>` clears only Tolk's entries.

## Content as input

Text in your content is sent to the model as data, never as instructions: the prompt says so, and Tolk checks every answer. Antlers tags, their parameters included, reach the model as placeholders. A translation that adds markup, a script or an Antlers tag the source did not have, or changes or drops a tag the source has (a link's `href`, a closing `</a>`, the `}}` that ends an Antlers tag), is rejected, and so is, in a Markdown field, a new link, web or email address or attribute list (`{x-init="…"}`, which Statamic's Markdown parser turns into HTML attributes); the source text is kept instead. So text in an entry that tries to instruct the model ("ignore the above and…") is translated like any other sentence, at worst badly, and cannot inject code into your site.
