import { execFileSync, execSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect } from '@playwright/test';
import { parse } from 'yaml';

export function sandboxPath() {
    if (!process.env.SANDBOX_PATH) {
        throw new Error('SANDBOX_PATH is not set: copy .env.browser.example to .env.browser and fill it in.');
    }

    return resolve(process.env.SANDBOX_PATH);
}

// A CP path relative to `baseURL`. The global setup reads the CP route from the sandbox.
export function cpUrl(path = '') {
    return `/${process.env.SANDBOX_CP_ROUTE ?? 'cp'}/${path}`.replace(/\/$/, '');
}

// A string runs through the shell; an array of arguments runs without one, so it needs no quoting.
export function artisan(command) {
    return php('artisan', command);
}

export function please(command) {
    return php('please', command);
}

export function tinker(code) {
    return artisan(['tinker', `--execute=${code}`]);
}

function execOptions() {
    return { cwd: sandboxPath(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] };
}

function php(script, command) {
    const args = Array.isArray(command) ? command : null;

    try {
        const output = args
            ? execFileSync('php', [script, ...args], execOptions())
            : execSync(`php ${script} ${command}`, execOptions());

        return output.trim();
    } catch (error) {
        throw new Error(`php ${script} ${args ? args.join(' ') : command} failed in the sandbox:\n${error.stdout ?? ''}${error.stderr ?? error.message}`);
    }
}

function git(...args) {
    return execFileSync('git', args, execOptions());
}

// Puts the sandbox's content back to its last commit. `users/` is restored (a listing visit writes view preferences
// into the user's file) but never cleaned: it also holds the owner's own account, which git does not track.
export function reset() {
    git('checkout', '--', 'content', 'users', 'resources', 'lang');
    git('clean', '-fdq', '--', 'content', 'resources', 'lang');
    rmSync(join(sandboxPath(), 'content/tolk'), { recursive: true, force: true });
    rmSync(join(sandboxPath(), 'storage/statamic/tolk/runs'), { recursive: true, force: true });
    please('stache:clear');
}

// The data an entry stores, as parsed from its file, or null when the site has no such entry. Dated origins are
// stored as `{date}.{slug}.md`; localizations and undated entries as `{slug}.md`.
export function entryFile(collection, site, slug) {
    const folder = join(sandboxPath(), 'content/collections', collection, site);

    if (!existsSync(folder)) {
        return null;
    }

    const pattern = new RegExp(`^(?:[^.]+\\.)?${slug.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\.(?:md|yaml|html)$`);
    const file = readdirSync(folder).find((name) => pattern.test(name));

    return file ? parseEntry(readFileSync(join(folder, file), 'utf8')) : null;
}

// The addon's state of an entry localization (`content/tolk/entries/{id}.yaml`, §A5), or null when there is none.
export function entryState(id) {
    const file = join(sandboxPath(), 'content/tolk/entries', `${id.toLowerCase()}.yaml`);

    return existsSync(file) ? parse(readFileSync(file, 'utf8')) : null;
}

function parseEntry(contents) {
    const lines = contents.split(/\r?\n/);

    if (lines[0] !== '---') {
        return parse(contents) ?? {};
    }

    const end = lines.indexOf('---', 1);
    const data = parse(lines.slice(1, end).join('\n')) ?? {};
    const body = lines.slice(end + 1).join('\n').trim();

    if (body !== '') {
        data.content = body;
    }

    return data;
}

// Opens the publish form of a stored entry and waits until its fields render. Returns the entry's stored data.
export async function openEntry(page, collection, slug, site = 'en') {
    const entry = entryFile(collection, site, slug);

    if (!entry) {
        throw new Error(`The sandbox has no ${site} entry ${collection}/${slug}.`);
    }

    await page.goto(cpUrl(`collections/${collection}/entries/${entry.id}`));
    await expect(page.locator('[id^="field_"]').filter({ visible: true }).first()).toBeVisible();

    return entry;
}
