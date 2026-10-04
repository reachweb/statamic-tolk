// Turns the payload of `editor/status` into what the banner shows. Pure apart from `__()` and `__n()`.

// In banner order. `attention` and `untracked` are parts of `manual`.
const PARTS = {
    untranslated: {
        text: (count) => __n(':count field untranslated|:count fields untranslated', count),
        description: () => __('Not translated yet. Translate fills them in.'),
    },
    outdated: {
        text: (count) => __n(':count field outdated|:count fields outdated', count),
        description: () =>
            __('The source changed since they were translated, or part of their text is still untranslated. Update translates them again.'),
    },
    manual: {
        text: (count) => __n(':count field edited by hand|:count fields edited by hand', count),
        description: () => __('Edited by hand. Translate and Update leave them alone.'),
    },
    attention: {
        text: (count) => __n(':count needs attention|:count need attention', count),
        description: () => __('Edited by hand, and the source changed since. Check them against the source.'),
    },
    untracked: {
        text: (count) => __n(':count untracked|:count untracked', count),
        description: () =>
            __('Edited by hand before their translation was tracked, so a change to the source cannot be detected. Mark them as up to date to track them.'),
    },
    current: {
        text: (count) => __n(':count field up to date|:count fields up to date', count),
        description: () => __('Translated and up to date with the source.'),
    },
};

export function itemStatusLabel(status) {
    return {
        missing: __('Missing'),
        untranslated: __('Untranslated'),
        outdated: __('Outdated'),
        attention: __('Needs attention'),
        current: __('Up to date'),
    }[status] ?? status;
}

// The non-zero counts in banner order.
export function countParts(counts = {}) {
    return Object.entries(PARTS)
        .filter(([key]) => (counts[key] ?? 0) > 0)
        .map(([key, part]) => ({
            key,
            count: counts[key],
            text: part.text(counts[key]),
            description: part.description(),
        }));
}

// No buttons without a model to translate with: no provider and no fake engine, or no default model.
export function needsSetup(status) {
    const defaults = status?.defaults ?? {};

    return !(defaults.providers_configured || defaults.fake_engine) || !defaults.translator;
}

export function describe(status) {
    if (status?.translatable !== true) {
        return { visible: false, reason: status?.reason ?? null, setup: false, translator: null, localization: null, origin: null };
    }

    const setup = needsSetup(status);

    return {
        visible: true,
        reason: null,
        setup,
        translator: status.defaults?.translator ?? null,
        localization: status.localization ? describeLocalization(status.localization, setup) : null,
        origin: status.origin ? describeOrigin(status.origin, setup) : null,
    };
}

function describeLocalization(block, setup) {
    const counts = block.counts ?? {};
    const parts = countParts(counts);
    const fields = Object.entries(block.fields ?? {});
    const incomplete = fields.filter(([, field]) => field.incomplete).map(([handle, field]) => ({ handle, label: field.label }));
    const notLocalizable = (block.out_of_scope ?? [])
        .filter((field) => field.reason === 'not_localizable')
        .map(({ handle, label }) => ({ handle, label }));
    const summary = localizationSummary(block, parts);

    return {
        source: block.source,
        target: block.target,
        status: block.status,
        label: itemStatusLabel(block.status),
        parts,
        summary,
        lead: parts.some((part) => part.key !== 'current') ? __('From :source:', { source: block.source?.name ?? '' }) : summary,
        incomplete,
        incompleteNote: incomplete.length ? incompleteNote(incomplete) : null,
        notLocalizable,
        hint: notLocalizable.length ? notLocalizableHint(notLocalizable) : null,
        translate: !setup && (counts.untranslated ?? 0) > 0,
        update: !setup && (counts.outdated ?? 0) > 0,
    };
}

function localizationSummary(block, parts) {
    const source = block.source?.name ?? '';

    if (parts.length === 0) {
        return __('Nothing to translate from :source.', { source });
    }

    if (parts.every((part) => part.key === 'current')) {
        return __('Up to date with :source.', { source });
    }

    return __('From :source: :parts.', { source, parts: parts.map((part) => part.text).join(', ') });
}

