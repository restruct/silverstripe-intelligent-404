<?php

namespace Restruct\I4bBrowser;

use Page;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Versioned\Versioned;

/**
 * BROWSER-TEST FIXTURE ONLY - GET /admin/i404-reset/seed makes sure these published root pages
 * exist and answers {URLSegment: Link} as JSON:
 *   i404-unique-target  "Unique target"   - the only page whose segment (or sound) matches
 *   i404-colour         "Colour page"     - } same soundex (I246): together they are
 *   i404-color          "Color page"      - } "several matches"
 *   i404-members-only   "Members only"    - CanViewType LoggedInUsers
 * Segments start with i404- so no default page (home, about-us, ...) matches or sounds alike.
 *
 * A LeftAndMain because the admin routes those by url_segment with no YAML; LeftAndMain's own
 * access check applies, so only the logged-in admin can call it. The runner copies
 * tests/browser/fixtures/ into the scratch host's app/; in the module itself it sits behind
 * tests/browser/_manifest_exclude, so no real install ever loads it.
 */
class I4bResetAdmin extends LeftAndMain
{
    private static $url_segment = 'i404-reset';

    private static $menu_title = 'Intelligent 404 browser reset';

    private static $allowed_actions = ['seed'];

    private const PAGES = [
        'i404-unique-target' => ['Unique target', 'Anyone'],
        'i404-colour' => ['Colour page', 'Anyone'],
        'i404-color' => ['Color page', 'Anyone'],
        'i404-members-only' => ['Members only', 'LoggedInUsers'],
    ];

    public function seed(HTTPRequest $request): HTTPResponse
    {
        $links = Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $links = [];
            foreach (self::PAGES as $segment => [$title, $canView]) {
                $page = Page::get()->filter('URLSegment', $segment)->first() ?: Page::create();
                $page->update(['Title' => $title, 'URLSegment' => $segment, 'ParentID' => 0, 'CanViewType' => $canView]);
                $page->write();
                $page->publishSingle();
                $links[$segment] = $page->Link();
            }
            return $links;
        });

        return HTTPResponse::create(json_encode($links))->addHeader('Content-Type', 'application/json');
    }
}
