import { existsSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { chromium, request } from '@playwright/test';
import { cpUrl, tinker } from './support/sandbox.js';
import { sessions } from './support/sessions.js';

const required = [
    'SANDBOX_PATH',
    'SANDBOX_URL',
    'SANDBOX_ADMIN_EMAIL',
    'SANDBOX_ADMIN_PASSWORD',
    'SANDBOX_EDITOR_EMAIL',
    'SANDBOX_EDITOR_PASSWORD',
];

export default async function globalSetup() {
    const missing = required.filter((name) => !process.env[name]);

    if (missing.length > 0) {
        throw new Error(`Set ${missing.join(', ')} in .env.browser (see .env.browser.example; the values are in the sandbox's SANDBOX.md).`);
    }

    const sandbox = askSandbox();

    if (!sandbox.fake_engine) {
        throw new Error('The fake engine is off in the sandbox. Set TOLK_FAKE_ENGINE=true in its .env: browser scenarios never call a real provider.');
    }

    process.env.SANDBOX_CP_ROUTE = sandbox.cp;

    await assertAnswers();

    // More than about five logins a minute answer 429, so a stored session is reused while it is still valid.
    const logins = [
        [sessions.admin, process.env.SANDBOX_ADMIN_EMAIL, process.env.SANDBOX_ADMIN_PASSWORD],
        [sessions.editor, process.env.SANDBOX_EDITOR_EMAIL, process.env.SANDBOX_EDITOR_PASSWORD],
    ];
    const valid = await Promise.all(logins.map(([file]) => stillLoggedIn(file)));
    const expired = logins.filter((login, index) => !valid[index]);

    if (expired.length === 0) {
        return;
    }

    const browser = await chromium.launch();

    try {
        for (const [file, email, password] of expired) {
            await storeSession(browser, file, email, password);
        }
    } finally {
        await browser.close();
    }
}

function askSandbox() {
    const output = tinker('echo json_encode(["fake_engine" => \\Reach\\StatamicTolk\\Ai\\FakeEngine::enabled(), "cp" => config("statamic.cp.route")]);');

    try {
        return JSON.parse(output.split('\n').at(-1));
    } catch {
        throw new Error(`The sandbox did not say whether its fake engine is on:\n${output}`);
    }
}

async function assertAnswers() {
    const url = new URL(cpUrl('auth/login'), process.env.SANDBOX_URL).href;
    let response;

    try {
        response = await fetch(url);
    } catch (error) {
        throw new Error(`The sandbox does not answer at ${url}: ${error.cause?.message ?? error.message}`);
    }

    if (!response.ok) {
        throw new Error(`The sandbox answers ${response.status} at ${url}.`);
    }
}

async function storeSession(browser, file, email, password) {
    const context = await browser.newContext({ baseURL: process.env.SANDBOX_URL });
    const page = await context.newPage();

    try {
        await page.goto(cpUrl('auth/login'), { waitUntil: 'networkidle' });
        await fillLoginField(page.locator('input[name="email"]'), email);
        await fillLoginField(page.locator('input[name="password"]'), password);
        await page.getByRole('button', { name: 'Continue' }).click();

        try {
            await page.waitForURL((url) => !url.pathname.includes('/auth/'), { timeout: 15_000 });
        } catch {
            const text = (await page.locator('main, body').first().innerText()).replace(/\s+/g, ' ').trim();

            throw new Error(`Could not log in to the sandbox as ${email}. The page at ${page.url()} reads: ${text.slice(0, 300)}`);
        }

        mkdirSync(dirname(file), { recursive: true });
        await context.storageState({ path: file });
    } finally {
        await context.close();
    }
}

// The login form can still re-render after the page loads, which empties a field filled too early. Checked by hand
// rather than with `toHaveValue`, which would print the password in its failure message.
async function fillLoginField(input, value) {
    for (let attempt = 0; attempt < 5; attempt++) {
        await input.fill(value);
        await input.page().waitForTimeout(200);

        if ((await input.inputValue()) === value) {
            return;
        }
    }

    throw new Error(`The login form's ${await input.getAttribute('name')} field does not keep its value.`);
}

async function stillLoggedIn(file) {
    if (!existsSync(file)) {
        return false;
    }

    const context = await request.newContext({ baseURL: process.env.SANDBOX_URL, storageState: file });

    try {
        const response = await context.get(cpUrl('dashboard'), { maxRedirects: 0 });

        return response.status() === 200;
    } finally {
        await context.dispose();
    }
}
