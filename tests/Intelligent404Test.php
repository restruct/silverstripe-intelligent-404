<?php

namespace Restruct\Silverstripe\Intelligent404\Tests;

use Restruct\Silverstripe\Intelligent404\Intelligent404;
use Restruct\Silverstripe\Intelligent404\Tests\Stub\PrivateProduct;
use Restruct\Silverstripe\Intelligent404\Tests\Stub\Product;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ErrorPage\ErrorPageController;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Behavioural tests for the Intelligent404 extension on ErrorPageController.
 *
 * The extension runs from the controller's `onAfterInit` hook and reads the ORIGINAL request URI
 * from $_SERVER['REQUEST_URI'], because ErrorPage::response_for() hands the error page controller a
 * fresh, empty HTTPRequest. So each test sets REQUEST_URI, builds the ErrorPage controller the way
 * response_for() does, and fires the hook through the public extend() API. That keeps the suite
 * independent of the host project's Page templates.
 */
class Intelligent404Test extends SapphireTest
{
    protected static $fixture_file = 'Intelligent404Test.yml';

    protected static $extra_dataobjects = [
        Product::class,
        PrivateProduct::class,
    ];

    /** @var string|null REQUEST_URI as it was before the test, restored in tearDown() */
    private $originalRequestUri;

    /** @var bool whether REQUEST_URI was set at all before the test */
    private $hadRequestUri;

    /** @var string environment type before the test, restored in tearDown() */
    private $originalEnvironment;

    /** @var string|null Versioned reading mode before the test, restored in tearDown() */
    private $originalReadingMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadRequestUri = array_key_exists('REQUEST_URI', $_SERVER);
        $this->originalRequestUri = $this->hadRequestUri ? $_SERVER['REQUEST_URI'] : null;
        $this->originalEnvironment = Injector::inst()->get(Kernel::class)->getEnvironment();
        $this->originalReadingMode = Versioned::get_reading_mode();

        // A 404 is served on the front end, where only published pages exist.
        foreach ([SiteTree::class, RedirectorPage::class, ErrorPage::class] as $class) {
            foreach ($this->allFixtureIDs($class) as $id) {
                $class::get()->byID($id)->publishSingle();
            }
        }
        Versioned::set_stage(Versioned::LIVE);

