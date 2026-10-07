<?php

namespace Restruct\Silverstripe\Intelligent404\Tests;

use Restruct\Silverstripe\Intelligent404\Intelligent404;
use Restruct\Silverstripe\Intelligent404\Intelligent404Resolution;
use Restruct\Silverstripe\Intelligent404\Intelligent404StatusMiddleware;
use Restruct\Silverstripe\Intelligent404\Tests\Stub\ResolverStub;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ErrorPage\ErrorPageController;
use SilverStripe\Versioned\Versioned;

/**
 * 4.2: the `updateIntelligent404Resolution` hook (project resolvers), the built-in normalised match
 * (`redirect_on_normalised_match`) and the status middleware. Fired like Intelligent404Test (REQUEST_URI set,
 * the ErrorPage controller built as ErrorPage::response_for() does, onAfterInit extended), plus the current
 * HTTPRequest registered the way Director does for a real request, which is where resolvers take the path from.
 *
 * Run as an ANONYMOUS visitor (logOut() in setUp): SapphireTest is otherwise logged in as an admin, for whom
 * every canView() passes, which hid a protected-page leak in the first version of these tests.
 */
class Intelligent404ResolverTest extends SapphireTest
{
    protected static $fixture_file = 'Intelligent404ResolverTest.yml';

    protected static $required_extensions = [
        ErrorPageController::class => [ResolverStub::class],
    ];

    /** @var string|null */
    private $originalRequestUri;

    /** @var bool */
    private $hadRequestUri;

    /** @var string|null */
    private $originalReadingMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadRequestUri = array_key_exists('REQUEST_URI', $_SERVER);
        $this->originalRequestUri = $this->hadRequestUri ? $_SERVER['REQUEST_URI'] : null;
        $this->originalReadingMode = Versioned::get_reading_mode();

        foreach (['news', 'results', 'about', 'membersonly'] as $fixture) {
            SiteTree::get()->byID($this->idFromFixture(SiteTree::class, $fixture))->publishSingle();
        }
        ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'))->publishSingle();
        Versioned::set_stage(Versioned::LIVE);
        $this->logOut();

