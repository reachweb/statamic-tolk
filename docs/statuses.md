# Statuses

Tolk tracks every translated field: whether its origin changed since it was translated, and whether someone edited its text by hand. That is what lets Update send only what changed and never overwrite your edits.

## Field statuses

| Status | Shown as | Meaning | Translate | Update |
| --- | --- | --- | --- | --- |
| Untranslated | *untranslated* | The localization has no translation of the field: it inherits the origin's text, or holds a copy of it that nobody translated. | translates it | translates it |
| Up to date | *up to date* | Tolk translated it, nobody changed its text since, and the origin has not changed. | leaves it | leaves it |
| Outdated | *outdated* | Tolk translated it and nobody changed its text, but the origin changed since, part of it is still untranslated, or it holds an [earlier translation](#an-earlier-translation-saved-again) that a newer one replaced. | leaves it | translates it again |
| Edited by hand | *edited by hand* | Someone changed its text after Tolk translated it, or wrote it by hand. | leaves it | leaves it |
| Not applicable | not counted | The origin's field is empty or has no text to translate. | | |

Two kinds of fields edited by hand are counted on their own:

- **Needs attention**: edited by hand, and the origin changed since. Check the text against the origin, edit it if needed, then mark it as up to date: editing alone does not clear the flag.
- **Untracked**: edited by hand before Tolk knew which origin text it belongs to (a site translated by hand before Tolk was installed). A change to the origin cannot be noticed until it is recorded ([baselines](#baselines)).

**Retranslate everything** (mode `all`) also translates up-to-date fields again, and **Overwrite manual edits** (`--force`) also replaces fields edited by hand. The field action **Translate from origin** does both for one field.

## Item statuses

An entry, a term, a global set or a navigation in one site counts once, by the worst status among its fields:

1. **Missing**: the localization does not exist yet.
2. **Untranslated**: at least one field is untranslated.
3. **Outdated**: at least one field is outdated.
4. **Needs attention**: at least one field edited by hand has a changed origin.
5. **Up to date** (*Current* on the Overview and in `tolk:status`): everything else.

The Overview and `tolk:status` count items this way, and also report the untracked fields. An item whose state file cannot be read, such as after a git merge conflict, has no status until the file is fixed ([Troubleshooting](troubleshooting.md#a-state-file-cannot-be-read)).

## How Tolk decides

Nothing is decided from a stored status. Tolk keeps a small record per translated field in `content/tolk/` (a fingerprint of the origin's value it translated from, a fingerprint of the text it produced, the model and the time), and compares it with the content every time a status is shown:

- The origin's value is compared with the fingerprint it was translated from. Any change to it, text or structure, makes the translation outdated.
- The localization's **text** is compared with the text Tolk produced. Only the text: not the structure around it.

Because the records live next to the content and are compared, not trusted, Tolk notices changes made anywhere: in the CP, in a file, by a git pull, by another addon.

A term's records are filed under its slug in the taxonomy's first site. When that slug changes in Statamic, they move with it; when it changes in the term's file name, they are left behind, and the term's translations read edited by hand and untracked ([Terms](content-types.md#terms)).

### Only text makes a field manual

A field counts as machine-translated as long as its text is exactly what Tolk produced. In a Bard field, where formatting starts and ends and a link's title count as text: linking a word, making it bold or changing a link's title is an edit. Changes that are not text are not tracked:

- swapping an image, toggling an option, changing a link target or turning bold into italic in a translated field does not make it "edited by hand";
- neither do the ids, `enabled` flags and other values Statamic's forms add when they save content.

The other side: **Update rebuilds a field from the origin's structure.** When it translates an outdated field again, it takes the origin's current value and puts the translations into it, so a non-text change made only in the localization (a different image in a Bard field) is replaced by the origin's. The same goes for a navigation's pages: a page added, removed or moved by hand in a translated tree, without a change to any title, leaves the tree up to date, and once it reads outdated, Update puts the origin's pages back in the origin's order ([Navigation](content-types.md#navigation)). A change without text in the origin makes the field outdated too, so with automatic updates on, saving one rebuilds the field in every localization at once, without a model call ([Automatic updates](editor.md#automatic-updates)). If a localization is meant to differ in more than its text, protect it ([below](#protecting-translations)) or mark the field as edited by hand by changing a word.

Defaults Statamic fills in do not count either. When a field with a text default (a "Read more" label) is added to a Replicator or Bard set later, Statamic's next save puts that default, in the source language, into every existing set. Tolk ignores nested values that equal their field's default when it reads a localization, so they do not turn the field "edited by hand".

### A copy of the origin is untranslated

A field whose text is exactly its origin's, with no record of a translation, reads **untranslated**, not "edited by hand": no human changed its text. Statamic saves such copies by itself. A Bard field a localization inherits can rewrite its own value while the form loads (normalising an image, for example), and the next Save stores the origin's Bard content as the localization's own value. Tolk treats that copy as untranslated, and Translate replaces it.

A field that is meant to read the same in both languages (a product name) and was written by hand reads untranslated for the same reason. **Protect this entry from updates** (offered while the entry also has a machine-translated field) or `tolk:baseline --include-copies` keeps such a field: once recorded, it is edited by hand. (**Mark manual fields as up to date** does not: it records only fields that already read as edited by hand.)

### A copy saved over a translation

Once Tolk has translated a field, a copy of the origin's text saved over that translation reads **edited by hand**, not untranslated. Tolk cannot tell it from text someone put back on purpose, and it never replaces text a person may have chosen. Two ways it happens without anyone choosing it:

- a form that still held a copy of the origin's text is saved after Tolk translated the field in the meantime (a run, or an automatic update);
- a Bard field set back to inherit the origin can rewrite itself while the form loads ([above](#a-copy-of-the-origin-is-untranslated)), and the next Save stores the origin's text again.

The field then stays in the origin's language: it does not make its item untranslated or outdated, so the item can read up to date, and Translate and Update leave it alone. Once its origin changes, it needs attention. Translate it with the field action **Translate from origin** ([The editor](editor.md#translating-one-field)) and save, or with **Overwrite manual edits** (`--force`), which also replaces every other field edited by hand in what it translates.

### Empty fields

A field a localization holds empty of its own, while its origin has no text either, could be a field someone emptied on purpose or a copy nobody looked at. Tolk keeps it edited by hand, except in two cases where Statamic stores one by itself:

- **A Bard copy without text.** When the origin's Bard field has no text yet (only an image, say), the copy the form saves ([above](#a-copy-of-the-origin-is-untranslated)) has none either. Tolk records it as a copy when the localization is saved, whoever saves it (with or without the editor's Tolk banner, or code), also after Update removed an earlier translation of the field because the origin's was emptied, and otherwise before it translates the localization while the origin still has no text: it reads up to date, and outdated once the origin changes, so Update translates the text the origin gets. Only text is tracked ([above](#only-text-makes-a-field-manual)), so this holds for any Bard field without text in both sites, also one whose image was changed by hand: protect it ([below](#protecting-translations)) if it should keep its own content.
- **A new term's empty fields.** Statamic's create form, with a site selected other than the taxonomy's first site, stores the new term in both sites, with an empty value for each field left empty. In the other site, that value would stop the field from inheriting. Tolk drops it when the term is created, so the field inherits, and Translate fills it in once the first site has text.

Any other empty value of a localization's own reads **edited by hand** once the origin has text, and Translate and Update leave it alone: a field someone emptied, an empty value saved by code or an import, a Bard copy without text saved before Tolk was installed or without Statamic's events (`saveQuietly()`) whose origin got its text first, or an empty value left by a term created that way before Tolk was installed. Translate such a field with **Overwrite manual edits**, or with the field action **Translate from origin**.

### Content written outside the CP

The first time an editor saves origin content in the CP that was written another way (imported, seeded, saved by code), Statamic adds row ids and `enabled` flags to its Replicator, Grid and Bard values. That changes the origin's fingerprint, so its machine translations read **outdated once**, although no text changed. **Update** fixes it without calling a model: every paragraph is reused from the earlier translation, and only the new structure is written.

### Collections with revisions

On a collection with revisions, the changes to a published entry wait in its working copy until someone publishes them. Tolk compares:

- the origin **as it is published**: changes waiting in its working copy do not make its translations outdated, and are not translated, until they are published;
- the localization **as its working copy holds it**, as its form shows it: a translation that waits there to be published reads **up to date** in the banner, on the Overview and in `tolk:status`, and Update leaves it alone, although visitors see it only once it is published.

So a site translated from a localization (`de_CH` from `de`) follows an update of `de` only once `de` is published. See [Bulk translations and runs](bulk-and-runs.md#revisions).

### Duplicated entries

Statamic's **Duplicate** action copies an entry with its localizations, translations included. Tolk gives each copied localization the records of the localization it was copied from, so its fields keep their statuses and follow the copy's own origin from then on: rewrite the copy's origin, and Update translates the copy again. Statamic adds "(Duplicated)" to each copy's title, so a machine-translated title reads outdated, and Update translates the new title. A field edited by hand stays edited by hand.

A copy made another way (files copied, an import) has no records: its translations read edited by hand and untracked. Translate it again with **Overwrite manual edits**, or record them with `tolk:baseline` ([Baselines](#baselines)).

### Saving without changes

Opening a localization and saving it without editing a text changes no status, as long as nothing saved the localization while its form was open. A form saves what it was opened with: when a run or an automatic update translated the localization in the meantime, the save puts the earlier text back. A field it inherited before then reads untranslated, and a field whose earlier translation came back reads outdated ([Forms opened before a run](editor.md#forms-opened-before-a-run)).

### An earlier translation saved again

A field that holds a translation Tolk made, after a newer translation replaced it, reads **outdated**, not edited by hand, so Update translates it again from the origin. That happens when a localization's form is left open with a translation filled in while the same field is saved elsewhere (by a run, **Update translations** on the origin, an automatic update or another tab), and the form is then saved without editing that text. Tolk remembers the last three translations of each field that newer ones replaced; an older one reads edited by hand.

## How Update reuses earlier work

When a field is outdated and nobody edited its text, Update sends only the paragraphs whose origin text changed (or that are new, or were left untranslated before). Every unchanged paragraph keeps its earlier translation, even when paragraphs were added, removed or moved around it. A changed paragraph is sent with its neighbours as context. A field whose origin changed only in structure is rebuilt without a model call.

Paragraphs are reused only from the field's latest translation. A field outdated because an older translation came back (a form opened before a run was saved, a revision was restored) is sent in full.

Mode `all` (**Retranslate everything**) reuses nothing.

## Manual fields

A field is edited by hand as soon as its text differs from what Tolk produced, however small the change. From then on:

- Translate and Update leave it alone, so your edits are never overwritten.
- When its origin changes and Tolk knows which origin text it belongs to, it **needs attention**.
- **Overwrite manual edits** (`--force`) replaces it; it is machine-translated again afterwards.
- A review leaves it alone unless you ask for it with **Overwrite manual edits**; then the reviewed field stays edited by hand.

Editing a filled field in the editor before saving it makes it edited by hand right away.

## Baselines

A **baseline** records which origin text a field edited by hand belongs to, without claiming Tolk translated it. The field stays edited by hand, and a later change to the origin makes it need attention.

- **Mark manual fields as up to date**, in the editor's options, records the current origin for the entry's fields edited by hand. Use it after checking them against the origin, which also clears "needs attention".
- `tolk:baseline` does the same in bulk, for a site translated by hand before Tolk ([The command line](cli.md#tolkbaseline)). It records every field edited by hand it selects, so it also clears "needs attention" there.

## Protecting translations

**Protect this entry from updates**, in the editor's options, turns the entry's machine-translated fields into fields edited by hand, with a baseline. Translate and Update then leave them alone, and a change to the origin shows as needing attention instead of being translated over. The baseline records the origin a field was translated from, so a field that was outdated needs attention right away. It also marks the fields already edited by hand as up to date. A field left partly untranslated ([below](#fields-left-partly-untranslated)) is not protected: it stays outdated, so Update translates the rest; edit it if that part should stay as it is. The action is shown while the entry has a machine-translated field that is not partly untranslated.

Global sets and navigations have no editor, so they have no such action. Protect them from the command line instead: `tolk:baseline --protect` does the same for every item of the containers it names, for example `php please tolk:baseline --nav=main --site=el --protect` ([The command line](cli.md#tolkbaseline)). It works for collections and taxonomies too.

To protect one field everywhere instead, switch off its **Translate automatically** option in the blueprint ([Configuration](configuration.md#per-field-options)).

## Fields left partly untranslated

When one part of a field cannot be translated (the model refuses it, or still returns text that breaks its formatting after two repairs), the field is delivered anyway with that part in the source language:

- the field reads **outdated**, so the next Update tries the missing part again, and only that part;
- the editor's banner names it ("Intro is partly untranslated. Update translates the rest after you save."), runs list it as left incomplete, and the command line prints the part;
- a new localization with such a field stays a draft even with **Same as origin**.

To find the part the model refuses, Tolk splits the refused request in halves and sends each again, until the refused text is alone, so the rest of the request is still translated. That takes about two more requests per halving, or three with **Review after translating** on, because each translated half is also reviewed on its own: for one refused text among 40 sent together, about 13 requests, or 19 with review. It takes more when the model refuses most of the text.

With the **Strict fields** setting on, such a field is not delivered at all and keeps its previous value. A run lists it as "Could not be translated" with the reason, such as "the model refused it".

## When the origin is emptied

When a field is emptied in the origin, its translation reads outdated, and Update removes it, so the localization inherits the empty field again. Tolk remembers the removed translation: if a form that was open before, or a restored revision, puts it back, it reads outdated again, and the next Update removes it again. A field edited by hand is kept and needs attention.
