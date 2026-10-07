<?php

namespace Restruct\Silverstripe\Intelligent404\Tests\Stub;

use Restruct\Silverstripe\Intelligent404\Intelligent404Resolution;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Project-side resolvers, as a consumer would write them: an extension on ErrorPageController implementing the
 * `updateIntelligent404Resolution` hook. $behaviour is a list of callables, each standing in for one resolver,
 * run in order on the same resolution WITHOUT checking isResolved() first: first-wins must be enforced by
 * Intelligent404Resolution itself. Set per test, reset in tearDown (a static must not outlive its test).
 */
class ResolverStub extends Extension implements TestOnly
{
    /** @var callable[] fn(Intelligent404Resolution $resolution): void */
    public static $behaviour = [];

    /** @var int how often the hook ran */
    public static $calls = 0;

    public function updateIntelligent404Resolution(Intelligent404Resolution $resolution)
    {
        static::$calls++;
        foreach ((array) static::$behaviour as $resolver) {
            $resolver($resolution);
        }
    }
}
