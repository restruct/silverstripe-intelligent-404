<?php

namespace Restruct\Silverstripe\Intelligent404\Tests;

use Restruct\Silverstripe\Intelligent404\Intelligent404;
use Restruct\Silverstripe\Intelligent404\Tests\Stub\HiddenProduct;
use Restruct\Silverstripe\Intelligent404\Tests\Stub\Product;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Versioned\Versioned;

/**
 * `exclude_hidden_from_search` for a record whose CLASS declares ShowInSearch while the configured
 * `data_objects` class does not (a subclass of it). Kept apart from Intelligent404Test so its
 * fixture cannot add soundalikes there.
 */
class Intelligent404SubclassTest extends SapphireTest
{
    protected static $fixture_file = 'Intelligent404SubclassTest.yml';

    protected static $extra_dataobjects = [
        Product::class,
        HiddenProduct::class,
    ];

    /** @var array $_SERVER as it was before the test, restored in tearDown() */
    private $originalServer;

    /** @var string|null Versioned reading mode before the test, restored in tearDown() */
    private $originalReadingMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
        $this->originalReadingMode = Versioned::get_reading_mode();

        // A 404 is served on the front end, where only published pages exist
        ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'))->publishSingle();
        Versioned::set_stage(Versioned::LIVE);

        Config::modify()->set(Intelligent404::class, 'allow_in_dev_mode', true);
        // Only the configured parent class; HiddenProduct records come along as Product::get() results
        Config::modify()->merge(Intelligent404::class, 'data_objects', [
            Product::class => ['group' => 'Products'],
        ]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        Versioned::set_reading_mode($this->originalReadingMode);

        parent::tearDown();
    }

    /**
     * The Location of the 301 the extension throws for $uri, or null when it does not redirect.
     */
    private function redirectFor(string $uri): ?string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $errorPage = ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'));
        try {
            ModelAsController::controller_for($errorPage)->extend('onAfterInit');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(301, $e->getResponse()->getStatusCode());
            return (string) $e->getResponse()->getHeader('Location');
        }

        return null;
    }

    public function testSubclassRecordHiddenFromSearchIsExcluded()
    {
        HiddenProduct::create(['Title' => 'Hidden gizmo', 'URLSegment' => 'hidden-gizmo', 'ShowInSearch' => false])->write();
        HiddenProduct::create(['Title' => 'Visible gizmo', 'URLSegment' => 'visible-gizmo'])->write();

        // Must-match control: the same shape, visible in search, is matched and redirected to
        $this->assertStringEndsWith('/shop/visible-gizmo', (string) $this->redirectFor('/gone/visible-gizmo'));

        $this->assertNull($this->redirectFor('/gone/hidden-gizmo'));

        // Not listed either (the options list is what renders when the redirect is off)
        Config::modify()->set(Intelligent404::class, 'redirect_on_single_match', false);
        $_SERVER['REQUEST_URI'] = '/gone/hidden-gizmo';
        $controller = ModelAsController::controller_for(
            ErrorPage::get()->byID($this->idFromFixture(ErrorPage::class, 'e404'))
        );
        $controller->extend('onAfterInit');
        $this->assertNull($controller->Intelligent404Options);
    }

    public function testSubclassRecordHiddenFromSearchIsMatchedWhenTheOptionIsOff()
    {
        HiddenProduct::create(['Title' => 'Hidden gizmo', 'URLSegment' => 'hidden-gizmo', 'ShowInSearch' => false])->write();
        Config::modify()->set(Intelligent404::class, 'exclude_hidden_from_search', false);

        $this->assertStringEndsWith('/shop/hidden-gizmo', (string) $this->redirectFor('/gone/hidden-gizmo'));
    }
}
