import { readFileSync } from 'node:fs';
import { createSSRApp } from 'vue';
import { parse } from 'vue/compiler-sfc';
import { renderToString } from 'vue/server-renderer';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { pickedModel } from '../editor/options.js';

const translate = (key, replacements = {}) => Object.entries(replacements).reduce((text, [name, value]) => text.split(`:${name}`).join(String(value)), key);

// The menu's element that carries `attribute`, to render on its own with the data it reads.
function fragment(attribute) {
    const { descriptor } = parse(readFileSync(new URL('./TranslateOptions.vue', import.meta.url), 'utf8'));
    const find = (node) => (node.props?.some((prop) => prop.name === attribute) ? node : ((node.children ?? []).map(find).find(Boolean) ?? null));

    return find(descriptor.template.ast).loc.source;
}

// Core's taggable Combobox commits the text in its search input as the value when the input loses focus or on Enter,
// whether or not it names an option; `commit` does what it does then.
async function render(attribute, state) {
    let commit = null;
    const app = createSSRApp({ template: fragment(attribute), data: () => state });
    app.config.globalProperties.__ = translate;
    app.component('Combobox', {
        props: ['modelValue', 'options', 'taggable', 'disabled', 'placeholder'],
        emits: ['update:modelValue'],
        setup(props, { emit }) {
            commit = (value) => emit('update:modelValue', value);

            return () => null;
        },
    });

    await renderToString(app);

    return commit;
}

const choices = [
    { value: 'anthropic:claude-sonnet-5-5', label: 'Claude Sonnet 5.5 (default)' },
    { value: 'anthropic:claude-haiku-5-5', label: 'Claude Haiku 5.5' },
];

beforeEach(() => {
    vi.stubGlobal('__', translate);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe.each([
    ['data-tolk-options-model', 'model', 'choices'],
    ['data-tolk-options-reviewer', 'reviewer', 'reviewerChoices'],
])('the select %s', (attribute, key, optionsKey) => {
    const state = () => ({
        [key]: 'anthropic:claude-sonnet-5-5',
        [optionsKey]: choices,
        mayType: true,
        loading: false,
        reviewerUsed: true,
        pickedModel,
    });

    it('keeps the model when text typed to search the list is committed', async () => {
        const data = state();
        const commit = await render(attribute, data);

        commit('son');
        expect(data[key]).toBe('anthropic:claude-sonnet-5-5');

        commit('Claude Haiku 5.5');
        expect(data[key]).toBe('anthropic:claude-sonnet-5-5');
    });

    it('takes a model of the list, or one typed as provider:model', async () => {
        const data = state();
        const commit = await render(attribute, data);

        commit('anthropic:claude-haiku-5-5');
        expect(data[key]).toBe('anthropic:claude-haiku-5-5');

        commit(' openai:gpt-custom ');
        expect(data[key]).toBe('openai:gpt-custom');
    });
});
