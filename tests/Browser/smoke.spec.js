import { expect, test } from './support/test.js';
import { openEntry } from './support/sandbox.js';

test('the admin opens an article and the publish form renders', async ({ page, browserErrors }) => {
    const entry = await openEntry(page, 'articles', 'what-to-eat-in-chania');

    await expect(page.locator('input#field_title')).toHaveValue(entry.title);
    await expect(page.getByRole('button', { name: 'Save & Publish', exact: true })).toBeVisible();
    expect(browserErrors()).toEqual([]);
});
