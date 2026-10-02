import { test, expect, guardPage, redirectChain, seed, suggestions, visitorContext } from './support';

// The whole path a lost visitor takes on a real server: unknown URL -> ErrorPage -> the module's
// 301 or suggestion list. The unit tests build the ErrorPage controller by hand and fake
// $_SERVER['REQUEST_URI']; here the URL comes from a real request.

test.beforeEach(async ({ page }) => {
    await seed(page);
});

test.describe('Intelligent 404', () => {
    test('an old nested URL with a .html extension is redirected (301) to its one exact match', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const page = await context.newPage();
            guardPage(page, errors);
            // i404-color sounds the same, so only the exact match on the segment WITHOUT ".html"
            // gives a single match; with the extension kept it would be two soundalikes and a list.
            const response = await page.goto('/old-site/section/i404-colour.html');
            const chain = await redirectChain(response!);
            // The module's 301 comes first; Silverstripe may add its own trailing-slash redirect after.
            expect(chain[0]).toEqual([301, '/old-site/section/i404-colour.html']);
            expect(response!.status()).toBe(200);
            expect(new URL(page.url()).pathname).toMatch(/^\/i404-colour\/?$/);
            await expect(page).toHaveTitle(/Colour page/);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);
    });

    test('a single soundalike is redirected to as well', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const page = await context.newPage();
            guardPage(page, errors);
            // "uniqe" sounds like "unique" (soundex I523 for both segments).
            const response = await page.goto('/i404-uniqe-target');
            expect((await redirectChain(response!))[0]).toEqual([301, '/i404-uniqe-target']);
            expect(new URL(page.url()).pathname).toMatch(/^\/i404-unique-target\/?$/);
            await expect(page).toHaveTitle(/Unique target/);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);
    });

    test('several matches: the 404 lists them, and each suggestion opens its page', async ({ browser, baseURL }) => {
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const page = await context.newPage();
            guardPage(page, errors);
            const response = await page.goto('/old/i404-collor');
            expect(response!.status(), 'still a 404').toBe(404);
            expect(await redirectChain(response!)).toEqual([]);
            await expect(page.getByRole('heading', { name: 'Were you looking for one of the following?' })).toBeVisible();
            await expect(suggestions(page)).toHaveCount(2);
            // The order is the database's; only the set matters.
            expect((await suggestions(page).locator('strong').allInnerTexts()).sort()).toEqual(['Color page', 'Colour page']);

            await suggestions(page).filter({ hasText: 'Colour page' }).click();
            await expect(page).toHaveTitle(/Colour page/);
            expect(new URL(page.url()).pathname).toMatch(/^\/i404-colour\/?$/);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);
    });

    test('a login-protected page is offered only to someone who may view it', async ({ page, browser, baseURL }) => {
        // Anonymous: no redirect, no list - a plain 404.
        const context = await visitorContext(browser, baseURL);
        const errors: string[] = [];
        try {
            const visitor = await context.newPage();
            guardPage(visitor, errors);
            const response = await visitor.goto('/old/i404-members-only');
            expect(response!.status()).toBe(404);
            expect(await redirectChain(response!)).toEqual([]);
            await expect(suggestions(visitor)).toHaveCount(0);
        } finally {
            await context.close();
        }
        expect(errors).toEqual([]);

        // The logged-in admin (this test's own session) is sent there.
        const response = await page.goto('/old/i404-members-only');
        expect((await redirectChain(response!))[0]).toEqual([301, '/old/i404-members-only']);
        await expect(page).toHaveTitle(/Members only/);
    });

    test('no match leaves the 404 alone', async ({ page }) => {
        const response = await page.goto('/zzqx-nothing-like-it');
        expect(response!.status()).toBe(404);
        expect(await redirectChain(response!)).toEqual([]);
        await expect(page.getByRole('heading', { name: 'Were you looking for one of the following?' })).toHaveCount(0);
        await expect(page.locator('body')).toContainText('Page not found');
    });
});