        Config::modify()->set(Intelligent404::class, 'allow_in_dev_mode', true);
        # Isolate the resolvers from the fuzzy single-match redirect, which would otherwise also catch
        # some of these URLs (soundex ignores case and punctuation)
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);

        ResolverStub::$behaviour = [];
        ResolverStub::$calls = 0;
    }

    protected function tearDown(): void
    {
        if ($this->hadRequestUri) {
            $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
        Versioned::set_reading_mode($this->originalReadingMode);
        ResolverStub::$behaviour = [];
        ResolverStub::$calls = 0;

        parent::tearDown();
    }

    /**
     * @param string $uri REQUEST_URI as the web server passes it (base folder included)
     * @param string|null $relativeUrl the base-relative URL Director would register; derived from $uri if null
     */
    private function hit(string $uri, ?string $relativeUrl = null): ErrorPageController
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $path = (string) strtok($relativeUrl ?? $uri, '?');
        Injector::inst()->registerService(new HTTPRequest('GET', $path), HTTPRequest::class);

        $errorPage = ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'));
        $controller = ModelAsController::controller_for($errorPage);
        $controller->extend('onAfterInit');

        return $controller;
    }

    /** @return HTTPResponse|null the redirect response, or null when there was none */
    private function redirectFor(string $uri, ?string $relativeUrl = null): ?HTTPResponse
    {
        try {
            $this->hit($uri, $relativeUrl);
        } catch (HTTPResponse_Exception $e) {
            return $e->getResponse();
        }
        return null;
    }

    private function normalisedMatchOn(): void
    {
        Config::modify()->set(Intelligent404::class, 'redirect_on_normalised_match', true);
    }

    private function assertRedirectPath(?HTTPResponse $response, string $path, int $code = 301): void
    {
        $this->assertNotNull($response, "expected a redirect to {$path}");
        $this->assertSame($code, $response->getStatusCode());
        $this->assertSame($path, rtrim((string) parse_url((string) $response->getHeader('Location'), PHP_URL_PATH), '/'));
    }

    // --- built-in normalised match ---------------------------------------------------------------------------

    public function testNormalisedMatchIsOffByDefault()
    {
        Config::modify()->remove(Intelligent404::class, 'redirect_on_normalised_match');
        $this->assertFalse((bool) Config::inst()->get(Intelligent404::class, 'redirect_on_normalised_match'));
        # Control for the tests below: without the option, the curly-apostrophe URL is not redirected
        $this->assertNull($this->redirectFor('/news/our-team%E2%80%99s-results'));
    }

    public function testCurlyApostropheUrlRedirectsToTheRenamedPage()
    {
        # A segment stored with a raw ’ is unroutable, and so is its old URL after a rename to plain ASCII
        $this->normalisedMatchOn();
        $this->assertRedirectPath($this->redirectFor('/news/our-team%E2%80%99s-results'), '/news/our-teams-results');
    }

    public function testCaseAndUnderscoresAreNormalisedAndTheQuerystringKept()
    {
        $this->normalisedMatchOn();
        $response = $this->redirectFor('/About_Us?ref=mail');
        $this->assertRedirectPath($response, '/about-us');
        $this->assertStringEndsWith('?ref=mail', (string) $response->getHeader('Location'));
    }

    public function testCaseOnlyDifferenceRedirects()
    {
        # The loop guard compares case-sensitively: /About-Us is not the same URL as /about-us
        $this->normalisedMatchOn();
        $this->assertRedirectPath($this->redirectFor('/About-Us'), '/about-us');
    }

    public function testNothingToNormaliseIsLeftAlone()
    {
        $this->normalisedMatchOn();
        $this->assertNull($this->redirectFor('/no-such-page'));
    }

    public function testDraftOnlyPageIsNoTarget()
    {
        $this->normalisedMatchOn();
        $this->assertNull($this->redirectFor('/Draft_Only'));
    }

    public function testProtectedPageIsNoTargetForAnAnonymousVisitor()
    {
        # Would leak the protected URL (canView check)
        $this->normalisedMatchOn();
        $this->assertNull($this->redirectFor('/Members_Only'));
        $this->assertNull($this->redirectFor('/members-only'));
    }

    public function testErrorPageIsNoTarget()
    {
        $this->normalisedMatchOn();
        $this->assertNull($this->redirectFor('/Page_Not_Found'));
    }

    public function testOnlyTheExactPathCounts()
    {
        # get_by_link() resolves more than the exact path; none of these may redirect to /news/our-teams-results
        # or /about-us: a wrong parent, the root-level URL of a nested page, and an unknown first segment
        $this->normalisedMatchOn();
        $this->assertNull($this->redirectFor('/Other/Our_Teams_Results'), 'wrong parent');
        $this->assertNull($this->redirectFor('/Our_Teams_Results'), 'root-level URL of a nested page');
        $this->assertNull($this->redirectFor('/Not_There/about-us'), 'unknown parent, existing root segment');
    }

    public function testSubfolderInstall()
    {
        # Site in /sub/: REQUEST_URI carries the folder, the registered request is relative to it
        Config::modify()->set(Director::class, 'alternate_base_url', '/sub/');
        $this->normalisedMatchOn();
        $this->assertRedirectPath($this->redirectFor('/sub/About_Us', 'About_Us'), '/sub/about-us');

        # Loop guard there too: a resolver pointing back at the requested URL is refused
        ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect('/sub/old/thing')];
        $this->assertNull($this->redirectFor('/sub/old/thing', 'old/thing'));
    }

    // --- resolver hook -----------------------------------------------------------------------------------------

    public function testProjectResolverRedirectWinsAndUsesItsCode()
    {
        ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect('/about-us', 302, 'stub')];
        $this->assertRedirectPath($this->redirectFor('/old/thing'), '/about-us', 302);
        $this->assertSame(1, ResolverStub::$calls);
    }

    public function testResolverSeesTheDecodedPathAndQuery()
    {
        $seen = null;
        ResolverStub::$behaviour = [function (Intelligent404Resolution $r) use (&$seen) {
            $seen = [$r->path, $r->query];
        }];
        $this->hit('/news/our-team%E2%80%99s-results/?a=1');
        $this->assertSame(['news/our-team’s-results', 'a=1'], $seen);
    }

    public function testFirstResolutionWinsInEitherOrder()
    {
        ResolverStub::$behaviour = [
            fn (Intelligent404Resolution $r) => $r->respond('<p>first</p>', 410),
            fn (Intelligent404Resolution $r) => $r->redirect('/about-us'),
        ];
        $controller = $this->hit('/old/thing');
        $this->assertStringContainsString('<p>first</p>', (string) $controller->Content, 'a later redirect does not override');

        ResolverStub::$behaviour = [
            fn (Intelligent404Resolution $r) => $r->redirect('/about-us', 302),
            fn (Intelligent404Resolution $r) => $r->respond('<p>second</p>', 410),
            fn (Intelligent404Resolution $r) => $r->redirect('/news', 301),
        ];
        $this->assertRedirectPath($this->redirectFor('/old/thing'), '/about-us', 302);
    }

    public function testRedirectToTheSameUrlIsRefused()
    {
        ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect('/old/thing/')];
        $this->assertNull($this->redirectFor('/old/thing'));
    }

    public function testRedirectToTheSamePathOnAnotherDomainIsAllowed()
    {
        # A domain move keeps the path: only a target on THIS site counts as a loop
        ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect('https://new.example/old/thing')];
        $response = $this->redirectFor('/old/thing');
        $this->assertNotNull($response);
        $this->assertSame('https://new.example/old/thing', $response->getHeader('Location'));
    }

    public function testProtocolRelativeAndBackslashTargetsAreRefused()
    {
        # The path reaches resolvers decoded: /old/%2F%2Fevil.example arrives as old//evil.example, and a resolver
        # building '/' . $rest would otherwise send the visitor off-site
        foreach (['//evil.example', '///evil.example', '/\\evil.example', '\\\\evil.example', 'evil.example/x'] as $target) {
            ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect($target)];
            $this->assertNull($this->redirectFor('/old/thing'), "refused: {$target}");
        }

        # Browsers strip tab/CR/LF anywhere in a URL: '/<tab>/evil.example' is requested as //evil.example. Both
        # straight targets and the README-style resolver ('/' . $rest of the decoded path) must be refused
        foreach (["/\t/evil.example", "/\n/evil.example", "/\r\\evil.example", "/ok\x00", "/a b", " //evil.example", "\t/ok"] as $target) {
            ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect($target)];
            $this->assertNull($this->redirectFor('/old/thing'), 'refused: ' . json_encode($target));
        }
        ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->redirect('/' . substr($r->path, strlen('old/')))];
        $this->assertNull($this->redirectFor('/old/%09/evil.example'), 'decoded tab then slash');
        $this->assertNull($this->redirectFor('/old/%09%5Cevil.example'), 'decoded tab then backslash');
        # Control: the same resolver with a harmless rest still redirects
        $this->assertRedirectPath($this->redirectFor('/old/about-us'), '/about-us');
    }

    public function testFirstRespondWinsOverALaterRespond()
    {
        ResolverStub::$behaviour = [
            fn (Intelligent404Resolution $r) => $r->respond('<p>first</p>', 410, true, 'First'),
            fn (Intelligent404Resolution $r) => $r->respond('<p>second</p>', 451, true, 'Second'),
        ];
        $controller = $this->hit('/old/thing');
        $this->assertSame('<p>first</p>', (string) $controller->Content);
        $this->assertSame('First', $controller->Title);
        $this->assertSame('410', $controller->getResponse()->getHeader(Intelligent404StatusMiddleware::STATUS_HEADER));
    }

    public function testRedirectRejectsANonRedirectStatus()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Intelligent404Resolution('x'))->redirect('/y', 410);
    }

    public function testABadRedirectCodeAfterAResolutionIsIgnored()
    {
        # A later resolver's mistake must not turn an already-answered 404 into a 500
        $resolution = (new Intelligent404Resolution('x'))->redirect('/y', 302);
        $resolution->redirect('/z', 410);
        $this->assertSame('/y', $resolution->redirectTo);
        $this->assertSame(302, $resolution->redirectCode);
    }

    public function testContentResolutionReplacesFuzzyOptionsAndMarksTheStatus()
    {
        ResolverStub::$behaviour = [fn (Intelligent404Resolution $r) => $r->respond('<p>No longer offered.</p>', 410, true, 'Gone', 'stub')];
        $controller = $this->hit('/about-uz');

        $this->assertSame('<p>No longer offered.</p>', $controller->Content);
        $this->assertSame('Gone', $controller->Title);
        $this->assertSame('<p>No longer offered.</p>', (string) $controller->Intelligent404Options);
        $this->assertSame('', (string) $controller->ContentWithout404Options, 'replaced in both template layouts');
        $this->assertSame('410', $controller->getResponse()->getHeader(Intelligent404StatusMiddleware::STATUS_HEADER));
    }

    public function testUnresolvedRequestStillGetsFuzzyOptions()
    {
        # Control for the test above: without a resolution the soundex suggestions appear as before
        $controller = $this->hit('/about-uz');
        $this->assertNotNull($controller->Intelligent404Options);
        $this->assertNull($controller->getResponse()->getHeader(Intelligent404StatusMiddleware::STATUS_HEADER));
    }

    // --- status middleware ---------------------------------------------------------------------------------------

    public function testMiddlewareSwapsTheStatusOfAMarkedErrorPage()
    {
        $middleware = new Intelligent404StatusMiddleware();
        $request = new HTTPRequest('GET', '/x');

        $marked = HTTPResponse::create('gone', 404)->addHeader(Intelligent404StatusMiddleware::STATUS_HEADER, '410');
        $result = $middleware->process($request, fn () => $marked);
        $this->assertSame(410, $result->getStatusCode());
        $this->assertNull($result->getHeader(Intelligent404StatusMiddleware::STATUS_HEADER));

        # Never turns a non-404 into something else, and never into a non-4xx
        $ok = HTTPResponse::create('fine', 200)->addHeader(Intelligent404StatusMiddleware::STATUS_HEADER, '410');
        $this->assertSame(200, $middleware->process($request, fn () => $ok)->getStatusCode());
        $toSuccess = HTTPResponse::create('nf', 404)->addHeader(Intelligent404StatusMiddleware::STATUS_HEADER, '200');
        $this->assertSame(404, $middleware->process($request, fn () => $toSuccess)->getStatusCode());
        $plain = HTTPResponse::create('nf', 404);
        $this->assertSame(404, $middleware->process($request, fn () => $plain)->getStatusCode());
    }
}
