# Troubleshooting

Start with `php please tolk:doctor`, or the **Audit** page under **Tools › Translations**: most problems show up there.

## Nothing is translated

A field Tolk does not translate is left as it is, so "nothing was translated" usually means the fields were out of scope. The banner, the run page and the command's report list the text fields left out and why.

- **The fields are not localizable.** This is the most common cause: Statamic's default blueprints do not mark `title` and other fields localizable, and a field that is not localizable is shared by every site. Make them localizable on the Audit page (**Tools › Translations › Audit**, tick the fields, **Make localizable**) or with `php please tolk:doctor --fix-localizable`.
- **The collection or taxonomy is not enabled for the site.** Add the site in the collection's settings, or let `tolk:site` do it.
- **The fieldtype has no handler.** Only text-bearing core fieldtypes are translated (see [Content types](content-types.md#fieldtypes)); a third-party fieldtype needs a handler ([Extending](extending.md)).
- **The field is switched off or excluded**: its **Translate automatically** option is off, or its handle is in **Excluded fields**, or its collection or taxonomy is excluded in the settings.
- **The titles are generated** by the collection, so Tolk leaves them to Statamic.
- **Everything is up to date.** The default mode translates only what is missing. Use **Update** (mode `update`) for outdated fields, **Retranslate everything** (mode `all`) for everything, and **Overwrite manual edits** (`--force`) for fields edited by hand.
- **A page is skipped with "Translate Home into Greek first."** In a collection whose tree has a root page (a home page), the first page saved into a site's empty tree becomes that site's home page, so the root page has to be translated first for the tree to keep its structure. Open the root page (the result and the run's page link to it), translate it into that site, then translate the page again; or include the root page in the run.

## The banner does not appear

- The collection or taxonomy has only one site, or multi-site is off.
- Your role lacks `translate content`, or you may not edit the entry or access the site.
- The form is read-only, or the entry is open in a stack, or it is the create page of a new entry.
- **The CP assets are not published.** Statamic publishes them after every Composer command. If a deployment skipped that, run:

  ```bash
  php artisan vendor:publish --tag=statamic-tolk --force
  ```

  and reload the CP. The browser's developer tools show a 404 for `vendor/statamic-tolk/build/…` when this is the cause.

The banner says **Translation is not set up yet** when no provider has an API key, or the translator model's provider has none. Set a key in `.env` ([Installation](installation.md#api-keys)) and check it with **Test connection** on the settings page.

## Requests time out

A request that times out with several pieces of text is split and sent again, so a timeout costs time but loses nothing. When it keeps happening:

- **Lower the batch size**: `batch.max_chars.web` (the editor and CP runs, default 4,000 characters) or `batch.max_chars.background` (the command line, 10,000). Smaller requests finish faster.
- **Raise the timeout**: `http.timeout.web` (45 seconds) or `http.timeout.background` (180). Keep `http.timeout.web` below your web server's and proxy's timeouts (often 60 seconds), and on a queue below the connection's `retry_after` minus 40 seconds.
- **Use a faster model**: the fast models of each provider answer much sooner.

A single piece of text that times out alone (a very long paragraph) is retried and then reported; it cannot be split further.

## The provider asks to wait, or rejects requests

- **Rate limits and overloaded providers**: Tolk pauses every request to that provider for as long as it asks, then continues. The editor shows "The provider asked to wait. Trying again shortly." A run's item fails after ten waits in a row, and three such failures in a row abort the run. Lower `concurrency.*`, set a **Fallback model** of another provider, or raise your account's limits with the provider.
- **Rejected**: an invalid or revoked key, a spending limit reached, a model the provider no longer offers, or, for Gemini, a project Google serves only with billing enabled (its free tier is not offered in every country). The run is aborted with the provider's message (with any key removed). For a retired model, choose another in the settings, or Automatic, which follows Tolk's catalog; an update of Tolk brings the catalog up to date.

## Runs

### Queue or browser?

Runs started in the CP run on the queue when the queue connection has a worker, and are driven by their open run page otherwise (`runs.execution`, default `auto`). On Statamic's default `sync` queue, the run page drives the run: keep it open until the run ends. If it closes, the run pauses and continues the next time its page is opened. See [Bulk translations and runs](bulk-and-runs.md#drivers).

To force one way, set `runs.execution` to `queue` or `browser`. `tolk:doctor` reports which applies, and an error when a queue run could not work.

### "This run is not moving"

- **A queue run**: no worker has worked on it for a while. Start one (`php artisan queue:work`, or check your process manager), then press **Resume**; or press **Run in this browser instead** to let the page drive it.
- **A command-line run**: the command that ran it stopped (closed terminal, Ctrl-C, a crash). Press **Resume** on its run page to continue it in the CP (on the queue when it has a worker, otherwise driven by the page), or start the command again.
- **An automatic update**: the PHP process that saved the entry stopped before the run ended, usually because the web server stops requests after a fixed time. Press **Resume** on its run page, or save the entry again. A queue worker avoids it ([Automatic updates](bulk-and-runs.md#automatic-updates)).
- `tolk:prune` stops runs that made no progress for `runs.claim_ttl` seconds (5 minutes): it marks them aborted and commits what they saved, so **Resume** continues them. Schedule it daily ([`tolk:prune`](cli.md#tolkprune)).

### Saving an entry does not update its translations

Automatic updates need **Update translations automatically** on (the settings' Automation tab), and only these saves count: of an entry in a collection with more than one site, not excluded, and among **Collections** when that list is not empty; in the entry's origin, not in a localization without localizations of its own; while the entry is published (with revisions, when it is published). A save that changes no text starts nothing, and the user who saved must be allowed to edit the localizations. With a queue worker the update starts about 10 seconds after the save. Each update is listed on the Runs page. See [Automatic updates](editor.md#automatic-updates).

### Items fail with "No worker finished this item in 3 attempts."

The process translating the item died three times (a PHP time or memory limit, a killed worker). Check the PHP error log, raise `memory_limit` or `max_execution_time` for the queue worker, or lower the batch sizes; then **Retry failed**.

### "Another process is saving content"

Content is saved by one process at a time, under a lock ([Why one writer](bulk-and-runs.md#why-one-writer)). Runs wait for it. `tolk:lang` and `tolk:forms` give up on a file after `write.wait_seconds` (60) with "Another process is saving content; the write lock was not free within 60 seconds." and report it as failed; run the command again. When it happens often, something holds the lock for long: a very large item, or a slow disk. Raise `write.wait_seconds`.

## Statuses look wrong

- **Translations read outdated, but nobody changed the origin.** The origin was probably saved in the CP for the first time after being written another way (imported, seeded), which adds ids to its Replicator, Grid and Bard values. **Update** fixes it without calling a model. See [Statuses](statuses.md#content-written-outside-the-cp).
- **A field reads "edited by hand", but nobody edited it.** Its text differs from what Tolk produced: someone or something changed a word, or Statamic's form rewrote it. Translate it again with **Translate from origin** or **Overwrite manual edits**.
- **A field reads untranslated although it was saved.** It holds a copy of the origin's text that nobody translated; Statamic's form can save one by itself for a Bard field. Translate replaces it, and **Protect this entry from updates** keeps it if it is meant to stay.
- **A site translated by hand before Tolk** reads "edited by hand" and untracked: run `tolk:baseline` so origin changes are noticed.
- **A page removed from a translated navigation came back after Update**, or pages moved by hand returned to the source's order. Only text marks a tree edited by hand, so Update rebuilt it from the source's tree. Protect the tree with `tolk:baseline --nav=main --site=el --protect` after changing it ([Navigation](content-types.md#navigation)).

## The interface is in English

The CP texts are translatable: add them to `lang/{locale}.json` ([Installation](installation.md#the-interface-language)). Command output stays in English.
