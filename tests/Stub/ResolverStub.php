<?php

namespace Restruct\Silverstripe\Intelligent404\Tests\Stub;

use Restruct\Silverstripe\Intelligent404\Intelligent404Resolution;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * A project-side resolver, as a consumer would write one: an extension on ErrorPageController implementing
 * the `updateIntelligent404Resolution` hook. What it does is set per test through $behaviour, which the test
 * resets in tearDown (a static must not outlive the test that set it).
 */
class ResolverStub extends Extension implements TestOnly
{
    /** @var callable|null fn(Intelligent404Resolution $resolution): void */
    public static $behaviour = null;

    /** @var int how often the hook ran, to prove resolvers are (not) consulted */
    public static $calls = 0;

    public function updateIntelligent404Resolution(Intelligent404Resolution $resolution)
    {
        static::$calls++;
        if (static::$behaviour && !$resolution->isResolved()) {
            (static::$behaviour)($resolution);
        }
    }
}
