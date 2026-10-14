# The editor

Tolk works inside Statamic's entry and term forms. In a localization it fills translations into the open form, where you check them and save as usual: nothing is stored until you press Save. In the origin it translates the saved entry (with revisions, the published one) into other sites and saves those translations directly.

## The banner

The banner sits above the form's tabs. It appears when:

- the collection (or taxonomy) has more than one site, the entry's (or term's) among them, and Statamic's multi-site is on;
- the collection (or taxonomy) is not excluded in the settings ([Configuration](configuration.md#content));
- you have the `translate content` permission and may edit the entry;
- the form is not read-only.

It does not appear on the create page of a new entry, in stacked editors (an entry opened from a relationship field), in global sets or in the navigation editor. Global sets and navigation are translated from the [Overview](bulk-and-runs.md#the-overview) and the [command line](cli.md).

![The banner on a Greek localization whose origin changed: one field outdated, one edited by hand, four up to date](images/banner.png)

### In a localization

The banner shows how many fields are untranslated, outdated, edited by hand, need attention or are up to date ([Statuses](statuses.md) explains each), and the fields that are not translated because they are not localizable, with a link to the Audit page for users who may open it. Hover or focus a count to see what it means.

- **Translate** fills the untranslated fields.
- **Update** fills the untranslated fields and translates the outdated ones again. Only the paragraphs whose source changed are sent to the model; the others keep their earlier translation.
- **Review** asks a second model to check the translations in the form (see [Review](review.md)).
- **Translate it** (or **Translate them**) shows under a note that names the fields Tolk translated that hold the origin's text again, such as from a form saved after a run translated them. It translates only those fields, although they count as edited by hand ([A copy saved over a translation](statuses.md#a-copy-saved-over-a-translation)).
- **Options** opens the translation options (below).

While a translation runs, the fields fill in as their parts come back, a progress bar shows how far it is, and **Cancel** stops it. Fields already on their way are still filled in. Leaving the page stops it too, without filling in anything more. Save is refused while a translation runs ("A translation is in progress. Wait for it to finish, or cancel it."), because the form would be saved half translated. The other way round, nothing starts while the form is being saved: Translate, Update, Review, Retry, Undo, the offer and **Translate from origin** wait for the save to finish ("The form is being saved. Try again when the save has finished."), because Statamic puts the saved values back into the form when the save returns. In a collection with revisions, **Publish** discards a translation that is not saved yet (see [Collections with revisions](#collections-with-revisions)).

Statamic's autosave (an experimental option of a collection) is refused the same way while a translation runs. The message appears once per translation, so it does not come back at every autosave, and a Save you press later in the same translation is refused without it. Once the translation has finished, the next autosave saves the filled fields, and Undo no longer applies. Until then, once an autosave was refused, Statamic takes a Save you press for an autosave: it shows no "Saved" and keeps the form open, whatever your "after saving" preference.

When it has finished, the banner says what happened: how many fields were translated and with which model, and which fields were skipped or failed. **Undo** puts back the fields as they were before the translation, as long as you have not saved; text you typed into a field after it was filled is kept, and so is a link or bold you added there. **Retry** repeats the translation for the fields it has not filled yet. It is offered after an error, and after a translation that finished with fields it could not translate, for example because the provider stayed unavailable; the banner then adds "Retry translates them again." to the line that names them. When the translation cannot continue where it stopped, such as after a change to the origin, the button reads **Start again**. To use another model, for example when the provider rejected the model or stays unavailable, choose it in **Options**: Retry repeats the same one.

The filled fields are not saved yet. Read them, change what you like, then press Save. After the save, Tolk records what it translated:

- A field you did not change after it was filled counts as machine-translated, so a later change to the origin marks it outdated and Update replaces it.
- A field whose text you changed before saving counts as edited by hand: Translate and Update leave it alone from then on (see [Statuses](statuses.md#manual-fields)).
- A field you set back to follow the origin with Statamic's sync button keeps following the origin and stays untranslated.

A field you typed into before pressing Translate, or in which you linked a word or made it bold, is skipped, and the banner names it ("Intro was skipped because you edited it."), so your text is never overwritten. A field that a condition hides is skipped too ("Button label was skipped because it is hidden. Show it and translate again."), because Statamic would save it empty. A field that a condition hid after it was filled is left out of the save and keeps following the origin; the banner lists it so you can show it and translate it again. A field that already had text of its own is different: when a condition hides it as you save, Statamic saves it empty, whether Tolk filled it or not, so show it before you save.

### Forms opened before a run

A form holds the localization as it was when the page loaded. The translations Tolk saves server-side (the origin's **Translate into…** and **Update translations**, automatic updates, bulk runs and the command line) go straight into the localization. They do not change a form that is open on it, in your browser or anyone else's, and its banner keeps the statuses it loaded with. Saving that form afterwards stores what the form holds, as Statamic does with any two saves of one entry: the last save wins. The run's translations are lost: a field the localization inherited before reads untranslated again, and a field whose earlier translation comes back reads outdated; the banner shows both after the save. The next Update pays for them again, and sends a field whose earlier translation came back in full, because Tolk reuses paragraphs only from a field's latest translation.

So when a run may have saved a localization since you opened it, reload it before you edit it. Leaving a form you did not change saves nothing. If you have already saved it, **Update** translates the fields the save put back, and leaves the ones you edited alone.

### Slugs

A new localization keeps its origin's slug: Statamic's form would normally derive the slug from the translated title, so Tolk puts the origin's slug back when it fills a localization you just created. In a collection of the **Translated slugs** setting, Tolk leaves the slug to Statamic instead, which makes it from the translated title when you save. A slug that already exists is never changed. Change a slug by hand if you want a different one.

### The offer

When you create a localization in the form (by picking a site that has none in the **Sites** panel), Tolk offers to translate it:

![The offer after creating a German localization](images/offer.png)

It appears when:

- the **Offer to translate new localizations** setting (`offer_on_create`) is on;
- you have not turned the offer off for yourself;
- a model is available (a provider has an API key);
- the new localization has untranslated fields.

**Translate** fills the fields, as the banner's Translate does; **Not now** (or Esc) leaves the form as Statamic created it. When the collection has more than two sites and lets you choose where a localization starts from, Statamic first asks which site to copy from; the offer comes after that.

**Don't offer again** turns the offer off for your user only. Turn it back on with **Offer to translate new localizations** in the options menu. The offer keeps the origin's slug, unless the collection has translated slugs (see above).

Terms have no offer, because a term exists in every site of its taxonomy and the form never creates one. Use the banner's Translate there.

## The options menu

**Options** in the banner opens the translation options:

- **Model**: the model to translate with. The menu remembers your choice for your user and preselects it the next time; the banner's Translate and Update and the offer always use the model of the settings. Choosing the default model forgets your choice. Users who may edit Tolk's settings may also type a model outside the list, as `provider:model`; other text only searches the list and leaves the model as it was.
- **Retranslate everything**: translates every field again, the up-to-date ones too. Without it, the menu's Translate fills untranslated fields and updates outdated ones, as Update does.
- **Overwrite manual edits**: also replaces the fields edited by hand. They count as machine-translated afterwards.
- **Review after translating** and **Reviewer**: review this translation with a second model, whatever the setting says, and with which model (see [Review](review.md)). The reviewer can be chosen while **Review after translating** is checked, or while the form holds translations that **Review** can check: machine translations, and with **Overwrite manual edits** checked also fields edited by hand. The reviewer shown is the one that reviews, also when you choose another **Model**: the one marked "(default)" is the reviewer the settings give their own model.
- **Translate** and **Review** run the translation or the review with these choices.

Every time the menu opens, **Retranslate everything** and **Overwrite manual edits** start unchecked and **Review after translating** starts as the setting says.

Under **Translation status** are two actions that take effect at once, without **Translate**, each shown only while it has something to do:

- **Mark manual fields as up to date** records that the fields edited by hand match the current source. They stay edited by hand, and a later change to the source shows them as needing attention. Use it after you checked hand-written fields against their source, and on content translated by hand before Tolk was installed.
- **Protect this entry from updates** turns the entry's translated fields into fields edited by hand, so Translate and Update leave them alone from then on. An outdated field is recorded against the origin it was translated from, so it needs attention until you check it and mark it as up to date. A field left partly untranslated is not protected: it stays outdated, so Update can still translate the rest. Protect also marks the fields that were already edited by hand as up to date. It is shown while the entry has a machine-translated field, up to date or outdated, that is not partly untranslated.

The **Offer to translate new localizations** switch turns the offer on or off for your user, as soon as you flip it. It shows only while the setting is on.

## Translating one field

Every top-level field Tolk can translate has **Translate from origin** in its field actions (the menu next to its label), in a localization:

1. Open the field's actions and choose **Translate from origin**.
2. Confirm: "The current content of this field will be replaced."

The field is translated again from the origin, whatever its status, edited by hand included, and filled into the form unsaved. The rest of the form is left alone.

The action is not offered on nested fields (inside a Replicator, Grid or Bard set), on fields that are not localizable, in the origin, or while another entry is open in a stack.

## In the origin

On the origin entry, the banner lists each localization with its status, for the sites you may access. A missing localization reads "Not created yet". The names link to the localizations. When every localization exists and is up to date, there is nothing to do there, so the banner is hidden; it comes back as soon as a localization needs work, for example after you change the origin and save, or, in a collection with revisions, publish.

- **Translate into…** opens a dialog with the sites that have no localization or have untranslated fields, all checked. **New localizations** chooses whether new ones are saved as **Drafts** or the **Same as origin** (published when the origin is; it needs the publish permission). **Review after translating** reviews each translation before it is saved.
- **Update translations** updates every localization with outdated fields.

Unlike the rest of the editor, these save the translations directly, in each localization, as a run of this one entry. They translate the **saved** entry, so both buttons wait while the form has unsaved text ("Save first: the translations are made from the saved entry."). The open form is not changed, and neither is a localization's form that is open elsewhere: saved afterwards, it puts back what it was opened with ([Forms opened before a run](#forms-opened-before-a-run)).

In a collection with revisions, Save puts the changes of a published entry in its working copy, and these buttons translate the **published** entry. The note then reads "Publish first: the translations are made from the published entry.", and it stays after you save, while the entry has unpublished changes. When you publish, the banner reads the statuses again: the note goes, and the localizations your changes made outdated say so. The buttons work again after the save, but they translate the entry as it was last published: publish first, then translate, or the paragraphs you changed are translated again (and paid for again) once you publish.

While the run goes, the banner shows its progress and **Cancel**. When Tolk's runs are driven by the browser (a site without a queue worker, see [Bulk translations and runs](bulk-and-runs.md#drivers)), the open page does the work: keep it open until the translations are saved. If you leave, the run continues the next time you open the entry, unless a scheduled `tolk:prune` stopped it after five minutes without progress; then start it again. With a queue worker, the run continues on its own. When no queue worker has taken the run up after half a minute, or none has worked on it for five minutes, the banner says so and offers **Translate in this page instead**: the open page then drives the run, as without a queue worker.

When it has finished, the banner shows a line per site with a link to the localization and its result. New localizations appear in Statamic's **Sites** panel after a reload, which **Reload** does.

You need only `translate content` for this: you follow and cancel the run you started from the entry, move it to the page when no queue worker takes it up, and it continues when you open the entry again, without seeing the Runs pages. A cancelled or aborted run is resumed on the Runs pages, which need `run bulk translations`; otherwise start the translation again from the entry.

### Automatic updates

With **Update translations automatically** on in the settings (the Automation tab, see [Configuration](configuration.md#automation)), you do not need the button: each time you save a published entry in its origin, Tolk starts the same run as **Update translations**, into every localization of the entry. It translates the fields whose origin changed and the fields not translated yet, and keeps the fields edited by hand. With **Create missing localizations** on, it also translates the entry into the sites of its collection that have none, as **Translate into…** does.

- The origin's banner says when it happens: "Translations are updated automatically each time this entry is saved", or "…when this entry is published" for a draft, and in a collection with revisions, where Save goes to the working copy and only Publish saves the entry.
- After a save, or after **Publish** in a collection with revisions, the banner says "Updating the translations automatically into Greek…" and then shows the run's progress and its result, as for **Update translations**. When the update is done before the banner sees its run, it says "The translations into Greek were updated automatically." With a queue worker the run starts about 10 seconds after the save; when it has not started within half a minute, the banner stops waiting for it, and shows it the next time you open the entry. You can leave the page: the run does not need it. Without a queue worker the run starts once the save's response has been sent; on a web server that cannot send it before PHP has finished (Apache's mod_php, php-cgi, `php artisan serve`), Save waits until the run has ended ([Automatic updates](bulk-and-runs.md#automatic-updates)).
- A save that leaves nothing to update starts nothing: one that changes no translated field while the localizations are up to date. A change without text in a translated field is a change too: a toggle, an image or a link changed inside a Replicator, Grid or Bard field makes the field outdated, and the run rebuilds it in each localization from the origin's structure, reusing the earlier translations without calling a model. That replaces such a change made only in a localization ([Only text makes a field manual](statuses.md#only-text-makes-a-field-manual)). A save while an earlier run of the entry is still going waits for it, so the same fields are not paid for twice. When a bulk run saves a localization that others are translated from, their update waits until that run is done with the sites it is still to translate from it ([Automatic updates](bulk-and-runs.md#automatic-updates)).
- A localization's form that is open while the update saves into it does not show the update, and saving that form puts back what it was opened with ([Forms opened before a run](#forms-opened-before-a-run)).
- The run translates as you: the sites you may not access or edit are left out, also further along a chain of localizations translated from one another. It is listed on the Runs page as an automatic update, and its cost counts under **Automatic updates** on the Overview.
- The localization's banner says when an automatic update last saved it, "Updated automatically from English on 9 Oct 2026, 10:42.", with "not published yet" while the update waits in the working copy of a published localization in a collection with revisions (unless **Publish updates** is on). Once someone publishes the localization, the note is gone, also while a later change waits in its working copy.
- A new published entry of a collection with **Propagate** on, whose localizations Statamic creates as it saves the entry, is translated into them, also without **Create missing localizations**.
- Saves from code (an import, a form handler) count too, when the entry is published. A save inside a queue job is updated by a job on that job's queue ([Automatic updates](bulk-and-runs.md#automatic-updates)).
- Entries only: terms, global sets and the navigation are updated with **Update translations**, bulk runs or the command line.

How the run is driven, with and without a queue worker, is in [Bulk translations and runs](bulk-and-runs.md#automatic-updates).

## Collections with revisions

In the editor, Tolk only fills the form, and Statamic saves it as it always does: on a collection with revisions, your Save goes to the working copy, and publishing stays a separate step. What happens when Tolk saves server-side (Translate into…, bulk runs, the command line) is in [Bulk translations and runs](bulk-and-runs.md#revisions).

- Translations are made from the origin as it is published, in a localization's banner as in the origin's. Changes waiting in the origin's working copy are not translated, and its localizations do not read outdated, until someone publishes them.
- A localization's banner reads the localization as its working copy holds it, as the form does: a translation you saved but did not publish counts as up to date. Visitors see it only once it is published.

Save a translation before you publish it. Statamic offers **Publish** only while the form has no unsaved changes, but its dialog stays open while a translation that started before fills the form, and once it has published, Statamic puts the published values back into the form. **Publish** therefore stops a translation that still runs and takes back what it filled in, so that no field that inherited from the origin is left with the origin's text as its own for the next save, and says "Publishing discarded the unsaved translation. Translate again to fill in the form."
