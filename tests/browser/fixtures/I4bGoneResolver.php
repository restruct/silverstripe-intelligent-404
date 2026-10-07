<?php

namespace Restruct\I4bBrowser;

use Restruct\Silverstripe\Intelligent404\Intelligent404Resolution;
use SilverStripe\Core\Extension;

/**
 * BROWSER-TEST FIXTURE ONLY - a project resolver as a consumer would write one: GET /i404-gone-item (and
 * anything below it) answers 410 Gone on the normal 404 template, with its own text and a link onwards.
 * Copied into the scratch host's app/ by the runner; behind tests/browser/_manifest_exclude in the module.
 */
class I4bGoneResolver extends Extension
{
    public function updateIntelligent404Resolution(Intelligent404Resolution $resolution)
    {
        if ($resolution->isResolved() || !preg_match('#^i404-gone-item(/|$)#', $resolution->path)) {
            return;
        }
        $resolution->respond(
            '<p class="i404-gone">This item is no longer available.</p><p><a href="/i404-unique-target">See the alternative</a></p>',
            410,
            true,
            'No longer available',
            'browser-fixture'
        );
    }
}