function incompleteNote(fields) {
    return __n(
        ':fields is partly untranslated. Update translates the rest.|:fields are partly untranslated. Update translates the rest.',
        fields.length,
        { fields: labels(fields) },
    );
}

function notLocalizableHint(fields) {
    return __n(
        ':fields is not translated because it is not localizable. Run php please tolk:doctor for details.|:fields are not translated because they are not localizable. Run php please tolk:doctor for details.',
        fields.length,
        { fields: labels(fields) },
    );
}

function labels(fields) {
    return fields.map((field) => field.label).join(', ');
}

function describeOrigin(block, setup) {
    const localizations = (block.localizations ?? []).map((localization) => {
        const missing = !localization.exists;
        const counts = localization.counts ?? {};
        const parts = missing ? [] : countParts(counts);

        return {
            site: localization.site,
            name: localization.name,
            exists: localization.exists,
            status: localization.status,
            label: itemStatusLabel(localization.status),
            parts,
            summary: originSummary(localization, parts),
            editUrl: localization.edit_url ?? null,
            translate: missing || (counts.untranslated ?? 0) > 0,
            update: !missing && (counts.outdated ?? 0) > 0,
        };
    });

    return {
        localizations,
        translateInto: !setup && localizations.some((localization) => localization.translate),
        update: !setup && localizations.some((localization) => localization.update),
    };
}

function originSummary(localization, parts) {
    if (!localization.exists) {
        return __('Not created yet.');
    }

    if (parts.length === 0) {
        return __('Nothing to translate.');
    }

    return parts.map((part) => part.text).join(', ');
}

// `anthropic:claude-sonnet-5-5` → `claude-sonnet-5-5`.
export function modelName(reference) {
    const separator = reference.indexOf(':');

    return separator === -1 ? reference : reference.slice(separator + 1);
}

export function fieldLabels(handles, status) {
    const fields = status?.localization?.fields;

    return handles.map((handle) => (fields && !Array.isArray(fields) ? fields[handle]?.label : null) ?? handle).join(', ');
}

// What the banner says once a translation has finished, was cancelled or stopped. Fields are named by their labels
// from the status.
export function describeSummary(summary, status = null) {
    if (!summary) {
        return null;
    }

    const count = summary.delivered.length;
    const models = summary.models.map(modelName).join(', ');
    const edited = Object.keys(summary.skipped).filter((handle) => summary.skipped[handle] === 'edited');
    const manual = Object.keys(summary.planSkipped ?? {}).filter((handle) => summary.planSkipped[handle] === 'manual');
    const failed = Object.keys(summary.failed);

    return {
        translated:
            count === 0
                ? __('No fields were translated.')
                : models
                  ? __n(':count field translated with :model.|:count fields translated with :model.', count, { model: models })
                  : __n(':count field translated.|:count fields translated.', count),
        incomplete: summary.incomplete.length
            ? __n(
                  ':fields is partly untranslated. Update translates the rest after you save.|:fields are partly untranslated. Update translates the rest after you save.',
                  summary.incomplete.length,
                  { fields: fieldLabels(summary.incomplete, status) },
              )
            : null,
        edited: edited.length
            ? __n(
                  ':fields was skipped because you edited it.|:fields were skipped because you edited them.',
                  edited.length,
                  { fields: fieldLabels(edited, status) },
              )
            : null,
        manual: manual.length
            ? __n(
                  ':fields was skipped because it was edited by hand. Overwrite manual edits in the options replaces it.|:fields were skipped because they were edited by hand. Overwrite manual edits in the options replaces them.',
                  manual.length,
                  { fields: fieldLabels(manual, status) },
              )
            : null,
        failed: failed.length
            ? __n(':fields could not be translated.|:fields could not be translated.', failed.length, { fields: fieldLabels(failed, status) })
            : null,
        cancelled: summary.cancelled ? __('The translation was cancelled before it finished.') : null,
        unsaved: count > 0 ? __('Not saved yet. Check the fields, then save.') : null,
    };
}
