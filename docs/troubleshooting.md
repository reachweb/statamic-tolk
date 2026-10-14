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

The banner says **Translation is not set up yet**, and offers no Translate or Update, when no provider has an API key. Set a key in `.env` ([Installation](installation.md#api-keys)) and check it with **Test connection** on the settings page. It says so too when **Translator model** is Automatic and the model catalog has no quality model for the first provider with a key; `tolk:doctor` names that provider.

When the **Translator model** chosen in the settings uses a provider without a key while another provider has one, the banner looks as usual and Translate stops with "The AI provider [gemini] has no API key. Set GEMINI_API_KEY or TOLK_GEMINI_API_KEY in .env." `tolk:doctor` and the Audit page report it as an error ("The translator model … uses gemini, which has no API key, so every translation fails."). Set that provider's key, or choose a model of a provider with a key, or Automatic, in the settings.

## Requests time out

A request that times out with several pieces of text is split and sent again, so a timeout costs time but loses nothing. When it keeps happening:

- **Lower the batch size**: `batch.max_chars.web` (the editor and CP runs, default 4,000 characters) or `batch.max_chars.background` (the command line, 10,000). Smaller requests finish faster.
- **Raise the timeout**: `http.timeout.web` (45 seconds) or `http.timeout.background` (180). Keep `http.timeout.web` below your web server's and proxy's timeouts (often 60 seconds), and on a queue below the connection's `retry_after` minus 40 seconds.
- **Use a faster model**: the fast models of each provider answer much sooner.

A single piece of text that times out alone (a very long paragraph) is retried and then reported; it cannot be split further.

## The provider asks to wait, or rejects requests

- **Rate limits and overloaded providers**: Tolk pauses every request to that provider for as long as it asks, then continues. The editor shows "The provider asked to wait. Trying again shortly." A run's item fails after ten waits in a row, and three such failures in a row abort the run. Lower `concurrency.*`, set a **Fallback model** of another provider, or raise your account's limits with the provider.
- **Rejected**: an invalid or revoked key, a spending limit reached, a model the provider no longer offers, or, for Gemini, a daily quota used up (it resets at midnight Pacific time; **Resume** the run then) or a project Google serves only with billing enabled (its free tier is not offered in every country). The run is aborted with the provider's message (with any key removed). For a retired model, choose another in the settings, or Automatic, which follows Tolk's catalog; an update of Tolk brings the catalog up to date.

## Runs

### Queue or browser?

Runs started in the CP run on the queue when the queue connection has a worker, and are driven by their open run page otherwise (`runs.execution`, default `auto`). On Statamic's default `sync` queue, the run page drives the run: keep it open until the run ends. If you leave it or close the tab, the run pauses and continues the next time its page is opened. See [Bulk translations and runs](bulk-and-runs.md#drivers).

To force one way, set `runs.execution` to `queue` or `browser`. `tolk:doctor` reports which applies, and an error when a queue run could not work.

### "This run is not moving"

- **A queue run**: no worker has worked on it for a while. Start one (`php artisan queue:work`, or check your process manager), then press **Resume**; or press **Run in this browser instead** to let the page drive it. A run started from an entry's banner, or by saving it, says so in the banner too, with **Translate in this page instead**, which does the same for the user who started it.
- **A command-line run**: the command that ran it stopped without cancelling it, such as a closed terminal, a restarted server or Ctrl-C where PHP has no pcntl extension (Windows, some Docker images) or disables `pcntl_signal`, which stop its workers with it. Press **Resume** on its run page to continue it in the CP (on the queue when it has a worker, otherwise driven by the page), or start the command again. Ctrl-C (with pcntl) cancels the run instead, and so does a crash of the command in most cases ([How the command runs](cli.md#how-the-command-runs)), so its page offers **Resume** at once. Without PHP's posix extension (Windows), the workers of a command that was killed (`kill -9`, the out-of-memory killer) go on translating, so the run reads as not moving only `runs.claim_ttl` after they have translated everything; press **Cancel** on its run page to stop them sooner, then **Resume**. `tolk:doctor` says whether PHP has pcntl and posix.
- **An automatic update**: the PHP process that drove it stopped before the run ended, usually because the web server stops requests after a fixed time, or because the queue worker that ran the update of a save inside a queue job was killed. Press **Resume** on its run page, or save the entry again. The entry's banner tells the user who saved that the run may have stopped: **Cancel** it there, then update the translations again. A queue worker for Tolk's runs avoids it ([Automatic updates](bulk-and-runs.md#automatic-updates)).
- `tolk:prune` stops runs that made no progress for `runs.claim_ttl` seconds (5 minutes): it marks them aborted and commits what they saved, so **Resume** continues them. Schedule it daily ([`tolk:prune`](cli.md#tolkprune)).

### Saving an entry does not update its translations

Automatic updates need **Update translations automatically** on (the settings' Automation tab), and only these saves count: of an entry in a collection with more than one site, not excluded, and among **Collections** when that list is not empty; in the entry's origin, not in a localization without localizations of its own; while the entry is published (with revisions, when it is published). A save that leaves nothing to update starts nothing (one that changes no translated field while the localizations are up to date), and the user who saved must be allowed to edit the localizations. A localization saved by a bulk run that updates outdated translations leaves to that run the sites it is still to translate from it. With a queue worker the update starts about 10 seconds after the save. Without one, a save inside a queue job (an import) is updated about 10 seconds later by a job of its own on that job's queue, and a save in a web request once its response has been sent; a save made within a minute after the web server stopped a process waiting for an earlier update of the entry updates nothing ([Automatic updates](bulk-and-runs.md#automatic-updates)). Each update is listed on the Runs page. See [Automatic updates](editor.md#automatic-updates).

### Saving an entry takes as long as its automatic update

Without a queue worker for Tolk's runs, the PHP process that saved the entry runs its automatic update after it has sent the response. Only a web server that can send a response before PHP has finished lets the editor go on at once: PHP-FPM, LiteSpeed and FrankenPHP. Under Apache's mod_php, php-cgi (FastCGI without FPM, as on IIS) or `php artisan serve`, the browser waits until the update's run has ended: Save keeps spinning, a proxy that gives up first (Cloudflare after 100 seconds) reports an error although the entry was saved, and `php artisan serve`, with its single worker by default, answers no other request meanwhile. The Audit page warns about it; `tolk:doctor` runs on the command line and cannot tell. Run a queue worker for Tolk's runs (`runs.execution` `auto` or `queue`), serve the site with PHP-FPM, or switch automatic updates off and use **Update translations** ([Automatic updates](bulk-and-runs.md#automatic-updates)).

### Items fail with "No worker finished this item in 3 attempts."

The process translating or saving the item died three times (a PHP time or memory limit, a killed worker), also when the same queue lane or run page took the item back each time. Check the PHP error log, raise `memory_limit` or `max_execution_time` for the queue worker or the web server, or lower the batch sizes; then **Retry failed**.

### "Another process is saving content"

Content is saved by one process at a time, under a lock ([Why one writer](bulk-and-runs.md#why-one-writer)). Runs wait for it. `tolk:lang` and `tolk:forms` give up on a file after `write.wait_seconds` (60) with "Another process is saving content; the write lock was not free within 60 seconds." and report it as failed; run the command again. When it happens often, something holds the lock for long: a very large item, or a slow disk. Raise `write.wait_seconds`.

## Statuses look wrong

- **Translations read outdated, but nobody changed the origin.** The origin was probably saved in the CP for the first time after being written another way (imported, seeded), which adds ids to its Replicator, Grid and Bard values. **Update** fixes it without calling a model. See [Statuses](statuses.md#content-written-outside-the-cp).
- **A field reads "edited by hand", but nobody edited it.** Its text differs from what Tolk produced: someone or something changed a word, or Statamic's form rewrote it. Translate it again with **Translate from origin** or **Overwrite manual edits**.
- **A field is still in the origin's language, but reads "edited by hand" and its item up to date.** A form saved the origin's text over Tolk's translation, such as a form left open while a run or an automatic update translated the field, and saved afterwards. Tolk keeps it as text someone may have put back on purpose ([A copy saved over a translation](statuses.md#a-copy-saved-over-a-translation)), so Update leaves it alone. Translate it with **Translate from origin** and save, or with **Overwrite manual edits**.
- **A field reads untranslated although it was saved.** It holds a copy of the origin's text that nobody translated; Statamic's form can save one by itself for a Bard field. Translate replaces it, and **Protect this entry from updates** keeps it if it is meant to stay.
- **A site translated by hand before Tolk** reads "edited by hand" and untracked: run `tolk:baseline` so origin changes are noticed.
- **A page removed from a translated navigation came back after Update**, or pages moved by hand returned to the source's order. Only text marks a tree edited by hand, so Update rebuilt it from the source's tree. Protect the tree with `tolk:baseline --nav=main --site=el --protect` after changing it ([Navigation](content-types.md#navigation)).
- **Translations a run saved read untranslated or outdated again.** A form of the localization that was open before the run was saved afterwards, and put back the text it was opened with. **Update** translates them again; reload a localization a run may have saved before you edit it ([Forms opened before a run](editor.md#forms-opened-before-a-run)).
- **After a deploy, every translation reads "edited by hand" and Update sends nothing.** The state files are gone: with content in a database, a deploy that replaces the site's folder takes `content/tolk/` with it. Restore the folder from a backup and set `state.path` to a folder every deploy keeps ([Configuration](configuration.md#translation-state)). Without a backup, run `tolk:baseline`, which keeps the current translations as edited by hand but notices origin changes again, or translate them again with **Overwrite manual edits** (`--force`).

## "The Tolk settings in resources/addons/statamic-tolk.yaml could not be read"

Everything that reads the Tolk settings (the banner, the Overview, runs, the commands) fails with this error, which the commands print and the CP writes to the log, when Statamic cannot read the settings file: after it was edited by hand or by a merge. The rest of the message says why: YAML that does not parse, or a text with `{{` that Statamic cannot read as Antlers, because it reads every addon setting as Antlers. Fix the file: put such a text between `{{ noparse }}` and `{{ /noparse }}` ([What the settings page refuses](configuration.md#what-the-settings-page-refuses)). The settings page cannot open the file either until it is fixed.

## A state file cannot be read

Tolk keeps what it knows about each item's translations in a YAML file under `content/tolk/` (see [How Tolk decides](statuses.md#how-tolk-decides)). When git merges two branches that both translated the same item, the file can be left with conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`) or with the same field twice, and Tolk can no longer read it. It never writes over such a file, which would lose what both branches recorded, so until the file is fixed:

- the item's banner says "The translation status could not be loaded" and names the file, and so does a translation started there; the banner of its origin shows that site as *Unreadable*;
- the Overview counts the item as *with unreadable state*, and `tolk:status` apart from the statuses (`unreadable` in its JSON);
- a run or an automatic update fails that item with the same message and translates the others, and `tolk:baseline` skips it;
- `tolk:doctor` and `tolk:prune` list every state file that cannot be read. The Audit page does not, because reading every state file takes a while on a large site.

To fix a file, either:

- **Resolve the conflict** as in any other file: keep the record of each field from the side whose text the item's content kept (usually the side you chose in the content file), keep the fields only one side has, and remove the markers. A record that does not match the text makes the field read outdated or edited by hand; it never changes content.
- **Or delete the file.** The item's translations then read "edited by hand" and untracked. Run `tolk:baseline` for its collection and site to record them, or translate them again with **Overwrite manual edits**.

Deleting the item deletes its state file as well, readable or not.

## The interface is in English

The CP texts are translatable: add them to `lang/{locale}.json` ([Installation](installation.md#the-interface-language)). Command output stays in English.
