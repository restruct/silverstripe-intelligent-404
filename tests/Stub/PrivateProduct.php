<?php

namespace Restruct\Silverstripe\Intelligent404\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A non-SiteTree record with a Link() but WITHOUT its own canView(), so it keeps the DataObject
 * default (ADMIN only). Proves that `data_objects` matches are checked with canView() too, not only
 * pages, and documents what happens to a class configured without a canView().
 *
 * Kept concrete on purpose, like Product.
 */
class PrivateProduct extends DataObject implements TestOnly
{
    # Short table name rather than the FQCN-derived default
    private static $table_name = 'I404PrivateProduct';

    private static $db = [
        'Title' => 'Varchar',
        'URLSegment' => 'Varchar',
    ];

    /**
     * The module only requires a Link() method on a matched class.
     */
    public function Link()
    {
        return '/private-shop/' . $this->URLSegment;
    }

    /**
     * The bundled options template renders $MenuTitle for every result.
     */
    public function getMenuTitle()
    {
        return $this->Title;
    }
}
