// Core's `shouldOpenDropdown` for a Combobox that takes typed values. Since Statamic 6.36 such a combobox opens its list
// when it gains the focus without a click (statamic/cms#15249), and the list puts the focus back into the field when it
// closes, which opens it again: Tab never leaves the field, and in a modal Esc closes the modal with the list. The list
// therefore opens only on a click, a key or typing, never while a focus event is dispatched.
export function opensOnRequest(event = globalThis.event) {
    return event?.type !== 'focus';
}

// Core calls `shouldOpenDropdown(open)`, so the event is read here and not taken as an argument.
export function shouldOpenDropdown() {
    return opensOnRequest();
}
