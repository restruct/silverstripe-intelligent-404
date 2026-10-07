<?php

namespace Restruct\Silverstripe\Intelligent404\Tests;

use Restruct\Silverstripe\Intelligent404\Intelligent404;
use Restruct\Silverstripe\Intelligent404\Intelligent404Resolution;
use Restruct\Silverstripe\Intelligent404\Intelligent404StatusMiddleware;
use Restruct\Silverstripe\Intelligent404\Tests\Stub\ResolverStub;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ErrorPage\ErrorPageController;
use SilverStripe\Versioned\Versioned;

/**
 * 4.2: the `updateIntelligent404Resolution` hook (project resolvers), the built-in normalised match
 * (`redirect_on_normalised_match`) and the status middleware. Fired the same way as Intelligent404Test:
 * REQUEST_URI set, the ErrorPage controller built as ErrorPage::response_for() does, onAfterInit extended.
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

        foreach (['news', 'diplomas', 'about'] as $fixture) {
            SiteTree::get()->byID($this->idFromFixture(SiteTree::class, $fixture))->publishSingle();
        }
        ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'))->publishSingle();
        Versioned::set_stage(Versioned::LIVE);

        Config::modify()->set(Intelligent404::class, 'allow_in_dev_mode', true);
        # Isolate the resolvers from the fuzzy single-match redirect, which would otherwise also catch
        # some of these URLs (soundex ignores case and punctuation)
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);

        ResolverStub::$behaviour = null;
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
        ResolverStub::$behaviour = null;
        ResolverStub::$calls = 0;

        parent::tearDown();
    }

    private function hit(string $uri): ErrorPageController
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $errorPage = ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'));
        $controller = ModelAsController::controller_for($errorPage);
        $controller->extend('onAfterInit');

        return $controller;
    }

    /** @return HTTPResponse|null the redirect response, or null when there was none */
    private function redirectFor(string $uri): ?HTTPResponse
    {
        try {
            $this->hit($uri);
        } catch (HTTPResponse_Exception $e) {
            return $e->getResponse();
        }
        return null;
    }

    public function testNormalisedMatchIsOffByDefault()
    {
        Config::modify()->remove(Intelligent404::class, 'redirect_on_normalised_match');
        $this->assertFalse((bool) Config::inst()->get(Intelligent404::class, 'redirect_on_normalised_match'));
        # Control for the tests below: without the option, the curly-apostrophe URL is not redirected
        $this->assertNull($this->redirectFor('/news/drie-lasdiploma%E2%80%99s'));
    }

    public function testCurlyApostropheUrlRedirectsToTheRenamedPage()
    {
        # Field case: a page renamed from `drie-lasdiploma’s` (stored raw, unroutable) to `drie-lasdiplomas`
        Config::modify()->set(Intelligent404::class, 'redirect_on_normalised_match', true);
        $response = $this->redirectFor('/news/drie-lasdiploma%E2%80%99s');
        $this->assertNotNull($response, 'expected a redirect');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringEndsWith('/news/drie-lasdiplomas', rtrim((string) $response->getHeader('Location'), '/'));
    }

    public function testCaseAndUnderscoresAreNormalisedAndTheQuerystringKept()
    {
        Config::modify()->set(Intelligent404::class, 'redirect_on_normalised_match', true);
        $response = $this->redirectFor('/About_Us?ref=mail');
        $this->assertNotNull($response, 'expected a redirect');
        $this->assertStringContainsString('/about-us', (string) $response->getHeader('Location'));
        $this->assertStringEndsWith('?ref=mail', (string) $response->getHeader('Location'));
    }

    public function testDraftOnlyPageIsNoTarget()
    {
        Config::modify()->set(Intelligent404::class, 'redirect_on_normalised_match', true);
        $this->assertNull($this->redirectFor('/Draft_Only'));
    }

    public function testNothingToNormaliseIsLeftAlone()
    {
        # Already a clean segment: the page just does not exist, so the normalised match has nothing to add
        Config::modify()->set(Intelligent404::class, 'redirect_on_normalised_match', true);
        $this->assertNull($this->redirectFor('/no-such-page'));
    }

    public function testProjectResolverRedirectWinsAndUsesItsCode()
    {
        ResolverStub::$behaviour = fn (Intelligent404Resolution $r) => $r->redirect('/about-us', 302, 'stub');
        $response = $this->redirectFor('/old/thing');
        $this->assertNotNull($response, 'expected a redirect');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, ResolverStub::$calls);
    }

    public function testResolverSeesTheDecodedPathAndQuery()
    {
        $seen = null;
        ResolverStub::$behaviour = function (Intelligent404Resolution $r) use (&$seen) {
            $seen = [$r->path, $r->query];
        };
        $this->hit('/cursus/drie-lasdiploma%E2%80%99s/?a=1');
        $this->assertSame(['cursus/drie-lasdiploma’s', 'a=1'], $seen);
    }

    public function testRedirectToTheSameUrlIsRefused()
    {
        # Loop guard: a resolver pointing back at the requested URL must not produce a redirect loop
        ResolverStub::$behaviour = fn (Intelligent404Resolution $r) => $r->redirect('/old/thing/');
        $this->assertNull($this->redirectFor('/old/thing'));
    }

    public function testContentResolutionReplacesFuzzyOptionsAndMarksTheStatus()
    {
        ResolverStub::$behaviour = fn (Intelligent404Resolution $r) => $r->respond('<p>No longer offered.</p>', 410, true, 'Gone', 'stub');
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
