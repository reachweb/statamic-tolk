// Turns the Audit page's checks (`Audit::run()` with the controller's links) into what the page shows. Pure apart from
// `__()` and `__n()`.

export const LOCALIZABLE = 'localizable';

const LEVELS = {
    ok: { color: 'green', icon: 'checkmark', class: 'text-green-600' },
    warning: { color: 'amber', icon: 'alert-warning-exclamation-mark', class: 'text-amber-600' },
    error: { color: 'red', icon: 'warning-diamond', class: 'text-red-600' },
};

// The element id of a check on the Audit page, where a link with it as the hash scrolls to.
export function checkAnchor(id) {
    return `tolk-audit-${id}`;
}

// The link of the hints about fields that are not localizable; null for users without the Audit page.
export function localizableUrl(auditUrl) {
    return auditUrl ? `${auditUrl}#${checkAnchor(LOCALIZABLE)}` : null;
}

export function levelLabel(level) {
    return { ok: __('OK'), warning: __('Warning'), error: __('Error') }[level] ?? level;
}

export function level(name) {
    return { label: levelLabel(name), ...(LEVELS[name] ?? LEVELS.warning) };
}

// One line over every check: how many items are errors and warnings.
export function summary(checks) {
    const items = (Array.isArray(checks) ? checks : []).flatMap((check) => check.items ?? []);
    const errors = items.filter((item) => item.level === 'error').length;
    const warnings = items.filter((item) => item.level === 'warning').length;

    if (errors === 0 && warnings === 0) {
        return { level: 'ok', text: __('Everything is set up. Tolk found nothing to change.') };
    }

    const parts = [
        errors > 0 ? __n(':count error|:count errors', errors) : null,
        warnings > 0 ? __n(':count warning|:count warnings', warnings) : null,
    ].filter(Boolean);

    return {
        level: errors > 0 ? 'error' : 'warning',
        text: __('The audit found :problems. Errors keep Tolk from translating; warnings leave something untranslated or slower than it could be.', {
            problems: parts.join(', '),
        }),
    };
}

// The check's explanation. The localizable check's own text points at the command, which this page replaces.
export function checkDetail(check, canMakeLocalizable) {
    if (check?.id !== LOCALIZABLE) {
        return check?.detail ?? '';
    }

    return canMakeLocalizable
        ? __('A text field that is not localizable is shared by every site, so Tolk does not translate it. Choose the fields that hold text in each language and make them localizable.')
        : __('A text field that is not localizable is shared by every site, so Tolk does not translate it. Someone who may edit the Tolk settings and blueprints can make them localizable here, or with php please tolk:doctor --fix-localizable.');
}

// An item that names a blueprint and lists its fields (the localizable and technical fields checks).
export function isBlueprintItem(item) {
    return typeof item?.namespace === 'string' && typeof item?.blueprint === 'string' && Array.isArray(item?.fields);
}

export function blueprintKey(item) {
    return `${item.namespace}|${item.blueprint}`;
}

// The fields of a not-localizable item the page can change, and those that come from an imported fieldset.
export function splitFields(item) {
    const fields = Array.isArray(item?.fields) ? item.fields : [];

    return {
        editable: fields.filter((field) => !field.fieldset),
        imported: fields.filter((field) => Boolean(field.fieldset)),
    };
}

export function fieldName(field) {
    return field.label && field.label !== field.handle ? `${field.label} (${field.handle})` : field.handle;
}

// Why a field of an imported fieldset cannot be changed here.
export function importedNote(field) {
    return __('From the fieldset :fieldset: make it localizable in the fieldset, which changes every blueprint that imports it.', {
        fieldset: field.fieldset,
    });
}

// The handles chosen for an item, in the blueprint's order, among the fields the page can change.
export function chosenFields(item, chosen) {
    const set = new Set(Array.isArray(chosen) ? chosen : []);

    return splitFields(item)
        .editable.map((field) => field.handle)
        .filter((handle) => set.has(handle));
}

export function confirmation(item, handles) {
    const labels = splitFields(item)
        .editable.filter((field) => handles.includes(field.handle))
        .map(fieldName)
        .join(', ');

    return __n(
        'This changes the blueprint file of :blueprint: :fields becomes localizable, so each site keeps its own value and Tolk translates it. Existing localizations keep the value they inherit until they are translated or edited.|This changes the blueprint file of :blueprint: :fields become localizable, so each site keeps its own value and Tolk translates them. Existing localizations keep the values they inherit until they are translated or edited.',
        handles.length,
        { blueprint: item.name, fields: labels },
    );
}

export function resultMessage(changed) {
    return changed > 0
        ? __n(':count field is now localizable.|:count fields are now localizable.', changed)
        : __('Nothing changed: the fields were already localizable.');
}
