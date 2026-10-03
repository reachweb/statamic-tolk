import { fileURLToPath } from 'node:url';

// Stored logins, written by the global setup. Scenarios run as the admin unless they call
// `test.use({ storageState: sessions.editor })`.
export const sessions = {
    admin: fileURLToPath(new URL('../.auth/admin.json', import.meta.url)),
    editor: fileURLToPath(new URL('../.auth/editor.json', import.meta.url)),
};
