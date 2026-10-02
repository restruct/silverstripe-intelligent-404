import { test as base, expect, type Browser, type BrowserContext, type Page, type Response } from '@playwright/test';

// Shared fixtures and helpers for the intelligent-404 specs.
//
// The pages come from the fixture reset endpoint (tests/browser/fixtures/I4bResetAdmin.php); the
// module is switched on in dev mode by tests/browser/fixtures/_config/intelligent404.yml. The
// scratch hosts have no theme, so pages and the 404 render with framework's plain fallback
// template, which still prints the ErrorPage $Content the module appends its list to.

const NOT_FOUND_TEXT = 'Failed to load resource: the server responded with a status of 404 (Not Found)';

/**
 * test, extended with an automatic console guard: every spec fails if a page logs a console error
 * or throws an uncaught exception. One message is expected and not counted: Chromium logs a 404
 * DOCUMENT response as "Failed to load resource ... 404" against that document's own URL, and a 404
 * document is what several specs open on purpose. Any other 404 (an asset, a request) still fails.
 */
export const test = base.extend<{ consoleGuard: void }>({
    consoleGuard: [
        async ({ context }, use, testInfo) => {
            const errors: string[] = [];
            const documents404 = new Set<string>();
            // Every page of the context, also the ones a spec opens in a second context it makes itself
            // is not covered here; specs that make one call guardPage() on it.
            context.on('page', (page) => guardPage(page, errors, documents404));
            for (const page of context.pages()) {
                guardPage(page, errors, documents404);
            }

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/** Collect a page's console errors into `errors`, leaving out a 404 document's own load message. */
export function guardPage(page: Page, errors: string[], documents404 = new Set<string>()): void {
    page.on('response', (r) => {
        if (r.request().isNavigationRequest() && r.status() === 404) {
            documents404.add(r.url());
        }
    });
    page.on('console', (msg) => {
        if (msg.type() !== 'error') {
            return;
        }
        if (msg.text() === NOT_FOUND_TEXT && documents404.has(msg.location().url)) {
            return;
        }
        errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
    });
    page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));
}

/** Make sure the fixture pages exist (as the logged-in admin); answers {segment: link}. */
export async function seed(page: Page): Promise<Record<string, string>> {
    const response = await page.request.get('/admin/i404-reset/seed');
    expect(response.status(), 'fixture pages seeded').toBe(200);
    return response.json();
}

/** A fresh browser context: no cookies, so an anonymous visitor. */
export async function visitorContext(browser: Browser, baseURL: string | undefined): Promise<BrowserContext> {
    return browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
}

/** The redirect chain that led to a response, oldest first, as [status, url] pairs. */
export async function redirectChain(response: Response): Promise<Array<[number, string]>> {
    const chain: Array<[number, string]> = [];
    let request = response.request().redirectedFrom();
    while (request) {
        const r = await request.response();
        chain.unshift([r!.status(), new URL(request.url()).pathname]);
        request = request.redirectedFrom();
    }
    return chain;
}

/**
 * The module's suggestion links on a 404 page. Its list has the class "404options", which starts
 * with a digit, so ".404options" is not a valid CSS selector; an attribute selector matches it.
 */
export function suggestions(page: Page) {
    return page.locator('ul[class~="404options"] li a');
}
