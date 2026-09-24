<?php

namespace Restruct\Silverstripe\Intelligent404\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A non-SiteTree record with a Link(), standing in for the "products" example in the README:
 * proves that `data_objects` entries other than SiteTree are matched, filtered and excluded.
 *
 * Kept concrete on purpose - an abstract DataObject anywhere in a module's tests/ breaks the
 * temp-database build in every consuming project (see the module-update SOP, Phase 4).
 */
class Product extends DataObject implements TestOnly
{
    # Short table name rather than the FQCN-derived default, which is long and unreadable
    private static $table_name = 'I404Product';

    private static $db = [
        'Title' => 'Varchar',
        'URLSegment' => 'Varchar',
        'InStock' => 'Boolean',
    ];

    /**
     * The module only requires a Link() method on a matched class.
     */
    public function Link()
    {
        return '/shop/' . $this->URLSegment;
    }

    /**
     * The bundled options template renders $MenuTitle for every result.
     */
    public function getMenuTitle()
    {
        return $this->Title;
    }
}