        // The module is off in dev mode by default; most tests switch that off to exercise it.
        Config::modify()->set(Intelligent404::class, 'allow_in_dev_mode', true);
    }

    protected function tearDown(): void
    {
        if ($this->hadRequestUri) {
            $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
        Injector::inst()->get(Kernel::class)->setEnvironment($this->originalEnvironment);
        Versioned::set_reading_mode($this->originalReadingMode);

        parent::tearDown();
    }

    /**
     * Fire the hook on the controller of the ErrorPage with the given code, as a request for $uri.
     * Returns the controller, so a test can inspect what the extension put on it.
     */
    private function hit(string $uri, string $errorPageFixture = 'e404'): ErrorPageController
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $errorPage = ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, $errorPageFixture));
        $controller = ModelAsController::controller_for($errorPage);
        $controller->extend('onAfterInit');

        return $controller;
    }

    /**
     * Assert that requesting $uri makes the extension throw a 301 to a URL ending in $expectedPath.
     */
    private function assertRedirectsTo(string $uri, string $expectedPath): void
    {
        try {
            $this->hit($uri);
        } catch (HTTPResponse_Exception $e) {
            $response = $e->getResponse();
            $this->assertSame(301, $response->getStatusCode());
            $this->assertStringEndsWith($expectedPath, (string) $response->getHeader('Location'));
            return;
        }
        $this->fail("Expected a 301 redirect for {$uri}, got none");
    }

    /**
     * Assert that requesting $uri neither redirects nor adds options.
     */
    private function assertLeftAlone(string $uri, string $errorPageFixture = 'e404'): void
    {
        try {
            $controller = $this->hit($uri, $errorPageFixture);
        } catch (HTTPResponse_Exception $e) {
            $this->fail("Expected no redirect for {$uri}, got one to " . $e->getResponse()->getHeader('Location'));
        }
        $this->assertNull($controller->Intelligent404Options, "Expected no 404 options for {$uri}");
    }

    public function testExtensionIsAppliedToErrorPageController()
    {
        $this->assertTrue(ErrorPageController::has_extension(Intelligent404::class));
    }

    public function testConfigDefaults()
    {
        // setUp() switches allow_in_dev_mode on; read the class's own declared default instead.
        Config::modify()->remove(Intelligent404::class, 'allow_in_dev_mode');
        $this->assertFalse((bool) Config::inst()->get(Intelligent404::class, 'allow_in_dev_mode'));
        $this->assertTrue((bool) Config::inst()->get(Intelligent404::class, 'redirect_on_single_match'));

        $dataObjects = Config::inst()->get(Intelligent404::class, 'data_objects');
        $this->assertArrayHasKey(SiteTree::class, $dataObjects);
        $this->assertSame('Pages', $dataObjects[SiteTree::class]['group']);
        $this->assertEqualsCanonicalizing(
            [ErrorPage::class, RedirectorPage::class, VirtualPage::class],
            $dataObjects[SiteTree::class]['exclude']['ClassName']
        );
    }

    public function testAllowInDevModeIsDeclaredOffByDefault()
    {
        // Read the declared default straight off the class static, not through Config: setUp() and
        // other tests Config::modify() this value, and a config layer on top could mask a changed
        // default. Off by default means a dev site shows the plain 404 unless a project opts in.
        $property = new \ReflectionProperty(Intelligent404::class, 'allow_in_dev_mode');
        $this->assertTrue($property->isStatic());
        $this->assertFalse($property->getDefaultValue());
    }

    public function testSingleExactMatchRedirects()
    {
        $this->assertRedirectsTo('/gone/about-us', '/company/about-us');
    }

    public function testKnownPageExtensionIsStripped()
    {
        $this->assertRedirectsTo('/old-site/about-us.php', '/company/about-us');
    }

    public function testPhpExtensionIsStrippedBeforeMatching()
    {
        // testKnownPageExtensionIsStripped cannot tell stripping from no stripping: soundex() ignores
        // the dot, so "about-us.php" still soundex()es to A132 and the single soundalike match redirects
        // to the same page. A second page that SOUNDS like about-us (A132) but is spelled differently
        // takes that fallback away: unstripped, the request has no exact match and two soundalikes, so
        // it lists options; only the stripped "about-us" exact-matches one page and redirects.
        // Created here rather than in Intelligent404Test.yml, where it would also give
        // testSingleSoundalikeMatchRedirects a second soundalike and stop its redirect.
        // setUp() left the reading mode on Live; write on Draft and publish, like the fixture pages.
        Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $lookalike = SiteTree::create([
                'Title' => 'About as',
                'URLSegment' => 'about-as',
            ]);
            $lookalike->write();
            $lookalike->publishSingle();
        });

        $this->assertRedirectsTo('/x/about-us.php', '/company/about-us');
    }

    public function testSingleSoundalikeMatchRedirects()
    {
        // "abuot-us" is not an exact segment, but soundex()es the same as "about-us" (A132)
        $this->assertRedirectsTo('/gone/abuot-us', '/company/about-us');
    }

    public function testMultipleMatchesListOptionsInsteadOfRedirecting()
    {
        $controller = $this->hit('/gone/contact');

        $options = (string) $controller->Intelligent404Options;
        $this->assertStringContainsString('Were you looking for one of the following?', $options);
        $this->assertStringContainsString('href="/company/contact"', $options);
        $this->assertStringContainsString('href="/contact"', $options);

        // The options are appended to $Content, and the untouched original is kept alongside it
        $this->assertStringContainsString('<p>Sorry, not found.</p>', (string) $controller->Content);
        $this->assertStringContainsString('href="/company/contact"', (string) $controller->Content);
        $this->assertInstanceOf(DBHTMLVarchar::class, $controller->ContentWithout404Options);
        $this->assertSame('<p>Sorry, not found.</p>', $controller->ContentWithout404Options->getValue());

        $this->assertSame('contact', $controller->SearchQuery);
    }

    public function testOptionsListCarriesAValidCssClassAndKeepsTheOldOne()
    {
        $options = (string) $this->hit('/gone/contact')->Intelligent404Options;

        // "404options" starts with a digit, so ".404options" is not a valid CSS selector (#4).
        // "intelligent404-options" is the one to style; the old class stays for existing themes.
        $this->assertMatchesRegularExpression('/<ul class="(?:[^"]* )?intelligent404-options(?: [^"]*)?">/', $options);
        $this->assertMatchesRegularExpression('/<ul class="(?:[^"]* )?404options(?: [^"]*)?">/', $options);
    }

    public function testSearchQueryIsSanitised()
    {
        // Dashes and underscores become spaces. "about_us" soundex()es to the about-us page, and
        // with redirects off that single match is listed rather than followed.
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);
        $controller = $this->hit('/gone/about_us');
        $this->assertSame('about us', $controller->SearchQuery);
    }

    public function testRedirectOnSingleMatchCanBeDisabled()
    {
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);

        $controller = $this->hit('/gone/about-us');
        $this->assertStringContainsString('href="/company/about-us"', (string) $controller->Intelligent404Options);
    }

    public function testNoMatchLeavesThe404Alone()
    {
        $this->assertLeftAlone('/gone/xyzzy');
    }

    public function testExcludedPageTypesAreNotMatched()
    {
        // A RedirectorPage is excluded by default, so its exact segment matches nothing
        $this->assertLeftAlone('/gone/old-news');
    }

    public function testOnlyActsOn404()
    {
        $this->assertLeftAlone('/gone/about-us', 'e500');
    }

    public function testErrorPageRenderedAtItsOwnUrlIsLeftAlone()
    {
        // dev/build renders the 404 ErrorPage at its own URL to write the static error file.
        // Without the guard, its segment would exact-match the lookalike page and redirect there.
        $this->assertLeftAlone('/page-not-found');
    }

    public function testDisabledInDevModeByDefault()
    {
        Injector::inst()->get(Kernel::class)->setEnvironment(Kernel::DEV);
        Config::modify()->set(Intelligent404::class, 'allow_in_dev_mode', false);

        $this->assertLeftAlone('/gone/about-us');
    }

    public function testActiveOutsideDevModeWithoutTheDevFlag()
    {
        Injector::inst()->get(Kernel::class)->setEnvironment(Kernel::LIVE);
        Config::modify()->set(Intelligent404::class, 'allow_in_dev_mode', false);

        $this->assertRedirectsTo('/gone/about-us', '/company/about-us');
    }

    public function testMissingRequestUriIsHandledWithoutWarning()
    {
        // Regression: the URI was read from $_SERVER['REQUEST_URI'] unguarded, which raises an
        // "Undefined array key" warning when the error page controller runs without a web request.
        $errorPage = ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'));
        $controller = ModelAsController::controller_for($errorPage);
        unset($_SERVER['REQUEST_URI']);

        $controller->extend('onAfterInit');

        $this->assertNull($controller->Intelligent404Options);
    }

    public function testOtherDataObjectsAreMatchedFilteredAndGrouped()
    {
        Config::modify()->merge(Intelligent404::class, 'data_objects', [
            Product::class => [
                'group' => 'Products',
                'filter' => ['InStock' => true],
            ],
        ]);

        $this->assertRedirectsTo('/gone/widget', '/shop/widget');

        // The gadget exists but is filtered out
        $this->assertLeftAlone('/gone/gadget');

        // Matches land in their own group: the bundled template only renders $Pages, so a
        // product-only match adds the header but no page links.
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);
        $options = (string) $this->hit('/gone/widget')->Intelligent404Options;
        $this->assertStringContainsString('Were you looking for one of the following?', $options);
        $this->assertStringNotContainsString('/shop/widget', $options);
    }

    public function testOtherDataObjectsCanBeExcluded()
    {
        Config::modify()->merge(Intelligent404::class, 'data_objects', [
            Product::class => [
                'exclude' => ['URLSegment' => 'widget'],
            ],
        ]);

        $this->assertLeftAlone('/gone/widget');
    }

    public function testLeadingBackslashInConfiguredClassNameIsNormalised()
    {
        // Regression: the README told projects to key data_objects as `\Product`. ClassInfo::exists()
        // only matches that form once the class happens to be loaded already, so such an entry was
        // silently skipped. In a test run every DataObject is already loaded by the temp-database
        // build, so this asserts the normalisation itself.
        $this->assertSame(Product::class, Intelligent404::normaliseClassName('\\' . Product::class));
        $this->assertSame(Product::class, Intelligent404::normaliseClassName(Product::class));
    }

    public function testConfiguredClassesAreReturnedNormalised()
    {
        // The seam onAfterInit() reads data_objects through: keys lose their leading backslash,
        // each entry keeps its own config, and a missing or non-array config means "nothing to match".
        $productConfig = ['group' => 'Products', 'filter' => ['InStock' => true]];
        Config::modify()->set(Intelligent404::class, 'data_objects', [
            '\\' . Product::class => $productConfig,
        ]);
        $this->assertSame([Product::class => $productConfig], Intelligent404::getConfiguredClasses());

        Config::modify()->set(Intelligent404::class, 'data_objects', 'not-an-array');
        $this->assertSame([], Intelligent404::getConfiguredClasses());

        Config::modify()->remove(Intelligent404::class, 'data_objects');
        $this->assertSame([], Intelligent404::getConfiguredClasses());
    }

    public function testMatchingUsesTheNormalisedClassList()
    {
        // The call site: onAfterInit() must match against the normalised list, not the raw config.
        // Every class is already loaded in a test run, so a raw `\Class` key would still "work" here;
        // what tells the two apart is that `\SiteTree` and `SiteTree` collapse into ONE entry, and
        // the later one (which filters every page out) wins. Matched raw, the plain SiteTree entry
        // would still find the about-us page and redirect there.
        Config::modify()->set(Intelligent404::class, 'data_objects', [
            SiteTree::class => [
                'group' => 'Pages',
            ],
            '\\' . SiteTree::class => [
                'group' => 'Pages',
                'filter' => ['URLSegment' => 'no-such-segment'],
            ],
        ]);

        $this->assertLeftAlone('/gone/about-us');
    }

    /**
     * Create and publish a page (the fixture pages are published in setUp(); these are made per test so
     * they cannot add a soundalike to another test). Soundex codes: members-only / membres-only M516,
     * hidden-page H351, none of which collides with Intelligent404Test.yml.
     */
    private function publishedPage(array $data): SiteTree
    {
        $page = null;
        // setUp() left the reading mode on Live; write on Draft and publish, like the fixture pages
        Versioned::withVersionedMode(function () use ($data, &$page) {
            Versioned::set_stage(Versioned::DRAFT);
            $page = SiteTree::create($data);
            $page->write();
            $page->publishSingle();
        });

        return $page;
    }

    /**
     * A page only the members of one group may view, and that group.
     */
    private function membersOnlyPage(): Group
    {
        $group = Group::create(['Title' => 'Staff', 'Code' => 'i404-staff']);
        $group->write();
        $page = $this->publishedPage([
            'Title' => 'Members only',
            'URLSegment' => 'members-only',
            'CanViewType' => 'OnlyTheseUsers',
        ]);
        $page->ViewerGroups()->add($group);

        return $group;
    }

    public function testProtectedPageIsNotRedirectedToForAnAnonymousVisitor()
    {
        // SECURITY: an exact match on a page the visitor may not view must not redirect there, which
        // would reveal its URL (and, followed, its existence behind the login form).
        $this->membersOnlyPage();
        $this->logOut();

        $this->assertLeftAlone('/gone/members-only');
    }

    public function testProtectedPageIsNotListedForAnAnonymousVisitor()
    {
        // SECURITY: nor may it appear in the options list, which shows its title and link
        $this->membersOnlyPage();
        $this->logOut();
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);

        $this->assertLeftAlone('/gone/members-only');
    }

    public function testProtectedPageIsNotListedForAMemberWithoutAccess()
    {
        // Logged in is not enough: it is the page's own canView() that decides
        $this->membersOnlyPage();
        $outsider = Member::create(['Email' => 'outsider@example.com', 'FirstName' => 'Outsider']);
        $outsider->write();
        $this->logInAs($outsider);
        $this->assertNotNull(\SilverStripe\Security\Security::getCurrentUser(), 'Expected a logged-in member');
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);

        $this->assertLeftAlone('/gone/members-only');
    }

    public function testProtectedPageIsRedirectedToAndListedForAPermittedMember()
    {
        $group = $this->membersOnlyPage();
        $member = Member::create(['Email' => 'staff@example.com', 'FirstName' => 'Staff']);
        $member->write();
        $member->Groups()->add($group);
        $this->logInAs($member);

        $this->assertRedirectsTo('/gone/members-only', '/members-only');

        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);
        $options = (string) $this->hit('/gone/members-only')->Intelligent404Options;
        $this->assertStringContainsString('href="/members-only"', $options);
        $this->assertStringContainsString('Members only', $options);
    }

    public function testProtectedExactMatchDoesNotCountTowardsTheSingleMatchRedirect()
    {
        // The protected page exact-matches, a public page only sounds like it. Unchecked, the one exact
        // match would win and redirect to the protected page; checked, the anonymous visitor has no
        // exact match left and is sent to the one public soundalike.
        $this->membersOnlyPage();
        $this->publishedPage(['Title' => 'Membres only', 'URLSegment' => 'membres-only']);
        $this->logOut();

        $this->assertRedirectsTo('/gone/members-only', '/membres-only');
    }

    public function testOtherDataObjectsAreCheckedWithCanViewToo()
    {
        // PrivateProduct keeps the DataObject default canView() (ADMIN only): an anonymous visitor
        // gets nothing, an administrator is redirected.
        PrivateProduct::create(['Title' => 'Secret item', 'URLSegment' => 'secret-item'])->write();
        Config::modify()->merge(Intelligent404::class, 'data_objects', [
            PrivateProduct::class => [
                'group' => 'Products',
            ],
        ]);

        $this->logOut();
        $this->assertLeftAlone('/gone/secret-item');

        $this->logInWithPermission('ADMIN');
        $this->assertRedirectsTo('/gone/secret-item', '/private-shop/secret-item');
    }

    public function testPageHiddenFromSearchIsExcludedByDefault()
    {
        $this->publishedPage(['Title' => 'Hidden page', 'URLSegment' => 'hidden-page', 'ShowInSearch' => false]);

        // The declared default, read off the class itself (see testAllowInDevModeIsDeclaredOffByDefault)
        $property = new \ReflectionProperty(Intelligent404::class, 'exclude_hidden_from_search');
        $this->assertTrue($property->getDefaultValue());

        $this->assertLeftAlone('/gone/hidden-page');

        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);
        $this->assertLeftAlone('/gone/hidden-page');
    }

    public function testPageHiddenFromSearchIsMatchedWhenTheOptionIsOff()
    {
        $this->publishedPage(['Title' => 'Hidden page', 'URLSegment' => 'hidden-page', 'ShowInSearch' => false]);
        Config::modify()->set(Intelligent404::class, 'exclude_hidden_from_search', false);

        $this->assertRedirectsTo('/gone/hidden-page', '/hidden-page');
    }
}
