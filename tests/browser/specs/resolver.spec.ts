import { test, expect, guardPage, redirectChain, seed, visitorContext } from './support';

// 4.2: the built-in normalised match and the resolver hook, through a real request. The unit tests cover
// the decisions; this proves the visitor-facing result: the redirect chain, and that a resolver's 410 page
// still renders through the normal ErrorPage template (status swapped by the middleware, after rendering).

test.beforeEach(async ({ page }) => {
    await seed(page);
});

test.describe('Intelligent 404 resolvers', () => {
    test('a URL that only differs by case and underscores goes straight to the page (normalised match)', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const page = await context.newPage();
            guardPage(page, errors);
            // Fuzzy matching alone would LIST colour + color (same soundex); the normalised match is exact.
            const response = await page.goto('/I404_Colour');
            expect((await redirectChain(response!))[0]).toEqual([301, '/I404_Colour']);
            expect(new URL(page.url()).pathname).toMatch(/^\/i404-colour\/?$/);
            await expect(page).toHaveTitle(/Colour page/);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);
    });

    test('an old URL segment with a curly apostrophe is redirected to the clean page', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const page = await context.newPage();
            guardPage(page, errors);
            // The apostrophe sits INSIDE the segment on purpose: before 4.2 the module read the URL only up to the
            // first '%', so a trailing one still left 'i404-colour' and its old single-match redirect passed this
            // test without the normalised match (caught by the must-fail control against main).
            const response = await page.goto('/i404-col%E2%80%99our');
            expect((await redirectChain(response!))[0][0]).toBe(301);
            expect(new URL(page.url()).pathname).toMatch(/^\/i404-colour\/?$/);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);
    });

    test('a resolver can answer 410 Gone with its own content on the normal 404 template', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const page = await context.newPage();
            guardPage(page, errors);
            const response = await page.goto('/i404-gone-item/old-name');
            expect(response!.status()).toBe(410);
            expect(response!.headers()['x-intelligent404-status'], 'marker header removed').toBeUndefined();
            expect(await redirectChain(response!)).toEqual([]);
            await expect(page.locator('.i404-gone')).toHaveText('This item is no longer available.');
            await expect(page).toHaveTitle(/No longer available/);

            await page.getByRole('link', { name: 'See the alternative' }).click();
            await expect(page).toHaveTitle(/Unique target/);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);
    });
});
