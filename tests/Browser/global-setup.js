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

    const browser = await chromium.launch();

    try {
        await storeSession(browser, sessions.admin, process.env.SANDBOX_ADMIN_EMAIL, process.env.SANDBOX_ADMIN_PASSWORD);
        await storeSession(browser, sessions.editor, process.env.SANDBOX_EDITOR_EMAIL, process.env.SANDBOX_EDITOR_PASSWORD);
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

// More than about five logins a minute answer 429, so a stored session is reused while it is still valid.
async function storeSession(browser, file, email, password) {
    if (await stillLoggedIn(file)) {
        return;
    }

    const context = await browser.newContext({ baseURL: process.env.SANDBOX_URL });
    const page = await context.newPage();

    try {
        await page.goto(cpUrl('auth/login'));
        await page.locator('input[name="email"]').fill(email);
        await page.locator('input[name="password"]').fill(password);
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
