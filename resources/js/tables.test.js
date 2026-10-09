import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse } from 'vue/compiler-sfc';
import { describe, expect, it } from 'vitest';

const root = fileURLToPath(new URL('.', import.meta.url));
const components = readdirSync(root, { recursive: true }).filter((file) => file.endsWith('.vue'));

// Each of the component's `<Table>`s, by line, with whether an element around it in the component scrolls sideways.
function tables(file) {
    const { descriptor } = parse(readFileSync(root + file, 'utf8'));
    const found = [];
    const scrolls = (node) => node.props?.some((prop) => prop.name === 'class' && /(^|\s)overflow-x-auto(\s|$)/.test(prop.value?.content ?? '')) ?? false;
    const walk = (node, scrolled) => {
        if (node.tag === 'Table') {
            found.push({ at: `${file}:${node.loc.start.line}`, scrolled });
        }

        (node.children ?? []).forEach((child) => walk(child, scrolled || scrolls(node)));
    };

    walk(descriptor.template.ast, false);

    return found;
}

describe('the tables of the CP', () => {
    // As core's listings do, so a narrow screen scrolls the table inside its card instead of the page.
    it('each scroll sideways inside their own container', () => {
        const all = components.flatMap(tables);

        expect(all.length).toBeGreaterThan(0);
        expect(all.filter((table) => !table.scrolled).map((table) => table.at)).toEqual([]);
    });
});
